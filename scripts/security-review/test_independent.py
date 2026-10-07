"""Independent adversarial probes of the exact patched upstream action.

Run: python3 scripts/security-review/test_independent.py <pinned-action-checkout>
Includes the author's transport suite plus six independent regression probes.
All Git/filesystem fixtures are temporary; Claude/network calls are mocked.
"""
import ast, importlib.util, json, os, subprocess, sys, unittest
from pathlib import Path
from types import SimpleNamespace
from typing import Optional, Tuple, Dict, Any
from unittest.mock import patch

adapter = Path(__file__).resolve().parent
sys.path.insert(0,str(adapter))
spec=importlib.util.spec_from_file_location('adapter_tests',adapter/'test_adapter.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
class IndependentTests(mod.AdapterTests):
 def commit_files(self, entries):
  for name, content in entries.items():
   target=self.repo/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_text(content)
  mod.git(self.repo,'--literal-pathspecs','add','--',*entries)
  mod.git(self.repo,'-c','commit.gpgsign=false','commit','-m','independent adversarial names')
  head=mod.git(self.repo,'rev-parse','HEAD');self.metadata['head']['sha']=head
  event=json.loads(self.event_path.read_text());event['pull_request']['head']['sha']=head;self.event_path.write_text(json.dumps(event))
 def test_marker_cannot_omit_source_from_inline_diff(self):
  self.commit_files({'security-api.py':'# @generated\nauthorize = False\n'})
  data=self.client.get_pr_data('test/repo',570)
  self.assertIn('security-api.py',[f['filename'] for f in data['files']])
  self.assertIn('authorize = False',self.client.get_pr_diff('test/repo',570))
 def test_quoted_excluded_names_stay_excluded(self):
  self.commit_files({'docs/é.md':'EXCLUDED_UNICODE_SENTINEL\n','docs/line\nbreak.md':'EXCLUDED_NEWLINE_SENTINEL\n'})
  data=self.client.get_pr_data('test/repo',570);diff=self.client.get_pr_diff('test/repo',570)
  self.assertNotIn('docs/é.md',[f['filename'] for f in data['files']])
  self.assertNotIn('EXCLUDED_UNICODE_SENTINEL',diff);self.assertNotIn('EXCLUDED_NEWLINE_SENTINEL',diff)
 def test_literal_git_pathspecs_do_not_drop_or_expand_files(self):
  entries={':(exclude)victim.py':'EXCLUDE_MAGIC_SENTINEL\n','[abc].py':'GLOB_LITERAL_SENTINEL\n','victim.py':'VICTIM_SENTINEL\n'}
  self.commit_files(entries);data=self.client.get_pr_data('test/repo',570);diff=self.client.get_pr_diff('test/repo',570)
  for name,content in entries.items():
   self.assertIn(name,[f['filename'] for f in data['files']]);self.assertIn(content.strip(),diff)
 def test_obviously_partial_model_completion_fails(self):
  result=self.valid_result();result['input_scope']['files']=[f'file-{i}.py' for i in range(313)];result['input_scope']['file_count']=313
  with self.assertRaises(ValueError):mod.verify(result,self.event)
 def test_oauth_only_and_no_credentials_exact_runner(self):
  namespace={'os':os,'subprocess':subprocess,'Optional':Optional,'Tuple':Tuple,'Dict':Dict,'Any':Any,'Path':Path,'SUBPROCESS_TIMEOUT':600}
  selected=[n for n in self.tree.body if isinstance(n,ast.ClassDef) and n.name=='SimpleClaudeRunner']
  exec(compile(ast.Module(body=selected,type_ignores=[]),'patched-exact-runner','exec'),namespace)
  runner=namespace['SimpleClaudeRunner']()
  with patch.object(subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout='',stderr='')):
   with patch.dict(os.environ,{'CLAUDE_CODE_OAUTH_TOKEN':'synthetic-oauth'},clear=True):self.assertEqual((True,''),runner.validate_claude_available())
   with patch.dict(os.environ,{},clear=True):self.assertFalse(runner.validate_claude_available()[0])
 def test_command_rejects_missing_and_invalid_json_results(self):
  path=self.root/'independent-missing-result.json'
  path.unlink(missing_ok=True)
  for contents in [None,'not JSON',json.dumps({'error':'upstream skipped/error'})]:
   if contents is not None:path.write_text(contents)
   result=subprocess.run([sys.executable,str(adapter/'verify_result.py'),str(path),str(self.event_path)],capture_output=True)
   self.assertEqual(1,result.returncode);self.assertIn(b'Security review gate failed',result.stderr)

if __name__=='__main__':unittest.main()
