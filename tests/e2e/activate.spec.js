/**
 * Smoke test: the plugin activates cleanly and the front end still renders.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PLUGIN = 'showfm/showfm';

test.describe( 'show.fm plugin', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/plugins/${ PLUGIN }`,
			data: { status: 'inactive' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/plugins/${ PLUGIN }`,
			data: { status: 'inactive' },
		} );
	} );

	test( 'activates from the Plugins screen', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'plugins.php' );

		await page
			.getByRole( 'link', {
				name: 'Activate show.fm Podcast Player',
				exact: true,
			} )
			.click();

		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin activated.'
		);
		await expect(
			page.getByRole( 'link', {
				name: 'Deactivate show.fm Podcast Player',
				exact: true,
			} )
		).toBeVisible();
	} );

	test( 'the front end renders with the plugin active', async ( {
		page,
	} ) => {
		const response = await page.goto( '/' );

		expect( response?.status() ).toBe( 200 );
		const html = await page.content();
		expect( html ).not.toMatch(
			/(Fatal error|Parse error|Warning|Notice|Deprecated):/
		);
	} );
} );
