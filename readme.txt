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

This version is an early development release. The blocks and the connection screen are not in it yet.

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
* When: to sync episodes into posts (every 15 minutes, and when show.fm signals that an episode changed), to report the connection's health, and to tell show.fm the address of each post created for an episode.
* What is sent: the site key show.fm issued for this site, your site's address, the plugin version, sync status, and the post addresses and post IDs of synced posts.

**show.fm media (m.cdn.media)**

* Audio and transcript files are served from m.cdn.media.
* When: only when a visitor plays an episode or opens a transcript. The visitor's browser loads the file directly, so show.fm sees the visitor's IP address and browser details, as with any audio file on the web.
* The player itself is bundled with the plugin. Nothing is loaded from embed.cdn.media.

**show.fm account (my.show.fm)**

* Used when an administrator clicks Connect. my.show.fm opens in the administrator's browser, where they sign in and approve the connection.
* What is sent: your site's address and a one-time code that proves the request came from your site.

show.fm [Terms of Service](https://show.fm/terms) and [Privacy Policy](https://show.fm/privacy).

== Installation ==

1. Install and activate the plugin from the Plugins screen.
2. Add a show.fm block to a post or page.

== Frequently Asked Questions ==

= Do I need a show.fm account? =

Not for the player and episode lists of a public show. Publish to WordPress needs an account.

== Changelog ==

= 0.1.0 =
* Development release: the plugin's foundations (API client, cache and encrypted connection storage). No blocks or settings screens yet.
