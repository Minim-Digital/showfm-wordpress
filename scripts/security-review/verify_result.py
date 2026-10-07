"""Fail closed when the security action produced no completed analysis.

Input provenance proves which changes were supplied. The model's completion and
file count remain its own report, not independent proof of files actually read.
"""
import json
import sys
from pathlib import Path


def verify(result: object, event: dict) -> dict:
    if not isinstance(result, dict) or 'error' in result:
        raise ValueError('Security analysis is missing or returned an error')
    pull = event['pull_request']
    if result.get('repo') != event['repository']['full_name'] or result.get('pr_number') != pull['number']:
        raise ValueError('Security result does not match this PR')
    scope = result.get('input_scope')
    if not isinstance(scope, dict) or scope.get('head_sha') != pull['head']['sha'] or scope.get('base_sha') != pull['base']['sha']:
        raise ValueError('Security result does not match the event snapshot')
    files = scope.get('files')
    if not isinstance(files, list) or not files or not all(isinstance(file, str) and file for file in files):
        raise ValueError('Security result has no complete input file manifest')
    if type(scope.get('file_count')) is not int or scope['file_count'] != len(files) or len(set(files)) != len(files):
        raise ValueError('Security input file count is inconsistent')
    findings, summary = result.get('findings'), result.get('analysis_summary')
    if not isinstance(findings, list) or not all(isinstance(finding, dict) for finding in findings):
        raise ValueError('Security result is missing a valid findings array')
    if not isinstance(summary, dict) or summary.get('review_completed') is not True:
        raise ValueError('Security analysis did not report completion')
    reviewed = summary.get('files_reviewed')
    if type(reviewed) is not int or reviewed <= 0:
        raise ValueError('Security analysis reported no reviewed files')
    if reviewed < len(files):
        raise ValueError('Security analysis reported a partial review of the input manifest')
    for finding in findings:
        if not isinstance(finding.get('file'), str) or not finding['file'] or not isinstance(finding.get('severity'), str) or finding['severity'].upper() not in {'HIGH', 'MEDIUM', 'LOW'}:
            raise ValueError('Security finding is malformed')
    return {'input_files': len(files), 'model_reported_files_reviewed': reviewed,
            'findings': len(findings),
            'high_findings': sum(finding['severity'].upper() == 'HIGH' for finding in findings)}


if __name__ == '__main__':
    try:
        counts = verify(json.loads(Path(sys.argv[1]).read_text()),
                        json.loads(Path(sys.argv[2]).read_text()))
        print(json.dumps(counts))
        if counts['high_findings']:
            raise ValueError('Security review reported high-severity findings requiring resolution')
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f'Security review gate failed: {error}', file=sys.stderr)
        raise SystemExit(1)
