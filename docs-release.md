# Releasing to WordPress.org

My notes for submitting show.fm to the WordPress.org plugin directory, starting with 1.0.2,
and for each release after it. Nothing here has been submitted yet.

## Before I submit

- **Account.** I submit under my own WordPress.org account, `danmaby`. The readme's
  `Contributors: danmaby` line must name a real WordPress.org user, and the submitter
  becomes the plugin's first committer. After approval I add the others (see "Committers"
  below).
- **Two-factor authentication.** WordPress.org has required 2FA for plugin authors since
  1 October 2024. I turn it on in the account's security settings before I submit.
- **SVN password.** Committing to the plugin's SVN needs the SVN password from the same
  security page, not my account password. I generate it once the plugin is approved.

## Build and check the zip

```sh
npm ci
composer install
npm run env:start
npm run release            # dist/showfm-1.0.2.zip, checked against bin/release-files.txt
```

`npm run release` stops if the plugin header, `SHOWFM_VERSION` and the readme's Stable tag
disagree, if the zip holds anything other than the files in `bin/release-files.txt`, or if
any shipped file names a show.fm development host. The plugin's built-in hosts are
production only. A development environment is selected by a must-use plugin through the
`showfm_environment` filter, kept outside this repository. CI
runs the same build, the allow-list check and Plugin Check (Plugin Repo category) on every
pull request. I only submit a zip from a green `main`, ideally the `showfm-zip` artifact of
that CI run.

On 8 October 2026 the 1.0.0 zip passed Plugin Check with no errors and no warnings in every
category, including the runtime and experimental checks, and so has each release since.

## Publish the GitHub release

The plugin's public download, until WordPress.org approves it, is a GitHub release. Once PR #10
is merged and `main` is green:

1. Download the `showfm-zip` artifact from the CI run on `main`, or build it with
   `npm run release` from a clean checkout of that commit.
2. Tag the merge commit `v1.0.0` and push the tag.
3. Create the GitHub release `v1.0.0` from that tag, with the 1.0.0 changelog from
   `readme.txt` as its notes, and attach `showfm-1.0.0.zip`.
4. Check the attached zip installs on a fresh site before I share the link.

## Submit

1. Sign in and open <https://wordpress.org/plugins/developers/add/>.
2. Upload `showfm-1.0.2.zip`.
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
> GitHub repository (ShowDotFM/showfm-wordpress) and our npm package (@showfm/embed).
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
> @showfm/embed package (source: github.com/ShowDotFM/showfm-embed). A test checks it
> byte for byte against the npm release. `build/` is compiled from `src/` in
> github.com/ShowDotFM/showfm-wordpress with @wordpress/scripts. Nothing loads from a CDN.
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

# The plugin: trunk and the 1.0.2 tag hold the zip's contents.
rsync -a --delete ../showfm-wordpress/dist/showfm/ trunk/
svn add --force trunk
svn cp trunk tags/1.0.2

# The listing assets: banners, icons and screenshots go in the top-level assets folder.
cp ../showfm-wordpress/.wordpress-org/* assets/
svn add --force assets
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/icon.svg

svn commit -m "Release 1.0.2" --username danmaby
```

`svn commit` asks for the SVN password. The assets never go in `trunk` or a tag. The
directory can take a few hours to show new images.

### Committers

Once the plugin is live, on its page, Advanced view, "Committers": I add the show.fm
company account (registered with a show.fm email address, with 2FA on) and Nathan's account
by their WordPress.org usernames. Each needs 2FA and their own SVN password. In the next
release I add both usernames to the readme's `Contributors:` line, after mine.

## Each later release

1. Bump the version in `showfm.php` (header and `SHOWFM_VERSION`), `package.json`, the
   blocks' `block.json` files, `tests/phpstan-bootstrap.php` and the readme's Stable tag,
   and add a changelog entry.
2. Run `npm run i18n:pot` and commit the `.pot`.
3. Build with `npm run release`, then bring `trunk/` in line with the new zip and tag it:

    ```sh
    cd showfm-svn
    svn update
    rsync -a --delete ../showfm-wordpress/dist/showfm/ trunk/
    # Files the release removed: rsync deleted them, and svn must delete them too.
    svn status trunk | awk '/^!/ { print $2 }' | xargs -r svn rm
    # Files the release added.
    svn add --force trunk
    svn status trunk            # check: only M, A and D lines
    svn cp trunk tags/1.0.3     # the new version
    svn commit -m "Release 1.0.3" --username danmaby
    ```

    The package's hashed chunk names change with each @showfm/embed release, so most
    releases delete and add files under `trunk/assets/showfm-embed/chunks/`.

4. If the screens changed, run `npm run wporg:screenshots` (wp-env must be running) and
   `npm run wporg:assets`, check the images by eye, and copy them into `assets/`.

## Support

- `wp showfm cache flush` clears the plugin's cache of show.fm data on one site, and
  `wp showfm cache flush --network` on every site of a network. It's the first thing to try
  when a block shows old episode details. Pages fall back to each block's saved copy until
  the background refresh catches up.

## The bundled package and WordPress.org

- The plugin bundles @showfm/embed 1.6.1 and checks every file against the npm release.
- Every element carries `platform="wordpress"`, so with `credit="off"` (the default) no
  show displays "Powered by show.fm" (guideline 10).
- Load on click uses the package's self-hosting `click-loader-local.js`. It names no host,
  and the CDN loader is not in the zip. `npm run release` fails if any shipped script names
  embed.cdn.media (guideline 8).
- When I bump the package, I keep both rules: the exact version, verified against npm, and
  no CDN URL in any shipped script.
