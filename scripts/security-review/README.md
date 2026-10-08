# Security-review action adapter

The workflow checks out `ShowDotFM/claude-code-security-review` at
`e1092743ad11d9d918e00cb4e740e7b121205d98` and applies `action.patch` locally.
`prepare_action.py` verifies that commit and the original Python and action YAML files' SHA-256 hashes
before applying the patch. The fork is unchanged remotely; no provider code is
vendored here.

The patch replaces the limited GitHub diff/files transports with a complete local
Git manifest and diff bound to the PR event and checked-out HEAD. It retains the
upstream prompt template, model, OAuth, findings filtering and directory exclusions.
Its custom scan instructions require inspecting every manifest path, including
dependency locks/manifests and security-relevant documentation/certificates, and
reporting incomplete work honestly. These instructions reach the initial analysis
and omitted-diff retry; the upstream initial prompt does not include the PR body.
Exact literal path selection aligns the inline diff with its manifest; incidental
`@generated` text does not suppress source files. The prompt-too-long retry
keeps every input path and the exact comparison revisions. Input provenance means
files supplied, not proof that the model read their bodies.

`verify_result.py` rejects missing/error/incomplete or stale results. Completed
zero-finding analysis passes when the model reports completing at least all input
files; high-severity findings fail as the scanner intended. Counts remain
self-reported, not independent evidence of every body read.

The setup patch uses preinstalled `gh` and `jq` and checks their `--version` commands. It invokes apt only when a tool is absent, so an unavailable Ubuntu mirror cannot block a runner that already has both tools. Broken tools and unsuccessful fallback installs still fail the job. The scan and completion gate are unchanged.

## Tests

Obtain a pristine checkout of the public pinned action, then run:

```sh
git clone https://github.com/ShowDotFM/claude-code-security-review.git /tmp/security-review-action
git -C /tmp/security-review-action checkout --detach e1092743ad11d9d918e00cb4e740e7b121205d98
python3 scripts/security-review/test_adapter.py /tmp/security-review-action
```

Tests apply the patch to a temporary clone of the exact source. They exercise the
patched client and actual `main()` fallback with synthetic GitHub responses and a
real Git repository containing more than 300 changed files, unusual filenames,
mixed manifest/lock/documentation/certificate inputs in both prompt variants,
stale refs, an external diff driver and denied/allowed result shapes. They make no
Claude calls, send no comments and do not use credentials. CI runs them against
its pinned action checkout before patching the action that will scan the PR.

## Updating the pin

Review the upstream delta, keep the OAuth support, update the workflow pin and
`prepare_action.py` pin/hash, then regenerate the smallest applicable patch against
the exact new source. Run the tests above and inspect a genuine labelled review's
artifact and completion gate. Do not relax the hash check or accept fuzzy patches
to make an upgrade pass. Do not treat a green provider step as evidence if the
final completion gate or the analysis itself failed.
