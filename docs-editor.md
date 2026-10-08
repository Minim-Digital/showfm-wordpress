# Block editor UI (WP-2b)

The editor script (`src/`, built to `build/index.js` and `build/index.css`) gives the four
blocks their placeholders, pickers, inspector controls and in-block states, and adds the
"show.fm" panel to the post sidebar. It follows the plugin design, areas 6 (Blocks) and 7
(Post sidebar). The front end is unchanged: blocks still render from the cache (WP-2a).

## Flow

Every block goes through the same steps (`src/components/block-edit.js`):

1. **Show.** Not connected: a "Show address or slug" field. It accepts
   `the-long-table.show.fm`, `https://show.fm/the-long-table`, a slug or a UUID
   (`src/address.js`). Connected, for people who can publish: the account's shows, from
   one cached request, with "Use the address of another public show". If show.fm cannot
   be reached the list says so with Try again (and how long to wait when rate limited); it
   never drops to the address field silently.
2. **Episode** (Player, Play button, Transcript). Search, a season filter, "Latest episode"
   (not for a transcript) and, when the show is the connected account's and the user can
   publish, scheduled episodes. If the keyed list fails, the picker lists the public
   episodes and says that scheduled ones could not be loaded, with Try again. The Episode
   list stops at the show.
3. **Preview.** The real element, inside the editor iframe. `Assets::editor_assets` now
   always enqueues `v1.js` in the editor, so a block in a new post previews too.

Choosing writes the UUIDs and, for a public episode only, the insert-time snapshot: the
title (at most 300 characters), the listen URL and the audio URL. A scheduled episode
stores its UUID and nothing else. Latest episode stores the show's title and listen URL.

`Attributes::snapshot()` applies the same rules on save (`content_save_pre`) and on render:
listen URLs must be https on `show.fm` or a subdomain, audio URLs https on `m.cdn.media` or
`media.podcasterplus.com`. With another environment selected through `showfm_environment`
(see `Environment`), its listen domain and media hosts count too. The editor gets the same
lists as `listenRoots` and `mediaHosts` in `window.showfmEditor`. No credentials or ports.
Anything else is dropped.

A block whose episode has never been confirmed public and has no snapshot title (a
scheduled pick, or a block written by the sync) renders the bare element with no fallback
content: nothing shows, and in the browser the element collapses on the public 404 until
the episode is public. The first view schedules the cache refresh that confirms it.

The Transcript follows a Player or Episode list on the page. Choosing one gives that block
an element `id` (always `showfm-` and up to 57 letters, digits, hyphens or underscores, so it
cannot clash with a page's own ids) if it has no valid unique one, and the transcript keeps `for` and the followed
block's `episode` in step. Without a block to follow it shows one episode's transcript.

## Editor states

| State          | When                                              | What the block shows                                                   |
| -------------- | ------------------------------------------------- | ---------------------------------------------------------------------- |
| Loading        | Any request in flight                             | Spinner with "Loading episode…" or "Loading show…"                     |
| Not found      | 404 for a show, or for an episode of another show | "We couldn't find that show or episode." Choose another, Check address |
| Scheduled      | Publisher on a connected site, keyed scheduled    | "Goes live on {date} at {time}." above a dimmed card                   |
| Not public yet | Public 404, not connected or not a publisher      | "This episode isn't public yet." Connect link for admins               |
| Unpublished    | Connected, keyed status draft                     | "No longer available on show.fm." Open the show on show.fm             |
| Archived       | Connected, keyed status archived                  | "Archived in show.fm."                                                 |
| Deleted        | Connected, keyed 404 for the account's show       | "No longer available on show.fm." without a link                       |
| Not this show  | Keyed episode belongs to another show             | As not found                                                           |
| Paused         | Public 403                                        | "Paused because of the show's plan." Go to billing (connected)         |
| External       | Connected, the show's hosting is external         | "This show is hosted outside show.fm, so it can't be embedded here."   |
| Unreachable    | Network error or 5xx                              | "Couldn't reach show.fm. Showing the last saved copy." plus the SSR    |
| Rate limited   | 429, or the user's request limit                  | "show.fm is busy right now. Showing the last saved copy." plus the SSR |
| No transcript  | Transcript of an episode without one              | "There's no transcript for this episode yet…"                          |

"The last saved copy" is `ServerSideRender` of the block, which renders from the cache or the
snapshot and never fetches.

## REST routes

`Editor_Api` registers four read-only routes under `showfm/v1/editor/`:

| Route                                | Reads                                                                         |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| `shows`                              | Connected publishers: `/v1/me/podcasts` (one request; title and slug)         |
| `show?ref=`                          | `/v1/podcasts/{ref}`; external shows refused from the keyed list              |
| `episodes?podcast=`                  | The account's show: `/v1/me/podcasts/{id}/episodes` (scheduled and published) |
|                                      | Any other show: the public first page, 50 episodes                            |
| `episode?id=` (optional `&podcast=`) | `/v1/episodes/{id}`; on a 404, `/v1/me/episodes/{id}` for the account's show  |

- `permission_callback` is `current_user_can( 'edit_posts' )`. Browser requests use core's
  cookie authentication, so the `wp_rest` nonce (sent by `apiFetch`) is required.
- Keyed data that is not public (the account's show list, scheduled, unpublished and
  archived episodes) goes only to users with `publish_posts`. Contributors get public data
  only: the address field, the public episode list, and "not public yet" for anything else.
- Every answer is a 200 with a `state` and normalised fields only. The site key, ping
  secret and site id never leave the server; tests assert this.
- Answers are cached in transients under the cache namespace and version (so a cache
  version bump, `Cache::flush()`, clears them): 5 minutes for data, 1 minute for 403 and
  404, 15 seconds for errors, so an outage costs one request per path rather than one per
  editor request.
- Each user may send 30 uncached requests a minute (one transient counter per user, expiring
  with the minute); the next answer is `rate_limited` with `retryAfter`.
- A public 429 holds every editor call until Retry-After (`showfm_editor_hold`). A keyed
  429 holds keyed calls through `Api_Client`. A keyed 401 marks the connection for
  reconnecting; the editor then uses public data only and sends no keyed request again.
- Nothing is requested until someone enters a show or opens a block that has one.

`Editor::enqueue` prints `window.showfmEditor` before the script: `connected`,
`privateData` (connected and the user can publish), `reconnect`, `canConnect`, `connectUrl`,
`appUrl` and `api`. Nothing secret.

## Post panel

`Editor::register_fields` adds a read-only `showfm_sync` REST field (edit context, people
who can edit the post) from WP-4a's post meta: `synced` (has `_showfm_site_id`), `state`
(`_showfm_sync_state`), `edited` (`_showfm_edited`) and `syncedAt` (`_showfm_synced_at`,
now written by `Sync_Posts::apply`). The panel shows on posts created by show.fm, posts with
`_showfm_episode_id`, and posts with a block bound to `showfm/episode`. "Episode for this
post" writes `_showfm_episode_id`, which the bindings source reads.

## Tests

- `npm run test:js` runs Vitest (jsdom) on `src/test/`: helpers, pickers, controls, states,
  the block flow, the transcript and the post panel.
- `tests/phpunit/test-editor-api.php` covers capability, nonce, caching, states, 401 and 429
  handling and secrecy; `test-editor-blocks.php` the server changes.
- `tests/e2e/editor.spec.js` runs on `wp-env` with the test plugin
  `tests/e2e/plugins/showfm-e2e-fixtures`, which answers the server's show.fm requests from
  fixtures and sets up a connection and synced posts. The browser's element requests are
  answered with `page.route`.

## Gaps between the design and what ships

- The heading level menu offers H2 to H6: the elements accept `heading-level` 2 to 6, not 1.
- The Player's Mini-player toggle is off by default, as in the package. The corner control
  (Left or Right, Right by default) shows only while the mini-player is on, in the Player,
  Episode list and Play button alike.
- The connected show picker shows each show's title and address, with a plain tile for the
  artwork and no episode count: the keyed show list has neither, and the picker makes one
  request rather than one per show.
- Contributors on a connected site see the same picker without scheduled episodes, and the
  address field rather than the account's shows.
- Accent swatches come from the theme's palette (or WordPress's default one). The show's
  own colour applies whenever no accent is chosen, so it is not a swatch.
- A scheduled episode cannot load in the element (the public API answers 404), so the
  editor shows a dimmed card with its title, show and date under the strip.
- Go to billing opens `my.show.fm/settings`: the app has no separate billing address.
- The post panel starts closed, like every plugin document panel, until someone opens it.
- Added beyond the design: the rate-limited strip and the no-transcript message.
