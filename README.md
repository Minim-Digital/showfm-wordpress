# show.fm for WordPress

The show.fm WordPress plugin: a podcast player, episode lists and Publish to WordPress for
show.fm shows. GPLv2 or later, copyright show.fm Ltd.

This file is for developers. The WordPress.org readme is `readme.txt`.

## Status

Version 0.1.0 has the foundation (the API client, the cache, encrypted connection storage
and uninstall) and the plugin side of connecting a site to show.fm: the browser flow, the
code exchange, WP-CLI registration, the ownership challenge, the signed ping endpoint and
the daily health report. Settings > show.fm has the Connection and Display tabs and the
admin notices. Blocks and the shortcode are available, together with the background sync engine.
The blocks have their editor UI (pickers, inspector controls, in-block states and the post
panel); see [docs-editor.md](docs-editor.md).

## Embed migration

The engine, the Settings > show.fm > Migrate tab, its REST routes and the
`wp showfm migrate-embeds` command are documented in [docs-migrator.md](docs-migrator.md),
including fixtures, undo behaviour and the current server contract gaps.

## Requirements

- WordPress 6.6 or later, PHP 7.4 or later.
- For development: Node 22.12 or later on the 22 line, 24, or 26 and later (what Vitest 5 needs; `.nvmrc` and CI use the latest 22), Docker (for `wp-env`), and Composer (or Docker to run
  it).

## Layout

| Path               | What it is                                                                                            |
| ------------------ | ----------------------------------------------------------------------------------------------------- |
| `showfm.php`       | Plugin header, constants and bootstrap.                                                               |
| `uninstall.php`    | Removes the plugin's options, transients, cron events and `_showfm_*` post meta. Never deletes posts. |
| `includes/`        | One small class per job, in the `ShowFM` namespace, autoloaded from `class-*.php`.                    |
| `tests/phpunit/`   | PHPUnit tests, run inside `wp-env`.                                                                   |
| `tests/e2e/`       | Playwright tests, run against `wp-env`.                                                               |
| `bin/build-zip.sh` | Builds the distribution zip in `dist/`.                                                               |

### Classes

- `Api_Client` calls `https://api.show.fm` with `wp_safe_remote_get` and `wp_safe_remote_post`
  only: 5-second timeout, no redirects, `User-Agent: showfm-wordpress/{version}; +{home_url}`
  and `If-None-Match`. It returns an `Api_Result` typed as success, not modified,
  unavailable (403 or 404), unauthorised (401), rate limited (429 with Retry-After),
  transient failure (network error or 5xx) or failed (anything else). A 401 marks the
  connection "reconnect needed" and stops every keyed call until the admin reconnects. The
  key is sent only as a Bearer header and never logged, echoed or returned.
- `Cache` keeps public API responses in transients. Fresh for 15 minutes, served stale for
  up to 7 days, refreshed only by WP-Cron single events with 0 to 120 seconds of jitter.
  Reads never make HTTP calls. A 403 or 404 stores an "unavailable" marker and callers
  render nothing. Network errors, 429 and 5xx keep the last good copy and back off,
  doubling up to an hour. `flush()` bumps a version in the key prefix and never calls
  `wp_cache_flush()`.
- `Connection` stores the site key, ping secret, site id and expiry encrypted with libsodium
  secretbox, keyed from `wp_salt( 'auth' )`, in options with autoload off. If the salts
  change, the state becomes "reconnect needed" without errors. `masked_key()` shows the last
  four characters only. Set the salts in `wp-config.php`: without them WordPress keeps a
  generated salt in the same database, and a database dump alone would then be enough.
- `Uninstaller` does the uninstall cleanup, on every site of a multisite network.
- `Connect` runs the connect protocol (below). Its outcomes are typed (`Connect::ERROR_*`)
  and kept per user for the settings screen, with `Connect::message()` for the text.
- `Challenge_Endpoint` and `Ping_Endpoint` are the two REST routes show.fm calls.
- `Health` sends the daily health report and finishes a verify that failed at connect time.
- `Admin` registers Settings > show.fm (`options-general.php?page=showfm`), its React app
  (`src/admin.jsx`, built to `build/admin.js` with `@wordpress/components` from core) and the
  connect action. `admin.php?page=showfm`, which the show.fm app links to, redirects there with
  the connect return kept. The app's data is preloaded into the page.
- `Admin_Endpoint` is `showfm/v1/admin/connection` (GET), `admin/connection/dismiss-result`,
  `admin/disconnect` and `admin/notices/dismiss` (all POST), for `manage_options` with the
  `wp_rest` nonce. `Admin_Status` builds the Connection tab's data from local state only: no
  request, no key (the last four characters only), no ping secret. Reading it changes nothing:
  the connect outcome stays for 15 minutes (`Connect::RESULT_TTL`) until the admin dismisses it
  or starts again, so a reload or a second tab still shows it. The live state wins. Every state has its own random id
  (`wp_generate_uuid4()`), stored as `i` in `showfm_connection`: a connect or reconnect writes
  a new id with the encrypted credentials in one write, a disconnect replaces both with a new
  id alone in one write, and a failed connect that leaves no connection starts a new
  disconnected state with a conditional UPDATE (or an INSERT on a new install) that never
  overwrites credentials another request has just saved. An outcome records the state id it
  belongs to and shows only while that id is still the stored one, compared for equality
  only, so it never comes back after any later connect or disconnect, from another tab,
  another admin or WP-CLI. "Connected" also shows only while the stored key works, and
  Disconnect clears the admin's outcome. Each flow reads the connection once
  (`Connection::pinned()`): the browser flow keeps that snapshot (`{credentials, id}`, never the
  ciphertext) in its transient from the start to the exchange, every outcome it records is bound
  to it, and the settings view, its notice and the Dashboard notice answer from one read. Every change to the
  connection state runs through `Connection::mutate()`: one per-site lock (the sync's lease on
  its own row, `showfm_connection_lock`, with a 30-second TTL and a MySQL named lock where
  available), a fresh read inside it, and the decision made on that read. That covers saving
  (with the verify marker and scheduled jobs), disconnect with its whole teardown, the "needs
  reconnecting" flag, the plan pause, verify results, account details and connect results. Inside a
  change, a write guard on WordPress's `add_option`, `update_option` and `delete_option` actions
  renews the lease and checks by compare-and-swap that the change still owns it before every
  option write, including each cron and transient write; `guarded()` does the same before a
  step that writes another way. A change that outlived its lease stops before its next write
  (`Connection_Lost`). A change that waits more than three seconds changes nothing and reports
  busy. Account details are stored with the state id they were fetched for, only when the site
  id and both answers come from that one pinned state and it is still stored, and they show
  only while it is the current state: after a reconnect, even with the same site id, the
  account and shows stay hidden until a fetch for the new state succeeds. The REST
  disconnect needs the `stateId` the screen showed (400 without it, 409 if the connection
  changed, 503 if busy). Reading the state never writes. Keyed API results carry the state id
  of the key they sent. The
  counter of earlier development builds
  (`showfm_connection_generation`) is deleted on `admin_init` and on uninstall.
- Disconnect (the REST route and `wp showfm disconnect`) first asks show.fm to revoke the site
  key with `Connect::revoke()`: one `POST /v1/me/sites/{id}/disconnect` with `{}`, the pinned
  key and a 3-second timeout, made before the connection lock is taken and never retried. Then
  it removes the local connection through `Connection::mutate()` whatever the answer. The
  outcome is `revoked` (200), `refused` (a 401, or a key show.fm had already refused or that
  has expired: nothing is sent then) or `not_revoked` (no answer, a server error, a rate limit,
  or a key that can't be read after the salts changed). The answer's `disconnected` carries the
  outcome, its message and the first show's Connected sites page
  (`{app}/p/{slug}/settings/sites`), which the notice links to when the key must be revoked
  there. A 401 here never flags the stored state, since it is removed next. If the revoke
  succeeded but the local clear finds the lock busy or lost (503, `data.revoke`), the message
  says the key was revoked and that Disconnect again finishes: the retry's revoke gets a 401,
  which counts as nothing left to revoke, and the clear goes through. The teardown itself is
  resumable: `Connect::disconnect()` writes a marker (`showfm_disconnect_teardown`, with the
  revoke outcome) before the swap and removes it after the last step. If the lease is lost
  after the swap, the site is disconnected and the message says the clean-up finishes on the
  next admin page or Disconnect. `Connect::finish_teardown()` runs on `admin_init`, at the start
  of every sync, first in the REST disconnect (without needing the old state id) and in
  `wp showfm disconnect` (which does not stop at "not connected" while it is pending). It runs
  the idempotent teardown only while no credentials are stored, so a connection saved since
  keeps its jobs. Afterwards focus
  moves to the Connect card's heading and one polite message is spoken.
- `Publishing` holds the Publishing tab's settings (`showfm_publishing`, autoload off): auto-post
  (on), post type (`post`), category, author (0 means the first user who can publish the type),
  theme template, transcript (on) and featured image (on). It checks them: the post type must be
  public, in the REST API and support the editor; the author must exist, belong to the site and
  be able to publish that type; the category must exist (and is dropped for a type without
  categories); the template must be one the theme offers for the type. The sync reads them only
  when it creates a post. A post keeps its type, author, category and template, and the
  transcript and featured image choices are stored on it (`_showfm_post_options`), so changing a
  setting never rewrites an existing post. The template is set as `_wp_page_template` meta after
  checking it, never as `page_template`, which would fail the insert after the row exists.
- `Publishing_Endpoint` is `showfm/v1/admin/publishing` (GET and POST), for `manage_options`
  with the `wp_rest` nonce. The GET answers with the settings, the choices for each field, the
  connected shows, a fixable sync problem (`row_post_type` or `row_author`) and the recent
  activity. The POST type-checks and sanitises the fields, then `Publishing::validate()` refuses
  a bad one with a 400 naming it (`data.field`). When the sync was held on the post type or
  author, saving records a retry request (`Sync::RETRY_OPTION`, a counter) and queues a pull
  (`Sync::retry_now()`). The request is durable: a pull already running on the old settings
  persists no back-off once it sees it, and the next pull drops any back-off.
- `Sync_Activity` keeps the last 20 things the sync did (`showfm_sync_activity`, autoload off):
  posted, scheduled, updated (title, description or date), updated after an edit here (date and
  status only), moved to draft, moved to the bin, no longer synced, paused by the plan, and not
  posted because auto-posting is off (recorded once per episode until another event for it,
  tracked in `showfm_sync_skipped`, a map of up to 1,000 episode ids kept apart from the 20
  events, so an entry pushed out of them is not recorded again).
  It stores plugin event codes, the episode title, the show
  and post ids, never remote error text. Re-applying a row that changes nothing records nothing.
- `Notices` shows at most one admin notice on the Dashboard and Plugins screens (the settings
  screen shows it on every tab except Connection): refused key, plan pause, sync configuration
  problem, expiry within 7 days, within 30 days. Reconnect is a form that POSTs to
  `admin-post.php` with a nonce; the other actions are links. Dismissals are per user and per
  instance key (user meta `showfm_dismissed_notices`), so the next stage shows again.
- `Account` keeps the account holder's name and the connected shows from `GET /v1/me` and
  `GET /v1/me/podcasts`, fetched after connecting and after each daily health report.
- `Embed_Settings` registers the four Display settings (`show_in_rest`), saved through
  `/wp/v2/settings`.

The plugin infers what show.fm does not expose: `Connection` records when show.fm first refused
the key (`showfm_connection_refused_at`) and when a verify or health report got
`403 plan_upgrade_required` (`showfm_plan_paused_at`); `Ping_Endpoint::note_change()` records a
change that arrived through the 15-minute check with no ping after a 10-minute grace
(`showfm_ping_missed_at`), which the next accepted ping clears. A return from show.fm carries
`showfm_return={token}`, a random token kept with the flow. With the flow's own token and no
`code` or `state`, it means the admin cancelled; any other value changes nothing.

- `Cli` is `wp showfm connect`, `status` and `disconnect` (which revokes the key first, as above).
- `Privacy` adds the suggested privacy policy text.
- `Editor_Api` is the block editor's read-only REST proxy (`showfm/v1/editor/*`), and
  `Editor` prints the editor's settings and the post panel's `showfm_sync` field. See
  [docs-editor.md](docs-editor.md).

### Connecting a site

Plan sections 5.2.2, 5.2.6 and 5.3.5 (show.fm issue #731). Every admin action needs
`manage_options` and a nonce. No key, code or verifier is logged, echoed or put in a URL.

1. **Connect** (`admin-post.php?action=showfm_connect`). The plugin makes a `state` (32
   random bytes, base64url) and a PKCE `code_verifier`, keeps both for 10 minutes in a
   per-user transient, and redirects to `{SHOWFM_APP_URL}/connect/wordpress` with
   `site_url` (`home_url()`), `rest_root` (`rest_url()`), `state`, `code_challenge` (S256),
   `return` (the settings page) and `partner` (if `SHOWFM_PARTNER` is set). No outbound HTTP.
2. **Challenge.** show.fm fetches `GET /wp-json/showfm/v1/challenge?state=…`. The route is
   public, answers `{"code_challenge": "…"}` for that exact state only (the transient is
   named by the state's SHA-256 and the stored hash is compared with `hash_equals`), 404
   for anything else and never cacheable. While no connection has been started in the last
   10 minutes (`showfm_challenge_open_until`), every request gets a 404 with no lookup or
   write. While one has, the right state is always answered, and wrong states share one
   global budget of 60 a minute: one options row per minute, counted with one atomic
   conditional UPDATE, with or without an object cache. Only rows of older minutes are
   removed. The caller's address plays no part and rotating addresses add no rows.
3. **Return.** On the settings page load the plugin checks `state` against the user's flow
   (single use), keeps the code server-side and redirects to the clean URL at once.
4. **Exchange.** On the clean load it POSTs `{code, code_verifier}` to
   `https://api.show.fm/v1/sites/exchange`, stores `site_id`, `api_key`, `ping_secret` and
   `expires_at` with `Connection` (encrypted), then POSTs `/v1/me/sites/{id}/verify` with the
   plugin, WordPress and PHP versions and the site name. A reconnect keeps the old
   credentials until the new ones are stored.
5. **WP-CLI** (`wp showfm connect`). The key comes from `--key=-` (standard input), then
   `--key=<key>`, then the `SHOWFM_KEY` environment variable, then a hidden prompt when
   standard input is a terminal. Prefer `SHOWFM_KEY` or `--key=-`: a key typed as
   `--key=<key>` stays in shell history and shows in `ps`, and the command warns about it.
   The plugin makes a state and challenge and
   POSTs `/v1/me/sites` with `site_url`, `rest_root`, `state` and `code_challenge`; show.fm
   fetches the challenge back inside that request, then returns the site id and ping
   secret. Then it verifies. `wp showfm status` shows the state, the masked key, the
   expiry, the last sync and the last ping. `wp showfm disconnect [--yes]` asks show.fm to revoke
   the key (best effort), then removes the local credentials and ping nonce claims and
   unschedules the plugin's events. When the key could not be revoked, it warns with the
   Connected sites link.
6. **Ping** (`POST /wp-json/showfm/v1/ping`). The permission callback checks
   `X-Showfm-Signature: v1={hex HMAC-SHA256(ping_secret, "v1.{site_id}.{timestamp}.{nonce}")}`
   with `X-Showfm-Site`, `X-Showfm-Timestamp` (within 300 seconds) and `X-Showfm-Nonce`
   (each accepted once in 10 minutes). A nonce is claimed with one `INSERT IGNORE` into
   the options table, so two copies of a ping arriving together cannot both pass; expired
   claims are removed on the next claim. The body is never read. The handler queues one
   `showfm_pull` event, calls `spawn_cron()` and answers 202.
7. **Health.** The daily `showfm_health` event POSTs `/v1/me/sites/{id}/health` with the
   versions, `last_sync_at` and `sync_error_count`. A 401 from any keyed call marks the
   connection "reconnect needed" and stops keyed calls. A 429 holds every keyed call until
   its Retry-After has passed (`showfm_rate_limited_until`).

A WordPress install in a subdirectory sends a `site_url` with a path. show.fm accepts that
once podcaster-plus-app PR #741 is merged.

### Publishing engine

`wp showfm sync [--dry-run] [--from-start]` runs the same consumer as `showfm_pull`
and the jittered 15-minute `showfm_poll`. `wp showfm sync status` reads local state only.
A run handles at most ten pages of twenty rows, then queues a continuation. Dry runs
preview one page only and leave posts, reports and the cursor unchanged. The server
records contact and the requested `after` cursor, so a dry run never requests the next
page at a cursor it has not applied. `dry_run_limit` means more rows remain beyond the
preview. Authentication and rate-limit protection remain active during a dry run.

A per-blog options lease uses a unique row and conditional updates to prevent
concurrent claims. It renews before work and expires after five minutes if a worker
crashes. A MySQL session lock adds immediate crash recovery when supported. Unsupported
GET_LOCK or session ownership falls back to the lease; hosts with multiplexed database
connections can disable named locks through `showfm_sync_use_named_lock`. A contending
pull queues another attempt after 15 seconds. A stale worker cannot release a new lease.

The consumer validates the page envelope and requires `cursor.next` to match the
rows' highest usable sequence, then handles rows individually. Unknown or
malformed rows and validation refusals are skipped with a plugin-owned error code and
sequence number. Configuration errors (unavailable post types or publishing authors)
and transient write failures stop at the failing row, save only the successful prefix
and back off. After five failed attempts at one sequence, the row is skipped with its
reason retained in `showfm_connection_sync_status`. Its active retry and latest 50
exhausted rows remain available to CLI status and the later connection screen.
After handling the page it stores the cursor and pending reports
together in the non-autoloaded `showfm_sync` option. The next GET acknowledges
`last_pulled_seq`, including an extra read after the final page. Indexed identity
receipts and a bounded primary-key lookup recover interrupted inserts; existing posts
migrate through their episode meta. `_showfm_content_hash`, lifecycle fields and
pending artwork together determine whether a row needs work. `_showfm_synced_revision`
hashes the stored title, content and excerpt. A WordPress edit permanently sets
`_showfm_edited`; content is then protected. User trash or permanent deletion records
a durable local detachment and is never undone by an upsert or replay. Restoring a
post from the bin keeps that detachment; automatic reattachment is not supported.
The player is the WP-2a `showfm/player` block. Description/show notes are sanitised,
saved block HTML, rather than a live binding that would bypass edit protection.

The per-blog `showfm_publishing` option is the Publishing tab's (see `Publishing` above):
`auto_post`, `post_type`, `category`, `author`, `template`, `transcript` and
`featured_image`. With auto-posting off, an episode without a post is skipped (and shows as
"Not posted" in the recent activity); existing posts keep updating. A new post is a player,
then the `showfm/transcript` block when the transcript setting is on, then show notes,
falling back to the plain description. Public artwork is
sideloaded once from the exact show.fm media hosts `m.cdn.media`, `m.showfm.dev`,
`media.podcasterplus.com` or `media.podcasterplus.dev`. Downloads require HTTPS, no
credentials, explicit ports or redirects, and safe HTTP validation. Limits are 10 MB,
8000 pixels per side and 16 million pixels, checked before image processing; only
JPEG, PNG, WebP and GIF are accepted. Indexed attachment receipts deduplicate source
URLs. Permanent failures are recorded per post and skipped; network errors, 429,
5xx and temporarily missing public metadata retry up to three times independently
of the feed cursor. Scheduled artwork is
not exposed by the merged feed, so it waits for the public episode on publication.

Scheduled/published rows become future/published posts; removed or unpublished rows
become drafts; deleted rows go to the bin, including when automatic trash is disabled.
Detached rows retain the post and `_showfm_sync_notice` says “No longer synced from
show.fm”; paused rows retain the post. A restored-access upsert resumes the existing
post and still respects the permanent edit flag. Tombstones without a local post do
not create empty posts or send a report, since the API requires a positive post ID.

The server has a per-episode report endpoint, not a bulk endpoint. Reports are drained
in batches of twenty requests. Network and server failures retry idempotently with
separate backoff, without holding up feed reads or pings. HTTP 400/403/404 responses,
missing posts and invalid local permalinks drop that report with a local reason code.
Detached and paused source rows do not send reports. A successful user trash or
permanent deletion queues one `trashed` report, the terminal state accepted by the
server, using its saved post ID and URL. These separate per-episode outbox entries
survive removal of the post and cannot be overwritten by a concurrent feed save. A valid report URL must use HTTPS,
the home host and a path beneath the home path. A 401 stops keyed requests and asks for
reconnection; 429 honours Retry-After up to a one-day cap. Feed failures and report
retries back off to an hour. `wp showfm sync status` shows pending report/artwork counts,
the active apply retry, exhausted rows and the latest 50 local diagnostic entries (own codes, sequence numbers and timestamps,
never remote error text or post content).
A new connection ID starts its own cursor and outbox without claiming the old
connection's posts. Uninstall removes plugin receipts, never posts or media files.

Contract reviewed against podcaster-plus-app `04f2718fc344f4fdecd9a90a6cfb9b8d5685db33`:
`connected-sites.md`, the keyed site routes and the generated OpenAPI schemas.

### Staging

Point the plugin at the staging API in `wp-config.php`:

```php
define( 'SHOWFM_API_URL', 'https://api.showfm.dev' );
```

Only `https://api.show.fm` and `https://api.showfm.dev` are accepted. Anything else falls
back to production, so the site key is never sent to another host.

The connect flow opens the app at `SHOWFM_APP_URL`, which accepts `https://my.show.fm`
(the default) and `https://my.showfm.dev` only. A host that resells show.fm can set its
partner code, which is passed to show.fm for attribution:

```php
define( 'SHOWFM_APP_URL', 'https://my.showfm.dev' );
define( 'SHOWFM_PARTNER', 'your-partner-code' );
```

## Development

```sh
NODE_ENV=development npm install
composer install            # or: docker run --rm -v "$PWD":/app -w /app composer:2 composer install
npm run env:start           # WordPress on http://localhost:8888 (user admin, password password)
```

| Command                      | What it does                                                                              |
| ---------------------------- | ----------------------------------------------------------------------------------------- |
| `composer lint`              | PHPCS with WordPress Coding Standards 3 and PHPCompatibilityWP (PHP 7.4+).                |
| `composer analyse`           | PHPStan level 6 with the WordPress extension.                                             |
| `npm run test:php`           | PHPUnit inside `wp-env` (`tests-cli`).                                                    |
| `npm run test:php:multisite` | The same suite as a multisite network.                                                    |
| `npm run test:e2e`           | Playwright tests against the `wp-env` development site.                                   |
| `npm run test:js`            | Vitest (jsdom) unit tests: the editor in `src/test/`, the settings screen in `tests/js/`. |
| `npm run lint:js`            | ESLint through `@wordpress/scripts`.                                                      |
| `npm run i18n:pot`           | Builds, then regenerates `languages/showfm.pot` with WP-CLI in `wp-env`.                  |
| `npm run format`             | Prettier through `@wordpress/scripts`.                                                    |
| `npm run zip`                | Builds `dist/showfm/` and `dist/showfm-{version}.zip`.                                    |

The PHPUnit suite runs the WP-CLI commands against a stand-in for `WP_CLI`
(`tests/stubs/wp-cli.php`), which PHPStan also reads for the signatures.

Run PHPUnit before Playwright, or on a fresh environment: the core test installer resets
the tables of the `wp-env` tests site (port 8889), so the browser tests use the development
site (port 8888).

`npm run build` builds the block editor script, the settings app and the notice script from
`src/` into `build/`. `bin/build-zip.sh` runs it when `src/` exists.

The Playwright settings tests set up each connection state with a test-only plugin,
`tests/e2e/plugin/showfm-e2e-states.php`, which `wp-env` maps into the site and the tests
activate. It stores a local connection without contacting show.fm, and never ships.

The editor tests use a second test-only plugin, `tests/e2e/plugins/showfm-e2e-fixtures`,
which answers the server's show.fm requests from fixtures and sets up a connection and
synced posts. It never ships either.

## Rules

- No HTTP on the render path, on activation or on `init`. Tests hook `pre_http_request` to
  prove it, and the PHPUnit bootstrap blocks every request in the suite.
- No Composer runtime dependencies. Composer and npm are development tooling only.
- Everything is prefixed `showfm_` or namespaced `ShowFM`.
- The zip is built from `.distignore`. `bin/build-zip.sh` fails if tests, dependencies, CI
  files, AI tool directories or Markdown get into it.

## CI

`.github/workflows/ci.yml` runs PHPCS, PHPStan, PHPUnit (single site and multisite) on
`wp-env`, ESLint, the Vitest unit tests, builds the zip, runs Plugin Check (Plugin Repo category) against the
built zip, and runs the Playwright tests.

`.github/workflows/security-review.yml` runs the Claude security review when a pull request
has the `security-review` label. See the comments in that file and
`scripts/security-review/README.md`.

## Licence

GPLv2 or later. See `LICENSE`.
