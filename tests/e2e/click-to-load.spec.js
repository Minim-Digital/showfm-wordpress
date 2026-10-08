/**
 * "Load players only after a visitor clicks": each block shows the package's facade, nothing
 * is requested before the click (not even the bundled v1.js), and the block upgrades after
 * it. Nothing ever comes from embed.cdn.media.
 */
const { randomUUID } = require( 'node:crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Fresh IDs for each run and each block. The server's background cache refresh asks the
// real API about them and stores "unavailable", so an ID can't be reused.
const id = () => randomUUID();
const PODCAST = id();
const LOCAL_V1 = '/wp-content/plugins/showfm/assets/showfm-embed/v1.js';

const podcast = {
	id: PODCAST,
	slug: 'the-long-table',
	title: 'The Long Table',
	artwork: { url: null },
	links: { listen: 'https://the-long-table.show.fm' },
	branding: { show_powered_by: false },
};
const episode = {
	id: null,
	slug: 'sourdough',
	title: 'Sourdough',
	season_number: 2,
	episode_number: 4,
	published_at: '2026-09-24T09:00:00.000Z',
	audio: {
		url: 'https://m.cdn.media/e2e/sourdough.mp3',
		content_type: 'audio/mpeg',
		duration_seconds: 3120,
	},
	artwork: { url: null },
	links: { listen: 'https://the-long-table.show.fm/e/sourdough' },
	transcript: {
		url: 'https://m.cdn.media/e2e/sourdough.vtt',
		type: 'text/vtt',
	},
	podcast,
};

const blocks = [
	[ 'player', 'Play podcast episode', { episode: id() } ],
	[ 'episodes', 'Load episodes', { podcast: PODCAST } ],
	[ 'play', 'Play podcast episode', { episode: id() } ],
	[ 'transcript', 'Load transcript', { episode: id() } ],
];

/**
 * Answers the elements' browser requests from fixtures, and records every request that
 * leaves the site, and every request for v1.js.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {string[]} The recorded URLs, filled as the page runs.
 */
async function watch( page ) {
	const seen = [];
	page.on( 'request', ( request ) => {
		const url = request.url();
		if (
			! url.startsWith( 'http://localhost' ) ||
			url.includes( '/v1.js' )
		) {
			seen.push( url );
		}
	} );
	const cors = { 'access-control-allow-origin': '*' };
	await page.route( 'https://api.show.fm/**', ( route ) => {
		const { pathname } = new URL( route.request().url() );
		let body = null;
		const match = /^\/v1\/episodes\/([0-9a-f-]{36})$/.exec( pathname );
		if ( match ) {
			body = { data: { ...episode, id: match[ 1 ] } };
		} else if ( pathname === `/v1/podcasts/${ PODCAST }` ) {
			body = { data: podcast };
		} else if ( pathname === `/v1/podcasts/${ PODCAST }/episodes` ) {
			body = {
				data: [ { ...episode, id: id() } ],
				podcast,
				pagination: {},
			};
		}
		return route.fulfill( {
			status: body ? 200 : 404,
			contentType: 'application/json',
			headers: cors,
			body: JSON.stringify( body || { error: { code: 'not_found' } } ),
		} );
	} );
	await page.route( 'https://m.cdn.media/**', ( route ) =>
		route.request().url().endsWith( '.vtt' )
			? route.fulfill( {
					contentType: 'text/vtt',
					headers: cors,
					body: 'WEBVTT\n\n00:00.000 --> 00:05.000\n<v Tom>Welcome to the bakery.\n',
				} )
			: route.fulfill( { status: 404, headers: cors } )
	);
	await page.route( 'https://embed.cdn.media/**', ( route ) =>
		route.fulfill( { status: 404 } )
	);
	return seen;
}

test.describe( 'Load players only after a visitor clicks', () => {
	const pages = {};

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: '/wp/v2/plugins/showfm/showfm',
			data: { status: 'active' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { showfm_load_on_click: true },
		} );
		for ( const [ type, , attributes ] of blocks ) {
			const block = {
				...attributes,
				snapshot: {
					title: `Fallback ${ type }`,
					listenUrl: 'https://the-long-table.show.fm/e/sourdough',
					audioUrl: 'https://m.cdn.media/e2e/sourdough.mp3',
				},
			};
			pages[ type ] = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/pages',
				data: {
					title: `Click to load: ${ type }`,
					status: 'publish',
					content: `<!-- wp:showfm/${ type } ${ JSON.stringify(
						block
					) } /-->`,
				},
			} );
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { showfm_load_on_click: false },
		} );
		for ( const page of Object.values( pages ) ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ page.id }`,
				params: { force: true },
			} );
		}
	} );

	for ( const [ type, label ] of blocks ) {
		test( `${ type }: facade, no request before the click, then it upgrades`, async ( {
			browser,
		} ) => {
			// A visitor: logged out, so the admin bar's avatar is not on the page either.
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();
			const seen = await watch( page );
			await page.goto( pages[ type ].link );
			const element = page.locator( `showfm-${ type }` );
			await expect( element ).toHaveAttribute( 'load', 'click' );

			// The facade, drawn by the bundled click loader.
			const button = element.locator( '[data-showfm-facade-ui] button' );
			await expect( button ).toBeVisible();
			await expect( button ).toHaveAttribute( 'aria-label', label );
			await expect(
				page.locator( 'script[src*="showfm-embed/click-loader.js"]' )
			).toHaveAttribute(
				'data-src',
				/\/assets\/showfm-embed\/v1\.js\?ver=/
			);
			await expect(
				page.locator( 'script[src*="showfm-embed/v1.js"]' )
			).toHaveCount( 0 );
			await page.waitForTimeout( 500 );
			expect( seen ).toEqual( [] );

			await button.click();
			// Upgraded: the element draws itself, and the facade is gone from view.
			await expect
				.poll( () =>
					element.evaluate(
						( node ) => node.shadowRoot?.childElementCount ?? 0
					)
				)
				.toBeGreaterThan( 0 );
			await expect( button ).toBeHidden();
			await expect
				.poll( () =>
					seen.some( ( url ) =>
						url.startsWith( 'https://api.show.fm/' )
					)
				)
				.toBe( true );
			// v1.js came from the plugin, once, and nothing from the CDN.
			const scripts = seen.filter( ( url ) => url.includes( '/v1.js' ) );
			expect( scripts ).toHaveLength( 1 );
			expect( new URL( scripts[ 0 ] ).pathname ).toBe( LOCAL_V1 );
			expect(
				seen.filter( ( url ) => url.includes( 'embed.cdn.media' ) )
			).toEqual( [] );
			await context.close();
		} );
	}
} );
