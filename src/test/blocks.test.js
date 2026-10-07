/**
 * The block flow (show, episode, preview and states), the inspectors, and the post panel.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from '@wordpress/element';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { request } from '../api';
import { PlayerInspector } from '../blocks/player';
import { PlayInspector } from '../blocks/play';
import { EpisodesInspector } from '../blocks/episodes';
import TranscriptEdit from '../blocks/transcript';
import ShowfmBlockEdit from '../components/block-edit';
import SyncPanel from '../sidebar/panel';

vi.mock( '../api', () => ( {
	request: vi.fn(),
	forget: vi.fn(),
	editorPath: ( route, args = {} ) =>
		`/showfm/v1/editor/${ route }?${ new URLSearchParams(
			Object.entries( args ).filter( ( [ , v ] ) => v )
		) }`,
} ) );

vi.mock( '@wordpress/block-editor', () => ( {
	store: 'core/block-editor',
	useBlockProps: ( props = {} ) => props,
	useSettings: () => [ [ { name: 'Theme', color: '#123456' } ], [] ],
	BlockControls: ( { children } ) => (
		<div data-testid="toolbar">{ children }</div>
	),
	InspectorControls: ( { children } ) => (
		<div data-testid="inspector">{ children }</div>
	),
} ) );

vi.mock( '@wordpress/server-side-render', () => ( {
	default: ( { block } ) => <div data-testid="last-saved">{ block }</div>,
} ) );

const followable = [];
const updateBlockAttributes = vi.fn();
vi.mock( '@wordpress/data', async ( original ) => ( {
	...( await original() ),
	useSelect: ( callback ) =>
		callback( () => ( {
			getBlocksByName: () => followable.map( ( b ) => b.clientId ),
			getBlock: ( id ) => followable.find( ( b ) => b.clientId === id ),
			getBlocks: () => followable,
		} ) ),
	useDispatch: () => ( { updateBlockAttributes } ),
} ) );

const SHOW = {
	id: '33333333-3333-4333-8333-333333333333',
	title: 'The Long Table',
	listen: 'https://the-long-table.show.fm',
	artwork: 'https://m.cdn.media/art.jpg',
	hosting: 'showfm',
};
const EPISODE = {
	id: '22222222-2222-4222-8222-222222222222',
	title: 'Sourdough',
	season: 2,
	number: 4,
	date: '2026-09-24T09:00:00+00:00',
	duration: 3120,
	listen: 'https://the-long-table.show.fm/e/sourdough',
	audio: 'https://m.cdn.media/sourdough.mp3',
	podcast: { id: SHOW.id, title: SHOW.title },
};

/**
 * Answers editor routes from a table.
 *
 * @param {Object} answers Route to answer.
 */
function answer( answers ) {
	request.mockImplementation( ( route ) =>
		Promise.resolve( answers[ route ] || { state: 'error' } )
	);
}

function Harness( { initial = {}, onSet, ...props } ) {
	const [ attributes, setState ] = useState( initial );
	return (
		<ShowfmBlockEdit
			type="player"
			name="showfm/player"
			label="show.fm Player"
			needsEpisode
			headingControl
			attributes={ attributes }
			setAttributes={ ( next ) => {
				onSet?.( next );
				setState( ( current ) => ( { ...current, ...next } ) );
			} }
			renderInspector={ () => <p>inspector</p> }
			{ ...props }
		/>
	);
}

beforeEach( () => {
	request.mockReset();
	followable.length = 0;
	updateBlockAttributes.mockReset();
	window.showfmEditor = { connected: true, api: 'https://api.show.fm' };
} );

afterEach( () => {
	delete window.showfmEditor;
} );

describe( 'block flow', () => {
	it( 'goes from show to episode to the real element, and writes the snapshot', async () => {
		answer( {
			shows: { state: 'ok', connected: true, shows: [ SHOW ] },
			show: { state: 'ok', show: SHOW },
			episodes: { state: 'ok', episodes: [ EPISODE ] },
			episode: { state: 'ok', episode: EPISODE },
		} );
		const onSet = vi.fn();
		const { container } = render( <Harness onSet={ onSet } /> );
		fireEvent.click( await screen.findByText( 'The Long Table' ) );
		fireEvent.click(
			await screen.findByRole( 'radio', { name: /Sourdough/ } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Use this episode' } )
		);
		await waitFor( () =>
			expect( onSet ).toHaveBeenLastCalledWith( {
				snapshot: {
					title: 'Sourdough',
					listenUrl: 'https://the-long-table.show.fm/e/sourdough',
					audioUrl: 'https://m.cdn.media/sourdough.mp3',
				},
			} )
		);
		expect( onSet ).toHaveBeenCalledWith(
			expect.objectContaining( { episode: EPISODE.id, podcast: SHOW.id } )
		);
		const element = await waitFor( () => {
			const found = container.querySelector( 'showfm-player' );
			expect( found ).not.toBeNull();
			return found;
		} );
		expect( element.getAttribute( 'episode' ) ).toBe( EPISODE.id );
		expect( element.getAttribute( 'api' ) ).toBe( 'https://api.show.fm' );
		expect( element.getAttribute( 'credit' ) ).toBe( 'off' );
		expect(
			screen.getByRole( 'button', { name: 'Change episode' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'inspector' ) ).toBeInTheDocument();
	} );

	it( 'stores the show for Latest episode', async () => {
		answer( {
			show: { state: 'ok', show: SHOW },
			episodes: { state: 'ok', episodes: [ EPISODE ] },
		} );
		const onSet = vi.fn();
		render( <Harness initial={ { podcast: SHOW.id } } onSet={ onSet } /> );
		fireEvent.click(
			await screen.findByRole( 'radio', { name: /Latest episode/ } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Use this episode' } )
		);
		expect( onSet ).toHaveBeenCalledWith( {
			episode: undefined,
			podcast: SHOW.id,
			snapshot: {
				title: 'The Long Table',
				listenUrl: 'https://the-long-table.show.fm',
			},
		} );
	} );

	it( 'shows loading, then each state for a saved episode', async () => {
		let resolve;
		request.mockImplementation(
			() => new Promise( ( done ) => ( resolve = done ) )
		);
		const saved = {
			episode: EPISODE.id,
			podcast: SHOW.id,
			snapshot: { title: 'Sourdough' },
		};
		render( <Harness initial={ saved } /> );
		expect( screen.getByText( 'Loading episode…' ) ).toBeInTheDocument();
		resolve( { state: 'deleted' } );
		expect(
			await screen.findByText( 'No longer available on show.fm.' )
		).toBeInTheDocument();
	} );

	it( 'shows a scheduled episode with its date, dimmed', async () => {
		answer( {
			episode: {
				state: 'scheduled',
				episode: {
					...EPISODE,
					title: 'Bread and butter pudding',
					date: '2030-10-14T09:00:00+00:00',
					scheduled: true,
				},
			},
		} );
		render(
			<Harness
				initial={ {
					episode: EPISODE.id,
					podcast: SHOW.id,
					snapshot: { title: 'x' },
				} }
			/>
		);
		expect(
			await screen.findByText( /Goes live on 14 October/ )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Bread and butter pudding' )
		).toBeInTheDocument();
	} );

	it( 'falls back to the last saved copy when show.fm cannot be reached', async () => {
		answer( { episode: { state: 'error' } } );
		render(
			<Harness
				initial={ {
					episode: EPISODE.id,
					podcast: SHOW.id,
					snapshot: { title: 'x' },
				} }
			/>
		);
		expect( await screen.findByTestId( 'last-saved' ) ).toHaveTextContent(
			'showfm/player'
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Couldn’t reach show.fm. Showing the last saved copy.'
		);
		answer( { episode: { state: 'ok', episode: EPISODE } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await waitFor( () =>
			expect( screen.queryByTestId( 'last-saved' ) ).toBeNull()
		);
	} );

	it( 'shows the heading level only when the title is a heading', async () => {
		answer( { episode: { state: 'ok', episode: EPISODE } } );
		const saved = {
			episode: EPISODE.id,
			podcast: SHOW.id,
			snapshot: { title: 'x' },
		};
		const { unmount } = render( <Harness initial={ saved } /> );
		await screen.findByRole( 'button', { name: 'Change episode' } );
		expect(
			screen.queryByRole( 'button', { name: 'Change level' } )
		).toBeNull();
		unmount();
		render( <Harness initial={ { ...saved, 'heading-level': '2' } } /> );
		expect(
			await screen.findByRole( 'button', { name: 'Change level' } )
		).toBeInTheDocument();
	} );
} );

describe( 'inspectors', () => {
	it( 'Player: size, theme, accent, waveform, transcript and heading', () => {
		const set = vi.fn();
		render(
			<PlayerInspector
				attributes={ {} }
				setAttributes={ set }
				data={ { episode: EPISODE } }
				startPicking={ vi.fn() }
				blocks={ [
					{
						clientId: 'h',
						name: 'core/heading',
						attributes: { level: 2 },
					},
					{ clientId: 'me', name: 'showfm/player', attributes: {} },
				] }
				clientId="me"
			/>
		);
		expect( screen.getByText( 'Sourdough' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /^The Long Table · S2 · E4 · .+ · 52 min$/ )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'radio', { name: 'Compact' } ) );
		expect( set ).toHaveBeenCalledWith( { size: 'compact' } );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Dark' } ) );
		expect( set ).toHaveBeenCalledWith( { theme: 'dark' } );
		fireEvent.click( screen.getByLabelText( 'Waveform' ) );
		expect( set ).toHaveBeenCalledWith( { wave: 'false' } );
		fireEvent.click( screen.getByLabelText( 'Transcript' ) );
		expect( set ).toHaveBeenCalledWith( { transcript: 'on' } );
		fireEvent.click(
			screen.getByLabelText( 'Show the title as a heading' )
		);
		expect( set ).toHaveBeenCalledWith( { 'heading-level': '3' } );
		fireEvent.click( screen.getByRole( 'option', { name: /Theme/ } ) );
		expect( set ).toHaveBeenCalledWith( { accent: '#123456' } );
		expect(
			screen.queryByLabelText( 'Mini-player' )
		).not.toBeInTheDocument();
	} );

	it( 'Play button: variant, size and the mini-player warning', () => {
		const set = vi.fn();
		const { rerender } = render(
			<PlayInspector
				attributes={ {} }
				setAttributes={ set }
				data={ {} }
				startPicking={ vi.fn() }
			/>
		);
		expect( screen.getByLabelText( 'Mini-player' ) ).toBeChecked();
		expect(
			screen.queryByText( 'Visitors can only play and pause.' )
		).toBeNull();
		fireEvent.click( screen.getByRole( 'radio', { name: 'Text link' } ) );
		expect( set ).toHaveBeenCalledWith( { variant: 'link' } );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Large' } ) );
		expect( set ).toHaveBeenCalledWith( { size: 'lg' } );
		fireEvent.click( screen.getByLabelText( 'Mini-player' ) );
		expect( set ).toHaveBeenCalledWith( { 'mini-player': 'off' } );
		rerender(
			<PlayInspector
				attributes={ { 'mini-player': 'off' } }
				setAttributes={ set }
				data={ {} }
				startPicking={ vi.fn() }
			/>
		);
		expect(
			screen.getAllByText( 'Visitors can only play and pause.' ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'Episode list: count, season, hidden types, style, layout, descriptions and mini-player', () => {
		const set = vi.fn();
		render(
			<EpisodesInspector
				attributes={ { hide: 'trailer' } }
				setAttributes={ set }
				data={ { show: { ...SHOW, episodes: 8 } } }
				changeShow={ vi.fn() }
				seasons={ [ 2, 1 ] }
			/>
		);
		expect(
			screen.getByText( 'the-long-table.show.fm · 8 episodes' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Hide trailers' ) ).toBeChecked();
		fireEvent.click( screen.getByLabelText( 'Hide bonus episodes' ) );
		expect( set ).toHaveBeenCalledWith( { hide: 'trailer,bonus' } );
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Season' } ), {
			target: { value: '2' },
		} );
		expect( set ).toHaveBeenCalledWith( { season: '2' } );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Number of episodes' } ),
			{ target: { value: '3' } }
		);
		expect( set ).toHaveBeenCalledWith( { count: '3' } );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Minimal' } ) );
		expect( set ).toHaveBeenCalledWith( { variant: 'minimal' } );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Grid' } ) );
		expect( set ).toHaveBeenCalledWith( { layout: 'grid' } );
		fireEvent.click( screen.getByLabelText( 'Show descriptions' ) );
		expect( set ).toHaveBeenCalledWith( { descriptions: 'off' } );
		fireEvent.click( screen.getByLabelText( 'Mini-player' ) );
		expect( set ).toHaveBeenCalledWith( { 'mini-player': 'on' } );
	} );
} );

describe( 'Transcript', () => {
	function renderTranscript( attributes, set = vi.fn() ) {
		render(
			<TranscriptEdit attributes={ attributes } setAttributes={ set } />
		);
		return set;
	}

	it( 'follows a Player on the page and gives it a unique id', () => {
		followable.push(
			{
				clientId: 'aaaaaaaa-1',
				name: 'showfm/player',
				attributes: {
					id: 'dup',
					episode: EPISODE.id,
					snapshot: { title: 'Sourdough' },
				},
			},
			{
				clientId: 'bbbbbbbb-2',
				name: 'showfm/player',
				attributes: { id: 'dup', episode: 'other' },
			}
		);
		const set = renderTranscript( {} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Sourdough' } ) );
		expect( updateBlockAttributes ).toHaveBeenCalledWith( 'aaaaaaaa-1', {
			id: 'showfm-player-aaaaaaaa',
		} );
		expect( set ).toHaveBeenCalledWith( {
			for: 'showfm-player-aaaaaaaa',
			episode: EPISODE.id,
			podcast: undefined,
			snapshot: {},
		} );
	} );

	it( 'previews the transcript element following the player, with height', () => {
		followable.push( {
			clientId: 'aaaaaaaa-1',
			name: 'showfm/player',
			attributes: { id: 'p1', episode: EPISODE.id },
		} );
		const set = vi.fn();
		const { container } = render(
			<TranscriptEdit
				attributes={ { for: 'p1', episode: EPISODE.id, height: '400' } }
				setAttributes={ set }
			/>
		);
		const element = container.querySelector( 'showfm-transcript' );
		expect( element.getAttribute( 'for' ) ).toBe( 'p1' );
		expect( element.getAttribute( 'height' ) ).toBe( '400' );
		expect(
			screen.getByRole( 'button', { name: 'Change player' } )
		).toBeInTheDocument();
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Player' } ), {
			target: { value: '' },
		} );
		expect( set ).toHaveBeenCalledWith( { for: undefined } );
	} );

	it( 'keeps the followed player’s episode', () => {
		followable.push( {
			clientId: 'aaaaaaaa-1',
			name: 'showfm/player',
			attributes: { id: 'p1', episode: 'new-episode' },
		} );
		const set = renderTranscript( { for: 'p1', episode: 'old-episode' } );
		expect( set ).toHaveBeenCalledWith( { episode: 'new-episode' } );
	} );

	it( 'says when the followed player is gone', () => {
		renderTranscript( { for: 'gone', episode: EPISODE.id } );
		expect(
			screen.getAllByText(
				'The player this transcript followed is no longer on the page.'
			).length
		).toBeGreaterThan( 0 );
	} );
} );

describe( 'post panel', () => {
	const options = [
		{ value: 'e-knives', label: 'Knives', meta: 'S2 · E2 · 20 Aug 2026' },
		{ value: 'e-sourdough', label: 'Sourdough', meta: 'S2 · E4' },
	];

	it( 'shows the status, the episode and Open in show.fm', () => {
		const onChange = vi.fn();
		render(
			<SyncPanel
				sync={ { synced: true, state: 'synced', edited: true } }
				episodeId="e-sourdough"
				options={ options }
				appUrl="https://my.show.fm/p/x/e/sourdough"
				onChange={ onChange }
			/>
		);
		expect(
			screen.getByText(
				'Edited here, so show.fm now only updates the date and status.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'combobox', { name: 'Episode for this post' } )
		).toHaveValue( 'Sourdough' );
		expect(
			screen.getByRole( 'link', { name: /Open in show.fm/ } )
		).toHaveAttribute( 'href', 'https://my.show.fm/p/x/e/sourdough' );
		const input = screen.getByRole( 'combobox', {
			name: 'Episode for this post',
		} );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'kni' } } );
		fireEvent.click( screen.getByRole( 'option', { name: /Knives/ } ) );
		expect( onChange ).toHaveBeenCalledWith( 'e-knives' );
	} );

	it( 'asks for another episode once the episode was deleted', () => {
		render(
			<SyncPanel
				sync={ { synced: true, state: 'deleted' } }
				episodeId="e-sourdough"
				options={ options }
				appUrl="https://my.show.fm/p/x/e/sourdough"
				onChange={ vi.fn() }
			/>
		);
		expect(
			screen.getByText( 'The episode was deleted on show.fm.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'combobox', { name: 'Episode for this post' } )
		).toHaveValue( '' );
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );
} );
