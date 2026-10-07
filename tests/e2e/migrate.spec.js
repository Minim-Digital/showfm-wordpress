/**
 * Settings > show.fm > Migrate: the full flow on two seeded posts, a Buzzsprout embed and a
 * PowerPress shortcode. Scan, read the dry run, pick an episode, confirm, then check the
 * results and the changed posts, at 1440px and at 390px.
 *
 * The test-only plugin in tests/e2e/plugin stores a local connection, seeds the posts, and
 * answers the migrator's episode list requests without contacting show.fm.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PAGE = 'options-general.php';
const QUERY = 'page=showfm&tab=migrate';

/**
 * Puts the connection into a state.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} state        State name.
 */
async function setState( requestUtils, state ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/showfm-e2e/v1/state',
		data: { state },
	} );
}

/**
 * Sets up the migration: `reset` clears it, `catalogue` (`up`, `down` or '') sets how the
 * episode lists are answered, and `posts` adds the two posts with old players.
 *
 * @param {Object}  requestUtils      Request utilities.
 * @param {Object}  options           Options.
 * @param {boolean} options.reset     Clear any migration.
 * @param {string}  options.catalogue How the episode lists are answered.
 * @param {boolean} options.posts     Add the two posts.
 * @return {Promise<Object>} The seeded post IDs.
 */
async function setMigrate(
	requestUtils,
	{ reset = true, catalogue = '', posts = false }
) {
	return requestUtils.rest( {
		method: 'POST',
		path: '/showfm-e2e/v1/migrate',
		data: { reset, catalogue, posts },
	} );
}

/**
 * Activates or deactivates a plugin by its file.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} plugin       Plugin file without `.php`.
 * @param {string} status       `active` or `inactive`.
 */
async function setPlugin( requestUtils, plugin, status ) {
	await requestUtils.rest( {
		method: 'PUT',
		path: `/wp/v2/plugins/${ plugin }`,
		data: { status },
	} );
}

/**
 * Checks the page doesn't scroll sideways.
 *
 * @param {Object} page Page.
 */
async function expectNoSideScroll( page ) {
	const widths = await page.evaluate( () => ( {
		scroll: document.documentElement.scrollWidth,
		view: window.innerWidth,
	} ) );
	expect( widths.scroll ).toBeLessThanOrEqual( widths.view );
}

for ( const [ label, width, height ] of [
	[ '1440px', 1440, 900 ],
	[ '390px', 390, 844 ],
] ) {
	test.describe( `show.fm Migrate tab at ${ label }`, () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await setPlugin( requestUtils, 'showfm/showfm', 'active' );
			await setPlugin(
				requestUtils,
				'showfm-e2e-states/showfm-e2e-states',
				'active'
			);
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await setMigrate( requestUtils, {} );
			await setState( requestUtils, 'not_connected' );
			await requestUtils.deleteAllPosts();
			await setPlugin(
				requestUtils,
				'showfm-e2e-states/showfm-e2e-states',
				'inactive'
			);
		} );

		test( 'scans, reviews, picks, confirms and links the results', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await requestUtils.deleteAllPosts();
			await requestUtils.deleteAllPages();
			await setState( requestUtils, 'connected' );
			const { posts } = await setMigrate( requestUtils, {
				catalogue: 'up',
				posts: true,
			} );
			await page.setViewportSize( { width, height } );
			await admin.visitAdminPage( PAGE, QUERY );

			// 4a: the intro.
			await expect(
				page.getByRole( 'tab', { name: 'Migrate' } )
			).toHaveAttribute( 'aria-selected', 'true' );
			await expect(
				page.getByRole( 'heading', {
					name: 'Swap old podcast embeds for show.fm blocks',
				} )
			).toBeVisible();
			await expect( page.getByText( 'Buzzsprout' ) ).toBeVisible();
			await expect(
				page.getByText( 'Seriously Simple Podcasting' )
			).toBeVisible();
			await expect(
				page.getByText( 'Checks 2 published posts and pages.', {
					exact: false,
				} )
			).toBeVisible();
			await expectNoSideScroll( page );
			const scan = await page
				.getByRole( 'button', { name: 'Scan posts' } )
				.boundingBox();
			expect( scan.height ).toBeGreaterThanOrEqual(
				width < 600 ? 44 : 36
			);

			// 4b and 4c: scan, then the dry-run report.
			await page.getByRole( 'button', { name: 'Scan posts' } ).click();
			const report = page.getByRole( 'heading', {
				name: 'Dry run · 2 embeds in 2 posts',
			} );
			await expect( report ).toBeVisible();
			await expect( report ).toBeFocused();
			await expect(
				page.getByText( 'nothing has changed yet', { exact: false } )
			).toBeVisible();
			const ready = page
				.getByRole( 'link', { name: 'We’re launching a podcast' } )
				.locator( 'xpath=ancestor::tr' );
			await expect( ready ).toContainText( 'PowerPress' );
			await expect( ready ).toContainText( 'Welcome to The Long Table' );
			await expect( ready ).toContainText( 'Audio file' );
			await expect( ready ).toContainText( 'Ready' );
			const { link } = await requestUtils.rest( {
				path: `/wp/v2/posts/${ posts[ 0 ] }`,
			} );
			await expect(
				page.getByRole( 'link', { name: 'We’re launching a podcast' } )
			).toHaveAttribute( 'href', link );
			const choose = page
				.getByRole( 'link', { name: 'Leftovers, part one' } )
				.locator( 'xpath=ancestor::tr' );
			await expect( choose ).toContainText( 'Buzzsprout' );
			await expect( choose ).toContainText( 'Title and date' );
			await expect( choose ).toContainText( 'Choose one' );
			await expect( choose ).toContainText( '2 possible matches' );
			const swap = page.getByRole( 'button', { name: 'Swap 1 embed' } );
			await expect( swap ).toBeVisible();
			await expectNoSideScroll( page );

			// The pick.
			const pick = page.getByRole( 'combobox', {
				name: 'Episode for “Leftovers, part one”',
			} );
			const saved = page.waitForResponse(
				( response ) =>
					response.url().includes( 'migrate%2Fchoice' ) ||
					response.url().includes( 'migrate/choice' )
			);
			await pick.selectOption( { index: 2 } );
			expect( ( await saved ).status() ).toBe( 200 );
			await expect( choose ).toContainText( 'Ready' );
			await expect(
				page.getByRole( 'button', { name: 'Swap 2 embeds' } )
			).toBeVisible();

			// 4d: confirm.
			await page.getByRole( 'button', { name: 'Swap 2 embeds' } ).click();
			const dialog = page.getByRole( 'dialog', {
				name: 'Swap 2 embeds?',
			} );
			await expect( dialog ).toContainText(
				'We’ll replace them with show.fm Player blocks in 2 posts.'
			);
			await expect( dialog ).toContainText(
				'WordPress keeps a revision of every post we change, so you can restore any of them.'
			);
			await dialog
				.getByRole( 'button', { name: 'Swap 2 embeds' } )
				.click();

			// 4e: the results.
			const changed = page.getByRole( 'heading', {
				name: 'What changed',
			} );
			await expect( changed ).toBeVisible();
			await expect( changed ).toBeFocused();
			await expect(
				page.getByText( 'Swapped 2 embeds in 2 posts.', {
					exact: true,
				} )
			).toBeVisible();
			const powerpress = page
				.getByRole( 'link', { name: 'We’re launching a podcast' } )
				.locator( 'xpath=ancestor::tr' );
			await expect( powerpress ).toContainText(
				'PowerPress shortcode → show.fm Player'
			);
			await expect(
				powerpress.getByRole( 'link', { name: 'Compare revisions' } )
			).toHaveAttribute( 'href', /revision\.php\?revision=\d+/ );
			await expect(
				page
					.getByRole( 'link', { name: 'Leftovers, part one' } )
					.locator( 'xpath=ancestor::tr' )
			).toContainText( 'Buzzsprout embed → show.fm Player' );
			await expectNoSideScroll( page );

			// The posts now hold show.fm Player blocks, with the pick.
			for ( const id of posts ) {
				const post = await requestUtils.rest( {
					path: `/wp/v2/posts/${ id }`,
					params: { context: 'edit' },
				} );
				expect( post.content.raw ).toContain( '<!-- wp:showfm/player' );
			}
			const picked = await requestUtils.rest( {
				path: `/wp/v2/posts/${ posts[ 1 ] }`,
				params: { context: 'edit' },
			} );
			expect( picked.content.raw ).toContain(
				'a1b2c3d4-0003-4000-8000-000000000003'
			);

			// The revision link opens the compare screen.
			await powerpress
				.getByRole( 'link', { name: 'Compare revisions' } )
				.click();
			await expect( page ).toHaveURL( /revision\.php/ );
		} );

		test( 'says show.fm is unreachable, survives a reload, then finds nothing to migrate', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await requestUtils.deleteAllPosts();
			await requestUtils.deleteAllPages();
			await setState( requestUtils, 'connected' );
			await setMigrate( requestUtils, { catalogue: 'down' } );
			await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: {
					title: 'Just words',
					content: 'No players here.',
					status: 'publish',
				},
			} );
			await page.setViewportSize( { width, height } );
			await admin.visitAdminPage( PAGE, QUERY );

			await page.getByRole( 'button', { name: 'Scan posts' } ).click();
			const unreachable = page
				.locator( '.showfm-migrate__problem' )
				.getByText(
					'We couldn’t reach show.fm to list your episodes. Your progress is saved. Try again in a moment.',
					{ exact: true }
				);
			await expect( unreachable ).toBeVisible();
			await expectNoSideScroll( page );

			// The problem and the scan survive a reload; show.fm comes back.
			await setMigrate( requestUtils, { reset: false, catalogue: 'up' } );
			await page.reload();
			await expect( unreachable ).toBeVisible();
			await expect(
				page.getByRole( 'heading', { name: 'Scan paused' } )
			).toBeVisible();
			// The engine waits at least five seconds before asking show.fm again.
			await page.waitForTimeout( 6000 );
			await page.getByRole( 'button', { name: 'Try again' } ).click();

			const empty = page.getByRole( 'heading', {
				name: 'Nothing to migrate.',
			} );
			await expect( empty ).toBeVisible();
			await expect( empty ).toBeFocused();
			await expect(
				page.getByText(
					'We checked 1 published post or page and didn’t find embeds from other podcast hosts.'
				)
			).toBeVisible();
			await expectNoSideScroll( page );
		} );
	} );
}
