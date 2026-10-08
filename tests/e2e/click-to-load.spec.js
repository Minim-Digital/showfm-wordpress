/**
 * "Load players only after a visitor clicks": each block shows the package's facade, nothing
 * is requested before the click (not even the bundled v1.js), and the block upgrades after
 * it. Nothing ever comes from embed.cdn.media. The self-hosting loader takes v1.js from
 * window.showfmEmbedSrc, then data-src; the optimiser tests take either away, or both, or
 * run the loader without document.currentScript.
 */
const { randomUUID } = require( 'node:crypto' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
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

// The pages the optimiser tests rewrite, each an Episode list of its own.
const OPTIMISED = [ 'stripped', 'no-global', 'both', 'module', 'module-only' ];

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
		} else if ( /^\/v1\/podcasts\/[0-9a-f-]{36}$/.test( pathname ) ) {
			body = { data: podcast };
		} else if (
			/^\/v1\/podcasts\/[0-9a-f-]{36}\/episodes$/.test( pathname )
		) {
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
		// One page per test, each visited once. The server's background cache refresh asks
		// the real API about these made-up IDs and stores "unavailable", so a page viewed a
		// second time would render nothing. _fields keeps WordPress from rendering the page
		// (and scheduling that refresh) in the REST response.
		const create = async ( key, type, attributes ) => {
			const block = {
				...attributes,
				snapshot: {
					title: `Fallback ${ type }`,
					listenUrl: 'https://the-long-table.show.fm/e/sourdough',
					audioUrl: 'https://m.cdn.media/e2e/sourdough.mp3',
				},
			};
			pages[ key ] = {
				type,
				...( await requestUtils.rest( {
					method: 'POST',
					path: '/wp/v2/pages',
					params: { _fields: 'id,link' },
					data: {
						title: `Click to load: ${ key }`,
						status: 'publish',
						content: `<!-- wp:showfm/${ type } ${ JSON.stringify(
							block
						) } /-->`,
					},
				} ) ),
			};
		};
		for ( const [ type, , attributes ] of blocks ) {
			await create( type, type, attributes );
		}
		for ( const key of OPTIMISED ) {
			await create( key, 'episodes', { podcast: id() } );
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

	/**
	 * Opens a block's page as a logged-out visitor, optionally rewriting the HTML first as
	 * a script optimiser might.
	 *
	 * @param {Object}                   browser   Browser.
	 * @param {string}                   key       The page: a block type or an optimiser test.
	 * @param {(html: string) => string} [rewrite] Changes the page's HTML.
	 * @return {Promise<Object>} The context, page, element and recorded requests.
	 */
	async function visit( browser, key, rewrite ) {
		const { type, link } = pages[ key ];
		// A visitor: logged out, so the admin bar's avatar is not on the page either.
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const page = await context.newPage();
		const seen = await watch( page );
		if ( rewrite ) {
			await page.route( link, async ( route ) => {
				const response = await route.fetch();
				await route.fulfill( {
					response,
					body: rewrite( await response.text() ),
				} );
			} );
		}
		await page.goto( link );
		const element = page.locator( `showfm-${ type }` );
		await expect( element ).toHaveAttribute( 'load', 'click' );
		return { context, page, element, seen };
	}

	/**
	 * The facade shows and nothing loads before the click; after it the element upgrades,
	 * with v1.js once from the plugin and nothing from the CDN.
	 *
	 * @param {Object}                          visited         Result of visit().
	 * @param {import('@playwright/test').Page} visited.page    Page.
	 * @param {Object}                          visited.element The element.
	 * @param {string[]}                        visited.seen    Recorded requests.
	 * @param {string}                          label           The facade button's name.
	 */
	async function expectClickToLoad( { page, element, seen }, label ) {
		const button = element.locator( '[data-showfm-facade-ui] button' );
		await expect( button ).toBeVisible();
		await expect( button ).toHaveAttribute( 'aria-label', label );
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
				seen.some( ( url ) => url.startsWith( 'https://api.show.fm/' ) )
			)
			.toBe( true );
		const scripts = seen.filter( ( url ) => url.includes( '/v1.js' ) );
		expect( scripts ).toHaveLength( 1 );
		expect( new URL( scripts[ 0 ] ).pathname ).toBe( LOCAL_V1 );
		expect(
			seen.filter( ( url ) => url.includes( 'embed.cdn.media' ) )
		).toEqual( [] );
	}

	/**
	 * Nothing happens: no facade, the server fallback stays, and nothing is requested.
	 *
	 * @param {Object}                          visited         Result of visit().
	 * @param {import('@playwright/test').Page} visited.page    Page.
	 * @param {Object}                          visited.element The element.
	 * @param {string[]}                        visited.seen    Recorded requests.
	 */
	async function expectNothing( { page, element, seen } ) {
		await page.waitForTimeout( 1000 );
		await expect(
			element.locator( '[data-showfm-facade-ui]' )
		).toHaveCount( 0 );
		await expect( element.locator( 'a' ).first() ).toBeVisible();
		await expect(
			page.locator( 'script[src*="showfm-embed/v1.js"]' )
		).toHaveCount( 0 );
		expect( seen ).toEqual( [] );
	}

	for ( const [ type, label ] of blocks ) {
		test( `${ type }: facade, no request before the click, then it upgrades`, async ( {
			browser,
		} ) => {
			const visited = await visit( browser, type );
			// The plugin gives the loader both sources: the global, then data-src.
			expect(
				await visited.page.evaluate( () => window.showfmEmbedSrc )
			).toMatch( /\/assets\/showfm-embed\/v1\.js\?ver=/ );
			await expect(
				visited.page.locator(
					'script[src*="showfm-embed/click-loader-local.js"]'
				)
			).toHaveAttribute(
				'data-src',
				/\/assets\/showfm-embed\/v1\.js\?ver=/
			);
			await expect(
				visited.page.locator( 'script[src*="click-loader.js"]' )
			).toHaveCount( 0 );
			await expectClickToLoad( visited, label );
			await visited.context.close();
		} );
	}

	// What a script optimiser can do to the page. The loader is the tag with the
	// showfm-embed-click-loader-js id; the global is set by the "-before" inline script.
	const LOADER =
		/<script\b[^>]*\bid="showfm-embed-click-loader-js"[^>]*><\/script>/;
	const GLOBAL =
		/<script\b[^>]*\bid="showfm-embed-click-loader-js-before"[^>]*>[\s\S]*?<\/script>/;
	const stripDataSrc = ( html ) =>
		html.replace( LOADER, ( tag ) =>
			tag.replace( /\sdata-src="[^"]*"/, '' )
		);
	const dropGlobal = ( html ) => html.replace( GLOBAL, '' );
	// Inside a module script, document.currentScript is null, as when the loader runs from
	// a combined or delayed bundle.
	const loaderSource = readFileSync(
		join( __dirname, '../../assets/showfm-embed/click-loader-local.js' ),
		'utf8'
	);
	const withoutCurrentScript = ( html ) =>
		html.replace(
			LOADER,
			() => `<script type="module">${ loaderSource }</script>`
		);

	test( 'data-src stripped, the global still set: it loads from the plugin', async ( {
		browser,
	} ) => {
		const visited = await visit( browser, 'stripped', stripDataSrc );
		await expect(
			visited.page.locator( 'script[src*="click-loader-local.js"]' )
		).not.toHaveAttribute( 'data-src' );
		await expectClickToLoad( visited, 'Load episodes' );
		await visited.context.close();
	} );

	test( 'the global missing, data-src still set: it loads from the plugin', async ( {
		browser,
	} ) => {
		const visited = await visit( browser, 'no-global', dropGlobal );
		expect(
			await visited.page.evaluate( () => window.showfmEmbedSrc )
		).toBeUndefined();
		await expectClickToLoad( visited, 'Load episodes' );
		await visited.context.close();
	} );

	test( 'both missing: nothing loads and the fallback stays', async ( {
		browser,
	} ) => {
		const visited = await visit( browser, 'both', ( html ) =>
			dropGlobal( stripDataSrc( html ) )
		);
		await expectNothing( visited );
		await visited.context.close();
	} );

	test( 'no currentScript, the global set: it loads from the plugin', async ( {
		browser,
	} ) => {
		const visited = await visit( browser, 'module', withoutCurrentScript );
		await expect(
			visited.page.locator( 'script[src*="click-loader-local.js"]' )
		).toHaveCount( 0 );
		await expectClickToLoad( visited, 'Load episodes' );
		await visited.context.close();
	} );

	test( 'no currentScript and no global: nothing loads and the fallback stays', async ( {
		browser,
	} ) => {
		const visited = await visit( browser, 'module-only', ( html ) =>
			dropGlobal( withoutCurrentScript( html ) )
		);
		await expectNothing( visited );
		await visited.context.close();
	} );
} );
