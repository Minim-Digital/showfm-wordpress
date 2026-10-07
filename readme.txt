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

This version is an early development release. It can connect a site to show.fm, from the show.fm menu or with WP-CLI, but the blocks and the designed settings screens are not in it yet.

The plugin works for any public show.fm show without an account. Publish to WordPress needs a show.fm account and a connected site.

The source code is on GitHub: [show.fm for WordPress](https://github.com/Minim-Digital/showfm-wordpress).

== External services ==

This plugin relies on show.fm, a podcast hosting service run by show.fm Ltd. It sends nothing until you add a show or episode to a page, or connect the site to show.fm. It does not collect analytics or telemetry.

**show.fm public API (api.show.fm)**

* Used to fetch show and episode details (titles, descriptions, artwork, audio links and transcripts) for the shows and episodes you add to your pages.
* When: when you add a show or episode in the editor, and when the plugin refreshes its cached copy in the background (at most every 15 minutes for each show or episode). Your visitors' page views never cause a request from your server.
* What is sent: the show or episode identifier, and your site's address in the request's User-Agent header. Your server's IP address is visible to show.fm, as with any web request. No visitor data is sent.

**show.fm API for connected sites (api.show.fm)**

* Used only after an administrator connects the site to a show.fm account.
* Connecting (code exchange): once, when the administrator returns from my.show.fm after approving the connection. Sent: the one-time code from my.show.fm and the matching verifier this site created, in the body of a request to api.show.fm/v1/sites/exchange. show.fm answers with the site key and ping secret, which the plugin stores encrypted.
* Connecting with WP-CLI: once, when someone runs `wp showfm connect`. Sent: the site key, your site's address and REST API address, and a one-time state and challenge, to api.show.fm/v1/me/sites.
* Reporting in (verify): right after connecting. If that fails, it is retried with the next health report. Sent: the site key, the plugin, WordPress and PHP versions, and the site's name.
* Health report: once a day while connected. Sent: the site key, the plugin, WordPress and PHP versions, the time of the last sync and the number of sync errors.
* Sync: to sync episodes into posts (every 15 minutes, and when show.fm signals that an episode changed), and to tell show.fm the address of each post created for an episode. Sent: the site key, the plugin version, sync status, and the post addresses and post IDs of synced posts.
* After show.fm refuses the site key, the plugin sends nothing more until an administrator reconnects. When show.fm asks it to slow down, it waits as long as show.fm says.

**Requests from show.fm to your site**

* Connection check: while an administrator connects the site, show.fm requests `/wp-json/showfm/v1/challenge` on your site to confirm that the request came from it. Your site answers with the one-time challenge only.
* Wake-up pings: while connected, show.fm sends signed, empty requests to `/wp-json/showfm/v1/ping` when an episode changes. The plugin checks the signature, then starts a sync in the background. A ping carries no data.

**show.fm media (m.cdn.media)**

* Audio and transcript files are served from m.cdn.media.
* When: only when a visitor plays an episode or opens a transcript. The visitor's browser loads the file directly, so show.fm sees the visitor's IP address and browser details, as with any audio file on the web.
* The player itself is bundled with the plugin. Nothing is loaded from embed.cdn.media.

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

= Can I connect from the command line? =

Yes. Create a site key in show.fm under Connected sites, then Add a site with WP-CLI, and run `wp showfm connect`. It asks for the key with the input hidden, or reads it from the `SHOWFM_KEY` environment variable, or from standard input with `--key=-` (for example `pass show showfm/site-key | wp showfm connect --key=-`). `--key=<key>` also works, but the key then stays in your shell history and shows in the process list. `wp showfm status` shows the connection and `wp showfm disconnect` removes it. On a multisite network, add `--url=` to pick the site: each site connects separately.

= How is the connection stored? =

The site key and ping secret are encrypted with a key derived from your WordPress salts. Set the salts in `wp-config.php`: without them WordPress keeps a generated salt in the database, so a copy of the database alone would be enough to read the key. Changing the salts means you connect again.

== Changelog ==

= 0.1.0 =
* Development release: the plugin's foundations (API client, cache and encrypted connection storage). No blocks or settings screens yet.
* Connect a site to show.fm from the show.fm menu or with `wp showfm connect`, with a daily health report and signed wake-up pings.
