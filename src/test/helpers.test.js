/**
 * Pure helpers: addresses, snapshots, formatting, the API cache and the sync status.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
import { setSettings } from '@wordpress/date';
import { parseShowAddress } from '../address';
import { forget, request } from '../api';
import { hexColours } from '../components/controls';
import {
	filterEpisodes,
	seasonsOf,
	sortEpisodes,
} from '../components/episode-picker';
import { defaultLevel } from '../components/heading-level';
import { toggleHidden } from '../blocks/episodes';
import { elementIdFor, followLabel } from '../blocks/transcript';
import { elementAttributes } from '../components/element-preview';
import { episodeMeta, formatDuration, goesLive } from '../format';
import { episodeSnapshot, showSnapshot } from '../snapshot';
import { hasEpisodeBindings, syncStatus, updatedWhen } from '../sidebar/status';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

beforeAll( () => {
	setSettings( {
		l10n: {
			locale: 'en_GB',
			months: [
				'January',
				'February',
				'March',
				'April',
				'May',
				'June',
				'July',
				'August',
				'September',
				'October',
				'November',
				'December',
			],
			monthsShort: [
				'Jan',
				'Feb',
				'Mar',
				'Apr',
				'May',
				'Jun',
				'Jul',
				'Aug',
				'Sept',
				'Oct',
				'Nov',
				'Dec',
			],
			weekdays: [
				'Sunday',
				'Monday',
				'Tuesday',
				'Wednesday',
				'Thursday',
				'Friday',
				'Saturday',
			],
			weekdaysShort: [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ],
			meridiem: { am: 'am', pm: 'pm', AM: 'AM', PM: 'PM' },
			relative: {},
			startOfWeek: 1,
		},
		formats: { time: 'H:i', date: 'j F Y', datetime: 'j F Y H:i' },
		timezone: { offset: 0, string: 'UTC', abbr: 'UTC' },
	} );
} );

describe( 'parseShowAddress', () => {
	it.each( [
		[ 'the-long-table.show.fm', 'the-long-table' ],
		[ 'https://the-long-table.show.fm/e/knives', 'the-long-table' ],
		[ 'https://show.fm/The-Long-Table', 'the-long-table' ],
		[ 'www.show.fm/the-long-table', 'the-long-table' ],
		[ 'second-helpings.showfm.dev', 'second-helpings' ],
		[ '  the-long-table ', 'the-long-table' ],
		[
			'33333333-3333-4333-8333-333333333333',
			'33333333-3333-4333-8333-333333333333',
		],
	] )( '%s gives %s', ( input, expected ) => {
		expect( parseShowAddress( input ) ).toBe( expected );
	} );

	it.each( [
		'',
		'https://evil.example/the-long-table',
		'my.show.fm',
		'api.show.fm',
		'show.fm',
		'not a slug',
		'javascript:alert(1)',
	] )( 'refuses %p', ( input ) => {
		expect( parseShowAddress( input ) ).toBeNull();
	} );
} );

describe( 'snapshots', () => {
	it( 'keeps only https links, and no audio for a scheduled episode', () => {
		expect(
			episodeSnapshot( {
				title: 'Knives',
				listen: 'https://show.fm/x/e/knives',
				audio: 'https://m.cdn.media/k.mp3',
			} )
		).toEqual( {
			title: 'Knives',
			listenUrl: 'https://show.fm/x/e/knives',
			audioUrl: 'https://m.cdn.media/k.mp3',
		} );
		expect(
			episodeSnapshot( {
				title: 'Soon',
				scheduled: true,
				listen: 'https://show.fm/x/e/soon',
				audio: 'https://m.cdn.media/s.mp3',
			} )
		).toEqual( {} );
		expect(
			episodeSnapshot( {
				title: 'Bad',
				listen: 'javascript:alert(1)',
				audio: 'http://m.cdn.media/a.mp3',
			} )
		).toEqual( { title: 'Bad' } );
		expect(
			showSnapshot( {
				title: 'The Long Table',
				listen: 'https://the-long-table.show.fm',
			} )
		).toEqual( {
			title: 'The Long Table',
			listenUrl: 'https://the-long-table.show.fm',
		} );
	} );

	it( 'keeps links on show.fm hosts only, and caps the title', () => {
		expect(
			episodeSnapshot( {
				title: 'x'.repeat( 400 ),
				listen: 'https://evil.example/e/knives',
				audio: 'https://evil.example/k.mp3',
			} )
		).toEqual( { title: 'x'.repeat( 300 ) } );
		const emoji = episodeSnapshot( {
			title: 'x'.repeat( 299 ) + '😀😀',
		} ).title;
		expect( emoji ).toBe( 'x'.repeat( 299 ) + '😀' );
		expect( JSON.stringify( emoji ) ).not.toMatch( /\\ud[89ab]/i );
		expect(
			episodeSnapshot( {
				title: 'Lookalike',
				listen: 'https://show.fm.evil.example/e/x',
				audio: 'https://user@m.cdn.media/k.mp3',
			} )
		).toEqual( { title: 'Lookalike' } );
		expect(
			episodeSnapshot( {
				title: 'Staging',
				listen: 'https://the-long-table.showfm.dev/e/x',
				audio: 'https://m.showfm.dev/x.mp3',
			} )
		).toEqual( { title: 'Staging' } );
		window.showfmEditor = { api: 'https://api.showfm.dev' };
		expect(
			episodeSnapshot( {
				title: 'Staging',
				listen: 'https://the-long-table.showfm.dev/e/x',
				audio: 'https://m.showfm.dev/x.mp3',
			} )
		).toEqual( {
			title: 'Staging',
			listenUrl: 'https://the-long-table.showfm.dev/e/x',
			audioUrl: 'https://m.showfm.dev/x.mp3',
		} );
		delete window.showfmEditor;
		expect(
			episodeSnapshot( {
				title: 'Legacy host',
				listen: 'https://show.fm/x/e/y',
				audio: 'https://media.podcasterplus.com/a.mp3',
			} ).audioUrl
		).toBe( 'https://media.podcasterplus.com/a.mp3' );
	} );
} );

describe( 'formatting', () => {
	it( 'formats durations', () => {
		expect( formatDuration( 3120 ) ).toBe( '52 min' );
		expect( formatDuration( 6000 ) ).toBe( '1 hr 40 min' );
		expect( formatDuration( 3600 ) ).toBe( '1 hr' );
		expect( formatDuration( null ) ).toBe( '' );
	} );

	it( 'summarises episodes as in the picker', () => {
		expect(
			episodeMeta( {
				season: 2,
				number: 4,
				type: 'full',
				date: '2026-09-24T09:00:00+00:00',
				duration: 3120,
			} )
		).toBe( 'S2 · E4 · 24 Sept 2026 · 52 min' );
		expect(
			episodeMeta( {
				season: 2,
				type: 'bonus',
				date: '2026-08-30T09:00:00+00:00',
				duration: 1380,
			} )
		).toBe( 'S2 · Bonus · 30 Aug 2026 · 23 min' );
		expect(
			episodeMeta( {
				season: 2,
				number: 5,
				scheduled: true,
				date: '2026-10-14T09:00:00+00:00',
				duration: 1000,
			} )
		).toBe( 'S2 · E5 · Goes live on 14 October at 09:00' );
		expect( goesLive( '2026-10-14T09:00:00+00:00' ) ).toBe(
			'Goes live on 14 October at 09:00.'
		);
	} );
} );

describe( 'picker helpers', () => {
	const episodes = [
		{ id: 'a', title: 'Knives', season: 2, date: '2026-08-20' },
		{ id: 'b', title: 'Pudding', season: 2, date: '2026-10-14' },
		{ id: 'c', title: 'Knives, revisited', season: 1, date: '2026-06-02' },
		{ id: 'd', title: 'Trailer', season: null, date: '2026-01-01' },
	];

	it( 'sorts newest first and filters by text and season', () => {
		expect( sortEpisodes( episodes ).map( ( e ) => e.id ) ).toEqual( [
			'b',
			'a',
			'c',
			'd',
		] );
		expect(
			filterEpisodes( episodes, 'KNIVES', '' ).map( ( e ) => e.id )
		).toEqual( [ 'a', 'c' ] );
		expect(
			filterEpisodes( episodes, 'knives', '1' ).map( ( e ) => e.id )
		).toEqual( [ 'c' ] );
		expect( seasonsOf( episodes ) ).toEqual( [ 2, 1 ] );
	} );
} );

describe( 'control helpers', () => {
	it( 'offers hex colours only, theme first, without repeats', () => {
		expect(
			hexColours(
				[
					{ name: 'Primary', color: '#7E22CE' },
					{ name: 'Var', color: 'var(--x)' },
				],
				[
					{ name: 'Again', color: '#7e22ce' },
					{ name: 'Red', color: '#c8553d' },
				]
			).map( ( c ) => c.name )
		).toEqual( [ 'Primary', 'Red' ] );
	} );

	it( 'toggles hidden episode types', () => {
		expect( toggleHidden( undefined, 'trailer', true ) ).toBe( 'trailer' );
		expect( toggleHidden( 'trailer', 'bonus', true ) ).toBe(
			'trailer,bonus'
		);
		expect( toggleHidden( 'trailer,bonus', 'trailer', false ) ).toBe(
			'bonus'
		);
		expect( toggleHidden( 'bonus', 'bonus', false ) ).toBeUndefined();
	} );

	it( 'picks a heading level one below the heading above', () => {
		const blocks = [
			{ clientId: 'h', name: 'core/heading', attributes: { level: 2 } },
			{ clientId: 'p', name: 'core/paragraph', attributes: {} },
			{ clientId: 'list', name: 'showfm/episodes', attributes: {} },
		];
		expect( defaultLevel( blocks, 'list' ) ).toBe( '3' );
		expect( defaultLevel( blocks, 'h' ) ).toBe( '2' );
		expect(
			defaultLevel(
				[
					{
						clientId: 'h',
						name: 'core/heading',
						attributes: { level: 6 },
					},
					{ clientId: 'x', name: 'showfm/player', attributes: {} },
				],
				'x'
			)
		).toBe( '6' );
	} );

	it( 'names and identifies blocks a transcript can follow', () => {
		const player = {
			clientId: 'abcdef1234',
			name: 'showfm/player',
			attributes: {
				snapshot: {
					title: 'Sourdough, salt and the slow return of the village bakery',
				},
			},
		};
		expect( followLabel( player, 0 ) ).toBe(
			'Sourdough, salt and the slow r…'
		);
		expect(
			followLabel(
				{ clientId: 'x', name: 'showfm/episodes', attributes: {} },
				1
			)
		).toBe( 'Episode list 2' );
		expect( elementIdFor( player, [] ) ).toBe( 'showfm-player-abcdef12' );
		expect(
			elementIdFor(
				{ ...player, attributes: { id: 'showfm-player-1' } },
				[ 'showfm-player-1' ]
			)
		).toBe( 'showfm-player-abcdef12' );
		expect(
			elementIdFor( { ...player, attributes: { id: 'showfm-mine' } }, [] )
		).toBe( 'showfm-mine' );
		// Ids without the prefix (such as wpadminbar) are replaced.
		expect(
			elementIdFor( { ...player, attributes: { id: 'wpadminbar' } }, [] )
		).toBe( 'showfm-player-abcdef12' );
	} );

	it( 'passes only element attributes to the preview, with the site API', () => {
		window.showfmEditor = { api: 'https://api.showfm.dev' };
		expect(
			elementAttributes( 'play', {
				episode: 'e',
				podcast: 'p',
				size: 'lg',
				snapshot: { title: 'x' },
				id: 'not-for-play',
			} )
		).toEqual( {
			episode: 'e',
			podcast: 'p',
			size: 'lg',
			api: 'https://api.showfm.dev',
			credit: 'off',
			platform: 'wordpress',
		} );
		delete window.showfmEditor;
	} );
} );

describe( 'request', () => {
	beforeEach( () => {
		forget();
		apiFetch.mockReset();
	} );

	it( 'asks each path once and keeps the answer', async () => {
		apiFetch.mockResolvedValue( { state: 'ok', show: { id: '1' } } );
		await request( 'show', { ref: 'x' } );
		await request( 'show', { ref: 'x' } );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/showfm/v1/editor/show?ref=x',
		} );
	} );

	it( 'asks again after an error, a rate limit or a failed request', async () => {
		apiFetch
			.mockResolvedValueOnce( { state: 'error' } )
			.mockResolvedValueOnce( { state: 'rate_limited', retryAfter: 5 } )
			.mockRejectedValueOnce( new Error( 'offline' ) )
			.mockResolvedValueOnce( 'not an object' );
		expect( ( await request( 'shows' ) ).state ).toBe( 'error' );
		expect( ( await request( 'shows' ) ).state ).toBe( 'rate_limited' );
		expect( ( await request( 'shows' ) ).state ).toBe( 'error' );
		expect( ( await request( 'shows' ) ).state ).toBe( 'error' );
		expect( apiFetch ).toHaveBeenCalledTimes( 4 );
	} );
} );

describe( 'post panel status', () => {
	const now = new Date( '2026-09-24T12:00:00Z' );

	it( 'shows nothing for a post show.fm did not create', () => {
		expect( syncStatus( null ) ).toBeNull();
		expect( syncStatus( { synced: false, state: '' } ) ).toBeNull();
	} );

	it( 'describes every sync state', () => {
		expect(
			syncStatus(
				{
					synced: true,
					state: 'synced',
					syncedAt: '2026-09-24T09:00:00Z',
				},
				now
			)
		).toMatchObject( {
			tone: 'success',
			title: 'In sync with show.fm',
			sub: 'Updated from show.fm today at 09:00.',
		} );
		expect(
			syncStatus( { synced: true, state: 'synced', edited: true } )
		).toMatchObject( {
			tone: 'warning',
			title: 'Edited here, so show.fm now only updates the date and status.',
		} );
		expect(
			syncStatus( { synced: true, state: 'unpublished' } ).title
		).toBe( 'The episode was unpublished on show.fm.' );
		expect(
			syncStatus( { synced: true, state: 'deleted' } )
		).toMatchObject( {
			tone: 'error',
			title: 'The episode was deleted on show.fm.',
		} );
		expect( syncStatus( { synced: true, state: 'paused' } ).title ).toBe(
			'Paused because of the show’s plan.'
		);
		expect( syncStatus( { synced: true, state: 'detached' } ).title ).toBe(
			'No longer synced from show.fm.'
		);
		expect( updatedWhen( '2026-09-20T09:00:00Z', now ) ).toBe(
			'on 20 Sept 2026 at 09:00'
		);
	} );

	it( 'finds blocks bound to the episode source at any depth', () => {
		const bound = {
			attributes: {
				metadata: {
					bindings: {
						content: {
							source: 'showfm/episode',
							args: { key: 'title' },
						},
					},
				},
			},
			innerBlocks: [],
		};
		expect(
			hasEpisodeBindings( [ { attributes: {}, innerBlocks: [ bound ] } ] )
		).toBe( true );
		expect(
			hasEpisodeBindings( [ { attributes: {}, innerBlocks: [] } ] )
		).toBe( false );
	} );
} );
