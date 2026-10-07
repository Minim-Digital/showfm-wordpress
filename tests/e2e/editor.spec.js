/**
 * The block editor UI (WP-2b): each block inserts, picks a show and an episode, changes its
 * controls, previews the real element in the editor iframe, saves and renders on the front
 * end. Also the connected show picker with a scheduled episode, and the "show.fm" post panel.
 *
 * The test plugin `showfm-e2e-fixtures` answers the server's show.fm requests; the browser's
 * own element requests are answered here with `page.route`.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PODCAST = '33333333-3333-4333-8333-333333333333';
const SOURDOUGH = '22222222-2222-4222-8222-222222222222';
const KNIVES = '55555555-5555-4555-8555-555555555555';
const PUDDING = '66666666-6666-4666-8666-666666666666';
const SOURDOUGH_TITLE =
	'Sourdough, salt and the slow return of the village bakery';

const podcast = {
	id: PODCAST,
	slug: 'the-long-table',
	title: 'The Long Table',
	artwork: { url: null },
	brand_color: '#C8553D',
	links: { listen: 'https://the-long-table.show.fm' },
	branding: { show_powered_by: false },
};
const episode = ( id, slug, title ) => ( {
	id,
	slug,
	title,
	published_at: '2026-09-24T09:00:00.000Z',
	season_number: 2,
	episode_number: 4,
	audio: {
		url: `https://m.cdn.media/e2e/${ slug }.mp3`,
		content_type: 'audio/mpeg',
		duration_seconds: 3120,
	},
	artwork: { url: null },
	links: { listen: `https://the-long-table.show.fm/e/${ slug }` },
	transcript:
		slug === 'sourdough'
			? { url: 'https://m.cdn.media/e2e/sourdough.vtt', type: 'text/vtt' }
			: null,
	podcast,
} );

/**
 * Answers the elements' own requests from the browser to the public API.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function routePublicApi( page ) {
	await page.route( 'https://m.cdn.media/**', ( route ) =>
		route.request().url().endsWith( '.vtt' )
			? route.fulfill( {
					status: 200,
					contentType: 'text/vtt',
					headers: { 'access-control-allow-origin': '*' },
					body: 'WEBVTT\n\n00:00.000 --> 00:05.000\n<v Tom>Welcome to the bakery.\n\n00:05.000 --> 00:09.000\n<v Maya>It smells wonderful.\n',
				} )
			: route.fulfill( { status: 404 } )
	);
	await page.route( 'https://api.show.fm/**', ( route ) => {
		const path = new URL( route.request().url() ).pathname;
		const bodies = {
			[ `/v1/episodes/${ SOURDOUGH }` ]: {
				data: episode( SOURDOUGH, 'sourdough', SOURDOUGH_TITLE ),
			},
			[ `/v1/episodes/${ KNIVES }` ]: {
				data: episode( KNIVES, 'knives', 'Knives' ),
			},
			[ `/v1/podcasts/${ PODCAST }` ]: { data: podcast },
			[ `/v1/podcasts/${ PODCAST }/episodes` ]: {
				data: [ episode( SOURDOUGH, 'sourdough', SOURDOUGH_TITLE ) ],
				podcast,
				pagination: { next_cursor: null },
			},
		};
		const body = bodies[ path ];
		return route.fulfill( {
			status: body ? 200 : 404,
			contentType: 'application/json',
			headers: { 'access-control-allow-origin': '*' },
			body: JSON.stringify( body || { error: { code: 'not_found' } } ),
		} );
	} );
}

/**
 * Sets the site's connection through the test plugin.
 *
 * @param {Object}  requestUtils Request utils.
 * @param {boolean} connected    Whether to connect.
 */
async function setConnection( requestUtils, connected ) {
	// Start from an empty cache, so no answer from an earlier run is reused.
	await requestUtils.rest( { method: 'POST', path: '/showfm-e2e/v1/reset' } );
	await requestUtils.rest( {
		method: 'POST',
		path: '/showfm-e2e/v1/connection',
		data: { connected },
	} );
}

/**
 * Chooses a show by address, then an episode, in the block's placeholder.
 *
 * @param {Object} editor Editor utils.
 * @param {string} title  Episode title, or "Latest episode".
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
			.getByRole( 'radio', { name: new RegExp( title ) } )
			.click();
		await canvas
			.getByRole( 'button', { name: 'Use this episode' } )
			.click();
	}
}

/**
 * Publishes and returns the post's front-end URL.
 *
 * @param {Object}                          editor Editor utils.
 * @param {import('@playwright/test').Page} page   Page.
 * @return {Promise<string>} Link.
 */
async function publish( editor, page ) {
	await editor.publishPost();
	return page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getPermalink()
	);
}

test.describe( 'show.fm blocks in the editor', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'show-fm' );
		await requestUtils.activatePlugin( 'test-fixtures-for-show-fm' );
		await setConnection( requestUtils, false );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setConnection( requestUtils, false );
		await requestUtils.deleteAllPosts();
	} );

	test.beforeEach( async ( { admin, page } ) => {
		await routePublicApi( page );
		await admin.createNewPost();
	} );

	test( 'Player: address, episode, controls, heading level, front end', async ( {
		editor,
		page,
	} ) => {
		await editor.insertBlock( { name: 'showfm/player' } );
		await expect(
			editor.canvas.getByText( 'Connect to show.fm', { exact: true } )
		).toBeVisible();
		await chooseByAddress( editor, 'Sourdough' );

		const element = editor.canvas.locator(
			`showfm-player[episode="${ SOURDOUGH }"]`
		);
		await expect( element ).toHaveCount( 1 );
		// The real element, upgraded inside the editor iframe.
		await expect
			.poll( () =>
				element.evaluate(
					( node ) =>
						!! node.ownerDocument.defaultView.customElements.get(
							'showfm-player'
						)
				)
			)
			.toBe( true );

		await editor.openDocumentSettingsSidebar();
		await page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'tab', { name: 'Block' } )
			.click();
		const inspector = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await expect( inspector.getByText( SOURDOUGH_TITLE ) ).toBeVisible();
		await inspector.getByRole( 'radio', { name: 'Compact' } ).click();
		await inspector.getByLabel( 'Show the title as a heading' ).check();
		await editor.clickBlockToolbarButton( 'Change level' );
		await page.getByRole( 'menuitemradio', { name: 'Heading 4' } ).click();
		await expect( element ).toHaveAttribute( 'size', 'compact' );
		await expect( element ).toHaveAttribute( 'heading-level', '4' );

		const link = await publish( editor, page );
		await page.goto( link );
		const front = page.locator( `showfm-player[episode="${ SOURDOUGH }"]` );
		await expect( front ).toHaveAttribute( 'size', 'compact' );
		await expect( front ).toHaveAttribute( 'heading-level', '4' );
		// The insert-time snapshot is the server fallback before the cache is warm.
		const html = await ( await page.request.get( link ) ).text();
		expect( html ).toContain(
			'<a href="https://the-long-table.show.fm/e/sourdough">'
		);
		expect( html ).toContain(
			'<audio controls preload="none" src="https://m.cdn.media/e2e/sourdough.mp3"></audio>'
		);
	} );

	test( 'Episode list: show, count, style, front end', async ( {
		editor,
		page,
	} ) => {
		await editor.insertBlock( { name: 'core/heading' } );
		await editor.canvas
			.getByRole( 'document', { name: 'Block: Heading' } )
			.fill( 'Episodes' );
		await editor.insertBlock( { name: 'showfm/episodes' } );
		await chooseByAddress( editor );
		const element = editor.canvas.locator(
			`showfm-episodes[podcast="${ PODCAST }"]`
		);
		await expect( element ).toHaveCount( 1 );
		// One level below the H2 above it.
		await expect( element ).toHaveAttribute( 'heading-level', '3' );

		await editor.openDocumentSettingsSidebar();
		const inspector = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await inspector.getByRole( 'tab', { name: 'Block' } ).click();
		await inspector
			.getByRole( 'spinbutton', { name: 'Number of episodes' } )
			.fill( '3' );
		await inspector.getByRole( 'radio', { name: 'Minimal' } ).click();
		await inspector.getByLabel( 'Hide trailers' ).check();
		await expect( element ).toHaveAttribute( 'count', '3' );

		const link = await publish( editor, page );
		await page.goto( link );
		const front = page.locator( `showfm-episodes[podcast="${ PODCAST }"]` );
		await expect( front ).toHaveAttribute( 'count', '3' );
		await expect( front ).toHaveAttribute( 'variant', 'minimal' );
		await expect( front ).toHaveAttribute( 'hide', 'trailer' );
		await expect( front ).toHaveAttribute( 'heading-level', '3' );
	} );

	test( 'Play button and a Transcript following a Player, front end', async ( {
		editor,
		page,
	} ) => {
		await editor.insertBlock( { name: 'showfm/player' } );
		await chooseByAddress( editor, 'Sourdough' );

		await editor.insertBlock( { name: 'showfm/play' } );
		await chooseByAddress( editor, 'Knives' );
		await editor.openDocumentSettingsSidebar();
		const inspector = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await inspector.getByRole( 'tab', { name: 'Block' } ).click();
		await inspector.getByRole( 'radio', { name: 'Large' } ).click();
		await inspector.getByLabel( 'Mini-player' ).uncheck();
		await expect(
			inspector.getByText( 'Visitors can only play and pause.' ).first()
		).toBeVisible();

		await editor.insertBlock( { name: 'showfm/transcript' } );
		await editor.canvas
			.getByRole( 'button', { name: SOURDOUGH_TITLE.slice( 0, 30 ) } )
			.click();
		await inspector
			.getByRole( 'spinbutton', { name: 'Height' } )
			.fill( '400' );
		const transcript = editor.canvas.locator( 'showfm-transcript' );
		await expect( transcript ).toHaveAttribute( 'height', '400' );
		const followed = await transcript.getAttribute( 'for' );
		expect( followed ).toMatch( /^showfm-player-/ );

		const link = await publish( editor, page );
		await page.goto( link );
		await expect(
			page.locator( `showfm-play[episode="${ KNIVES }"]` )
		).toHaveAttribute( 'mini-player', 'off' );
		await expect(
			page.locator( `showfm-play[episode="${ KNIVES }"]` )
		).toHaveAttribute( 'size', 'lg' );
		await expect(
			page.locator( `showfm-player#${ followed }` )
		).toHaveCount( 1 );
		const front = page.locator( `showfm-transcript[for="${ followed }"]` );
		await expect( front ).toHaveAttribute( 'episode', SOURDOUGH );
		await expect( front ).toHaveAttribute( 'height', '400' );
	} );

	test( 'Connected: the account’s shows and a scheduled episode', async ( {
		editor,
		requestUtils,
	} ) => {
		await setConnection( requestUtils, true );
		try {
			await editor.page.reload();
			await editor.insertBlock( { name: 'showfm/player' } );
			const canvas = editor.canvas;
			await expect( canvas.getByText( 'Choose a show.' ) ).toBeVisible();
			await canvas
				.getByRole( 'button', { name: /The Long Table/ } )
				.click();
			await expect( canvas.getByText( 'Scheduled' ) ).toBeVisible();
			await canvas
				.getByRole( 'radio', { name: /Bread and butter pudding/ } )
				.click();
			await canvas
				.getByRole( 'button', { name: 'Use this episode' } )
				.click();
			await expect(
				canvas.getByText( /Goes live on 14 October at/ )
			).toBeVisible();
			await expect(
				canvas.getByText( 'Visitors won’t see this block until then.' )
			).toBeVisible();
		} finally {
			await setConnection( requestUtils, false );
		}
	} );

	test( 'Not found: the in-block message', async ( { editor } ) => {
		await editor.insertBlock( { name: 'showfm/player' } );
		await editor.canvas
			.getByLabel( 'Show address or slug' )
			.fill( 'no-such-show' );
		await editor.canvas.getByRole( 'button', { name: 'Continue' } ).click();
		await expect(
			editor.canvas.getByText( 'We couldn’t find that show or episode.' )
		).toBeVisible();
	} );
} );

test.describe( 'show.fm post panel', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'show-fm' );
		await requestUtils.activatePlugin( 'test-fixtures-for-show-fm' );
		await setConnection( requestUtils, true );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setConnection( requestUtils, false );
		await requestUtils.deleteAllPosts();
	} );

	/**
	 * Creates a post the way the sync leaves it.
	 *
	 * @param {Object} requestUtils Request utils.
	 * @param {Object} data         State and edited flag.
	 * @return {Promise<number>} Post ID.
	 */
	async function syncedPost( requestUtils, data ) {
		const { id } = await requestUtils.rest( {
			method: 'POST',
			path: '/showfm-e2e/v1/synced-post',
			data,
		} );
		return id;
	}

	/**
	 * Opens the post sidebar and the "show.fm" panel, which starts closed like other
	 * plugin panels.
	 *
	 * @param {Object}                          editor Editor utils.
	 * @param {import('@playwright/test').Page} page   Page.
	 * @return {Promise<import('@playwright/test').Locator>} The panel.
	 */
	async function openPanel( editor, page ) {
		await editor.openDocumentSettingsSidebar();
		const toggle = page.getByRole( 'button', {
			name: 'show.fm',
			exact: true,
		} );
		if ( ( await toggle.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
			await toggle.click();
		}
		return page.locator( '.showfm-post-panel' );
	}

	test( 'in sync, with the episode and Open in show.fm', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const id = await syncedPost( requestUtils, { state: 'synced' } );
		await admin.editPost( id );
		const panel = await openPanel( editor, page );
		await expect( panel.getByText( 'In sync with show.fm' ) ).toBeVisible();
		await expect(
			panel.getByText( /Updated from show.fm today at/ )
		).toBeVisible();
		await expect(
			panel.getByRole( 'combobox', { name: 'Episode for this post' } )
		).toHaveValue( SOURDOUGH_TITLE );
		await expect(
			panel.getByRole( 'link', { name: /Open in show.fm/ } )
		).toHaveAttribute(
			'href',
			'https://my.show.fm/p/the-long-table/e/sourdough'
		);
	} );

	test( 'edited here, then choosing another episode', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const id = await syncedPost( requestUtils, {
			state: 'synced',
			edited: true,
		} );
		await admin.editPost( id );
		const panel = await openPanel( editor, page );
		await expect(
			panel.getByText(
				'Edited here, so show.fm now only updates the date and status.'
			)
		).toBeVisible();
		const field = panel.getByRole( 'combobox', {
			name: 'Episode for this post',
		} );
		await field.fill( 'pudding' );
		await page
			.getByRole( 'option', { name: /Bread and butter pudding/ } )
			.click();
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save', exact: true } )
			.click();
		await expect
			.poll( async () => {
				const post = await requestUtils.rest( {
					path: `/wp/v2/posts/${ id }`,
					params: { context: 'edit' },
				} );
				return post.meta._showfm_episode_id;
			} )
			.toBe( PUDDING );
	} );

	test( 'deleted on show.fm', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const id = await syncedPost( requestUtils, { state: 'deleted' } );
		await admin.editPost( id );
		const panel = await openPanel( editor, page );
		await expect(
			panel.getByText( 'The episode was deleted on show.fm.' )
		).toBeVisible();
		await expect(
			panel.getByRole( 'link', { name: /Open in show.fm/ } )
		).toHaveCount( 0 );
	} );
} );
