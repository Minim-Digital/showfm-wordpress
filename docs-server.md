# Server blocks (WP-2a)

Run `npm ci`, `npm run build` and `composer install` before starting WordPress.
`npm run zip` builds the editor entry point and copies every file from the exact
`@showfm/embed@1.4.0` CDN distribution. `npm run test:assets` compares file lists
and SHA-256 hashes, including locales and future chunks. The package's MIT
licence is in `assets/showfm-embed-LICENSE`. WordPress supplies the editor's React
and block libraries through the generated dependency file.

The four dynamic blocks are `showfm/player`, `showfm/episodes`, `showfm/play` and
`showfm/transcript`. Element attributes use their literal names, including
`heading-level`, `mini-player` and the list's `variant` (never `style`). `episode`
and `podcast` are UUIDs in blocks. The shortcode also accepts a podcast slug.
The editor controls are deliberately deferred to WP-2b; existing attributes can
be previewed through ServerSideRender.

The insertion snapshot is an object with `title`, `listenUrl` and `audioUrl`.
Both snapshot URLs must use HTTPS. Save sanitisation removes other schemes,
and rendering applies the same rule to existing content.
The inserting editor must only populate audio for an already public episode and
must never put scheduled/private titles or URLs into a public snapshot. There
is no snapshot JSON-LD: schema data comes only from the public API cache.
The render callback never fetches. Missing/stale entries schedule WP-1's single
cron event with jitter; a cached 403/404 suppresses the entire element and its
snapshot. A per-episode marker also suppresses that episode in cached lists and
latest-player output.

Examples:

```text
[showfm episode="11111111-2222-4333-8444-555555555555" size="compact"]
[showfm type="episodes" podcast="my-show" variant="minimal" count="5"]
[showfm type="play" episode="11111111-2222-4333-8444-555555555555" variant="label"]
[showfm type="transcript" episode="11111111-2222-4333-8444-555555555555" for="player-one"]
```

`showfm/episode` binds `args.key` values `title`, `description`, `published_date`
and `season_episode`. It resolves `showfm/episode` context first, otherwise
`postId` and `_showfm_episode_id`. Meta writes require the ability to edit the
post and are sanitised to UUIDs. The custom binding source reads only public
cached fields, not the protected meta through core's post-meta binding source.
For heading and paragraph content, the source escapes plain text once. Core
sanitises the result with `wp_kses_post()` and preserves those escaped entities.

The site options are `showfm_show_credit` (boolean, false) and `showfm_json_ld`
(boolean, true). Both are registered with sanitisation but have no admin screen.
A post author cannot enable credit without the site option. `SHOWFM_API_URL`
selects the API for both PHP and browser requests; per-post origins cannot send
visitors to a different API. Theme primary colour and body font map to
`--showfm-accent` and `--showfm-font`; block overrides are inline on the element.
Colour, typography and spacing supports also map to the corresponding
`--showfm-*` variables, with normal WordPress wrapper styles retained.

WordPress core owns oEmbed fetching/caching; this is distinct from cache-only
block/shortcode rendering. Both subdomain and legacy path listen URLs register
against the site's configured API endpoint. The provider validates the paths. Recognised episode/latest iframe responses are
converted to local players when output, so credit preferences, cached fallbacks
and the bundled-script policy apply to oEmbed as well.

## Verification and current package limitations

`npm run fixtures` calls the pinned server renderers on all shared package
fixtures plus hostile inputs in `tests/fixtures/parity-inputs.json`. Commit the
result; CI regenerates and rejects a diff. PHPUnit compares every output byte,
including serialised JSON-LD. Run single-site and multisite PHPUnit plus the
Playwright no-JavaScript fallback test.

The pinned 1.4.0 package registers all four elements (`showfm-player`, `showfm-episodes`,
`showfm-play` and `showfm-transcript`), loading the list, play button and transcript from
`chunks/` next to `v1.js`. The parity fixtures cover the three functions the PHP port has
(`renderEpisodeHTML`, `renderEpisodeListHTML`, `episodeJsonLd`); `renderTranscriptHTML` is
not ported, because the plugin never fetches a transcript in PHP. Public podcast UUID lookup and list filters depend on APP-4
reaching the selected API environment. No production deployment is part of this
change.
