/**
 * The five WordPress.org screenshots, captured from the real plugin on wp-env at 1280px wide
 * (Claude Design D-W9, 8e to 8i). They are written to .wordpress-org/screenshot-1..5.png, in
 * the order of the captions in readme.txt.
 *
 * Skipped unless SHOWFM_SCREENSHOTS=1: run `npm run wporg:screenshots`. The show.fm API is
 * answered from fixtures, by the test plugins on the server and by `page.route` in the
 * browser, so nothing reaches show.fm.
 */
const path = require( 'node:path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const OUT = path.join( __dirname, '..', '..', '.wordpress-org' );
const PODCAST = '33333333-3333-4333-8333-333333333333';
const SOURDOUGH = '22222222-2222-4222-8222-222222222222';
const SOURDOUGH_TITLE =
	'Sourdough, salt and the slow return of the village bakery';

test.skip(
	! process.env.SHOWFM_SCREENSHOTS,
	'Set SHOWFM_SCREENSHOTS=1 to capture the WordPress.org screenshots.'
);

const art = ( colour ) =>
	`https://m.cdn.media/wporg/${ colour.slice( 1 ) }.svg`;
const podcast = {
	id: PODCAST,
	slug: 'the-long-table',
	title: 'The Long Table',
	artwork: { url: art( '#C8553D' ) },
	brand_color: '#7E22CE',
	links: { listen: 'https://the-long-table.show.fm' },
	branding: { show_powered_by: false },
};
const episodes = [
	[
		SOURDOUGH,
		'sourdough',
		SOURDOUGH_TITLE,
		4,
		'2026-09-24',
		3138,
		'#C8553D',
	],
	[
		'77777777-7777-4777-8777-777777777777',
		'casserole',
		'Why everyone’s grandmother made the same casserole',
		3,
		'2026-09-17',
		2710,
		'#3D7A5C',
	],
	[
		'55555555-5555-4555-8555-555555555555',
		'knives',
		'Knives',
		2,
		'2026-09-10',
		2340,
		'#2F4B7C',
	],
	[
		'88888888-8888-4888-8888-888888888888',
		'fishing-boat',
		'Feeding forty on a fishing boat',
		1,
		'2026-09-03',
		2985,
		'#B8860B',
	],
].map( ( [ id, slug, title, number, date, seconds, colour ] ) => ( {
	id,
	slug,
	title,
	description:
		'Stories about food, the people who make it and the tables we share it at.',
	season_number: 2,
	episode_number: number,
	episode_type: 'full',
	published_at: `${ date }T09:00:00.000Z`,
	audio: {
		url: `https://m.cdn.media/wporg/${ slug }.mp3`,
		content_type: 'audio/mpeg',
		duration_seconds: seconds,
	},
	artwork: { url: art( colour ) },
	links: { listen: `https://the-long-table.show.fm/e/${ slug }` },
	transcript:
		'sourdough' === slug
			? {
					url: 'https://m.cdn.media/wporg/sourdough.vtt',
					type: 'text/vtt',
				}
			: null,
	podcast,
} ) );

/**
 * Answers the elements' own browser requests: episode data, artwork and the transcript.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function routePublicApi( page ) {
	const cors = { 'access-control-allow-origin': '*' };
	await page.route( 'https://m.cdn.media/**', ( route ) => {
		const url = route.request().url();
		if ( url.endsWith( '.svg' ) ) {
			const colour = url.slice( -10, -4 );
			return route.fulfill( {
				contentType: 'image/svg+xml',
				headers: cors,
				body: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1" fill="#${ colour }"/></svg>`,
			} );
		}
		if ( url.endsWith( '.vtt' ) ) {
			return route.fulfill( {
				contentType: 'text/vtt',
				headers: cors,
				body: 'WEBVTT\n\n00:00.000 --> 00:05.000\n<v Tom>Welcome to the bakery.\n',
			} );
		}
		return route.fulfill( { status: 404 } );
	} );
	await page.route( 'https://api.show.fm/**', ( route ) => {
		const { pathname } = new URL( route.request().url() );
		const episode = episodes.find(
			( item ) => pathname === `/v1/episodes/${ item.id }`
		);
		let body = episode ? { data: episode } : null;
		if ( pathname === `/v1/podcasts/${ PODCAST }` ) {
			body = { data: podcast };
		} else if ( pathname === `/v1/podcasts/${ PODCAST }/episodes` ) {
			body = {
				data: episodes,
				podcast,
				pagination: { next_cursor: null },
			};
		}
		return route.fulfill( {
			status: body ? 200 : 404,
			contentType: 'application/json',
			headers: cors,
			body: JSON.stringify( body || { error: { code: 'not_found' } } ),
		} );
	} );
}

/**
 * Sets a test plugin's REST state.
 *
 * @param {Object} requestUtils Request utilities.
 * @param {string} route        Route under showfm-e2e/v1.
 * @param {Object} data         Body.
 * @return {Promise<Object>} Response.
 */
function setTest( requestUtils, route, data ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `/showfm-e2e/v1/${ route }`,
		data,
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
 * Saves the viewport as a screenshot, without the mouse over anything.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {number}                          n    Screenshot number.
 */
async function capture( page, n ) {
	const { width, height } = page.viewportSize();
	await page.mouse.move( width - 2, height - 2 );
	await page.waitForTimeout( 500 );
	await page.screenshot( {
		path: path.join( OUT, `screenshot-${ n }.png` ),
	} );
}

/**
 * Chooses the show by address, then an episode, in the block's placeholder.
 *
 * @param {Object} editor Editor utilities.
 * @param {string} title  Episode title, or none for a list.
 */
async function chooseByAddress( editor, title ) {
	const canvas = editor.canvas;
	await canvas
		.getByLabel( 'Show address or slug' )
		.last()
		.fill( 'the-long-table.show.fm' );
	await canvas.getByRole( 'button', { name: 'Continue' } ).last().click();
	if ( title ) {
		await canvas
			.getByRole( 'radio', { name: new RegExp( title.slice( 0, 20 ) ) } )
			.click();
		await canvas
			.getByRole( 'button', { name: 'Use this episode' } )
			.click();
	}
}

/**
 * Opens the block tab of the settings sidebar.
 *
 * @param {Object}                          editor Editor utilities.
 * @param {import('@playwright/test').Page} page   Page.
 * @return {import('@playwright/test').Locator} The sidebar.
 */
async function openBlockSettings( editor, page ) {
	await editor.openDocumentSettingsSidebar();
	const inspector = page.getByRole( 'region', { name: 'Editor settings' } );
	await inspector.getByRole( 'tab', { name: 'Block' } ).click();
	return inspector;
}

/**
 * Starts a post with a title and an opening paragraph.
 *
 * @param {Object} admin  Admin utilities.
 * @param {Object} editor Editor utilities.
 * @param {string} title  Title.
 * @param {string} text   Paragraph.
 */
async function startPost( admin, editor, title, text ) {
	await admin.createNewPost( { title } );
	// The top toolbar keeps the block toolbar from covering the post.
	await editor.setPreferences( 'core', { fixedToolbar: true } );
	await editor.insertBlock( {
		name: 'core/paragraph',
		attributes: { content: text },
	} );
}

test.describe( 'WordPress.org screenshots', () => {
	test.describe.configure( { mode: 'serial' } );
	test.use( { viewport: { width: 1280, height: 860 } } );

	let siteTitle;

	test.beforeAll( async ( { requestUtils } ) => {
		siteTitle = ( await requestUtils.getSiteSettings() ).title;
		await requestUtils.updateSiteSettings( { title: 'The Long Table' } );
		await setPlugin( requestUtils, 'showfm/showfm', 'active' );
		await setPlugin(
			requestUtils,
			'showfm-e2e-fixtures/showfm-e2e-fixtures',
			'active'
		);
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'active'
		);
		await setTest( requestUtils, 'reset', {} );
		await setTest( requestUtils, 'connection', { connected: false } );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils
			.rest( {
				method: 'POST',
				path: '/wp/v2/users/me',
				data: { meta: { persisted_preferences: {} } },
			} )
			.catch( () => {} );
		await setTest( requestUtils, 'migrate', { reset: true } ).catch(
			() => {}
		);
		await setTest( requestUtils, 'publishing', { activity: false } ).catch(
			() => {}
		);
		await setTest( requestUtils, 'state', {
			state: 'not_connected',
		} ).catch( () => {} );
		await setPlugin(
			requestUtils,
			'showfm-e2e-states/showfm-e2e-states',
			'inactive'
		);
		await setPlugin(
			requestUtils,
			'showfm-e2e-fixtures/showfm-e2e-fixtures',
			'inactive'
		);
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();
		await requestUtils.updateSiteSettings( { title: siteTitle } );
	} );

	test( '1. the Player block in the editor', async ( {
		admin,
		editor,
		page,
	} ) => {
		await routePublicApi( page );
		await startPost(
			admin,
			editor,
			'The slow return of the village bakery',
			'This week we visit three bakeries that brought bread back to their villages, and ask what it takes to keep an oven going at four in the morning.'
		);
		await editor.insertBlock( { name: 'showfm/player' } );
		await chooseByAddress( editor, SOURDOUGH_TITLE );
		const player = editor.canvas.locator(
			`showfm-player[episode="${ SOURDOUGH }"]`
		);
		await expect( player ).toHaveCount( 1 );
		const inspector = await openBlockSettings( editor, page );
		await inspector.getByLabel( 'Transcript' ).check();
		await expect( player ).toHaveAttribute( 'transcript', /on|open/ );
		await expect(
			player.locator( 'css=[part="play"]' ).first()
		).toBeVisible();
		await editor.canvas
			.locator( '[data-type="showfm/player"]' )
			.click( { position: { x: 4, y: 4 } } );
		await capture( page, 1 );
	} );

	test( '2. the Episode list block in Card style', async ( {
		admin,
		editor,
		page,
	} ) => {
		await routePublicApi( page );
		await startPost(
			admin,
			editor,
			'Every episode',
			'Catch up on the whole of season two.'
		);
		await editor.insertBlock( { name: 'showfm/episodes' } );
		await chooseByAddress( editor );
		const list = editor.canvas.locator(
			`showfm-episodes[podcast="${ PODCAST }"]`
		);
		await expect( list ).toHaveCount( 1 );
		const inspector = await openBlockSettings( editor, page );
		await inspector.getByRole( 'radio', { name: 'Card' } ).click();
		await inspector.getByLabel( 'Hide trailers' ).check();
		await inspector.getByLabel( 'Hide bonus episodes' ).check();
		await expect( list ).toHaveAttribute( 'hide', 'trailer,bonus' );
		await expect(
			list.getByText( 'Feeding forty on a fishing boat' )
		).toBeVisible();
		await inspector
			.getByRole( 'button', { name: 'Episodes', exact: true } )
			.evaluate( ( button ) =>
				button.scrollIntoView( { block: 'start' } )
			);
		await capture( page, 2 );
	} );

	test( '3. the Connection tab, connected', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await page.setViewportSize( { width: 1280, height: 800 } );
		// The admin screens use the states plugin alone: the fixtures plugin would answer
		// the Migrate tab's episode lists too.
		await setPlugin(
			requestUtils,
			'showfm-e2e-fixtures/showfm-e2e-fixtures',
			'inactive'
		);
		await setTest( requestUtils, 'state', {
			state: 'connected',
			site: 'https://thelongtable.co.uk',
		} );
		await admin.visitAdminPage( 'options-general.php', 'page=showfm' );
		await expect(
			page.getByText( 'https://thelongtable.co.uk' )
		).toBeVisible();
		await expect( page.getByText( 'Maya Lindgren' ) ).toBeVisible();
		await capture( page, 3 );
	} );

	test( '4. the Publishing tab with recent activity', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await page.setViewportSize( { width: 1280, height: 1200 } );
		await setTest( requestUtils, 'state', { state: 'connected' } );
		await setTest( requestUtils, 'publishing', { activity: true } );
		await admin.visitAdminPage(
			'options-general.php',
			'page=showfm&tab=publishing'
		);
		await expect(
			page.getByRole( 'tab', { name: 'Publishing' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await expect( page.getByText( 'The spice drawer' ) ).toBeVisible();
		await capture( page, 4 );
	} );

	test( '5. the Migrate tab’s dry run', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await page.setViewportSize( { width: 1280, height: 1100 } );
		await setTest( requestUtils, 'publishing', { activity: false } );
		await requestUtils.deleteAllPosts();
		await setTest( requestUtils, 'state', { state: 'connected' } );
		await setTest( requestUtils, 'migrate', {
			reset: true,
			catalogue: 'up',
			posts: true,
		} );
		await admin.visitAdminPage(
			'options-general.php',
			'page=showfm&tab=migrate'
		);
		await page.getByRole( 'button', { name: 'Scan posts' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: 'Dry run · 2 embeds in 2 posts',
			} )
		).toBeVisible();
		await capture( page, 5 );
	} );
} );
