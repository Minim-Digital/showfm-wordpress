=== show.fm ===
Contributors: showfm
Tags: podcast, podcasting, audio, player, episodes
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Podcast player, episode lists and auto-posting for show.fm shows.

== Description ==

show.fm hosts podcasts. This plugin brings a show.fm show into WordPress:

* A podcast player, episode lists, a play button and transcripts, as blocks and a shortcode.
* Publish to WordPress: connect the site to show.fm and each new episode becomes a post.

This version is an early development release. Four server-rendered blocks and the [showfm] shortcode are available. Settings > show.fm connects the site to show.fm (or use WP-CLI), shows the state of the connection, and holds the site-wide display settings. The block picker controls follow in separate work. The pinned embed package (1.1.0) upgrades the player; lists, play buttons and transcripts currently display their readable HTML fallbacks until those elements ship in the package.

The plugin works for any public show.fm show without an account. Publish to WordPress needs a show.fm account and a connected site.

The source code and build instructions are on GitHub: [show.fm for WordPress](https://github.com/Minim-Digital/showfm-wordpress) and the MIT-licensed [show.fm embed package](https://github.com/Minim-Digital/showfm-embed/tree/v1.1.0). The bundled scripts are copied without modification from @showfm/embed 1.1.0.

== External services ==

This plugin relies on show.fm, a podcast hosting service run by show.fm Ltd. It sends nothing until you add a show or episode to a page, or connect the site to show.fm. It does not collect analytics or telemetry.

**show.fm public API (api.show.fm)**

* Used to fetch show and episode details (titles, descriptions, artwork, audio links and transcripts) for the shows and episodes you add to your pages.
* When: when WP-Cron refreshes a missing or stale cache entry (fresh for 15 minutes, with retries after errors). Page rendering reads only cached public data and schedules a background refresh; it makes no inline HTTP request. The enhanced player also requests public episode data directly from the visitor's browser when it loads, or after a click with load="click".
* What is sent: the show or episode identifier, and your site's address in the request's User-Agent header. Your server's IP address is visible to show.fm, as with any web request. Background requests send no visitor data. Browser requests expose the visitor's IP address and browser details to the service. WordPress may also request /v1/oembed to resolve a pasted listen-page URL, then caches the returned iframe in post meta.
* With "Load players only after a visitor clicks" turned on (Settings > show.fm > Display), the visitor's browser requests nothing from show.fm until the visitor presses play.
* While the site is connected, the settings screen shows each connected show's artwork from the same cached public show details (/v1/podcasts/{id}), refreshed in the background. Opening the screen makes no request itself.

**show.fm API for connected sites (api.show.fm)**

* Used only after an administrator connects the site to a show.fm account.
* Connecting (code exchange): once, when the administrator returns from my.show.fm after approving the connection. Sent: the one-time code from my.show.fm and the matching verifier this site created, in the body of a request to api.show.fm/v1/sites/exchange. show.fm answers with the site key and ping secret, which the plugin stores encrypted.
* Connecting with WP-CLI: once, when someone runs `wp showfm connect`. Sent: the site key, your site's address and REST API address, and a one-time state and challenge, to api.show.fm/v1/me/sites.
* Reporting in (verify): right after connecting. If that fails, it is retried before the next sync or health report. Sent: the site key, the plugin, WordPress and PHP versions, and the site's name.
* Health report: once a day while connected. Sent: the site key, the plugin, WordPress and PHP versions, the time of the last sync and the number of sync errors. If show.fm answers that the shows' plan does not include connected sites, the settings screen says auto-posting is paused.
* Account details: right after connecting, and after each daily health report. Sent: the site key, to api.show.fm/v1/me and /v1/me/podcasts. show.fm answers with the account holder's name and the ID, title and address of each show the site key can read. The plugin keeps these to show on the settings screen and deletes them on disconnect and uninstall.
* Sync: a check every 15 minutes (with a staggered start), signed wake-up requests and explicit WP-CLI sync commands pull episode changes in the background. Sent: the site key, the last applied sequence number and page size. Post reports send the episode identifier, post ID, HTTPS post address, publication state and source content hash. Transient report failures are queued and retried; terminal refusals, missing posts and invalid addresses are dropped with a local reason code; no WordPress post text or visitor data is uploaded. A dry run previews one page at the applied cursor (or zero with --from-start), recording contact with show.fm but never acknowledging unapplied changes. It leaves local posts and the saved cursor unchanged.
* After show.fm refuses the site key, the plugin stops authenticated requests until an administrator reconnects. Public embeds continue to work. When show.fm asks it to slow down, it waits as long as show.fm says.

**Requests from show.fm to your site**

* Connection check: while an administrator connects the site, show.fm requests `/wp-json/showfm/v1/challenge` on your site to confirm that the request came from it. Your site answers with the one-time challenge only.
* Wake-up pings: while connected, show.fm sends signed, empty requests to `/wp-json/showfm/v1/ping` when an episode changes. The plugin checks the signature, then starts a sync in the background. A ping carries no data.

**show.fm media (m.cdn.media)**

* Audio and transcript files are served from m.cdn.media.
* Featured images: when enabled in publishing settings, background sync also reads public episode artwork metadata and downloads that image once into the media library, reusing images already imported by this plugin. Scheduled artwork waits until it is public. Downloads use only show.fm media hosts (m.cdn.media, m.showfm.dev, media.podcasterplus.com and media.podcasterplus.dev), with limits of 10 MB, 8000 pixels per side and 16 million pixels. Permanent failures are skipped and temporary failures retry up to three times without delaying other episodes. The image host sees the server IP address; no site key is sent to it.
* When: audio and transcripts load only when a visitor plays an episode or opens a transcript. The visitor's browser loads the file directly, so show.fm sees the visitor's IP address and browser details, as with any audio file on the web.
* Scripts are bundled with the plugin. Recognised show.fm oEmbed iframe responses are rendered as local players too, so they do not load code from embed.cdn.media.

**show.fm account (my.show.fm)**

* Used when an administrator clicks Connect to show.fm. my.show.fm opens in the administrator's browser, where they sign in and approve the connection.
* What is sent, in the address the browser opens: your site's address, its REST API address, the address of the plugin's settings page to return to, a one-time state and challenge that prove the request came from your site, and a partner code if your host set one. No key is ever in that address.

show.fm [Terms of Service](https://show.fm/terms) and [Privacy Policy](https://show.fm/privacy).

== Installation ==

1. Install and activate the plugin from the Plugins screen.
2. Add a show.fm block to a post or page.
3. To post new episodes automatically, go to Settings > show.fm and choose Connect to show.fm.

== Frequently Asked Questions ==

= Do I need a show.fm account? =

Not for the player and episode lists of a public show. Publish to WordPress needs an account.

= Where are the settings? =

Settings > show.fm. The Connection tab connects the site and shows the account, the connected shows, when the key expires and when the site last checked for changes. The Display tab has four site-wide switches: the "Powered by show.fm" credit (off by default), loading players only after a visitor clicks (off), episode structured data for search engines (on) and using your theme's colours and fonts (on).

= What do the admin notices mean? =

The plugin shows at most one notice, only on the Dashboard, Plugins and show.fm screens, and only to administrators. It warns 30 and 7 days before the site key expires, when show.fm stops accepting the key (for example after the account password changes), when the shows' plan pauses auto-posting, and when a publishing setting stops new episodes being posted. Each notice has one button that fixes the problem. Dismissing a notice hides it for you until the next stage.

= Can I connect from the command line? =

Yes. Create a site key in show.fm under Connected sites, then Add a site with WP-CLI, and run `wp showfm connect`. It asks for the key with the input hidden, or reads it from the `SHOWFM_KEY` environment variable, or from standard input with `--key=-` (for example `pass show showfm/site-key | wp showfm connect --key=-`). `--key=<key>` also works, but the key then stays in your shell history and shows in the process list. `wp showfm status` shows the connection and `wp showfm disconnect` removes it. On a multisite network, add `--url=` to pick the site: each site connects separately.

= How is the connection stored? =

The site key and ping secret are encrypted with a key derived from your WordPress salts. Set the salts in `wp-config.php`: without them WordPress keeps a generated salt in the database, so a copy of the database alone would be enough to read the key. Changing the salts means you connect again.

The plugin keeps up to 50 local sync diagnostics containing its own reason codes, feed sequence numbers and timestamps. They contain no remote error text or post content. Identity receipts preserve user trash and deletion decisions, including during a replay. Restoring a post from the bin re-attaches it, queues one report of its restored state and resumes syncing; detected WordPress edits remain protected. A successful user trash or permanent deletion queues one trashed-state report using the saved post ID and address. Transient post-write failures retry up to five times per sequence; exhausted reasons remain in connection sync status. Configuration failures (an unavailable post type or no publishing author) keep the episode pending indefinitely, with back-off up to an hourly retry. Fixing the setting resumes syncing automatically without --from-start. Tools > Site Health and `wp showfm sync status` show these problems. Site Health also identifies invalid feed boundaries as server contract problems; checking it makes no HTTP request. Uninstall removes these plugin records while keeping posts and media.

== Changelog ==

= 0.1.0 =
* Development release: the plugin's foundations (API client, cache and encrypted connection storage). No designed settings screen yet.
* Added cache-only block and shortcode rendering, local embed assets, fallback parity checks, episode bindings, oEmbed and theme mapping. Credit defaults off and public-episode JSON-LD defaults on.
* Connect a site to show.fm from the show.fm menu or with `wp showfm connect`, with a daily health report and signed wake-up pings.
* Added the sync engine: scheduled and ping-triggered pulls, post lifecycle updates, permanent WordPress edit protection, optional featured images, durable post reports, and `wp showfm sync [--dry-run] [--from-start]` plus `wp showfm sync status`. Publishing controls and the post panel follow in a later release.

* Fixed lifecycle-only sync updates, terminal report handling, bounded artwork retries and limits, poison-row recovery, dry-run cursor safety, user deletion protection, fallback locking and local diagnostics.
* Fixed feed cursor boundary validation, completed artwork queue cleanup, recoverable apply errors with a five-attempt budget, local deletion reports and a 16 MP artwork limit.
* Fixed configuration retries so episodes are never skipped for a setting problem, added local Site Health diagnostics, and re-attached restored posts with edit protection and a durable restored-state report.
* Added Settings > show.fm with Connection and Display tabs. The Connection tab shows every connection state (connected, expiring, expired, disconnected by show.fm, paused by the plan, using scheduled checks, unreadable after the salts changed) and the outcome of connecting, with Reconnect and Disconnect. The old show.fm menu address redirects to it.
* Added the Display settings "Load players only after a visitor clicks" and "Use my theme's colours and fonts", next to the existing credit and structured data settings.
* Added admin notices for key expiry at 30 and 7 days, a refused key, a plan pause and a sync configuration problem: at most one at a time, dismissible per user.
* Connecting now stops early when the site's address does not use https, and a return from show.fm without approval says the connection was cancelled.
* Added the account name and connected shows to the settings screen, fetched after connecting and with the daily health report, and updated the suggested privacy policy text.
