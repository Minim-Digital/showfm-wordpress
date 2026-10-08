# Releasing to WordPress.org

My notes for submitting show.fm 1.0.0 to the WordPress.org plugin directory, and for each
release after it. Nothing here has been submitted yet.

## Before I submit

- **Account.** I need a WordPress.org account with a show.fm email address. I'll register the
  username `showfm`: the readme's `Contributors: showfm` line must name a real WordPress.org
  user, and that username was free on 8 October 2026 (`profiles.wordpress.org/showfm` was a
  404). If I use another username, I change the Contributors line to match before I build.
- **Two-factor authentication.** WordPress.org has required 2FA for plugin authors since
  1 October 2024. I turn it on in the account's security settings before I submit.
- **SVN password.** Committing to the plugin's SVN needs the SVN password from the same
  security page, not my account password. I generate it once the plugin is approved.

## Build and check the zip

```sh
npm ci
composer install
npm run env:start
npm run release            # dist/showfm-1.0.0.zip, checked against bin/release-files.txt
```

`npm run release` stops if the plugin header, `SHOWFM_VERSION` and the readme's Stable tag
disagree, or if the zip holds anything other than the files in `bin/release-files.txt`. CI
runs the same build, the allow-list check and Plugin Check (Plugin Repo category) on every
pull request. I only submit a zip from a green `main`, ideally the `showfm-zip` artifact of
that CI run.

On 8 October 2026 the 1.0.0 zip passed Plugin Check with no errors and no warnings in every
category, including the runtime and experimental checks. The readme passed the WordPress.org
readme validator with no errors. Its one warning was that `showfm` is not yet a WordPress.org
user, which the account above fixes.

## Submit

1. Sign in and open <https://wordpress.org/plugins/developers/add/>.
2. Upload `showfm-1.0.0.zip`.
3. **Check the slug.** WordPress.org builds the slug from the plugin name, and "show.fm"
   becomes `show-fm`. I want `showfm`, which matches the text domain, the GitHub repo and
   the npm scope. If the form shows `show-fm`, I ask for `showfm` in the reviewer notes, and
   again in a reply to the confirmation email straight away. The team can change a slug
   only before approval.
4. Paste the reviewer notes below.

### Reviewer notes

> show.fm is a podcast hosting service run by show.fm Ltd, which I represent. This plugin is
> our official WordPress plugin, and we own the show.fm name.
>
> **Slug:** please use `showfm` rather than `show-fm`. It matches the text domain, our
> GitHub repository (Minim-Digital/showfm-wordpress) and our npm package (@showfm/embed).
>
> **What it does:** blocks, a shortcode and oEmbed for public show.fm podcasts, with no
> account needed. Connecting a show.fm account adds posting new episodes as WordPress posts,
> and a tool that swaps embeds from other podcast hosts for show.fm blocks.
>
> **External services:** every request, what it sends and when, is listed in the readme's
> External services section. The plugin sends nothing until a show is added to a page or the
> site is connected. There is no telemetry.
>
> **Bundled code:** `assets/showfm-embed/` is an unmodified copy of our MIT-licensed
> @showfm/embed package (source: github.com/Minim-Digital/showfm-embed). A test checks it
> byte for byte against the npm release. `build/` is compiled from `src/` in
> github.com/Minim-Digital/showfm-wordpress with @wordpress/scripts. Nothing loads from a CDN.
>
> **Credit:** "Powered by show.fm" is off unless the site owner turns it on in
> Settings > show.fm > Display.
>
> **Testing:** the blocks work with any public show.fm show. Type a show's address, such as
> [a public show address], into a Player block.

I replace the placeholder with a public show the reviewers can use before I paste it.

## After approval

The approval email gives the SVN address, `https://plugins.svn.wordpress.org/showfm/`.

```sh
svn checkout https://plugins.svn.wordpress.org/showfm/ showfm-svn
cd showfm-svn

# The plugin: trunk and the 1.0.0 tag hold the zip's contents.
rsync -a --delete ../showfm-wordpress/dist/showfm/ trunk/
svn add --force trunk
svn cp trunk tags/1.0.0

# The listing assets: banners, icons and screenshots go in the top-level assets folder.
cp ../showfm-wordpress/.wordpress-org/* assets/
svn add --force assets
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/icon.svg

svn commit -m "Release 1.0.0" --username showfm
```

`svn commit` asks for the SVN password. The assets never go in `trunk` or a tag. The
directory can take a few hours to show new images.

## Each later release

1. Bump the version in `showfm.php` (header and `SHOWFM_VERSION`), `package.json`, the
   blocks' `block.json` files, `tests/phpstan-bootstrap.php` and the readme's Stable tag,
   and add a changelog entry.
2. Run `npm run i18n:pot` and commit the `.pot`.
3. Build with `npm run release`, then copy `dist/showfm/` into `trunk/` and to a new tag.
4. If the screens changed, run `npm run wporg:screenshots` (wp-env must be running) and
   `npm run wporg:assets`, check the images by eye, and copy them into `assets/`.

## Still to do for 1.0.0

- **@showfm/embed 1.6.0.** 1.6.0 adds the player's mini-player, the list's transcript and
  the credit rule, and it is in review in showfm-embed. Once it is on npm, the plugin bumps
  to exactly 1.6.0 in its own commit, and every element it renders carries
  `platform="wordpress"`. With `credit="off"`, that keeps "Powered by" hidden for any show,
  as guideline 10 needs. I submit after that commit.
