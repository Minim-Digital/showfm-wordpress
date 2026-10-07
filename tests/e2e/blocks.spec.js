/** Server output remains useful with JavaScript disabled. */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const episode = '11111111-2222-4333-8444-555555555555';
const podcast = '22222222-2222-4333-8444-555555555555';

test( 'each server block exposes its element and fallback without JavaScript', async ( {
	browser,
	requestUtils,
} ) => {
	await requestUtils.rest( {
		method: 'PUT',
		path: '/wp/v2/plugins/showfm/showfm',
		data: { status: 'active' },
	} );
	const types = [ 'player', 'episodes', 'play', 'transcript' ];
	const content = types
		.map(
			( type ) =>
				`<!-- wp:showfm/${ type } ${ JSON.stringify( { episode, podcast, snapshot: { title: `Fallback ${ type }`, listenUrl: 'https://test.show.fm/e/one', audioUrl: 'https://m.cdn.media/one.mp3' } } ) } /-->`
		)
		.join( '\n' );
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: {
			title: 'Server block fallback test',
			status: 'publish',
			content,
		},
	} );
	const context = await browser.newContext( { javaScriptEnabled: false } );
	try {
		const page = await context.newPage();
		await page.goto( post.link );
		for ( const type of types ) {
			const element = page.locator( `showfm-${ type }` );
			await expect( element ).toBeVisible();
			await expect( element.locator( 'a' ) ).toHaveText(
				`Fallback ${ type }`
			);
			await expect( element.locator( 'a' ) ).toHaveAttribute(
				'href',
				'https://test.show.fm/e/one'
			);
			await expect( element ).toHaveAttribute( 'credit', 'off' );
		}
		for ( const type of [ 'player', 'play' ] ) {
			await expect(
				page.locator( `showfm-${ type } audio` )
			).toHaveAttribute( 'preload', 'none' );
		}
		await expect(
			page.locator( 'script[src*="assets/showfm-embed/v1.js"]' )
		).toHaveCount( 1 );
	} finally {
		await context.close();
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ post.id }`,
			params: { force: true },
		} );
	}
} );
