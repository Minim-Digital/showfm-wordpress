# Embed migration engine

I added the migration service and WP-CLI command for WP-5a, and the Migrate tab under
Settings > show.fm for WP-5b. Both drive the same engine and report store.

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
- No migration job is registered on activation, cron or frontend rendering. The Migrate
  tab's REST routes run one bounded step per request, only when an administrator asks.
  The legacy compatibility filters only inspect local content and metadata.

## Migrate tab

Settings > show.fm > Migrate is a React tab (`src/admin/migrate-tab.jsx`, its screens in
`migrate-screens.jsx` and its words in `migrate-view.js`). It appears only while a
connection is stored, like Publishing. When the key no longer works it shows the intro
with a Connect or Reconnect button that posts the connect form.

The tab follows the design's six screens (intro, scanning, dry-run report, confirm, results,
nothing to migrate) and adds the states the design leaves out (see Design gaps below). Each
new screen moves focus to its heading. A problem moves focus to its notice. "Show more"
moves focus to the first new row. A pick keeps focus on its select, and Cancel in the
confirm dialog returns focus to the Swap button. When Stop or Resume goes away, focus
returns to the heading. Progress is read out politely (`speak()`) each quarter of the way,
not on every step.

### REST routes

All routes are under `showfm/v1/admin/migrate` and need `manage_options`. In the browser
the cookie check needs the `wp_rest` nonce, which `@wordpress/api-fetch` sends. Input is
validated by the route schema: run and episode UUIDs, a known group, offsets from 0 and
limits from 1 to 100.

| Route                        | What it does                                                                                                                                   |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /admin/migrate`         | The view: connected, hosts, the post count, the lease, the phase and its progress, the report summary, the swap summary and any saved problem. |
| `POST /admin/migrate/scan`   | One scan step: up to 10 catalogue requests (`Migrator::start()` with a limit), or one batch of 50 posts. `restart` starts afresh.              |
| `POST /admin/migrate/stop`   | Pauses the scan and gives the run back, so a reload doesn't carry on by itself.                                                                |
| `GET /admin/migrate/rows`    | A page of rows for one group (`ready`, `choose`, `unmatched`, `already`, `review`) or the results (`changed`, `failed`).                       |
| `POST /admin/migrate/choice` | Stores or clears the pick for one ambiguous embed, and answers with the view and `choice`, the pick as stored.                                 |
| `POST /admin/migrate/swap`   | One swap step: up to five posts through `Migrator::swap()`. `confirm` is needed to start a swap or to carry on another admin's.                |

A step answers with the view. A refused step is an error with `data.reason` and
`data.view`, the fresh view: `not_connected`, `connection_lost` (the key stopped working
during a scan or swap), `busy` (another admin's run, or another request on the site),
`stale` (the run changed in another tab), `rate_limited` and `unreachable` (with
`retryAt`), `reconnected` (the site reconnected with another key after the scan),
`invalid_choice`, `swapped` (choices are fixed once the swap starts), `confirm` (the swap
needs a confirm first), `unreleased` (the run finished but the lease couldn't be given
back) and `forbidden`.

Rows carry the post's title, link and date, the host, a short reference, how it matched,
the status, and for a pick the candidates' IDs, titles and dates. They never carry the key,
byte offsets, provenance fingerprints or legacy audio URLs: the reference is the player
address and episode ID, or the audio file's name, never a whole URL with its query.

### One admin at a time

Each step takes or renews a lease (`showfm_migrate_lease`, two minutes) for the admin who
runs it, under the engine's per-site lock. Each site has its own lease. While it is live,
another admin's steps are a 409 `busy` naming the admin, and their tab follows the progress
every five seconds. The lease is given back when the scan or swap finishes or the admin
stops it; a closed tab lets it lapse. Giving it back waits up to five seconds for the site
lock. If the lock stays busy, the step or Stop says so (`unreleased` or `busy`) rather than
failing quietly.

`wp showfm migrate-embeds` takes the same lease for its run, renews it before each batch
and each swapped post, and gives it back when it finishes or stops with an error. Every
path that changes anything, `--reset` included, refuses while another admin holds it.

A watching tab carries on a scan whose lease lapsed. A swap carries on by itself only for
the admin who confirmed it. Anyone else sees Resume swapping, which asks them to confirm
first; the server also refuses a swap step without `confirm` from anyone but the admin
who confirmed it.

### Reconnecting during a swap

Every swap step checks, under the site lock, that the scan is still current and that the
stored key is still the one the scan used, reading the connection from the database rather
than the request's cache. Each post is checked again the same way just before it is
swapped. A reconnect to another account therefore stops the swap at the next post with
`reconnected`, and a new scan is needed.

One window is accepted: a reconnect that lands while a single post is being swapped. That
post is still swapped to the episode the scan matched under the previous connection. It is
one post at most, it has a revision (the undo, linked from the results), the block names a
real public episode, and the key never enters the post. Closing that window would mean
holding the connection lock across each post's save hooks, which risks breaking the save
or blocking the reconnect for longer than its three-second wait.

### Resume

The scan's cursor is the engine's (`Migration_Store::state()` and the pending catalogue).
The found count and the pause live in `showfm_migrate_progress`. Picks live in
`showfm_migration_{run}_choices` and the swap cursor in `showfm_migration_{run}_swap`, so
a new scan or a discarded run removes them with the reports. A reload reads the view and
carries on a scan the admin didn't pause, or a swap they confirmed. A catalogue failure is
saved with its reason, so the problem shows again after a reload until its retry time. A
rate-limit notice goes at its retry time and the scan carries on.

### Picks

A pick must name one of the stored candidates of that ambiguous embed, in a post whose
report is `scanned`. Anything else is a 400 `invalid_choice`. The check and the write run
under the same per-site lock as the swap's first step, which copies the picks into the swap
cursor. Picks are therefore fixed the moment the swap starts: a later pick is a 409
`swapped`, and the swap only ever uses the copy. The tab saves one pick at a time per row,
shows the pick the server stored, and holds Swap while a pick is saving. The swap passes
the picks to `Migrator::swap()`, which checks them again.

A post the admin can't edit is listed under "Not changed" with the reason, and the swap
goes on with the rest. Only a refusal for the whole run (no connection, no
`manage_options`, another request) stops it.

### WP-CLI table

The design has no frame for the WP-CLI table, so I aligned it with the tab: the HOST,
STATUS and METHOD columns use the report's words ("PowerPress", "Ready", "Choose one",
"Left as is", "Nothing to do", "Check by hand", "Swapped"; "Audio file", "Episode ID",
"Title and date", "None"), and an empty revision shows as `-`. The columns, the candidate
IDs for `--choose` and the JSON output are unchanged.

### Design gaps

The design (Claude Design project d624d975, area 4) covers the six screens at 1280px. I
built these from the same patterns and recorded them for the design pass:

- **390px.** There is no phone frame for Migrate. The tiles go two by two, each report row
  stacks its cells with the column name above the value, and buttons fill the width at
  44px, as in the other 390px frames.
- **Not connected.** The design shows Migrate only while connected. When the stored key no
  longer works, the intro shows a warning and a Connect or Reconnect button.
- **Scanning copy.** The design says "You can leave this page. The scan keeps going." The
  scan runs only while the page is open, one short request at a time, so the tab says it
  carries on from where it stopped when you come back. "Checks 1,280 posts, pages and
  drafts" became "published posts and pages", which is what the scanner reads.
- **Paused and swapping.** Stop scanning shows "Scan paused" with Resume scanning. The swap
  shows its own progress card ("Swapping embeds").
- **Errors.** show.fm unreachable, rate limited, connection lost, another admin running,
  and a run that changed elsewhere show as notices above the screen, with the fix as the
  action.
- **Failures.** Posts the swap couldn't change are listed under "Not changed" with the
  reason, and the results notice counts them.
- **Check by hand.** Posts that couldn't be scanned safely, and players that share one
  block, form a fifth group, shown only when it has rows.
- **Already show.fm rows** say "Already a show.fm block" instead of the episode title,
  which the report doesn't hold.
- **Show more** adds up to 50 rows at a time ("Show 26 more"); the results say "Show all
  29 posts" when the rest fit in one page.

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
