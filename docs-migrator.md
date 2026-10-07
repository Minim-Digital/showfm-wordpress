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
wp showfm migrate-embeds --yes --resume --user=admin
wp showfm migrate-embeds --yes --resume --choose=42:1:11111111-2222-4333-8444-555555555555 --user=admin
```

`--dry-run` always prevents swaps, including when combined with `--yes`. Without either
mode the command refuses. `--yes` without `--resume` scans afresh before applying its
matches. `--choose` accepts comma-separated triples, with one-based embed numbers from
the report. Every choice must be a candidate of that exact ambiguous embed. Unmatched
items cannot be chosen. If a batch limit interrupts scanning, no swaps run until the scan
is complete. A failed catalogue fetch must be restarted; only completed catalogue reads
can produce a resumable post scan.

The table lists each embed's source, status, matching method, candidates and undo revision.
JSON emits one document with `run`, `complete` and `reports`, streaming post records rather
than building an array of every post. The stored report contains the same evidence and
revision URL. Starting a new scan replaces the previous report, but leaves WordPress
revisions intact. Reports and catalogue pages are local options with autoload disabled;
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
- Matching uses normalised enclosure URL, then exact GUID, then normalised title with a
  publication timestamp within 24 hours in either direction. An ambiguity at a stronger
  tier never falls through to weaker evidence. Audio path case and query parameters are
  retained; only schemes, hostname case and recognised Podtrac, Chartable and OP3 wrappers
  are normalised.
- Swaps splice only detected byte ranges and use WordPress's block-comment serialiser
  for `showfm/player` UUIDs and snapshots. A complete core HTML/shortcode/oEmbed wrapper
  around a sole player is replaced as one unit. Other content is never reserialised.
- Metadata-only players have zero-length locations at the end of the post, where the block
  is appended. Enclosure/audio metadata stays intact for feeds. Migration markers in the
  block snapshot suppress the corresponding automatic PowerPress/SSP content player only
  while all of that post's current legacy metadata URLs have replacement blocks. Removing
  the block or restoring the revision restores the original plugin's normal behaviour.
- Any existing show.fm block makes the whole post `already_showfm`, including a post
  previously migrated only in part. This follows the requested whole-post skip rule;
  untouched ambiguous/unmatched embeds remain available for manual review.
- A changed post or source metadata refuses the saved report. A changed site key requires
  a fresh scan. The engine creates an exact pre-edit revision and preserves it during
  the save even when the site's revision retention is one. Disabled revisions refuse
  swaps. Later revision retention and restore permissions follow WordPress core rules;
  for example, a multisite site administrator's restore sanitises legacy scripts.
- No migration job is registered on activation, cron, REST or frontend rendering.
  The legacy compatibility filters only inspect local content and metadata.

WP-5b can call the service and read `Migration_Store::state()`, `get(run, post_id)` or the
bounded `reports(run)` iterator. The future controller must check its nonce and capability,
and use stored reports, never accept candidate arrays or byte offsets from browser input.

## Server gap

I checked app `origin/main` at `04f2718fc344f4fdecd9a90a6cfb9b8d5685db33`, including
`docs-internal/src/architecture/connected-sites.md`,
`src/api/routes/developer/{index,content}.ts` and
`src/lib/developer-api/openapi/schemas.ts`.

Site keys have `podcasts:read` and `episodes:read`, limited to the key's accessible shows.
`GET /v1/me/podcasts/{podcast_id}/episodes` supplies `id`, `podcast_id`, `title` and
`published_at`, but no audio URL or GUID. `GET /v1/me/episodes/{episode_id}` additionally
supplies the current `media.audio.url` for public episodes. Both use the documented keyed
API, and list pagination uses `pagination.next_cursor`, without a `has_more` flag.

Neither endpoint exposes the original imported RSS enclosure URL or episode RSS GUID.
The app-side addition needed is **the original imported enclosure/audio URL and RSS GUID
on `GET /v1/me/podcasts/{podcast_id}/episodes`**, with documented fields in
`KeyedEpisodeListItem` and, for parity, `KeyedEpisode` on
`GET /v1/me/episodes/{episode_id}`. The coordinator should choose the actual wire field
names and map them to the import provenance, rather than assuming a database original
upload URL is the old RSS enclosure.

This engine reads only the existing fields. It fetches detail to obtain the current audio
URL and can match that URL or title/date. Its pure matcher supports a supplied `guid`, but
the API adapter does not manufacture one. Migrated audio whose URL changed and embeds
without enough title/date evidence remain unmatched until the server contract is extended.
Fetching detail for every published episode also costs one request per episode; adding
these fields to the list will remove that overhead.

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
