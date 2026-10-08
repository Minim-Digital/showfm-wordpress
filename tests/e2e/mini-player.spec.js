/**
 * The Player block's Mini-player toggle: a published Player with it on hands its audio to
 * the page's mini-player when the visitor scrolls it out of view while it plays. Without the
 * toggle (the default), scrolling away opens no mini-player.
 */
const { randomUUID } = require( 'node:crypto' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Silent 8 kHz mono WAV audio, so the player can really play.
 *
 * @param {number} seconds Length.
 * @return {Buffer} WAV.
 */
function silentWav( seconds ) {
	const rate = 8000;
	const data = rate * seconds;
	const head = Buffer.alloc( 44 );
	head.write( 'RIFF', 0 );
	head.writeUInt32LE( 36 + data, 4 );
	head.write( 'WAVEfmt ', 8 );
	head.writeUInt32LE( 16, 16 );
	head.writeUInt16LE( 1, 20 );
	head.writeUInt16LE( 1, 22 );
	head.writeUInt32LE( rate, 24 );
	head.writeUInt32LE( rate, 28 );
	head.writeUInt16LE( 1, 32 );
	head.writeUInt16LE( 8, 34 );
	head.write( 'data', 36 );
	head.writeUInt32LE( data, 40 );
	return Buffer.concat( [ head, Buffer.alloc( data, 128 ) ] );
}

const audio = silentWav( 120 );
const TITLE = 'The mini-player episode';

/**
 * Answers the player's requests: the episode, and the audio with byte ranges.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function route( page ) {
	const cors = { 'access-control-allow-origin': '*' };
	await page.route( 'https://api.show.fm/**', ( request ) => {
		const id = new URL( request.request().url() ).pathname
			.split( '/' )
			.pop();
		return request.fulfill( {
			contentType: 'application/json',
			headers: cors,
			body: JSON.stringify( {
				data: {
					id,
					slug: 'mini',
					title: TITLE,
					published_at: '2026-09-24T09:00:00.000Z',
					audio: {
						url: 'https://m.cdn.media/e2e/mini.wav',
						content_type: 'audio/wav',
						duration_seconds: 120,
					},
					artwork: { url: null },
					links: { listen: 'https://mini.show.fm/e/mini' },
					transcript: null,
					podcast: {
						id: randomUUID(),
						slug: 'mini',
						title: 'Mini',
						links: { listen: 'https://mini.show.fm' },
						branding: { show_powered_by: true },
					},
				},
			} ),
		} );
	} );
	await page.route( 'https://m.cdn.media/**', ( request ) => {
		const range = /bytes=(\d+)-(\d*)/.exec(
			request.request().headers().range || ''
		);
		const start = range ? Number( range[ 1 ] ) : 0;
		const end = range?.[ 2 ] ? Number( range[ 2 ] ) : audio.length - 1;
		return request.fulfill( {
			status: range ? 206 : 200,
			body: audio.subarray( start, end + 1 ),
			contentType: 'audio/wav',
			headers: {
				...cors,
				'accept-ranges': 'bytes',
				...( range && {
					'content-range': `bytes ${ start }-${ end }/${ audio.length }`,
				} ),
			},
		} );
	} );
}

test.describe( 'Player block mini-player', () => {
	const pages = {};

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: '/wp/v2/plugins/showfm/showfm',
			data: { status: 'active' },
		} );
		for ( const on of [ true, false ] ) {
			const block = {
				// A fresh ID: the server's background refresh marks unknown IDs unavailable.
				episode: randomUUID(),
				snapshot: {
					title: TITLE,
					listenUrl: 'https://mini.show.fm/e/mini',
					audioUrl: 'https://m.cdn.media/e2e/mini.wav',
				},
				...( on && {
					'mini-player': 'on',
					'mini-player-position': 'left',
				} ),
			};
			pages[ on ] = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/pages',
				data: {
					title: `Mini-player ${ on ? 'on' : 'off' }`,
					status: 'publish',
					content: `<!-- wp:showfm/player ${ JSON.stringify(
						block
					) } /-->
<!-- wp:spacer {"height":"3000px"} -->
<div style="height:3000px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->`,
				},
			} );
		}
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const page of Object.values( pages ) ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/pages/${ page.id }`,
				params: { force: true },
			} );
		}
	} );

	for ( const on of [ true, false ] ) {
		test( `toggle ${
			on ? 'on: hands off' : 'off: no hand-off'
		} when scrolled out of view while playing`, async ( { browser } ) => {
			const context = await browser.newContext( {
				storageState: { cookies: [], origins: [] },
			} );
			const page = await context.newPage();
			await route( page );
			await page.goto( pages[ on ].link );

			const player = page.locator( 'showfm-player' );
			if ( on ) {
				await expect( player ).toHaveAttribute( 'mini-player', 'on' );
				await expect( player ).toHaveAttribute(
					'mini-player-position',
					'left'
				);
			} else {
				await expect( player ).not.toHaveAttribute( 'mini-player' );
			}
			await player
				.getByRole( 'button', { name: 'Play', exact: true } )
				.click();
			await expect(
				player.getByRole( 'button', { name: 'Pause', exact: true } )
			).toBeVisible();

			await page.evaluate( () => window.scrollTo( 0, 2500 ) );
			const mini = page.locator( 'showfm-mini-player' );
			if ( on ) {
				await expect( mini.getByRole( 'region' ) ).toBeVisible();
				await expect( mini ).toContainText( TITLE );
				// The same audio carries on: the player is still playing.
				await expect(
					player.getByRole( 'button', {
						name: 'Pause',
						exact: true,
					} )
				).toHaveCount( 1 );
			} else {
				await page.waitForTimeout( 1000 );
				await expect( mini.getByRole( 'region' ) ).toHaveCount( 0 );
			}
			await context.close();
		} );
	}
} );
