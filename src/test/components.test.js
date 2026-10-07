/**
 * Placeholders, the episode picker, editor states and the heading level control.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { request } from '../api';
import EpisodePicker, { LATEST } from '../components/episode-picker';
import HeadingLevelDropdown from '../components/heading-level';
import ShowPlaceholder from '../components/show-placeholder';
import { StateMessage, StateStrip } from '../components/state-message';

vi.mock( '../api', () => ( {
	request: vi.fn(),
	forget: vi.fn(),
	editorPath: ( route, args = {} ) =>
		`/showfm/v1/editor/${ route }?${ new URLSearchParams( args ) }`,
} ) );

const SHOW = {
	id: '33333333-3333-4333-8333-333333333333',
	slug: 'the-long-table',
	title: 'The Long Table',
	artwork: 'https://m.cdn.media/art.jpg',
	episodes: 8,
	listen: 'https://the-long-table.show.fm',
	hosting: 'showfm',
};

const EPISODES = [
	{
		id: 'e-knives',
		title: 'Knives',
		season: 2,
		number: 2,
		type: 'full',
		date: '2026-08-20T09:00:00+00:00',
		duration: 2340,
	},
	{
		id: 'e-pudding',
		title: 'Bread and butter pudding',
		season: 2,
		number: 5,
		type: 'full',
		scheduled: true,
		date: '2030-10-14T09:00:00+00:00',
	},
	{
		id: 'e-bonus',
		title: 'Listener questions',
		season: 1,
		type: 'bonus',
		date: '2026-06-02T09:00:00+00:00',
	},
];

function settings( values ) {
	window.showfmEditor = values;
}

beforeEach( () => {
	request.mockReset();
} );

afterEach( () => {
	delete window.showfmEditor;
} );

describe( 'ShowPlaceholder', () => {
	it( 'asks for a show address when the site is not connected', async () => {
		settings( {
			connected: false,
			canConnect: true,
			connectUrl: '/wp-admin/admin.php?page=showfm',
		} );
		request.mockResolvedValue( { state: 'ok', show: SHOW } );
		const onSelect = vi.fn();
		render(
			<ShowPlaceholder
				label="show.fm Player"
				instructions="Add a public show from show.fm. You’ll choose the episode next."
				onSelect={ onSelect }
			/>
		);
		expect(
			screen.getAllByText(
				'Add a public show from show.fm. You’ll choose the episode next.'
			).length
		).toBeGreaterThan( 0 );
		expect(
			screen.getByRole( 'link', { name: 'Connect to show.fm' } )
		).toHaveAttribute( 'href', '/wp-admin/admin.php?page=showfm' );

		fireEvent.change( screen.getByLabelText( 'Show address or slug' ), {
			target: { value: 'not an address!' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Enter a show address'
		);
		expect( request ).not.toHaveBeenCalled();

		fireEvent.change( screen.getByLabelText( 'Show address or slug' ), {
			target: { value: 'https://the-long-table.show.fm' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( onSelect ).toHaveBeenCalledWith( SHOW ) );
		expect( request ).toHaveBeenCalledWith( 'show', {
			ref: 'the-long-table',
		} );
	} );

	it( 'says why a show cannot be used', async () => {
		settings( { connected: false } );
		request.mockResolvedValue( { state: 'not_found' } );
		render(
			<ShowPlaceholder label="show.fm Player" onSelect={ vi.fn() } />
		);
		fireEvent.change( screen.getByLabelText( 'Show address or slug' ), {
			target: { value: 'missing' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'We couldn’t find that show or episode.'
		);
		expect(
			screen.queryByRole( 'link', { name: 'Connect to show.fm' } )
		).not.toBeInTheDocument();
	} );

	it( 'lists the connected account’s shows and refuses external ones', async () => {
		settings( { connected: true, privateData: true } );
		request.mockResolvedValue( {
			state: 'ok',
			connected: true,
			shows: [
				SHOW,
				{ ...SHOW, id: 'x', title: 'Elsewhere', hosting: 'external' },
			],
		} );
		const onSelect = vi.fn();
		render(
			<ShowPlaceholder
				label="show.fm Episode list"
				onSelect={ onSelect }
			/>
		);
		expect( screen.getByText( 'Loading your shows…' ) ).toBeInTheDocument();
		fireEvent.click( await screen.findByText( 'Elsewhere' ) );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'This show is hosted outside show.fm'
		);
		expect(
			screen.getByText( 'the-long-table.show.fm · 8 episodes' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByText( 'The Long Table' ) );
		expect( onSelect ).toHaveBeenCalledWith( SHOW );

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Use the address of another public show',
			} )
		);
		expect(
			screen.getByLabelText( 'Show address or slug' )
		).toBeInTheDocument();
	} );
} );

describe( 'ShowPlaceholder errors and permissions', () => {
	it( 'keeps the show list with Try again when show.fm cannot be reached', async () => {
		settings( { connected: true, privateData: true } );
		request
			.mockResolvedValueOnce( {
				state: 'error',
				connected: true,
				shows: [],
			} )
			.mockResolvedValueOnce( {
				state: 'ok',
				connected: true,
				shows: [ SHOW ],
			} );
		render(
			<ShowPlaceholder label="show.fm Player" onSelect={ vi.fn() } />
		);
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Couldn’t reach show.fm to list your shows.'
		);
		expect(
			screen.queryByLabelText( 'Show address or slug' )
		).not.toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect(
			await screen.findByText( 'The Long Table' )
		).toBeInTheDocument();
		expect( request ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'says how long to wait when rate limited', async () => {
		settings( { connected: true, privateData: true } );
		request.mockResolvedValue( {
			state: 'rate_limited',
			retryAfter: 42,
			connected: true,
			shows: [],
		} );
		render(
			<ShowPlaceholder label="show.fm Player" onSelect={ vi.fn() } />
		);
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'show.fm is busy. Try again in 42 seconds.'
		);
		expect(
			screen.getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );

	it( 'gives a Contributor on a connected site the address field only', () => {
		settings( { connected: true, privateData: false } );
		render(
			<ShowPlaceholder label="show.fm Player" onSelect={ vi.fn() } />
		);
		expect(
			screen.getByLabelText( 'Show address or slug' )
		).toBeInTheDocument();
		expect( request ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'button', { name: 'Choose from your shows' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Connect to show.fm' } )
		).not.toBeInTheDocument();
	} );
} );

describe( 'EpisodePicker', () => {
	function renderPicker( props = {} ) {
		const handlers = {
			onChoose: vi.fn(),
			onCancel: vi.fn(),
			onChangeShow: vi.fn(),
		};
		render(
			<EpisodePicker
				label="show.fm Player"
				show={ SHOW }
				{ ...handlers }
				{ ...props }
			/>
		);
		return handlers;
	}

	it( 'offers Latest episode, scheduled episodes, search and seasons', async () => {
		request.mockResolvedValue( {
			state: 'ok',
			keyed: true,
			episodes: EPISODES,
		} );
		const { onChoose } = renderPicker();
		expect( screen.getByText( 'Loading episodes…' ) ).toBeInTheDocument();
		const radios = await screen.findAllByRole( 'radio' );
		expect( radios.map( ( radio ) => radio.value ) ).toEqual( [
			LATEST,
			'e-pudding',
			'e-knives',
			'e-bonus',
		] );
		expect( screen.getByText( 'Scheduled' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Goes live on 14 October at/ )
		).toBeInTheDocument();
		expect( screen.getAllByText( 'Bonus' ).length ).toBeGreaterThan( 0 );
		const use = screen.getByRole( 'button', { name: 'Use this episode' } );
		expect( use ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Season' ), {
			target: { value: '1' },
		} );
		expect(
			screen.getAllByRole( 'radio' ).map( ( radio ) => radio.value )
		).toEqual( [ 'e-bonus' ] );
		fireEvent.change( screen.getByLabelText( 'Season' ), {
			target: { value: '' },
		} );
		fireEvent.change( screen.getByPlaceholderText( 'Search episodes' ), {
			target: { value: 'knives' },
		} );
		expect(
			screen.getAllByRole( 'radio' ).map( ( radio ) => radio.value )
		).toEqual( [ 'e-knives' ] );

		fireEvent.click( screen.getByRole( 'radio' ) );
		fireEvent.click( use );
		expect( onChoose ).toHaveBeenCalledWith( EPISODES[ 0 ] );
		expect( request ).toHaveBeenCalledWith( 'episodes', {
			podcast: SHOW.id,
		} );
	} );

	it( 'chooses Latest episode, and hides it where it cannot work', async () => {
		request.mockResolvedValue( { state: 'ok', episodes: EPISODES } );
		const { onChoose, onCancel, onChangeShow } = renderPicker();
		fireEvent.click(
			await screen.findByRole( 'radio', { name: /Latest episode/ } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Use this episode' } )
		);
		expect( onChoose ).toHaveBeenCalledWith( LATEST );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onCancel ).toHaveBeenCalled();
		fireEvent.click( screen.getByRole( 'button', { name: 'Change' } ) );
		expect( onChangeShow ).toHaveBeenCalled();
	} );

	it( 'says when only public episodes could be listed', async () => {
		request
			.mockResolvedValueOnce( {
				state: 'ok',
				keyed: false,
				warning: 'error',
				episodes: [ EPISODES[ 0 ] ],
			} )
			.mockResolvedValueOnce( {
				state: 'ok',
				keyed: true,
				episodes: EPISODES,
			} );
		renderPicker();
		expect(
			await screen.findByText(
				'Couldn’t load scheduled episodes from show.fm, so only public episodes are listed.'
			)
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( await screen.findByText( 'Scheduled' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( /only public episodes are listed/ )
		).not.toBeInTheDocument();
	} );

	it( 'has no Latest option for a transcript', async () => {
		request.mockResolvedValue( { state: 'ok', episodes: EPISODES } );
		renderPicker( { allowLatest: false } );
		await screen.findAllByRole( 'radio' );
		expect(
			screen.queryByRole( 'radio', { name: /Latest episode/ } )
		).not.toBeInTheDocument();
	} );

	it( 'says when a show has no episodes, and when none match', async () => {
		request.mockResolvedValue( { state: 'ok', episodes: [] } );
		renderPicker( { allowLatest: false } );
		expect(
			await screen.findByText( 'This show has no episodes yet.' )
		).toBeInTheDocument();
	} );

	it( 'shows the state when the list cannot load', async () => {
		settings( { connected: true, appUrl: 'https://my.show.fm' } );
		request.mockResolvedValue( { state: 'paused' } );
		renderPicker();
		expect(
			await screen.findByText( 'Paused because of the show’s plan.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /Go to billing/ } )
		).toHaveAttribute( 'href', 'https://my.show.fm/settings' );
	} );
} );

describe( 'StateMessage', () => {
	it.each( [
		[ 'not_found', 'We couldn’t find that show or episode.' ],
		[ 'not_public', 'This episode isn’t public yet.' ],
		[ 'unpublished', 'No longer available on show.fm.' ],
		[ 'deleted', 'No longer available on show.fm.' ],
		[ 'archived', 'Archived in show.fm.' ],
		[ 'paused', 'Paused because of the show’s plan.' ],
		[
			'external',
			'This show is hosted outside show.fm, so it can’t be embedded here.',
		],
		[
			'no_transcript',
			'There’s no transcript for this episode yet, so visitors won’t see this block.',
		],
	] )( '%s says “%s”', ( state, text ) => {
		render(
			<StateMessage
				label="show.fm Player"
				state={ state }
				onChooseEpisode={ vi.fn() }
				onChangeShow={ vi.fn() }
			/>
		);
		expect( screen.getByText( text ) ).toBeInTheDocument();
	} );

	it( 'offers the right way out for each state', () => {
		settings( {
			connected: false,
			canConnect: true,
			connectUrl: '/connect',
		} );
		const choose = vi.fn();
		const change = vi.fn();
		const { rerender } = render(
			<StateMessage
				state="not_found"
				onChooseEpisode={ choose }
				onChangeShow={ change }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Choose another episode' } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the address' } )
		);
		expect( choose ).toHaveBeenCalled();
		expect( change ).toHaveBeenCalled();

		rerender( <StateMessage state="not_public" /> );
		expect(
			screen.getByRole( 'link', {
				name: /Connect to show.fm to preview scheduled episodes/,
			} )
		).toHaveAttribute( 'href', '/connect' );

		rerender(
			<StateMessage
				state="unpublished"
				data={ { episode: { appUrl: 'https://my.show.fm/p/x/e/y' } } }
				onChooseEpisode={ choose }
			/>
		);
		expect(
			screen.getByRole( 'link', { name: /Open the show on show.fm/ } )
		).toHaveAttribute( 'href', 'https://my.show.fm/p/x/e/y' );

		rerender(
			<StateMessage
				state="deleted"
				data={ { episode: { appUrl: 'https://my.show.fm/p/x/e/y' } } }
				onChooseEpisode={ choose }
			/>
		);
		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();

		rerender( <StateMessage state="paused" /> );
		expect(
			screen.queryByRole( 'link', { name: /Go to billing/ } )
		).not.toBeInTheDocument();

		rerender( <StateMessage state="external" onChangeShow={ change } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Use another show' } )
		);
		expect( change ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'shows the scheduled and last-saved-copy strips', () => {
		const retry = vi.fn();
		const { rerender } = render(
			<StateStrip
				state="scheduled"
				data={ { episode: { date: '2030-10-14T09:00:00+00:00' } } }
				onRetry={ retry }
			/>
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			/Goes live on 14 October at .*Visitors won’t see this block until then\./
		);
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();

		rerender( <StateStrip state="error" onRetry={ retry } /> );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Couldn’t reach show.fm. Showing the last saved copy.'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( retry ).toHaveBeenCalled();

		rerender( <StateStrip state="rate_limited" onRetry={ retry } /> );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'show.fm is busy right now. Showing the last saved copy.'
		);
	} );
} );

describe( 'HeadingLevelDropdown default', () => {
	it( 'shows H2 without a level, as defaultLevel() does', () => {
		render( <HeadingLevelDropdown onChange={ vi.fn() } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Change level' } )
		);
		expect(
			screen.getByRole( 'menuitemradio', { name: 'Heading 2' } )
		).toHaveAttribute( 'aria-checked', 'true' );
	} );
} );

describe( 'HeadingLevelDropdown', () => {
	it( 'offers H2 to H6 and reports the choice', () => {
		const onChange = vi.fn();
		render( <HeadingLevelDropdown value="3" onChange={ onChange } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Change level' } )
		);
		const levels = screen.getAllByRole( 'menuitemradio' );
		expect( levels.map( ( item ) => item.textContent ) ).toEqual( [
			'Heading 2',
			'Heading 3',
			'Heading 4',
			'Heading 5',
			'Heading 6',
		] );
		expect( levels[ 1 ] ).toHaveAttribute( 'aria-checked', 'true' );
		fireEvent.click( levels[ 2 ] );
		expect( onChange ).toHaveBeenCalledWith( '4' );
	} );
} );
