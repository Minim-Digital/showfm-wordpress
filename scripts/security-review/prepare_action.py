"""Apply the bounded local setup and transport patch to the exact pinned review action."""
import hashlib
import subprocess
import sys
from pathlib import Path

PIN = 'e1092743ad11d9d918e00cb4e740e7b121205d98'
ACTION_SHA256 = 'e3a02150c190567908a707a6f5a554928a799978660f2d5087a0a65398d61371'
SOURCE_SHA256 = '5bd1909de2a97237c8c80b97f0ea1a9786196afa6901aee03b088fffedf1de31'


def prepare(action_dir: Path) -> None:
    revision = subprocess.run(['git', '-C', str(action_dir), 'rev-parse', 'HEAD'],
                              check=True, capture_output=True, text=True).stdout.strip()
    if revision != PIN:
        raise ValueError('Security action checkout does not match the reviewed pin')
    source = action_dir / 'claudecode/github_action_audit.py'
    if hashlib.sha256(source.read_bytes()).hexdigest() != SOURCE_SHA256:
        raise ValueError('Security action source differs from the reviewed patch target')
    if hashlib.sha256((action_dir / 'action.yml').read_bytes()).hexdigest() != ACTION_SHA256:
        raise ValueError('Security action setup differs from the reviewed patch target')
    patch = Path(__file__).with_name('action.patch').resolve()
    command = ['git', '-C', str(action_dir), 'apply', '--whitespace=error']
    subprocess.run([*command, '--check', str(patch)], check=True)
    subprocess.run([*command, str(patch)], check=True)


if __name__ == '__main__':
    prepare(Path(sys.argv[1]).resolve())
