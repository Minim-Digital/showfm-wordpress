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

This version is an early development release. Four blocks (Player, Episode list, Play button and Transcript) and the [showfm] shortcode are available. Settings > show.fm connects the site to show.fm (or use WP-CLI), shows the state of the connection, and holds the site-wide display settings. In the block editor you add a show by its address, or pick one of your shows once the site is connected, then choose an episode or "Latest episode", and the block previews the real player. Posts created by show.fm have a "show.fm" panel in the post sidebar.

The plugin works for any public show.fm show without an account. Publish to WordPress needs a show.fm account and a connected site.

The source code and build instructions are on GitHub: [show.fm for WordPress](https://github.com/Minim-Digital/showfm-wordpress) and the MIT-licensed [show.fm embed package](https://github.com/Minim-Digital/showfm-embed/tree/v1.4.0). The bundled scripts are copied without modification from @showfm/embed 1.4.0. The block editor script is built from `src/` in the plugin's repository with @wordpress/scripts.

== External services ==

This plugin relies on show.fm, a podcast hosting service run by show.fm Ltd. It sends nothing until you add a show or episode to a page, or connect the site to show.fm. It does not collect analytics or telemetry.

**show.fm public API (api.show.fm)**

* Used to fetch show and episode details (titles, descriptions, artwork, audio links and transcripts) for the shows and episodes you add to your pages.
* When: when WP-Cron refreshes a missing or stale cache entry (fresh for 15 minutes, with retries after errors). Page rendering reads only cached public data and schedules a background refresh; it makes no inline HTTP request. The enhanced player also requests public episode data directly from the visitor's browser when it loads, or after a click with load="click".
* In the block editor: when someone who can edit posts enters a show's address or opens a show.fm block, the site looks the show and its episodes up (cached for five minutes), and the block's preview loads the player in the editor's browser like it does for visitors. Sent: the show or episode identifier, and your site's address in the User-Agent header.
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
* Disconnecting: when an administrator disconnects in Settings > show.fm or runs `wp showfm disconnect`. Sent: the site key, in one request to api.show.fm/v1/me/sites/{site id}/disconnect with an empty body, asking show.fm to revoke the key. The plugin waits at most 3 seconds and removes the key from the site whatever the answer; if show.fm can't be reached, it says so and links to Connected sites in show.fm, where the key can be revoked by hand.
* Block editor: while connected, when someone who can publish posts adds a show.fm block, the site lists the account's shows and their episodes, including scheduled ones, so they can be chosen before they go live. Sent: the site key in the authentication header, and show and episode IDs. Answers are cached for five minutes and only ever reach the browser as titles, dates and links, never the key. Contributors see public shows and episodes only.
* Embed migration: when an administrator explicitly runs a migration scan, the plugin lists the connected key's accessible shows and published episodes, including import matching fingerprints. No per-episode detail requests are made. Sent: the site key in the authentication header, pagination cursors, show and episode IDs, and the site address in the User-Agent. Post content and third-party embed URLs stay on the WordPress site.
* After show.fm refuses the site key, the plugin stops authenticated requests until an administrator reconnects. Public embeds continue to work. When show.fm asks it to slow down, it waits as long as show.fm says.

**Requests from show.fm to your site**

* Connection check: while an administrator connects the site, show.fm requests `/wp-json/showfm/v1/challenge` on your site to confirm that the request came from it. Your site answers with the one-time challenge only.
* Wake-up pings: while connected, show.fm sends signed, empty requests to `/wp-json/showfm/v1/ping` when an episode changes. The plugin checks the signature, then starts a sync in the background. A ping carries no data.

**show.fm media (m.cdn.media)**

* Audio and transcript files are served from m.cdn.media.
* Featured images: when "Use the episode artwork as the featured image" is on (Settings > show.fm > Publishing, on by default), background sync also reads public episode artwork metadata and downloads that image once into the media library, reusing images already imported by this plugin. Scheduled artwork waits until it is public. Downloads use only show.fm media hosts (m.cdn.media, m.showfm.dev, media.podcasterplus.com and media.podcasterplus.dev), with limits of 10 MB, 8000 pixels per side and 16 million pixels. Permanent failures are skipped and temporary failures retry up to three times without delaying other episodes. The image host sees the server IP address; no site key is sent to it.
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

= How do I add an episode to a post? =

Add a show.fm Player, Episode list, Play button or Transcript block. Type the show's address (such as the-long-table.show.fm) or its slug, then choose an episode or "Latest episode". A connected site lists your shows instead, including scheduled episodes, which the editor marks with the date they go live. Visitors see nothing for an episode until it is public.

= Who can see scheduled episodes in the editor? =

On a connected site, only people who can publish posts (Authors, Editors and Administrators) see the account's shows and its scheduled episodes. Contributors can add public shows and episodes by address, and a block with a scheduled episode tells them it isn't public yet. A scheduled episode's title is never saved in the post, and visitors see nothing until the episode is public.

= Where are the settings? =

Settings > show.fm. The Connection tab connects the site and shows the account, the connected shows, when the key expires and when the site last checked for changes. The Display tab has four site-wide switches: the "Powered by show.fm" credit (off by default), loading players only after a visitor clicks (off), episode structured data for search engines (on) and using your theme's colours and fonts (on).

= What do the admin notices mean? =

The plugin shows at most one notice, only on the Dashboard, Plugins and show.fm screens, and only to administrators. It warns 30 and 7 days before the site key expires, when show.fm stops accepting the key (for example after the account password changes), when the shows' plan pauses auto-posting, and when a publishing setting stops new episodes being posted. Each notice has one button that fixes the problem. Dismissing a notice hides it for you until the next stage.

= Does disconnecting cancel the site key? =

Yes, when show.fm can be reached. Disconnect in Settings > show.fm (or `wp showfm disconnect`) first asks show.fm to revoke the site key, then removes it from this site and stops posting, whatever the answer. The message afterwards says whether the key was revoked. If show.fm couldn't be reached, the key may still work, and the message links to a show's Connected sites page in show.fm, where you can disconnect the site by hand. Posts already created stay.

= How do I choose how episodes are posted? =

Settings > show.fm > Publishing appears once the site is connected. Choose whether new episodes are posted automatically, the post type (any public post type that uses the block editor), the category, the author (anyone who can publish that post type), the theme template, and whether to include the transcript and use the episode artwork as the featured image. These settings shape new posts only: changing them never rewrites posts that already exist. Unpublished episodes go back to draft and deleted episodes go to the bin. The tab also lists the last 20 things the sync did, with a link to each post.

= Can I connect from the command line? =

Yes. Create a site key in show.fm under Connected sites, then Add a site with WP-CLI, and run `wp showfm connect`. It asks for the key with the input hidden, or reads it from the `SHOWFM_KEY` environment variable, or from standard input with `--key=-` (for example `pass show showfm/site-key | wp showfm connect --key=-`). `--key=<key>` also works, but the key then stays in your shell history and shows in the process list. `wp showfm status` shows the connection and `wp showfm disconnect` removes it. On a multisite network, add `--url=` to pick the site: each site connects separately.

= Can I migrate existing podcast embeds? =

Yes, through WP-CLI on a connected site. Run `wp showfm migrate-embeds --dry-run --user=<administrator>` to review published posts and pages, then `wp showfm migrate-embeds --resume --yes --user=<administrator>` to replace matches with show.fm Player blocks. Use `--post=<id>`, `--format=json`, or `--choose=<post>:<embed>:<episode-uuid>` for an ambiguous candidate. Scans run in batches of 50 and can resume with `--resume`. The report links to WordPress revisions for undo. Existing show.fm blocks are reported individually; remaining legacy players are still scanned. Unmatched or unchosen ambiguous embeds stay untouched. Applying requires a completed dry run. Matching uses API import fingerprints, with title/date-only candidates always requiring an explicit choice. Catalogue downloads resume after errors and rate limits without losing successful pages or the previous report. Reconnecting automatically abandons pending pages from the old key; `--reset` discards pending pages, or use `--dry-run --reset` to begin afresh. The scan reports the 10,000-token cap explicitly if it is exceeded. There is no Migrate settings tab yet.

= How is the connection stored? =

The site key and ping secret are encrypted with a key derived from your WordPress salts. Set the salts in `wp-config.php`: without them WordPress keeps a generated salt in the database, so a copy of the database alone would be enough to read the key. Changing the salts means you connect again.

The plugin keeps up to 50 local sync diagnostics containing its own reason codes, feed sequence numbers and timestamps. They contain no remote error text or post content. Identity receipts preserve user trash and deletion decisions, including during a replay. Restoring a post from the bin re-attaches it, queues one report of its restored state and resumes syncing; detected WordPress edits remain protected. A successful user trash or permanent deletion queues one trashed-state report using the saved post ID and address. Transient post-write failures retry up to five times per sequence; exhausted reasons remain in connection sync status. Configuration failures (an unavailable post type or no publishing author) keep the episode pending indefinitely, with back-off up to an hourly retry. Fixing the setting resumes syncing automatically without --from-start. Tools > Site Health and `wp showfm sync status` show these problems. Site Health also identifies invalid feed boundaries as server contract problems; checking it makes no HTTP request. Uninstall removes these plugin records while keeping posts and media.

== Changelog ==

= 0.1.0 =
* Added the Publishing tab to Settings > show.fm, shown while connected: post new episodes automatically, post type, category, author, post template, transcript and featured image, how sync works, and the last 20 sync events with links to their posts. Settings shape new posts only and never rewrite existing ones. A setting that stops new episodes being posted shows on the tab, and saving a fix retries straight away.
* Disconnect now asks show.fm to revoke the site key first (best effort, 3-second timeout), then removes it from the site either way, and says whether the key was revoked or must be revoked in show.fm.
* In the editor, only people who can publish see the connected account's shows and scheduled episodes, show.fm links in blocks are limited to show.fm hosts, and a scheduled episode's details are never saved in the post.
* Added the block editor UI: show and episode pickers with search, seasons and Latest episode, inspector controls for every block, the heading level toolbar control, a Transcript that follows a Player, and in-block messages for scheduled, unavailable, paused and unreachable episodes. The blocks preview the real player in the editor.
* Added the "show.fm" panel to the post sidebar on posts created by show.fm: sync status, the episode for this post and a link to it in show.fm.
* Updated the bundled embed package to 1.4.0, which adds the episode list, play button with mini-player, and transcript elements.
* Development release: the plugin's foundations (API client, cache and encrypted connection storage). No designed settings screen yet.
* Added cache-only block and shortcode rendering, local embed assets, fallback parity checks, episode bindings, oEmbed and theme mapping. Credit defaults off and public-episode JSON-LD defaults on.
* Connect a site to show.fm from the show.fm menu or with `wp showfm connect`, with a daily health report and signed wake-up pings.
* Rejected oversized complete HTML tags without scanning their attribute contents as embeds; long candidates are prose only when no closing tag delimiter exists before the next opener or end.
* Treated unknown incomplete tag names as prose even beyond the 16 KiB tag budget, kept detector and wrapper tags strict, and refused report items with missing scan evidence.
* Kept unclosed less-than comparisons as prose, preserved caption fragments as Custom HTML around editable Player blocks, and stored wrapper fragments only once in migration reports.
* Protected migration undo revisions from a retention limit of one, handled text less-than signs and captioned wrappers, added stale catalogue reset, and removed unused show-identity matching.
* Fixed migration range overlap, partial-post scanning, concurrent-edit protection, provenance parity, dry-run enforcement, resumable catalogue downloads and report cleanup.
* Added the embed migration engine and WP-CLI dry runs, resumable reports, candidate choices and revision-backed swaps for Buzzsprout, Libsyn, Captivate, Transistor, Spotify, Podbean, PowerPress and Seriously Simple Podcasting.
* Added the sync engine: scheduled and ping-triggered pulls, post lifecycle updates, permanent WordPress edit protection, optional featured images, durable post reports, and `wp showfm sync [--dry-run] [--from-start]` plus `wp showfm sync status`. The post panel follows in a later release.

* Fixed lifecycle-only sync updates, terminal report handling, bounded artwork retries and limits, poison-row recovery, dry-run cursor safety, user deletion protection, fallback locking and local diagnostics.
* Fixed feed cursor boundary validation, completed artwork queue cleanup, recoverable apply errors with a five-attempt budget, local deletion reports and a 16 MP artwork limit.
* Fixed configuration retries so episodes are never skipped for a setting problem, added local Site Health diagnostics, and re-attached restored posts with edit protection and a durable restored-state report.
* Added Settings > show.fm with Connection and Display tabs. The Connection tab shows every connection state (connected, expiring, expired, disconnected by show.fm, paused by the plan, using scheduled checks, unreadable after the salts changed) and the outcome of connecting, with Reconnect and Disconnect. The old show.fm menu address redirects to it.
* Added the Display settings "Load players only after a visitor clicks" and "Use my theme's colours and fonts", next to the existing credit and structured data settings.
* Added admin notices for key expiry at 30 and 7 days, a refused key, a plan pause and a sync configuration problem: at most one at a time, dismissible per user.
* Connecting now stops early when the site's address does not use https, and a return from show.fm without approval says the connection was cancelled.
* Added the account name and connected shows to the settings screen, fetched after connecting and with the daily health report, and updated the suggested privacy policy text.
* Disconnect moves focus to the Connect card afterwards and the change is announced.
* The outcome of connecting stays on the settings screen across reloads and tabs until it is dismissed or 15 minutes pass, and only while it still matches the connection: Disconnect clears it, and an expired, refused or replaced connection hides it. Reconnect in an admin notice now submits a form instead of following a link.
