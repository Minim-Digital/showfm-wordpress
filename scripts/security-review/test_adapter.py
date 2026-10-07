"""Exercise the exact pinned action plus our patch without Claude or network I/O.

Usage: python3 scripts/security-review/test_adapter.py <pinned-action-checkout>
"""
import ast
import contextlib
import copy
import io
import json
import os
import re
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from typing import Any, Dict
from unittest.mock import patch

from prepare_action import PIN, prepare
from verify_result import verify

ACTION = Path(sys.argv[1]).resolve()
sys.argv = [sys.argv[0]]


def git(directory, *args):
    return subprocess.run(['git', '-C', str(directory), *args], check=True,
                          capture_output=True, text=True).stdout.strip()


class AdapterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.tmp.name)
        cls.action = cls.root / 'action'
        subprocess.run(['git', 'clone', '--shared', '--no-checkout', str(ACTION), str(cls.action)],
                       check=True, capture_output=True)
        git(cls.action, 'checkout', '--detach', PIN)
        prepare(cls.action)
        cls.source = (cls.action / 'claudecode/github_action_audit.py').read_text()
        cls.tree = ast.parse(cls.source)
        cls.repo = cls.root / 'repo'
        cls.repo.mkdir()
        git(cls.repo, 'init')
        git(cls.repo, 'config', 'user.name', 'Security Adapter Test')
        git(cls.repo, 'config', 'user.email', 'adapter@test.local')
        (cls.repo / 'README').write_text('base\n')
        git(cls.repo, 'add', '--', 'README')
        git(cls.repo, '-c', 'commit.gpgsign=false', 'commit', '-m', 'base')
        cls.base = git(cls.repo, 'rev-parse', 'HEAD')
        cls.paths = [f'file-{i:03}.py' for i in range(310)] + [
            '--output=escaped', 'odd;$(touch SHOULD_NOT_EXIST)\nname.py', 'unicodé.py', 'carriage\rreturn.py', ':(exclude)victim.py',
            'victim.py', '[abc].py', 'generated-marker.py']
        for name in cls.paths:
            (cls.repo / name).write_text('security_relevant_change = True\n')
        (cls.repo / 'docs').mkdir()
        (cls.repo / 'docs/ignored.md').write_text('excluded\n')
        (cls.repo / 'docs/é.md').write_text('excluded Unicode content\n')
        (cls.repo / 'generated-marker.py').write_text('# @generated\nauthorize = False\n')
        git(cls.repo, 'add', '--all', '--', '.')
        git(cls.repo, '-c', 'commit.gpgsign=false', 'commit', '-m', 'large change')
        cls.head = git(cls.repo, 'rev-parse', 'HEAD')
        cls.event = {'repository': {'full_name': 'test/repo'}, 'pull_request': {
            'number': 570, 'head': {'sha': cls.head}, 'base': {'sha': cls.base}}}
        cls.event_path = cls.root / 'event.json'
        cls.event_path.write_text(json.dumps(cls.event))

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def setUp(self):
        git(self.repo, 'checkout', '--detach', self.head)
        self.event_path.write_text(json.dumps(self.event))
        self.environment = patch.dict(os.environ, {
            'GITHUB_WORKSPACE': str(self.repo), 'GITHUB_EVENT_PATH': str(self.event_path),
            'GITHUB_TOKEN': 'synthetic-test-token', 'EXCLUDE_DIRECTORIES': 'docs',
            'REPO_PATH': str(self.repo),
        })
        self.environment.start()
        self.addCleanup(self.environment.stop)
        self.metadata = {'number': 570, 'title': 'Large PR', 'body': '',
                         'user': {'login': 'tester'}, 'created_at': '', 'updated_at': '',
                         'state': 'open', 'head': {'sha': self.head},
                         'base': {'sha': self.base}, 'additions': 318, 'deletions': 0}
        self.requests = []
        def request(url, **kwargs):
            self.requests.append((url, kwargs))
            return SimpleNamespace(raise_for_status=lambda: None, json=lambda: self.metadata)
        self.namespace = {'os': os, 'sys': sys, 'json': json, 'subprocess': subprocess,
                          'requests': SimpleNamespace(get=request), 'Path': Path,
                          're': re, 'Dict': Dict, 'Any': Any}
        selected = [node for node in self.tree.body if isinstance(node, ast.ClassDef)
                    and node.name == 'GitHubActionClient']
        exec(compile(ast.Module(body=selected, type_ignores=[]), 'pinned-action-client', 'exec'), self.namespace)
        self.client = self.namespace['GitHubActionClient']()

    def test_runner_tools_skip_apt_and_fail_closed(self):
        action_source = (self.action / 'action.yml').read_text()
        for tool in ('gh', 'jq'):
            block = re.search(
                rf'        if ! command -v {tool} .*?        {tool} --version',
                action_source, re.DOTALL).group()
            for scenario in ('installed', 'missing', 'broken', 'install-failed'):
                with self.subTest(tool=tool, scenario=scenario), tempfile.TemporaryDirectory() as directory:
                    directory = Path(directory)
                    executable = directory / tool
                    log = directory / 'apt.log'
                    if scenario in ('installed', 'broken'):
                        executable.write_text('#!/bin/bash\nexit ' + ('7' if scenario == 'broken' else '0') + '\n')
                        executable.chmod(0o755)
                    sudo = directory / 'sudo'
                    sudo.write_text(
                        '#!/bin/bash\nprintf "%s\\n" "$*" >> "$APT_LOG"\n'
                        + ('exit 9\n' if scenario == 'install-failed' else
                           'if [[ "$2" == install ]]; then\n'
                           '  printf "#!/bin/bash\\nexit 0\\n" > "$TOOL_PATH"\n'
                           '  /bin/chmod +x "$TOOL_PATH"\nfi\n'))
                    sudo.chmod(0o755)
                    result = subprocess.run(['/bin/bash', '-e', '-c', block], capture_output=True,
                                            env={'PATH': str(directory), 'APT_LOG': str(log),
                                                 'TOOL_PATH': str(executable)})
                    self.assertEqual(scenario in ('installed', 'missing'), result.returncode == 0)
                    if scenario in ('installed', 'broken'):
                        self.assertFalse(log.exists(), 'Preinstalled tools must not invoke apt')
                    else:
                        self.assertIn('apt-get update', log.read_text())
                    if scenario == 'missing':
                        self.assertIn(f'apt-get install -y {tool}', log.read_text())

    def test_modified_action_setup_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            subprocess.run(['git', 'clone', '--shared', '--no-checkout', str(ACTION), directory],
                           check=True, capture_output=True)
            git(directory, 'checkout', '--detach', PIN)
            source = Path(directory) / 'action.yml'
            source.write_text(source.read_text() + '\n# unexpected setup change\n')
            with self.assertRaisesRegex(ValueError, 'setup differs'):
                prepare(Path(directory))

    def test_complete_manifest_and_diff_with_untrusted_paths(self):
        data = self.client.get_pr_data('test/repo', 570)
        self.assertEqual(set(self.paths), {item['filename'] for item in data['files']})
        self.assertEqual(318, data['changed_files'])
        diff = self.client.get_pr_diff('test/repo', 570)
        self.assertIn('file-309.py', diff)
        self.assertNotIn('docs/ignored.md', diff)
        self.assertNotIn('excluded Unicode content', diff)
        self.assertIn('authorize = False', diff)
        self.assertIn('diff --git a/victim.py b/victim.py', diff)
        self.assertIn('diff --git a/[abc].py b/[abc].py', diff)
        self.assertIn('security_relevant_change', diff)
        self.assertFalse((self.repo / 'SHOULD_NOT_EXIST').exists())
        self.assertEqual(1, len(self.requests))
        self.assertEqual('https://api.github.com/repos/test/repo/pulls/570', self.requests[0][0])
        self.assertEqual(30, self.requests[0][1]['timeout'])

    def test_stale_checkout_is_rejected_before_network(self):
        git(self.repo, 'checkout', '--detach', self.base)
        with self.assertRaisesRegex(ValueError, 'Checked-out HEAD'):
            self.client.get_pr_data('test/repo', 570)
        self.assertEqual([], self.requests)

    def test_current_pr_head_or_base_must_match_event(self):
        for field in ['head', 'base']:
            with self.subTest(field=field):
                original = self.metadata[field]['sha']
                self.metadata[field]['sha'] = 'f' * 40
                with self.assertRaisesRegex(ValueError, 'PR changed'):
                    self.client.get_pr_data('test/repo', 570)
                self.metadata[field]['sha'] = original

    def test_event_identity_and_sha_are_validated(self):
        with self.assertRaisesRegex(ValueError, 'event does not match'):
            self.client.get_pr_data('different/repo', 570)
        altered = copy.deepcopy(self.event)
        altered['pull_request']['head']['sha'] = '--output=SHOULD_NOT_EXIST'
        self.event_path.write_text(json.dumps(altered))
        with self.assertRaisesRegex(ValueError, 'invalid commit SHA'):
            self.client.get_pr_data('test/repo', 570)
        self.assertEqual([], self.requests)

    def test_external_diff_is_disabled(self):
        marker = self.root / 'external-diff-was-run'
        driver = self.root / 'external-diff.sh'
        driver.write_text('#!/bin/sh\ntouch "' + str(marker) + '"\nexit 42\n')
        driver.chmod(0o700)
        with patch.dict(os.environ, {'GIT_EXTERNAL_DIFF': str(driver)}):
            self.client.get_pr_data('test/repo', 570)
        self.assertFalse(marker.exists())

    def test_actual_main_fallback_keeps_full_manifest_and_snapshot(self):
        # Mixed security inputs must reach both real upstream prompt variants.
        mixed_inputs = {
            'package.json': '{"scripts":{"postinstall":"node install.js"}}\n',
            'pnpm-lock.yaml': 'lockfileVersion: 9.0\n',
            'SECURITY.md': 'Certificate identity must be verified.\n',
            'root.crt': 'synthetic certificate fixture\n',
        }
        for filename, content in mixed_inputs.items():
            (self.repo / filename).write_text(content)
        git(self.repo, 'add', '--', *mixed_inputs)
        git(self.repo, '-c', 'commit.gpgsign=false', 'commit', '-m', 'mixed review inputs')
        head = git(self.repo, 'rev-parse', 'HEAD')
        self.metadata['head']['sha'] = head
        event = copy.deepcopy(self.event)
        event['pull_request']['head']['sha'] = head
        self.event_path.write_text(json.dumps(event))
        expected_paths = [*self.paths, *mixed_inputs]
        prompts = []
        def audit(repo, prompt):
            prompts.append(prompt)
            if len(prompts) == 1:
                return False, 'PROMPT_TOO_LONG', {}
            return True, '', {'findings': [], 'analysis_summary': {
                'review_completed': True, 'files_reviewed': len(expected_paths)}}
        prompt_source = (self.action / 'claudecode/prompts.py').read_text()
        exec(compile(prompt_source, 'pinned-prompts', 'exec'), self.namespace)
        self.namespace.update({
            'get_environment_config': lambda: ('test/repo', 570),
            'initialize_clients': lambda: (self.client, SimpleNamespace(
                validate_claude_available=lambda: (True, ''), run_security_audit=audit)),
            'initialize_findings_filter': lambda _: None,
            'apply_findings_filter': lambda *args: ([], [], {}),
            'EXIT_SUCCESS': 0, 'EXIT_GENERAL_ERROR': 1, 'EXIT_CONFIGURATION_ERROR': 2,
            'ConfigurationError': ValueError,
        })
        selected = [node for node in self.tree.body if isinstance(node, ast.FunctionDef) and node.name == 'main']
        exec(compile(ast.Module(body=selected, type_ignores=[]), 'pinned-action-main', 'exec'), self.namespace)
        output = io.StringIO()
        with contextlib.redirect_stdout(output), self.assertRaises(SystemExit) as exit_info:
            self.namespace['main']()
        self.assertEqual(0, exit_info.exception.code)
        self.assertEqual(2, len(prompts))
        for prompt in prompts:
            for filename in expected_paths:
                self.assertIn(filename, prompt)
            for requirement in (
                'Inspect the changes in every file in the input manifest',
                'regardless of extension or generated status',
                'dependency sources, version changes, integrity and lifecycle scripts',
                'documentation, instruction and certificate changes',
                'Do not copy the input count or inflate files_reviewed',
                'set review_completed to false',
            ):
                self.assertIn(requirement, prompt)
        self.assertIn(self.base, prompts[1])
        self.assertIn(head, prompts[1])
        self.assertIn('diff was omitted', prompts[1])
        self.assertIn('does not mean no changes', prompts[1])
        self.assertEqual(len(expected_paths), json.loads(output.getvalue())['input_scope']['file_count'])

    def test_patch_refuses_modified_source(self):
        # Already-patched code must not receive a fuzzy or repeated patch.
        with self.assertRaisesRegex(ValueError, 'differs from the reviewed patch target'):
            prepare(self.action)

    def valid_result(self):
        return {'repo': 'test/repo', 'pr_number': 570, 'input_scope': {
            'head_sha': self.head, 'base_sha': self.base, 'files': ['file.py'], 'file_count': 1},
            'findings': [], 'analysis_summary': {'review_completed': True, 'files_reviewed': 1}}

    def test_zero_findings_is_a_valid_completed_analysis(self):
        self.assertEqual(0, verify(self.valid_result(), self.event)['findings'])

    def test_reported_partial_review_cannot_claim_completion(self):
        result = self.valid_result()
        result['input_scope']['files'] = ['one.py', 'two.py']
        result['input_scope']['file_count'] = 2
        with self.assertRaisesRegex(ValueError, 'partial review'):
            verify(result, self.event)
        result['analysis_summary']['files_reviewed'] = 3
        self.assertEqual(3, verify(result, self.event)['model_reported_files_reviewed'])

    def test_missing_error_incomplete_and_stale_results_fail(self):
        invalid = [None, {}, {'error': '406'}, {**self.valid_result(), 'findings': None},
                   {**self.valid_result(), 'analysis_summary': {'review_completed': False, 'files_reviewed': 1}},
                   {**self.valid_result(), 'analysis_summary': {'review_completed': True, 'files_reviewed': 0}},
                   {**self.valid_result(), 'analysis_summary': {'review_completed': True, 'files_reviewed': True}}]
        stale = self.valid_result()
        stale['input_scope']['head_sha'] = self.base
        invalid.append(stale)
        for result in invalid:
            with self.subTest(result=result), self.assertRaises(ValueError):
                verify(result, self.event)

    def test_high_findings_fail_the_command_after_valid_analysis(self):
        result = self.valid_result()
        result['findings'] = [{'file': 'file.py', 'severity': 'HIGH'}]
        result_file = self.root / 'results.json'
        result_file.write_text(json.dumps(result))
        execution = subprocess.run([sys.executable, str(Path(__file__).with_name('verify_result.py')),
                                    str(result_file), str(self.event_path)], capture_output=True)
        self.assertEqual(1, execution.returncode)
        self.assertIn(b'high-severity findings', execution.stderr)


if __name__ == '__main__':
    unittest.main()
