# Import provenance parity vectors

`provenance-vectors.json` is an unchanged copy of all 64 vectors from
`Minim-Digital/podcaster-plus-app` at `08da1983625ae0683ed43ef0b58d7468daff2064`:
`src/lib/developer-api/__fixtures__/provenance-vectors.json` (PR #747).

The matching normalisation module is `src/lib/developer-api/provenance.ts`, contract
version 1. `includes/class-migration-url.php` ports it to PHP. The data-provider test in
`tests/phpunit/test-migration-review.php` checks the normalised value and SHA-256 for every
vector, including nulls, GUID whitespace, IDNA, IPv6, numeric IPv4, nested tracking
prefixes, percent escapes and invalid inputs. Preserve this file byte-for-byte when
updating it from the app contract; do not independently edit expected values here.
