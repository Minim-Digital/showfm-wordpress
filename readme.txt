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

This version is an early development release. Four blocks (Player, Episode list, Play button and Transcript) and the [showfm] shortcode are available, and the site can connect to show.fm from the show.fm menu or with WP-CLI. In the block editor you add a show by its address, or pick one of your shows once the site is connected, then choose an episode or "Latest episode", and the block previews the real player. Posts created by show.fm have a "show.fm" panel in the post sidebar. The designed connection screen follows in separate work.

The plugin works for any public show.fm show without an account. Publish to WordPress needs a show.fm account and a connected site.

The source code and build instructions are on GitHub: [show.fm for WordPress](https://github.com/Minim-Digital/showfm-wordpress) and the MIT-licensed [show.fm embed package](https://github.com/Minim-Digital/showfm-embed/tree/v1.4.0). The bundled scripts are copied without modification from @showfm/embed 1.4.0. The block editor script is built from `src/` in the plugin's repository with @wordpress/scripts.

== External services ==

This plugin relies on show.fm, a podcast hosting service run by show.fm Ltd. It sends nothing until you add a show or episode to a page, or connect the site to show.fm. It does not collect analytics or telemetry.

**show.fm public API (api.show.fm)**

* Used to fetch show and episode details (titles, descriptions, artwork, audio links and transcripts) for the shows and episodes you add to your pages.
* When: when WP-Cron refreshes a missing or stale cache entry (fresh for 15 minutes, with retries after errors). Page rendering reads only cached public data and schedules a background refresh; it makes no inline HTTP request. The enhanced player also requests public episode data directly from the visitor's browser when it loads, or after a click with load="click".
* In the block editor: when someone who can edit posts enters a show's address or opens a show.fm block, the site looks the show and its episodes up (cached for five minutes), and the block's preview loads the player in the editor's browser like it does for visitors. Sent: the show or episode identifier, and your site's address in the User-Agent header.
* What is sent: the show or episode identifier, and your site's address in the request's User-Agent header. Your server's IP address is visible to show.fm, as with any web request. Background requests send no visitor data. Browser requests expose the visitor's IP address and browser details to the service. WordPress may also request /v1/oembed to resolve a pasted listen-page URL, then caches the returned iframe in post meta.

**show.fm API for connected sites (api.show.fm)**

* Used only after an administrator connects the site to a show.fm account.
* Connecting (code exchange): once, when the administrator returns from my.show.fm after approving the connection. Sent: the one-time code from my.show.fm and the matching verifier this site created, in the body of a request to api.show.fm/v1/sites/exchange. show.fm answers with the site key and ping secret, which the plugin stores encrypted.
* Connecting with WP-CLI: once, when someone runs `wp showfm connect`. Sent: the site key, your site's address and REST API address, and a one-time state and challenge, to api.show.fm/v1/me/sites.
* Reporting in (verify): right after connecting. If that fails, it is retried before the next sync or health report. Sent: the site key, the plugin, WordPress and PHP versions, and the site's name.
* Health report: once a day while connected. Sent: the site key, the plugin, WordPress and PHP versions, the time of the last sync and the number of sync errors.
* Sync: a check every 15 minutes (with a staggered start), signed wake-up requests and explicit WP-CLI sync commands pull episode changes in the background. Sent: the site key, the last applied sequence number and page size. Post reports send the episode identifier, post ID, HTTPS post address, publication state and source content hash. Transient report failures are queued and retried; terminal refusals, missing posts and invalid addresses are dropped with a local reason code; no WordPress post text or visitor data is uploaded. A dry run previews one page at the applied cursor (or zero with --from-start), recording contact with show.fm but never acknowledging unapplied changes. It leaves local posts and the saved cursor unchanged.
* Block editor: while connected, when someone who can publish posts adds a show.fm block, the site lists the account's shows and their episodes, including scheduled ones, so they can be chosen before they go live. Sent: the site key in the authentication header, and show and episode IDs. Answers are cached for five minutes and only ever reach the browser as titles, dates and links, never the key. Contributors see public shows and episodes only.
* Embed migration: when an administrator explicitly runs a migration scan, the plugin lists the connected key's accessible shows and published episodes, including import matching fingerprints. No per-episode detail requests are made. Sent: the site key in the authentication header, pagination cursors, show and episode IDs, and the site address in the User-Agent. Post content and third-party embed URLs stay on the WordPress site.
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

== Frequently Asked Questions ==

= Do I need a show.fm account? =

Not for the player and episode lists of a public show. Publish to WordPress needs an account.

= How do I add an episode to a post? =

Add a show.fm Player, Episode list, Play button or Transcript block. Type the show's address (such as the-long-table.show.fm) or its slug, then choose an episode or "Latest episode". A connected site lists your shows instead, including scheduled episodes, which the editor marks with the date they go live. Visitors see nothing for an episode until it is public.

= Who can see scheduled episodes in the editor? =

On a connected site, only people who can publish posts (Authors, Editors and Administrators) see the account's shows and its scheduled episodes. Contributors can add public shows and episodes by address, and a block with a scheduled episode tells them it isn't public yet. A scheduled episode's title is never saved in the post, and visitors see nothing until the episode is public.

= Can I connect from the command line? =

Yes. Create a site key in show.fm under Connected sites, then Add a site with WP-CLI, and run `wp showfm connect`. It asks for the key with the input hidden, or reads it from the `SHOWFM_KEY` environment variable, or from standard input with `--key=-` (for example `pass show showfm/site-key | wp showfm connect --key=-`). `--key=<key>` also works, but the key then stays in your shell history and shows in the process list. `wp showfm status` shows the connection and `wp showfm disconnect` removes it. On a multisite network, add `--url=` to pick the site: each site connects separately.

= Can I migrate existing podcast embeds? =

Yes, through WP-CLI on a connected site. Run `wp showfm migrate-embeds --dry-run --user=<administrator>` to review published posts and pages, then `wp showfm migrate-embeds --resume --yes --user=<administrator>` to replace matches with show.fm Player blocks. Use `--post=<id>`, `--format=json`, or `--choose=<post>:<embed>:<episode-uuid>` for an ambiguous candidate. Scans run in batches of 50 and can resume with `--resume`. The report links to WordPress revisions for undo. Existing show.fm blocks are reported individually; remaining legacy players are still scanned. Unmatched or unchosen ambiguous embeds stay untouched. Applying requires a completed dry run. Matching uses API import fingerprints, with title/date-only candidates always requiring an explicit choice. Catalogue downloads resume after errors and rate limits without losing successful pages or the previous report. Reconnecting automatically abandons pending pages from the old key; `--reset` discards pending pages, or use `--dry-run --reset` to begin afresh. The scan reports the 10,000-token cap explicitly if it is exceeded. There is no Migrate settings tab yet.

= How is the connection stored? =

The site key and ping secret are encrypted with a key derived from your WordPress salts. Set the salts in `wp-config.php`: without them WordPress keeps a generated salt in the database, so a copy of the database alone would be enough to read the key. Changing the salts means you connect again.

The plugin keeps up to 50 local sync diagnostics containing its own reason codes, feed sequence numbers and timestamps. They contain no remote error text or post content. Identity receipts preserve user trash and deletion decisions, including during a replay. Restoring a post from the bin re-attaches it, queues one report of its restored state and resumes syncing; detected WordPress edits remain protected. A successful user trash or permanent deletion queues one trashed-state report using the saved post ID and address. Transient post-write failures retry up to five times per sequence; exhausted reasons remain in connection sync status. Configuration failures (an unavailable post type or no publishing author) keep the episode pending indefinitely, with back-off up to an hourly retry. Fixing the setting resumes syncing automatically without --from-start. Tools > Site Health and `wp showfm sync status` show these problems. Site Health also identifies invalid feed boundaries as server contract problems; checking it makes no HTTP request. Uninstall removes these plugin records while keeping posts and media.

== Changelog ==

= 0.1.0 =
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
* Added the sync engine: scheduled and ping-triggered pulls, post lifecycle updates, permanent WordPress edit protection, optional featured images, durable post reports, and `wp showfm sync [--dry-run] [--from-start]` plus `wp showfm sync status`. Publishing controls and the post panel follow in a later release.

* Fixed lifecycle-only sync updates, terminal report handling, bounded artwork retries and limits, poison-row recovery, dry-run cursor safety, user deletion protection, fallback locking and local diagnostics.
* Fixed feed cursor boundary validation, completed artwork queue cleanup, recoverable apply errors with a five-attempt budget, local deletion reports and a 16 MP artwork limit.
* Fixed configuration retries so episodes are never skipped for a setting problem, added local Site Health diagnostics, and re-attached restored posts with edit protection and a durable restored-state report.
