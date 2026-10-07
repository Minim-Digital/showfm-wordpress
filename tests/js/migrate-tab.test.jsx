import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import MigrateTab from '../../src/admin/migrate-tab';
import {
	MIGRATE_PATH,
	notChangedText,
	percent,
	problemAction,
	resumeStep,
	skipNote,
} from '../../src/admin/migrate-view';
import { view as connection } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );
vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const RUN = '12d47293-8beb-44e4-9112-8ccc50b40070';
const BAKERY = '21111111-2222-4333-8444-555555555555';
const BAKERY2 = '41111111-2222-4333-8444-555555555555';

const HOSTS = [
	'Buzzsprout',
	'Libsyn',
	'Captivate',
	'Transistor',
	'Spotify',
	'Podbean',
	'PowerPress',
	'Seriously Simple Podcasting',
];

/**
 * The tab's view as `GET /showfm/v1/admin/migrate` returns it.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} View.
 */
function migrate( overrides = {} ) {
	return {
		connected: true,
		connectUrl:
			'https://thelongtable.co/wp-admin/options-general.php?page=showfm&tab=connection',
		hosts: HOSTS,
		posts: 1280,
		lease: null,
		phase: 'intro',
		run: '',
		scan: null,
		report: null,
		swap: null,
		problem: null,
		...overrides,
	};
}

/**
 * A scan under way.
 *
 * @param {Object} scan Scan fields to replace.
 * @return {Object} View.
 */
function scanning( scan = {} ) {
	return migrate( {
		phase: 'scanning',
		run: RUN,
		lease: { mine: true, name: 'Maya Lindgren' },
		scan: {
			checked: 412,
			total: 1280,
			found: 17,
			catalogue: false,
			stopped: false,
			...scan,
		},
	} );
}

/**
 * A finished dry run.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} View.
 */
function reported( overrides = {} ) {
	return migrate( {
		phase: 'report',
		run: RUN,
		report: {
			counts: {
				ready: 31,
				choose: 4,
				unmatched: 2,
				already: 6,
				review: 0,
			},
			embeds: 43,
			posts: 39,
			chosen: 1,
			swap: { embeds: 32, posts: 29 },
			ago: '2 minutes ago',
		},
		...overrides,
	} );
}

/**
 * A finished swap.
 *
 * @param {Object} swap Swap fields to replace.
 * @return {Object} View.
 */
function results( swap = {} ) {
	return reported( {
		phase: 'results',
		swap: {
			total: 29,
			checked: 29,
			posts: 29,
			embeds: 32,
			failed: 0,
			finished: 'Today, 10:42',
			...swap,
		},
	} );
}

/**
 * A report row.
 *
 * @param {string} group  Group.
 * @param {number} index  Row number.
 * @param {Object} fields Fields to replace.
 * @return {Object} Row.
 */
function row( group, index, fields = {} ) {
	return {
		id: `${ 100 + index }:1`,
		post: {
			id: 100 + index,
			title: `Post ${ index }`,
			url: `https://thelongtable.co/post-${ index }/`,
			date: '24 Sept 2026',
		},
		embed: 1,
		host: 'Buzzsprout',
		ref: 'buzzsprout.com/…/1572',
		group,
		status: group,
		method: 'Audio file',
		episode: group === 'ready' ? `Episode ${ index }` : '',
		...fields,
	};
}

/** Rows for each group, as the rows route pages them. */
const ROWS = {
	ready: Array.from( { length: 31 }, ( _, i ) => row( 'ready', i ) ),
	choose: [
		row( 'choose', 40, {
			host: 'Captivate',
			method: 'Title and date',
			status: 'chosen',
			choice: BAKERY,
			canChoose: true,
			candidates: [
				{ id: BAKERY, title: 'The village bakery', date: '1 Mar 2024' },
				{
					id: BAKERY2,
					title: 'The village bakery, part two',
					date: '1 Mar 2024',
				},
			],
		} ),
		...[ 41, 42, 43 ].map( ( index ) =>
			row( 'choose', index, {
				method: 'Title and date',
				choice: '',
				canChoose: true,
				candidates: [
					{
						id: BAKERY,
						title: 'The village bakery',
						date: '1 Mar 2024',
					},
					{
						id: BAKERY2,
						title: 'The village bakery, part two',
						date: '',
					},
				],
			} )
		),
	],
	unmatched: [ 50, 51 ].map( ( index ) =>
		row( 'unmatched', index, { host: 'Spotify', method: 'None' } )
	),
	already: Array.from( { length: 6 }, ( _, i ) =>
		row( 'already', 60 + i, {
			host: 'show.fm block',
			ref: 'showfm/player',
			method: 'None',
		} )
	),
	changed: Array.from( { length: 29 }, ( _, i ) => ( {
		id: String( 200 + i ),
		post: {
			id: 200 + i,
			title: `Changed ${ i }`,
			url: `https://thelongtable.co/changed-${ i }/`,
			date: '',
		},
		change: 'Buzzsprout embed → show.fm Player',
		revisionUrl: `https://thelongtable.co/wp-admin/revision.php?revision=${
			900 + i
		}`,
	} ) ),
	failed: [
		{
			id: '300',
			post: {
				id: 300,
				title: 'Edited meanwhile',
				url: 'https://thelongtable.co/edited/',
				date: '',
			},
			reason: 'The post or source player changed after scanning. Start a new scan.',
		},
	],
};

/**
 * Answers the tab's requests: the view, rows by group, and any step the test sets.
 *
 * @param {Object} first    The first view.
 * @param {Object} handlers Answers by step (`scan`, `swap`, `stop`, `choice`): a view, an
 *                          error, a function of the request, or a list used in order.
 * @return {Object[]} Calls made, as their options.
 */
function serve( first, handlers = {} ) {
	const calls = [];
	const queues = Object.fromEntries(
		Object.entries( handlers ).map( ( [ key, value ] ) => [
			key,
			Array.isArray( value ) ? [ ...value ] : value,
		] )
	);
	let current = first;
	apiFetch.mockImplementation( ( options ) => {
		calls.push( options );
		const { path } = options;
		if ( path === MIGRATE_PATH ) {
			return Promise.resolve( current );
		}
		if ( path.startsWith( `${ MIGRATE_PATH }/rows` ) ) {
			const query = new URLSearchParams( path.split( '?' )[ 1 ] );
			const all = ROWS[ query.get( 'group' ) ] ?? [];
			const offset = Number( query.get( 'offset' ) );
			return Promise.resolve( {
				rows: all.slice(
					offset,
					offset + Number( query.get( 'limit' ) )
				),
				total: all.length,
			} );
		}
		const step = path.slice( MIGRATE_PATH.length + 1 );
		const handler = queues[ step ];
		const next = Array.isArray( handler ) ? handler.shift() : handler;
		const answer = typeof next === 'function' ? next( options ) : next;
		if ( answer instanceof Error || answer?.code ) {
			return Promise.reject( answer );
		}
		current = answer ?? current;
		return Promise.resolve( current );
	} );
	return calls;
}

/**
 * An answer that arrives on a later tick, so the tab draws each step.
 *
 * @param {Object} answer View.
 * @return {() => Promise<Object>} Handler.
 */
function later( answer ) {
	return () =>
		new Promise( ( resolve ) => setTimeout( () => resolve( answer ), 5 ) );
}

/**
 * A refused step, as api-fetch rejects it.
 *
 * @param {string} reason  Reason.
 * @param {string} message Message.
 * @param {Object} viewNow The fresh view.
 * @return {Object} Error.
 */
function refusal( reason, message, viewNow ) {
	return {
		code: `showfm_migration_${ reason }`,
		message,
		data: { status: 409, reason, retryAt: 0, view: viewNow },
	};
}

/**
 * The step requests made, by kind.
 *
 * @param {Object[]} calls Calls.
 * @param {string}   kind  Step.
 * @return {Object[]} Calls.
 */
function steps( calls, kind ) {
	return calls.filter(
		( call ) => call.path === `${ MIGRATE_PATH }/${ kind }`
	);
}

describe( 'Migrate tab helpers', () => {
	it( 'works out the per cent, the notes and the problem actions', () => {
		expect( percent( 412, 1280 ) ).toBe( 32 );
		expect( percent( 0, 0 ) ).toBe( 0 );
		expect( skipNote( reported().report ) ).toBe(
			'3 embeds still need a choice and will be skipped. Embeds with no match, and ones already on show.fm, stay as they are.'
		);
		expect( notChangedText( { ...reported().report, chosen: 1 } ) ).toBe(
			'Not changed: 3 embeds that needed a choice, 2 with no match and 6 already on show.fm.'
		);
		expect( problemAction( 'connection_lost' ) ).toBe( 'reconnect' );
		expect( problemAction( 'not_connected' ) ).toBe( 'reconnect' );
		expect( problemAction( 'rate_limited' ) ).toBe( 'retry' );
		expect( problemAction( 'unreachable' ) ).toBe( 'retry' );
		expect( problemAction( 'busy' ) ).toBe( 'refresh' );
		expect( problemAction( 'stale' ) ).toBe( '' );
	} );

	it( 'carries on only its own, unpaused, untroubled work', () => {
		expect( resumeStep( scanning() ) ).toBe( 'scan' );
		expect( resumeStep( scanning( { stopped: true } ) ) ).toBe( '' );
		expect(
			resumeStep( { ...scanning(), lease: { mine: false, name: 'Tom' } } )
		).toBe( '' );
		expect(
			resumeStep( {
				...scanning(),
				problem: { reason: 'rate_limited', message: '', retryAt: 1 },
			} )
		).toBe( '' );
		expect(
			resumeStep( migrate( { phase: 'swapping', swap: { mine: true } } ) )
		).toBe( 'swap' );
		expect(
			resumeStep(
				migrate( { phase: 'swapping', swap: { mine: false } } )
			)
		).toBe( '' );
		expect( resumeStep( reported() ) ).toBe( '' );
		expect( resumeStep( { ...scanning(), connected: false } ) ).toBe( '' );
	} );
} );

describe( 'Migrate tab', () => {
	it( 'introduces the migrator with the hosts it detects (4a)', async () => {
		serve( migrate() );
		render( <MigrateTab connection={ connection() } /> );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Swap old podcast embeds for show.fm blocks',
			} )
		).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( { path: MIGRATE_PATH } );
		expect(
			within( screen.getByRole( 'list' ) )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( HOSTS );
		expect(
			screen.getByText( 'Nothing changes until you confirm.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Checks 1,280 published posts and pages. Usually takes under a minute.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Scan posts' } )
		).toBeEnabled();
	} );

	it( 'says a connection is needed and connects with the form when not connected', async () => {
		const submit = vi
			.spyOn( window.HTMLFormElement.prototype, 'requestSubmit' )
			.mockImplementation( () => {} );
		serve( migrate( { connected: false } ) );
		const { container } = render(
			<MigrateTab connection={ connection( { state: 'expired' } ) } />
		);

		expect(
			await screen.findByText(
				'Connect this site to show.fm to swap old embeds. Matching needs your connected shows, so it can’t run without a connection.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Scan posts' } )
		).toBeNull();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Reconnect to show.fm' } )
		);
		expect( submit ).toHaveBeenCalledTimes( 1 );
		const form = container.querySelector( 'form' );
		expect( form ).toHaveAttribute(
			'action',
			'https://thelongtable.co/wp-admin/admin-post.php'
		);
		expect( form.querySelector( '[name="_wpnonce"]' ) ).toHaveValue(
			'abc123'
		);
	} );

	it( 'scans in steps with progress, then shows the report and focuses it (4b, 4c)', async () => {
		let release;
		let finish;
		const calls = serve( migrate(), {
			scan: [
				scanning( { catalogue: true, checked: 0, found: 0 } ),
				() =>
					new Promise( ( resolve ) => {
						release = () => resolve( scanning() );
					} ),
				() =>
					new Promise( ( resolve ) => {
						finish = () => resolve( reported() );
					} ),
			],
		} );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Scan posts' } )
		);

		const heading = await screen.findByRole( 'heading', {
			name: 'Scanning your posts',
		} );
		await waitFor( () => expect( heading ).toHaveFocus() );
		expect(
			screen.getByText( 'Getting your episodes from show.fm' )
		).toBeInTheDocument();
		await act( async () => release() );
		expect(
			await screen.findByText( 'Checked 412 of 1,280' )
		).toBeInTheDocument();
		expect( screen.getByText( '32%' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'progressbar' ) ).toHaveAttribute(
			'aria-valuenow',
			'32'
		);
		expect(
			screen.getByText(
				'Found 17 embeds so far. If you leave this page, the scan carries on from here when you come back.'
			)
		).toBeInTheDocument();

		await act( async () => finish() );
		const report = await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );
		await waitFor( () => expect( report ).toHaveFocus() );
		expect( steps( calls, 'scan' ).map( ( call ) => call.data ) ).toEqual( [
			{},
			{ run: RUN },
			{ run: RUN },
		] );
	} );

	it( 'shows the dry-run report by group, with how each matched and a link to each post (4c)', async () => {
		serve( reported() );
		render( <MigrateTab connection={ connection() } /> );

		await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );
		expect(
			screen.getByText(
				'Scanned 2 minutes ago · nothing has changed yet'
			)
		).toBeInTheDocument();
		const tiles = screen.getAllByRole( 'list' )[ 0 ];
		expect(
			within( tiles )
				.getAllByRole( 'listitem' )
				.map( ( item ) => item.textContent )
		).toEqual( [
			'31Ready to swap',
			'4Need your choice',
			'2No match',
			'6Already show.fm',
		] );

		const table = screen.getByRole( 'table' );
		expect(
			within( table )
				.getAllByRole( 'columnheader' )
				.map( ( cell ) => cell.textContent )
		).toEqual( [
			'Post',
			'Current embed',
			'Matched episode',
			'How it matched',
			'Status',
		] );
		await within( table ).findByRole( 'link', { name: 'Post 0' } );
		for ( const name of [
			'Ready to swap',
			'Needs your choice',
			'No match',
			'Already show.fm',
		] ) {
			expect( within( table ).getByText( name ) ).toBeInTheDocument();
		}
		expect( within( table ).queryByText( 'Check by hand' ) ).toBeNull();
		expect(
			within( table ).getByRole( 'link', { name: 'Post 0' } )
		).toHaveAttribute( 'href', 'https://thelongtable.co/post-0/' );
		const first = within( table )
			.getByRole( 'link', { name: 'Post 0' } )
			.closest( 'tr' );
		expect( first ).toHaveTextContent( 'Buzzsprout' );
		expect( first ).toHaveTextContent( 'buzzsprout.com/…/1572' );
		expect( first ).toHaveTextContent( 'Episode 0' );
		expect( first ).toHaveTextContent( 'Audio file' );
		expect( first ).toHaveTextContent( 'Ready' );
		const unmatched = within( table )
			.getByRole( 'link', { name: 'Post 50' } )
			.closest( 'tr' );
		expect( unmatched ).toHaveTextContent( 'No matching episode' );
		expect( unmatched ).toHaveTextContent( 'Left as is' );
		expect(
			within( table )
				.getByRole( 'link', { name: 'Post 60' } )
				.closest( 'tr' )
		).toHaveTextContent( 'Nothing to do' );

		expect(
			screen.getByRole( 'button', { name: 'Swap 32 embeds' } )
		).toBeEnabled();
		expect(
			screen.getByText(
				'3 embeds still need a choice and will be skipped. Embeds with no match, and ones already on show.fm, stay as they are.'
			)
		).toBeInTheDocument();
	} );

	it( 'loads more rows and focuses the first new one', async () => {
		serve( reported() );
		render( <MigrateTab connection={ connection() } /> );

		const more = await screen.findByRole( 'button', {
			name: 'Show 26 more',
		} );
		expect(
			screen.getAllByRole( 'link', { name: /^Post \d+$/ } )
		).toHaveLength( 5 + 4 + 2 + 5 );
		await userEvent.click( more );

		const next = await screen.findByRole( 'link', { name: 'Post 5' } );
		await waitFor( () => expect( next ).toHaveFocus() );
		expect(
			screen.getByRole( 'link', { name: 'Post 30' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /^Show \d+ more$/ } )
		).not.toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Show 1 more' } )
		).toBeInTheDocument();
	} );

	it( 'stores a pick from the candidates and keeps focus on it', async () => {
		const calls = serve( reported(), {
			choice: ( { data } ) => ( {
				choice: data.episode,
				...reported( {
					report: {
						...reported().report,
						chosen: 2,
						swap: { embeds: 33, posts: 30 },
					},
				} ),
			} ),
		} );
		render( <MigrateTab connection={ connection() } /> );

		const pick = await screen.findByRole( 'combobox', {
			name: 'Episode for “Post 41”',
		} );
		expect( pick ).toHaveValue( '' );
		expect(
			within( pick )
				.getAllByRole( 'option' )
				.map( ( o ) => o.textContent )
		).toEqual( [
			'Choose an episode',
			'The village bakery (1 Mar 2024)',
			'The village bakery, part two',
		] );
		expect( pick.closest( 'tr' ) ).toHaveTextContent( 'Choose one' );
		expect( pick.closest( 'tr' ) ).toHaveTextContent(
			'2 possible matches'
		);
		expect(
			screen
				.getByRole( 'combobox', { name: 'Episode for “Post 40”' } )
				.closest( 'tr' )
		).toHaveTextContent( 'Ready' );

		await userEvent.selectOptions( pick, BAKERY2 );

		expect( steps( calls, 'choice' )[ 0 ].data ).toEqual( {
			run: RUN,
			post: 141,
			embed: 1,
			episode: BAKERY2,
		} );
		expect(
			await screen.findByRole( 'button', { name: 'Swap 33 embeds' } )
		).toBeInTheDocument();
		expect( pick ).toHaveValue( BAKERY2 );
		expect( pick ).toHaveFocus();
		expect( pick.closest( 'tr' ) ).toHaveTextContent( 'Ready' );
	} );

	it( 'puts a refused pick back and says why', async () => {
		serve( reported(), {
			choice: {
				code: 'showfm_migration_invalid_choice',
				message: 'Choose one of the episodes listed for this embed.',
				data: {
					status: 400,
					reason: 'invalid_choice',
					view: reported(),
				},
			},
		} );
		render( <MigrateTab connection={ connection() } /> );

		const pick = await screen.findByRole( 'combobox', {
			name: 'Episode for “Post 41”',
		} );
		await userEvent.selectOptions( pick, BAKERY );

		const notice = await screen.findByText(
			'Choose one of the episodes listed for this embed.',
			{ selector: 'p' }
		);
		await waitFor( () =>
			expect( notice.closest( '.showfm-migrate__problem' ) ).toHaveFocus()
		);
		expect( pick ).toHaveValue( '' );
	} );

	it( 'confirms the swap with the count and the undo, and cancels back to the button (4d)', async () => {
		serve( reported() );
		render( <MigrateTab connection={ connection() } /> );

		const button = await screen.findByRole( 'button', {
			name: 'Swap 32 embeds',
		} );
		await userEvent.click( button );

		const dialog = screen.getByRole( 'dialog', {
			name: 'Swap 32 embeds?',
		} );
		expect( dialog ).toHaveTextContent(
			'We’ll replace them with show.fm Player blocks in 29 posts. Nothing else in those posts changes, and they stay published.'
		);
		expect( dialog ).toHaveTextContent(
			'WordPress keeps a revision of every post we change, so you can restore any of them.'
		);
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		await waitFor( () => expect( button ).toHaveFocus() );
	} );

	it( 'swaps in steps and shows what changed with each revision (4e)', async () => {
		const calls = serve( reported(), {
			swap: [
				migrate( {
					phase: 'swapping',
					run: RUN,
					lease: { mine: true, name: 'Maya' },
					report: reported().report,
					swap: {
						total: 29,
						checked: 5,
						posts: 5,
						embeds: 6,
						failed: 0,
						finished: '',
						mine: true,
						by: 'Maya',
					},
				} ),
				results(),
			],
		} );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Swap 32 embeds' } )
		);
		await userEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Swap 32 embeds',
			} )
		);

		const heading = await screen.findByRole( 'heading', {
			name: 'What changed',
		} );
		await waitFor( () => expect( heading ).toHaveFocus() );
		expect( steps( calls, 'swap' ).map( ( call ) => call.data ) ).toEqual( [
			{ run: RUN, confirm: true },
			{ run: RUN },
		] );
		expect(
			screen.getByText( 'Swapped 32 embeds in 29 posts.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'WordPress keeps a revision of every post we change.',
				{ exact: false, selector: 'p' }
			)
		).toBeInTheDocument();
		expect( screen.getByText( 'Today, 10:42' ) ).toBeInTheDocument();
		const post = await screen.findByRole( 'link', { name: 'Changed 0' } );
		expect( post ).toHaveAttribute(
			'href',
			'https://thelongtable.co/changed-0/'
		);
		expect( post.closest( 'tr' ) ).toHaveTextContent(
			'Buzzsprout embed → show.fm Player'
		);
		expect(
			within( post.closest( 'tr' ) ).getByRole( 'link', {
				name: 'Compare revisions',
			} )
		).toHaveAttribute(
			'href',
			'https://thelongtable.co/wp-admin/revision.php?revision=900'
		);
		expect(
			screen.getByRole( 'button', { name: 'Show all 29 posts' } )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Not changed: 3 embeds that needed a choice, 2 with no match and 6 already on show.fm.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows the swapping step with its progress', async () => {
		serve(
			migrate( {
				phase: 'swapping',
				run: RUN,
				lease: { mine: false, name: 'Tom Reyes' },
				swap: {
					total: 29,
					checked: 10,
					posts: 10,
					embeds: 11,
					failed: 0,
					finished: '',
				},
			} )
		);
		render( <MigrateTab connection={ connection() } /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Swapping embeds' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Changed 10 of 29 posts' )
		).toBeInTheDocument();
		expect( screen.getByRole( 'progressbar' ) ).toHaveAttribute(
			'aria-valuenow',
			'34'
		);
		expect(
			screen.getByText(
				'Tom Reyes is running a scan or swap. This page follows their progress.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Resume swapping' } )
		).toBeNull();
	} );

	it( 'lists posts that couldn’t change, with the reason', async () => {
		serve( results( { failed: 1, posts: 28 } ) );
		render( <MigrateTab connection={ connection() } /> );

		expect(
			await screen.findByText(
				'1 post couldn’t be changed. It’s listed below with the reason.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
		const failed = await screen.findByRole( 'link', {
			name: 'Edited meanwhile',
		} );
		expect( failed.closest( 'tr' ) ).toHaveTextContent(
			'The post or source player changed after scanning. Start a new scan.'
		);
	} );

	it( 'reviews the report after the swap, read only, and goes back', async () => {
		serve( results() );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Review them' } )
		);
		const heading = await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );
		await waitFor( () => expect( heading ).toHaveFocus() );
		expect(
			screen.getByText( 'Scanned 2 minutes ago' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /^Swap/ } ) ).toBeNull();
		expect(
			await screen.findByRole( 'combobox', {
				name: 'Episode for “Post 41”',
			} )
		).toBeDisabled();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Back to the results' } )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'What changed' } )
			).toHaveFocus()
		);
	} );

	it( 'says when there is nothing to migrate (4f)', async () => {
		const calls = serve(
			migrate( {
				phase: 'empty',
				run: RUN,
				report: {
					...reported().report,
					counts: {
						ready: 0,
						choose: 0,
						unmatched: 0,
						already: 0,
						review: 0,
					},
					embeds: 0,
					posts: 0,
				},
			} ),
			{ scan: [ scanning(), reported() ] }
		);
		render( <MigrateTab connection={ connection() } /> );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Nothing to migrate.',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'We checked 1,280 published posts and pages and didn’t find embeds from other podcast hosts.'
			)
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Scan again' } )
		);
		await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );
		expect( steps( calls, 'scan' )[ 0 ].data ).toEqual( { restart: true } );
	} );

	it( 'carries on a scan after a reload, and stops and resumes it', async () => {
		let release;
		const calls = serve( scanning(), {
			scan: [
				() =>
					new Promise( ( resolve ) => {
						release = () => resolve( scanning( { checked: 462 } ) );
					} ),
				scanning( { checked: 512 } ),
				reported(),
			],
			stop: () => scanning( { checked: 462, stopped: true } ),
		} );
		render( <MigrateTab connection={ connection() } /> );

		const stop = await screen.findByRole( 'button', {
			name: 'Stop scanning',
		} );
		expect( steps( calls, 'scan' )[ 0 ].data ).toEqual( { run: RUN } );
		await userEvent.click( stop );
		expect( steps( calls, 'stop' ) ).toHaveLength( 0 );
		await act( async () => release() );

		expect(
			await screen.findByRole( 'heading', { name: 'Scan paused' } )
		).toBeInTheDocument();
		expect( steps( calls, 'stop' ) ).toHaveLength( 1 );
		expect( steps( calls, 'scan' ) ).toHaveLength( 1 );
		expect(
			screen.getByText( 'Checked 462 of 1,280' )
		).toBeInTheDocument();

		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Scan paused' } )
			).toHaveFocus()
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Resume scanning' } )
		);
		await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );
		expect( steps( calls, 'scan' ) ).toHaveLength( 3 );
	} );

	it( 'keeps focus on the heading when Resume scanning goes away', async () => {
		let release;
		serve( scanning( { stopped: true } ), {
			scan: () =>
				new Promise( ( resolve ) => {
					release = () => resolve( reported() );
				} ),
		} );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Resume scanning' } )
		);

		const heading = await screen.findByRole( 'heading', {
			name: 'Scanning your posts',
		} );
		await waitFor( () => expect( heading ).toHaveFocus() );
		await act( async () => release() );
	} );

	it( 'saves picks one at a time per row and ends on the pick the server stored', async () => {
		const answers = [];
		const calls = serve( reported(), {
			choice: ( { data } ) =>
				new Promise( ( resolve ) => {
					answers.push( () =>
						resolve( {
							// The second save is stored as cleared, to show the row follows the server.
							choice: answers.length === 1 ? data.episode : '',
							...reported(),
						} )
					);
				} ),
		} );
		render( <MigrateTab connection={ connection() } /> );

		const pick = await screen.findByRole( 'combobox', {
			name: 'Episode for “Post 41”',
		} );
		await userEvent.selectOptions( pick, BAKERY );
		await userEvent.selectOptions( pick, BAKERY2 );

		expect( steps( calls, 'choice' ) ).toHaveLength( 1 );
		expect( pick ).toHaveValue( BAKERY2 );
		expect(
			screen.getByRole( 'button', { name: 'Swap 32 embeds' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		await act( async () => answers[ 0 ]() );
		await waitFor( () =>
			expect( steps( calls, 'choice' ) ).toHaveLength( 2 )
		);
		expect(
			steps( calls, 'choice' ).map( ( call ) => call.data.episode )
		).toEqual( [ BAKERY, BAKERY2 ] );
		expect(
			screen.getByRole( 'button', { name: 'Swap 32 embeds' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		await act( async () => answers[ 1 ]() );
		await waitFor( () => expect( pick ).toHaveValue( '' ) );
		expect( pick.closest( 'tr' ) ).toHaveTextContent( 'Choose one' );
		expect(
			screen.getByRole( 'button', { name: 'Swap 32 embeds' } )
		).not.toHaveAttribute( 'aria-disabled' );
	} );

	it( 'carries on the admin’s own swap after a reload', async () => {
		const calls = serve(
			migrate( {
				phase: 'swapping',
				run: RUN,
				swap: {
					total: 29,
					checked: 10,
					posts: 10,
					embeds: 11,
					failed: 0,
					finished: '',
					mine: true,
					by: 'Maya Lindgren',
				},
			} ),
			{ swap: [ results() ] }
		);
		render( <MigrateTab connection={ connection() } /> );

		await screen.findByRole( 'heading', { name: 'What changed' } );
		expect( steps( calls, 'swap' ).map( ( call ) => call.data ) ).toEqual( [
			{ run: RUN },
		] );
	} );

	it( 'asks before carrying on a swap another admin started and left', async () => {
		const calls = serve(
			migrate( {
				phase: 'swapping',
				run: RUN,
				swap: {
					total: 29,
					checked: 10,
					posts: 10,
					embeds: 11,
					failed: 0,
					finished: '',
					mine: false,
					by: 'Tom Reyes',
				},
			} ),
			{ swap: [ results() ] }
		);
		render( <MigrateTab connection={ connection() } /> );

		const resume = await screen.findByRole( 'button', {
			name: 'Resume swapping',
		} );
		expect( steps( calls, 'swap' ) ).toHaveLength( 0 );
		await userEvent.click( resume );

		const dialog = screen.getByRole( 'dialog', {
			name: 'Carry on swapping?',
		} );
		expect( dialog ).toHaveTextContent(
			'Tom Reyes started this swap. 19 of 29 posts are still to change.'
		);
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( steps( calls, 'swap' ) ).toHaveLength( 0 );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Resume swapping' } )
		);
		await userEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Carry on swapping',
			} )
		);
		await screen.findByRole( 'heading', { name: 'What changed' } );
		expect( steps( calls, 'swap' )[ 0 ].data ).toEqual( {
			run: RUN,
			confirm: true,
		} );
	} );

	it( 'reads out progress politely each quarter of the way', async () => {
		serve( migrate(), {
			scan: [
				later( scanning( { checked: 100, total: 1000, found: 2 } ) ),
				later( scanning( { checked: 150, total: 1000, found: 3 } ) ),
				later( scanning( { checked: 300, total: 1000, found: 9 } ) ),
				later( scanning( { checked: 320, total: 1000, found: 9 } ) ),
				later( scanning( { checked: 600, total: 1000, found: 17 } ) ),
				later( reported() ),
			],
		} );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Scan posts' } )
		);
		await screen.findByRole( 'heading', {
			name: 'Dry run · 43 embeds in 39 posts',
		} );

		const progress = speak.mock.calls.filter( ( [ text ] ) =>
			text.startsWith( 'Scan ' )
		);
		expect( progress ).toEqual( [
			[ 'Scan 30% done. 9 embeds found so far.', 'polite' ],
			[ 'Scan 60% done. 17 embeds found so far.', 'polite' ],
		] );
	} );

	it( 'clears a rate-limit notice at its retry time and carries on', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const message =
				'show.fm is limiting requests from this site. Your progress is saved. Try again after 10:44.';
			const calls = serve( scanning(), {
				scan: [
					{
						code: 'showfm_migration_rate_limited',
						message,
						data: {
							status: 503,
							reason: 'rate_limited',
							retryAt: Math.floor( Date.now() / 1000 ) + 2,
							view: scanning( { catalogue: true } ),
						},
					},
					reported(),
				],
			} );
			render( <MigrateTab connection={ connection() } /> );

			expect(
				await screen.findByText( message, { selector: 'p' } )
			).toBeInTheDocument();
			expect( steps( calls, 'scan' ) ).toHaveLength( 1 );

			await act( async () => vi.advanceTimersByTime( 4000 ) );

			await screen.findByRole( 'heading', {
				name: 'Dry run · 43 embeds in 39 posts',
			} );
			expect(
				screen.queryByText( message, { selector: 'p' } )
			).toBeNull();
			expect( steps( calls, 'scan' ) ).toHaveLength( 2 );
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'leaves a paused scan paused after a reload', async () => {
		const calls = serve( scanning( { stopped: true } ) );
		render( <MigrateTab connection={ connection() } /> );

		expect(
			await screen.findByRole( 'button', { name: 'Resume scanning' } )
		).toBeInTheDocument();
		expect( steps( calls, 'scan' ) ).toHaveLength( 0 );
	} );

	/**
	 * Starts a scan whose first step is refused, and waits for the notice to take focus.
	 *
	 * @param {string} reason  Reason.
	 * @param {string} message Message.
	 * @return {Promise<Object[]>} Calls made.
	 */
	async function refuseFirstScan( reason, message ) {
		const calls = serve( migrate(), {
			scan: [
				{
					code: `showfm_migration_${ reason }`,
					message,
					data: {
						status: 503,
						reason,
						retryAt: 0,
						view: scanning( { catalogue: true } ),
					},
				},
				reported(),
			],
		} );
		render( <MigrateTab connection={ connection() } /> );
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Scan posts' } )
		);
		const text = await screen.findByText( message, { selector: 'p' } );
		await waitFor( () =>
			expect( text.closest( '.showfm-migrate__problem' ) ).toHaveFocus()
		);
		return calls;
	}

	it.each( [
		[
			'unreachable',
			'We couldn’t reach show.fm to list your episodes. Your progress is saved. Try again in a moment.',
		],
		[
			'rate_limited',
			'show.fm is limiting requests from this site. Your progress is saved. Try again after 10:44.',
		],
	] )(
		'explains a %s step, focuses the notice, and tries again from where it stopped',
		async ( reason, message ) => {
			const calls = await refuseFirstScan( reason, message );

			await userEvent.click(
				screen.getByRole( 'button', { name: 'Try again' } )
			);

			await screen.findByRole( 'heading', {
				name: 'Dry run · 43 embeds in 39 posts',
			} );
			expect( steps( calls, 'scan' )[ 1 ].data ).toEqual( { run: RUN } );
		}
	);

	it( 'explains another scan already running and checks again without stepping', async () => {
		const message =
			'Tom Reyes is already running a scan or swap. Try again when it finishes.';
		const calls = await refuseFirstScan( 'busy', message );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Check again' } )
		);

		await waitFor( () =>
			expect(
				screen.queryByText( message, { selector: 'p' } )
			).toBeNull()
		);
		expect( steps( calls, 'scan' ) ).toHaveLength( 1 );
	} );

	it( 'explains a connection lost mid-run and reconnects with the form', async () => {
		const submit = vi
			.spyOn( window.HTMLFormElement.prototype, 'requestSubmit' )
			.mockImplementation( () => {} );
		serve( scanning(), {
			scan: refusal(
				'connection_lost',
				'The connection to show.fm was lost part way through. Nothing else has changed. Reconnect, then carry on.',
				{ ...scanning(), connected: false, lease: null }
			),
		} );
		render(
			<MigrateTab connection={ connection( { state: 'refused' } ) } />
		);

		const text = await screen.findByText(
			'The connection to show.fm was lost part way through. Nothing else has changed. Reconnect, then carry on.',
			{ selector: 'p' }
		);
		await waitFor( () =>
			expect( text.closest( '.showfm-migrate__problem' ) ).toHaveFocus()
		);
		const notice = text.closest( '.showfm-migrate__problem' );
		await userEvent.click(
			within( notice ).getByRole( 'button', {
				name: 'Reconnect to show.fm',
			} )
		);
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says when another admin is running a scan and follows it', async () => {
		vi.useFakeTimers( { shouldAdvanceTime: true } );
		try {
			const other = {
				...scanning(),
				lease: { mine: false, name: 'Tom Reyes' },
			};
			const calls = serve( other );
			render( <MigrateTab connection={ connection() } /> );

			expect(
				await screen.findByText(
					'Tom Reyes is running a scan or swap. This page follows their progress.',
					{ selector: 'p' }
				)
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: /scanning/ } )
			).toBeNull();
			expect( steps( calls, 'scan' ) ).toHaveLength( 0 );
			await act( async () => vi.advanceTimersByTime( 5000 ) );
			expect(
				calls.filter( ( call ) => call.path === MIGRATE_PATH ).length
			).toBeGreaterThan( 1 );
		} finally {
			vi.useRealTimers();
		}
	} );

	it( 'shows the fresh view when the run changed elsewhere', async () => {
		serve( reported(), {
			swap: refusal(
				'stale',
				'The scan changed in another tab or by another admin. This is the latest.',
				migrate( {
					phase: 'empty',
					run: RUN,
					report: reported().report,
				} )
			),
		} );
		render( <MigrateTab connection={ connection() } /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Swap 32 embeds' } )
		);
		await userEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Swap 32 embeds',
			} )
		);

		expect(
			await screen.findByText(
				'The scan changed in another tab or by another admin. This is the latest.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Nothing to migrate.' } )
		).toBeInTheDocument();
	} );
} );
