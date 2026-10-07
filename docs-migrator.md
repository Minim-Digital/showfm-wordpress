# Embed migration engine

I added the migration service and WP-CLI command for WP-5a. The Migrate tab is a separate task.

## Usage

Connect the site first, then run as a site administrator who can edit the affected posts.
On multisite, add `--url=<site>` to each command. Each site's credentials, cursor and report
are separate.

```sh
wp showfm migrate-embeds --dry-run --user=admin
wp showfm migrate-embeds --dry-run --post=42 --format=json --user=admin
wp showfm migrate-embeds --dry-run --batches=1 --user=admin
wp showfm migrate-embeds --dry-run --resume --user=admin
wp showfm migrate-embeds --reset --user=admin
wp showfm migrate-embeds --dry-run --reset --user=admin
wp showfm migrate-embeds --yes --resume --user=admin
wp showfm migrate-embeds --yes --resume --choose=42:1:11111111-2222-4333-8444-555555555555 --user=admin
```

`--dry-run` always prevents swaps, including when combined with `--yes`. Without either
mode the command refuses. `--yes`, with or without `--resume`, requires a completed dry
run and applies only that saved report. It never fetches a new catalogue or scans afresh.
`--choose` accepts comma-separated triples, with one-based embed numbers from the report.
Every choice must name a candidate of that exact ambiguous embed in a safely scanned post.
Unmatched items and posts with overlapping or shared-wrapper ranges cannot be chosen.
If a batch limit interrupts scanning, no swaps run until the scan is complete.

Catalogue pages and both API cursors survive request failures. Resume with `--dry-run
--resume` after the stored `retry_at` time; 429 honours `Retry-After`, other failures wait
at least five seconds. Successful pages are not fetched again. The active report remains
available until its replacement catalogue is complete. Pending acquisition is bound to
the connection and post restriction. After a reconnect with a different key, the next
scan automatically discards the obsolete pending run and its pages before fetching anew.
`--reset` discards pending acquisition without HTTP, even when disconnected, while keeping
the active report, posts and revisions. It requires `manage_options`. Combine it with
`--dry-run` to discard pending pages and start a fresh scan; it cannot be combined with
`--yes`, `--resume` or `--choose`. Resetting does not bypass the API's site-wide rate limit.
A successful new run removes earlier report rows and catalogue pages in batches of 50;
a failed new catalogue leaves the active report intact.

The table lists each embed's source, status, matching method, candidates and undo revision.
JSON emits one document with `run`, `complete` and `reports`, streaming post records rather
than building an array of every post. The stored report contains the same evidence and
revision URL. A successful new catalogue replaces the previous report and removes old run options,
but leaves WordPress revisions intact. Posts without embeds create no report rows; scan
errors remain visible. Reports and catalogue pages are local options with autoload disabled;
uninstall removes them with the plugin's existing namespace cleanup.

## Engine boundaries

- `Migrator::start()`, `batch()` and `swap()` require a connection and `manage_options`.
  Each post also needs `edit_post`. CLI without `--user` has no implicit permission.
- `Migration_Scanner` selects published `post` and `page` IDs in ascending batches of 50,
  with a starting upper ID bound and a cursor saved after each post. Deleting an earlier
  post does not shift subsequent results. Custom post types are outside WP-5a's scan scope.
- `Migration_Detectors` returns host, source, one-based embed number, byte offset and
  length, plus available audio URL, provider episode/show IDs and title/date/GUID hints.
  It never executes shortcode, script or oEmbed content. Literal examples, ordinary
  links and block metadata comments are ignored. Single-episode embeds can use the post's
  title/date; multiple external players cannot borrow one title for all their episodes.
  Local SSP references use the referenced published post's metadata and are rechecked at swap.
- Provider episode IDs remain opaque. They are neither show.fm UUIDs nor RSS GUIDs.
  Show/playlist embeds, SSP collections and unsupported PowerPress custom channels remain
  unmatched. There is no fuzzy title matching or HTTP probing of source hosts.
- Matching uses the SHA-256 fingerprint of the normalised source enclosure, then source
  GUID fingerprint or public `rss_guid`, then title with a publication timestamp within
  24 hours in either direction. Stronger ambiguity never falls through to weaker evidence.
  Title/date alone is always ambiguous and requires an explicit choice, even if the embed
  provides a provider show ID. The API has no verified mapping to connected show identities;
  there is no unused auto-approval path.
- Enclosure normalisation follows app provenance contract version 1: retain the scheme,
  path case, dot segments and Unicode; remove credentials, query and fragment; normalise
  authority and percent escape case; strip only the listed tracking prefixes. Query removal
  can merge different sources, so duplicate fingerprints remain ambiguous. GUIDs only lose
  surrounding ECMAScript whitespace. IDN authority matching requires PHP's `intl` extension;
  without it IDNs fail closed rather than produce a different fingerprint.
- One in-memory catalogue index is shared across embeds and batches in a run. Only the
  keyed podcast and episode list endpoints are requested, with at most 50 rows per page.
  No per-episode detail requests or remote enclosure probes are needed.
- Detection uses a bounded token walk, with a 2 MiB post, 16 KiB tag/comment/shortcode and
  10,000-token limit. The scanner counts visited candidate `<` and `[` starts, including
  text comparisons and unmatched brackets. Exceeding the cap reports the 10,000-token limit
  explicitly and accepts no embeds from that post; shorten or split it before rescanning.
  Ordinary text such as `Price is < 5` does not require a closing tag. Malformed markup and
  PCRE failures produce scan errors, never clean reports. Two players inside one core wrapper make the post ambiguous and cannot be swapped.
- Swaps splice only detected byte ranges and use WordPress's block-comment serialiser
  for `showfm/player` UUIDs and snapshots. A complete core HTML/shortcode/oEmbed wrapper
  around a sole player is replaced as one unit. Captioned wrappers retain the original
  figure, figcaption, inline markup and whitespace around the new block byte for byte,
  outside the removed core wrapper. Other content is never reserialised.
- Metadata-only players have zero-length locations at the end of the post, where the block
  is appended. Enclosure/audio metadata stays intact for feeds. Migration markers in the
  block snapshot suppress the corresponding automatic PowerPress/SSP content player only
  while all of that post's current legacy metadata URLs have replacement blocks. Removing
  the block or restoring the revision restores the original plugin's normal behaviour.
- Each existing show.fm block is reported as `already_showfm`. Mixed posts and partial
  migrations still expose every remaining legacy player on the next scan.
- Swaps re-read the row and metadata directly from the database, compare the complete
  evidence (including fields removed from referenced SSP episodes), and atomically update
  only when the original content and relevant post fields still match. An edit made during
  revision creation or at the actual write is refused. Reapplying a successful report is
  idempotent. The engine creates an exact pre-edit revision, even with revision retention
  set to one, by protecting that revision from pruning throughout save notifications.
  The undo row is checked before its link is returned. Disabled revisions refuse swaps. Later retention and restore permissions
  follow core rules; multisite site administrators' restores sanitise legacy scripts.
- Public migration snapshots store only the source fingerprint, never a legacy audio URL
  or its query tokens. Compatibility filters accept unexpected third-party arguments and
  share one parsed marker cache per post/content version, capped at 50 posts per request.
- State changes are serialised by a per-site database session lock. Unsupported lock
  backends refuse migration rather than run concurrently. CLI apply is bound to the run it
  reviewed, so a concurrent new scan cannot silently replace its report.
- No migration job is registered on activation, cron, REST or frontend rendering.
  The legacy compatibility filters only inspect local content and metadata.

WP-5b can call the service and read `Migration_Store::state()`, `get(run, post_id)` or the
bounded `reports(run)` iterator. The future controller must check its nonce and capability,
and use stored reports, never accept candidate arrays or byte offsets from browser input.

## Server contract and remaining gap

I read the keyed API and connected-site architecture on app `origin/main`, then checked
[app PR #747](https://github.com/Minim-Digital/podcaster-plus-app/pull/747) at
`08da1983625ae0683ed43ef0b58d7468daff2064`. Its keyed episode list and detail schemas expose
`source.enclosure_sha256`, `source.guid_sha256` and public-only `rss_guid`. Source values
are nullable lowercase SHA-256 hex strings; raw imported enclosure URLs and GUIDs are
not returned. The migrator consumes these fields directly from
`GET /v1/me/podcasts/{podcast_id}/episodes`; missing provenance fields fail catalogue
acquisition clearly while preserving progress and the previous report. PR #747 must be
deployed before real-account migration can use this contract.

The remaining server gap is independently verifiable **legacy provider show ID or source
feed identity associated with the connected podcast**, ideally on `GET /v1/me/podcasts`.
No such field currently exists. The adapter does not invent one; title/date candidates
require an explicit choice until a documented identity mapping is available.

The exact 64 app-side normalisation vectors are copied into
`tests/fixtures/provenance-vectors.json`, with source attribution in
`tests/fixtures/provenance-README.md`. The parity test checks both normalised values and
fingerprints for every enclosure and GUID vector. Matching fingerprints are not download
URLs, so migration snapshots use the list title and UUIDs without manufacturing media URLs.

## Fixture sources and verification

The fixtures are representative, with synthetic identifiers and example media URLs. They
cover legacy/current iframe URLs, Buzzsprout script/container markup, WordPress oEmbed
blocks and standalone URLs, host shortcodes, PowerPress enclosures and SSP metadata and
blocks. They are offline examples, not a claim of testing customers' live pages.

I checked the shapes against:

- [Buzzsprout embed help](https://www.buzzsprout.com/help/16-placing-embed-code)
  and [its older script player](https://www.buzzsprout.com/help/44-upgrade-to-the-new-large-player).
- [Libsyn's player documentation](https://help.libsynsupport.com/hc/en-us/articles/360040795712-Show-Media-Player).
- [Captivate's embed documentation](https://help.captivate.fm/en/article/manually-embedding-the-captivate-podcast-player-on-your-custom-website-m70pbx/).
- [Transistor's embed documentation](https://support.transistor.fm/en/article/how-do-i-embed-my-podcast-1dquric/).
- [Spotify's embed documentation](https://developer.spotify.com/documentation/embeds).
- [Podbean's player documentation](https://help.podbean.com/support/solutions/folders/25000027343).
- [PowerPress shortcode documentation](https://blubrry.com/support/powerpress-documentation/powerpress-settings/website/shortcode/)
  and [plugin source](https://plugins.svn.wordpress.org/powerpress/trunk/powerpress.php).
- [SSP shortcode documentation](https://support.castos.com/article/407-seriously-simple-podcasting-shortcodes),
  [block source](https://github.com/CastosHQ/Seriously-Simple-Podcasting/blob/master/src/index.js)
  and its PHP shortcode and frontend controllers.
- [WordPress revision behaviour](https://developer.wordpress.org/reference/functions/wp_save_post_revision/)
  and [block comment serialisation](https://developer.wordpress.org/reference/functions/get_comment_delimited_block_content/).

Run `npm run test:php`, `npm run test:php:multisite`, `composer lint` and
`composer analyse`. The PHPUnit suite blocks outbound HTTP and injects documented API
responses; it does not prove live API deployment or real-account acceptance.
