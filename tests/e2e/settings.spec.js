/**
 * Settings > show.fm: the Connection tab states reachable without show.fm, Display saving,
 * the admin notice, and a 390px phone layout.
 *
 * Connected states are set up by the test-only plugin in tests/e2e/plugin, which stores a
 * local connection without contacting show.fm.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PAGE = 'options-general.php';
const QUERY = 'page=showfm';

/**
 * Puts the connection into a state.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} state        State name.
 * @param {string} result       Optional connect outcome.
 */
async function setState( requestUtils, state, result = '' ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/showfm-e2e/v1/state',
		data: { state, result },
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

test.describe( 'show.fm settings', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await setPlugin( requestUtils, 'showfm/showfm', 'active' );
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'active'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setState( requestUtils, 'not_connected' );
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'inactive'
		);
		await requestUtils.updateSiteSettings( {
			showfm_load_on_click: false,
		} );
	} );

	test( 'not connected: explains connecting, and stops a site without https', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'not_connected' );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect(
			page.getByRole( 'heading', { name: 'show.fm', level: 1 } )
		).toBeVisible();
		await expect( page.getByRole( 'tab' ) ).toHaveText( [
			'Connection',
			'Display',
		] );
		await expect( page.getByText( 'Connecting adds' ) ).toBeVisible();

		// wp-env serves http, so the real connect flow stops here with the https message.
		await page
			.getByRole( 'button', { name: 'Connect to show.fm' } )
			.click();
		await expect( page ).toHaveURL( /options-general\.php\?page=showfm/ );
		await expect(
			page
				.locator( '.showfm-notice' )
				.getByText( 'This site’s address must use https.' )
		).toBeVisible();
	} );

	const states = [
		[ 'connected', 'Connected', null ],
		[
			'expiring30',
			'Expires in 30 days',
			'Your show.fm connection expires in 30 days.',
		],
		[
			'expiring7',
			'Expires in 7 days',
			'Your show.fm connection expires in 7 days.',
		],
		[ 'expired', 'Expired', 'Your show.fm connection has expired.' ],
		[ 'refused', 'Disconnected', 'show.fm disconnected this site.' ],
		[
			'paused',
			'Auto-posting paused',
			'Auto-posting is paused because the show’s plan doesn’t include connected sites.',
		],
		[ 'scheduled', 'Scheduled checks', 'Using scheduled checks.' ],
		[ 'unreadable', 'Needs reconnecting', 'Reconnect to show.fm.' ],
	];

	for ( const [ state, status, notice ] of states ) {
		test( `connection state: ${ state }`, async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await setState( requestUtils, state );
			await admin.visitAdminPage( PAGE, QUERY );

			await expect(
				page.locator( '.showfm-status' ).getByText( status )
			).toBeVisible();
			if ( notice ) {
				await expect(
					page.locator( '.showfm-notice strong' )
				).toHaveText( notice );
			} else {
				await expect( page.locator( '.showfm-notice' ) ).toHaveCount(
					0
				);
			}
			await expect(
				page.getByRole( 'term' ).filter( { hasText: 'This site' } )
			).toBeVisible();
			const html = await page.content();
			expect( html ).not.toContain( 'showfm_live_e2eexamplekey' );
		} );
	}

	test( 'connected: account, shows, masked key and updates', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected' );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect( page.getByText( 'Maya Lindgren' ) ).toBeVisible();
		await expect( page.getByText( 'The Long Table' ) ).toBeVisible();
		await expect(
			page.getByText( 'second-helpings.show.fm' )
		).toBeVisible();
		await expect( page.getByText( /^Key expires on / ) ).toBeVisible();
		await expect( page.getByText( '••••7f3a' ) ).toBeVisible();
		await expect(
			page.getByText( 'Last checked 3 minutes ago' )
		).toBeVisible();
	} );

	test( 'return from show.fm: success and cancelled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected', 'success' );
		await admin.visitAdminPage( PAGE, QUERY );
		await expect(
			page
				.locator( '.showfm-notice' )
				.getByText(
					'New episodes of The Long Table and Second Helpings will be posted here.'
				)
		).toBeVisible();

		await setState( requestUtils, 'not_connected', 'cancelled' );
		await admin.visitAdminPage( PAGE, QUERY );
		const notice = page.locator( '.showfm-notice' );
		await expect( notice ).toContainText(
			'Couldn’t connect to show.fm. The connection was cancelled.'
		);
		await expect(
			notice.getByRole( 'button', { name: 'Try again' } )
		).toBeVisible();

		// The outcome survives a reload until it is dismissed.
		await page.reload();
		await expect( notice ).toContainText( 'The connection was cancelled.' );
		const cleared = page.waitForResponse( ( response ) =>
			response.url().includes( 'dismiss-result' )
		);
		await notice.getByRole( 'button', { name: /Close|Dismiss/ } ).click();
		await cleared;
		await page.reload();
		await expect(
			page.getByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeVisible();
		await expect( page.locator( '.showfm-notice' ) ).toHaveCount( 0 );
	} );

	test( 'disconnect asks first, then returns to the connect card', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected' );
		await admin.visitAdminPage( PAGE, QUERY );

		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		const dialog = page.getByRole( 'dialog', {
			name: 'Disconnect from show.fm?',
		} );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText(
			'This site’s key stays valid at show.fm until you disconnect the site there too'
		);
		await expect(
			dialog.getByRole( 'link', {
				name: /Open Connected sites in show.fm/,
			} )
		).toHaveAttribute(
			'href',
			'https://my.show.fm/p/the-long-table/settings/sites'
		);
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();

		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await dialog.getByRole( 'button', { name: 'Disconnect' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeFocused();
		await expect( page.locator( '.showfm-notice' ) ).toContainText(
			'Disconnected from show.fm. This site’s key stays valid at show.fm until you disconnect the site there too.'
		);

		await page.reload();
		await expect(
			page.getByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeVisible();
	} );

	test( 'display: saves the switches and the front end follows them', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'not_connected' );
		await requestUtils.updateSiteSettings( {
			showfm_load_on_click: false,
		} );
		await admin.visitAdminPage( PAGE, `${ QUERY }&tab=display` );

		const credit = page.getByRole( 'checkbox', {
			name: 'Show “Powered by show.fm”',
		} );
		const click = page.getByRole( 'checkbox', {
			name: 'Load players only after a visitor clicks',
		} );
		await expect( credit ).not.toBeChecked();
		await expect( click ).not.toBeChecked();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Add episode structured data for search engines',
			} )
		).toBeChecked();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Use my theme’s colours and fonts',
			} )
		).toBeChecked();

		await click.check();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect(
			page.locator( '.showfm-notice' ).getByText( 'Settings saved.' )
		).toBeVisible();

		await page.reload();
		await expect( click ).toBeChecked();

		const post = await requestUtils.createPost( {
			title: 'Consent mode',
			content: '[showfm episode="11111111-2222-4333-8444-555555555555"]',
			status: 'publish',
		} );
		await page.goto( post.link );
		await expect( page.locator( 'showfm-player' ) ).toHaveAttribute(
			'load',
			'click'
		);
		await requestUtils.deleteAllPosts();
	} );

	test( 'the dashboard shows one notice, and dismissing it sticks', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'expiring30' );
		await admin.visitAdminPage( 'index.php' );

		const notice = page.locator( '.showfm-notice' );
		await expect( notice ).toHaveCount( 1 );
		await expect( notice ).toContainText(
			'Your show.fm connection expires in 30 days.'
		);
		await expect(
			notice.getByRole( 'button', { name: 'Reconnect' } )
		).toBeVisible();
		await expect( notice.locator( 'a[href*="_wpnonce"]' ) ).toHaveCount(
			0
		);

		const dismissed = page.waitForResponse(
			( response ) =>
				response.url().includes( 'notices%2Fdismiss' ) ||
				response.url().includes( 'notices/dismiss' )
		);
		await notice.locator( '.notice-dismiss' ).click();
		await dismissed;
		await page.reload();
		await expect( page.locator( '.showfm-notice' ) ).toHaveCount( 0 );

		// The Display tab carries the same notice; the Connection tab has its own message.
		await setState( requestUtils, 'expiring30' );
		await admin.visitAdminPage( PAGE, `${ QUERY }&tab=display` );
		await expect(
			page
				.locator( '.showfm-notice' )
				.getByRole( 'button', { name: 'Reconnect' } )
		).toBeVisible();
	} );

	test( 'Reconnect in a dashboard notice POSTs the connect form', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'expiring7' );
		await admin.visitAdminPage( 'index.php' );

		const posted = page.waitForRequest(
			( request ) =>
				request.url().includes( 'admin-post.php' ) &&
				request.method() === 'POST'
		);
		await page
			.locator( '.showfm-notice' )
			.getByRole( 'button', { name: 'Reconnect' } )
			.click();
		const request = await posted;
		expect( request.url() ).not.toContain( '_wpnonce' );
		expect( request.postData() ).toContain( 'action=showfm_connect' );

		// wp-env is http, so the flow stops at the https check and says so.
		await expect( page ).toHaveURL( /options-general\.php\?page=showfm/ );
		await expect( page.locator( '.showfm-notice' ) ).toContainText(
			'This site’s address must use https.'
		);
	} );

	test( 'the old settings address still works', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=showfm&tab=display' );

		await expect( page ).toHaveURL(
			/options-general\.php\?page=showfm&tab=display/
		);
	} );

	test( 'fits a 390px phone', async ( { admin, page, requestUtils } ) => {
		await setState( requestUtils, 'expiring7' );
		await page.setViewportSize( { width: 390, height: 844 } );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect( page.locator( '.showfm-notice strong' ) ).toHaveText(
			'Your show.fm connection expires in 7 days.'
		);
		const widths = await page.evaluate( () => ( {
			scroll: document.documentElement.scrollWidth,
			view: window.innerWidth,
		} ) );
		expect( widths.scroll ).toBeLessThanOrEqual( widths.view );

		for ( const name of [ 'Connection', 'Display' ] ) {
			await expect( page.getByRole( 'tab', { name } ) ).toBeInViewport();
		}
		const reconnect = page
			.locator( '.showfm-actions' )
			.getByRole( 'button', { name: 'Reconnect' } );
		const box = await reconnect.boundingBox();
		expect( box.height ).toBeGreaterThanOrEqual( 44 );

		await page.getByRole( 'button', { name: 'Disconnect' } ).click();
		await expect( page.getByRole( 'dialog' ) ).toBeVisible();
	} );
} );
