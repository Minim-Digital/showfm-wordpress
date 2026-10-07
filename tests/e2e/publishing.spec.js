/**
 * Settings > show.fm > Publishing: shown only while connected, the empty and filled recent
 * activity, saving, a sync configuration problem, and a 390px phone.
 *
 * The test-only plugin in tests/e2e/plugin stores a local connection and seeds activity
 * without contacting show.fm.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PAGE = 'options-general.php';
const QUERY = 'page=showfm&tab=publishing';

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
 * Resets the Publishing settings, with or without recent activity.
 *
 * @param {Object}  requestUtils Request utilities.
 * @param {boolean} activity     Whether to record three events.
 */
async function setPublishing( requestUtils, activity ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/showfm-e2e/v1/publishing',
		data: { activity },
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

test.describe( 'show.fm Publishing tab', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await setPlugin( requestUtils, 'showfm/showfm', 'active' );
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'active'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setPublishing( requestUtils, false );
		await setState( requestUtils, 'not_connected' );
		await requestUtils.deleteAllPosts();
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'inactive'
		);
	} );

	test( 'appears only while connected', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'not_connected' );
		await admin.visitAdminPage( PAGE, QUERY );
		await expect( page.getByRole( 'tab' ) ).toHaveText( [
			'Connection',
			'Display',
		] );

		await setState( requestUtils, 'connected' );
		await admin.visitAdminPage( PAGE, QUERY );
		await expect( page.getByRole( 'tab' ) ).toHaveText( [
			'Connection',
			'Publishing',
			'Display',
			'Migrate',
		] );
		await expect(
			page.getByRole( 'tab', { name: 'Publishing' } )
		).toHaveAttribute( 'aria-selected', 'true' );
	} );

	test( 'just connected: the defaults and the empty activity (2b)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected' );
		await setPublishing( requestUtils, false );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect(
			page.getByRole( 'heading', { name: 'Auto-posting' } )
		).toBeVisible();
		await expect(
			page.getByText( 'Applies to The Long Table and Second Helpings.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
		).toBeChecked();
		await expect(
			page.getByRole( 'combobox', { name: 'Post type' } )
		).toHaveValue( 'post' );
		await expect(
			page.getByRole( 'combobox', { name: 'Author' } )
		).not.toHaveValue( '0' );
		await expect(
			page.getByRole( 'checkbox', { name: 'Include the transcript' } )
		).toBeChecked();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Use the episode artwork as the featured image',
			} )
		).toBeChecked();
		await expect(
			page.getByText( 'No episodes posted yet.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'How sync works' } )
		).toBeVisible();
		await expect(
			page.getByText( 'Deleted episodes go to the bin.' )
		).toBeVisible();
	} );

	test( 'recent activity lists events with links to their posts (2a)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected' );
		await setPublishing( requestUtils, true );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect( page.getByText( 'Last 20 events' ) ).toBeVisible();
		const rows = page.locator( '.showfm-activity__table tbody tr' );
		await expect( rows ).toHaveCount( 3 );
		await expect( rows.nth( 0 ) ).toContainText( 'Today, ' );
		await expect( rows.nth( 0 ) ).toContainText( 'The Long Table' );
		await expect( rows.nth( 0 ) ).toContainText( 'Posted' );
		await expect(
			rows.nth( 0 ).getByRole( 'link', { name: 'Edit post' } )
		).toHaveAttribute( 'href', /post\.php\?post=\d+&action=edit/ );
		await expect( rows.nth( 1 ) ).toContainText( 'Scheduled for ' );
		await expect( rows.nth( 2 ) ).toContainText(
			'Skipped. Auto-posting was paused by the show’s plan.'
		);
		await expect( rows.nth( 2 ) ).toContainText( 'None' );
	} );

	test( 'saving turns auto-posting off and keeps every choice (2c)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'connected' );
		await setPublishing( requestUtils, false );
		await admin.visitAdminPage( PAGE, QUERY );

		await page
			.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
			.click();
		await expect(
			page.getByText(
				'New episodes aren’t posted here. Posts that already exist keep updating.'
			)
		).toBeVisible();
		await page
			.getByRole( 'combobox', { name: 'Post type' } )
			.selectOption( 'page' );
		await expect(
			page.getByRole( 'combobox', { name: 'Category' } )
		).toBeDisabled();
		await page
			.getByRole( 'checkbox', { name: 'Include the transcript' } )
			.click();

		const saved = page.waitForResponse(
			( response ) =>
				( response.url().includes( 'admin%2Fpublishing' ) ||
					response.url().includes( 'admin/publishing' ) ) &&
				response.request().method() === 'POST'
		);
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		expect( ( await saved ).status() ).toBe( 200 );
		await expect( page.locator( '.components-snackbar' ) ).toContainText(
			'Settings saved.'
		);

		await page.reload();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
		).not.toBeChecked();
		await expect(
			page.getByRole( 'combobox', { name: 'Post type' } )
		).toHaveValue( 'page' );
		await expect(
			page.getByRole( 'checkbox', { name: 'Include the transcript' } )
		).not.toBeChecked();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Use the episode artwork as the featured image',
			} )
		).toBeChecked();

		await setPublishing( requestUtils, false );
	} );

	test( 'a sync configuration problem shows on the tab, once', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setState( requestUtils, 'sync_problem' );
		await setPublishing( requestUtils, false );

		await admin.visitAdminPage( 'index.php' );
		const link = page
			.locator( '.showfm-notice' )
			.getByRole( 'link', { name: 'Check the Publishing settings' } );
		await expect( link ).toBeVisible();
		await link.click();

		await expect(
			page.getByRole( 'heading', { name: 'Auto-posting' } )
		).toBeVisible();
		await expect( page.locator( '.showfm-notice' ) ).toHaveCount( 1 );
		await expect( page.locator( '.showfm-notice' ) ).toContainText(
			'Choose another post type or author, then save.'
		);
	} );

	test( 'fits a 390px phone', async ( { admin, page, requestUtils } ) => {
		await setState( requestUtils, 'connected' );
		await setPublishing( requestUtils, true );
		await page.setViewportSize( { width: 390, height: 844 } );
		await admin.visitAdminPage( PAGE, QUERY );

		await expect(
			page.getByRole( 'heading', { name: 'Auto-posting' } )
		).toBeVisible();
		const widths = await page.evaluate( () => ( {
			scroll: document.documentElement.scrollWidth,
			view: window.innerWidth,
		} ) );
		expect( widths.scroll ).toBeLessThanOrEqual( widths.view );
		const save = await page
			.getByRole( 'button', { name: 'Save changes' } )
			.boundingBox();
		expect( save.height ).toBeGreaterThanOrEqual( 44 );
		expect( save.width ).toBeGreaterThan( 300 );
	} );
} );
