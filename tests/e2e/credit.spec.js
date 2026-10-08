/**
 * "Powered by show.fm" on a free show, whose plan doesn't allow hiding it. The plugin passes
 * platform="wordpress", so with the default setting (credit="off") no element shows the
 * credit. With the Display tab's opt-in on, it shows. WordPress.org guideline 10.
 */
const { randomUUID } = require( 'node:crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

// Fresh IDs for each run: the server's background refresh marks unknown IDs unavailable.
const EPISODE = randomUUID();
const PODCAST = randomUUID();

// A free show: its branding payload doesn't allow hiding the credit.
const podcast = {
	id: PODCAST,
	slug: 'free-show',
	title: 'A Free Show',
	artwork: { url: null },
	links: { listen: 'https://free-show.show.fm' },
	branding: { show_powered_by: true },
};
const episode = {
	id: EPISODE,
	slug: 'first',
	title: 'The first episode',
	season_number: 1,
	episode_number: 1,
	published_at: '2026-09-24T09:00:00.000Z',
	audio: {
		url: 'https://m.cdn.media/e2e/first.mp3',
		content_type: 'audio/mpeg',
		duration_seconds: 1800,
	},
	artwork: { url: null },
	links: { listen: 'https://free-show.show.fm/e/first' },
	transcript: null,
	podcast,
};
const snapshot = {
	title: 'The first episode',
	listenUrl: 'https://free-show.show.fm/e/first',
	audioUrl: 'https://m.cdn.media/e2e/first.mp3',
};

/**
 * Sets the credit opt-in.
 *
 * @param {Object}  requestUtils Request utilities.
 * @param {boolean} on           Whether the site shows the credit.
 */
async function setCredit( requestUtils, on ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: { showfm_show_credit: on },
	} );
}

test.describe( '"Powered by show.fm" on a free show', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: '/wp/v2/plugins/showfm/showfm',
			data: { status: 'active' },
		} );
		post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/pages',
			data: {
				title: 'Credit on a free show',
				status: 'publish',
				content: [
					`<!-- wp:showfm/player ${ JSON.stringify( {
						episode: EPISODE,
						snapshot,
					} ) } /-->`,
					`<!-- wp:showfm/episodes ${ JSON.stringify( {
						podcast: PODCAST,
						snapshot,
					} ) } /-->`,
				].join( '\n' ),
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setCredit( requestUtils, false );
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ post.id }`,
			params: { force: true },
		} );
	} );

	for ( const [ label, on ] of [
		[ 'hidden by default', false ],
		[ 'shown when the site opts in', true ],
	] ) {
		test( label, async ( { browser, requestUtils } ) => {
			await setCredit( requestUtils, on );
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();
			const cors = { 'access-control-allow-origin': '*' };
			await page.route( 'https://api.show.fm/**', ( route ) => {
				const { pathname } = new URL( route.request().url() );
				const body = {
					[ `/v1/episodes/${ EPISODE }` ]: { data: episode },
					[ `/v1/podcasts/${ PODCAST }` ]: { data: podcast },
					[ `/v1/podcasts/${ PODCAST }/episodes` ]: {
						data: [ episode ],
						podcast,
						pagination: {},
					},
				}[ pathname ];
				return route.fulfill( {
					status: body ? 200 : 404,
					contentType: 'application/json',
					headers: cors,
					body: JSON.stringify(
						body || { error: { code: 'not_found' } }
					),
				} );
			} );
			await page.route( 'https://m.cdn.media/**', ( route ) =>
				route.fulfill( { status: 404, headers: cors } )
			);
			await page.goto( post.link );

			const player = page.locator( 'showfm-player' );
			const list = page.locator( 'showfm-episodes' );
			for ( const element of [ player, list ] ) {
				await expect( element ).toHaveAttribute(
					'platform',
					'wordpress'
				);
				await expect( element ).toHaveAttribute(
					'credit',
					on ? 'on' : 'off'
				);
			}
			// Both elements have upgraded and loaded the free show's data.
			for ( const element of [ player, list ] ) {
				await expect
					.poll( () =>
						element.evaluate(
							( node ) => node.shadowRoot?.textContent ?? ''
						)
					)
					.toContain( 'The first episode' );
			}

			const credit = page.getByText( /Powered by/ );
			if ( on ) {
				// Once per page, on the first embed.
				await expect( credit ).toHaveCount( 1 );
				await expect( credit ).toBeVisible();
			} else {
				await page.waitForTimeout( 500 );
				await expect( credit ).toHaveCount( 0 );
			}
			await context.close();
		} );
	}
} );
