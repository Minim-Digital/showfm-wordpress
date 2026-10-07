# show.fm for WordPress

The show.fm WordPress plugin: a podcast player, episode lists and Publish to WordPress for
show.fm shows. GPLv2 or later, copyright show.fm Ltd.

This file is for developers. The WordPress.org readme is `readme.txt`.

## Status

Version 0.1.0 has the foundation (the API client, the cache, encrypted connection storage
and uninstall) and the plugin side of connecting a site to show.fm: the browser flow, the
code exchange, WP-CLI registration, the ownership challenge, the signed ping endpoint and
the daily health report. The settings page is a placeholder until the designed admin
screens land. Blocks and the shortcode are available, together with the background sync engine.

## Requirements

- WordPress 6.6 or later, PHP 7.4 or later.
- For development: Node 20.10 or later, Docker (for `wp-env`), and Composer (or Docker to run
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
- `Admin` registers the settings page (`admin.php?page=showfm`) and the connect action. Its
  screen is a placeholder for the designed one.
- `Cli` is `wp showfm connect`, `status` and `disconnect`.
- `Privacy` adds the suggested privacy policy text.

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
   expiry, the last sync and the last ping. `wp showfm disconnect [--yes]` removes the local
   credentials and ping nonce claims and unschedules the plugin's events; the key stays live
   in show.fm until it is revoked there.
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
have the same bound and leave posts, reports and the local cursor unchanged, but the
server records feed reads, including their requested `after` cursor. Authentication
and rate-limit protection remain active during a dry run.

A per-database, per-blog MySQL session lock prevents overlapping consumers, including
across PHP workers and object caches. It is released by `finally` or by the database
when a crashed process disconnects. It has no time lease that could expire under a
slow live worker. Hosts must support MySQL/MariaDB named session locks on the same
connection used by `$wpdb`; transaction-pooling database proxies are not supported.

The consumer validates a complete page before applying its rows, then stores its
cursor and pending reports together in the non-autoloaded `showfm_sync` option. The
next GET sends the applied cursor, including an extra read after the final page, to
acknowledge `last_pulled_seq`. A stable post GUID recovers inserts interrupted before
meta was written. `_showfm_content_hash` skips unchanged content, while
`_showfm_synced_revision` hashes the stored title, content and excerpt. A detected
WordPress edit permanently sets `_showfm_edited`; only status and dates then change.
The player is the WP-2a `showfm/player` block. Description/show notes are sanitised,
saved block HTML, rather than a live binding that would bypass edit protection.

The per-blog `showfm_publishing` option accepts `post_type` (default `post`), `author`
(default first site administrator), `categories` (array of IDs) and `featured_image`
(default false). WP-4b will add the Publishing tab and post panel. The default template
is a player followed by show notes, falling back to the plain description. Transcript
insertion and template controls are not exposed by this engine. Public artwork is
sideloaded once with a 10 MB cap, HTTPS, no redirects, safe HTTP validation and image
MIME checks; the attachment GUID deduplicates its source URL. Scheduled artwork is
not exposed by the merged feed, so it waits for the public episode on publication.

Scheduled/published rows become future/published posts; removed or unpublished rows
become drafts; deleted rows go to the bin, including when automatic trash is disabled.
Detached rows retain the post and `_showfm_sync_notice` says “No longer synced from
show.fm”; paused rows retain the post. A restored-access upsert resumes the existing
post and still respects the permanent edit flag. Tombstones without a local post do
not create empty posts or send a report, since the API requires a positive post ID.

The server has a per-episode report endpoint, not a bulk endpoint. Reports are drained
in batches of twenty requests, retained until acknowledged and retried idempotently.
HTTP 400/403/404 reports remain queued but do not block feed reads: access-removed or
plan-paused reports can be refused by the server until access returns. Invalid local
permalinks are also retained without being sent. A valid report URL must use HTTPS,
the home host and a path beneath the home path. A 401 stops keyed requests and asks for
reconnection; 429 honours the full Retry-After; other failures back off to an hour.
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

| Command                      | What it does                                                               |
| ---------------------------- | -------------------------------------------------------------------------- |
| `composer lint`              | PHPCS with WordPress Coding Standards 3 and PHPCompatibilityWP (PHP 7.4+). |
| `composer analyse`           | PHPStan level 6 with the WordPress extension.                              |
| `npm run test:php`           | PHPUnit inside `wp-env` (`tests-cli`).                                     |
| `npm run test:php:multisite` | The same suite as a multisite network.                                     |
| `npm run test:e2e`           | Playwright smoke test against the `wp-env` development site.               |
| `npm run lint:js`            | ESLint through `@wordpress/scripts`.                                       |
| `npm run format`             | Prettier through `@wordpress/scripts`.                                     |
| `npm run zip`                | Builds `dist/showfm/` and `dist/showfm-{version}.zip`.                     |

The PHPUnit suite runs the WP-CLI commands against a stand-in for `WP_CLI`
(`tests/stubs/wp-cli.php`), which PHPStan also reads for the signatures.

Run PHPUnit before Playwright, or on a fresh environment: the core test installer resets
the tables of the `wp-env` tests site (port 8889), so the browser tests use the development
site (port 8888).

`npm run build` is ready for blocks: `@wordpress/scripts` builds `src/` into `build/`.
`bin/build-zip.sh` runs it when `src/` exists.

## Rules

- No HTTP on the render path, on activation or on `init`. Tests hook `pre_http_request` to
  prove it, and the PHPUnit bootstrap blocks every request in the suite.
- No Composer runtime dependencies. Composer and npm are development tooling only.
- Everything is prefixed `showfm_` or namespaced `ShowFM`.
- The zip is built from `.distignore`. `bin/build-zip.sh` fails if tests, dependencies, CI
  files, AI tool directories or Markdown get into it.

## CI

`.github/workflows/ci.yml` runs PHPCS, PHPStan, PHPUnit (single site and multisite) on
`wp-env`, ESLint, builds the zip, runs Plugin Check (Plugin Repo category) against the
built zip, and runs the Playwright smoke test.

`.github/workflows/security-review.yml` runs the Claude security review when a pull request
has the `security-review` label. See the comments in that file and
`scripts/security-review/README.md`.

## Licence

GPLv2 or later. See `LICENSE`.
