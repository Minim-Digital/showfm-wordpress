# show.fm for WordPress

The show.fm WordPress plugin: a podcast player, episode lists and Publish to WordPress for
show.fm shows. GPLv2 or later, copyright show.fm Ltd.

This file is for developers. The WordPress.org readme is `readme.txt`.

## Status

Version 0.1.0 is the foundation only: the API client, the cache, encrypted connection
storage and uninstall. Blocks, the shortcode, the connection flow and the admin screens
come in later pull requests.

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
  four characters only.
- `Uninstaller` does the uninstall cleanup, on every site of a multisite network.

### Staging

Point the plugin at the staging API in `wp-config.php`:

```php
define( 'SHOWFM_API_URL', 'https://api.showfm.dev' );
```

Only `https://api.show.fm` and `https://api.showfm.dev` are accepted. Anything else falls
back to production, so the site key is never sent to another host.

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
