import apiFetch from '@wordpress/api-fetch';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import PublishingTab, {
	PUBLISHING_PATH,
	autoPostHelp,
	forPostType,
} from '../../src/admin/publishing-tab';
import { activity, publishing } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

/**
 * Renders the tab once its data has loaded.
 *
 * @param {Object} data Publishing data.
 */
async function open( data = publishing() ) {
	apiFetch.mockResolvedValueOnce( data );
	render( <PublishingTab /> );
	await screen.findByRole( 'heading', { name: 'Auto-posting' } );
}

describe( 'Publishing tab', () => {
	it( 'loads from the Publishing route', async () => {
		await open();

		expect( apiFetch ).toHaveBeenCalledWith( { path: PUBLISHING_PATH } );
		expect( PUBLISHING_PATH ).toBe( '/showfm/v1/admin/publishing' );
	} );

	it( 'shows every setting with its saved value (2a)', async () => {
		await open();

		expect(
			screen.getByText( 'Applies to The Long Table and Second Helpings.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
		).toBeChecked();
		expect(
			screen.getByText(
				'When an episode is published on show.fm, a post is created here. Scheduled episodes become scheduled posts.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'combobox', { name: 'Post type' } )
		).toHaveValue( 'post' );
		expect(
			screen.getByRole( 'combobox', { name: 'Category' } )
		).toHaveValue( '3' );
		expect(
			screen.getByRole( 'combobox', { name: 'Author' } )
		).toHaveValue( '1' );
		expect(
			screen.getByRole( 'combobox', { name: 'Post template' } )
		).toHaveValue( '' );
		expect(
			screen.getByRole( 'checkbox', { name: 'Include the transcript' } )
		).toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Use the episode artwork as the featured image',
			} )
		).toBeChecked();
	} );

	it( 'explains how sync works, without settings for it', async () => {
		await open();

		const rules = screen
			.getByRole( 'heading', { name: 'How sync works' } )
			.closest( '.showfm-rules' );
		expect( within( rules ).getAllByRole( 'listitem' ) ).toHaveLength( 4 );
		expect(
			within( rules ).getByText(
				'Unpublished episodes go back to draft.'
			)
		).toBeInTheDocument();
		expect(
			within( rules ).getByText( 'Deleted episodes go to the bin.' )
		).toBeInTheDocument();
		expect( within( rules ).queryByRole( 'checkbox' ) ).toBeNull();
	} );

	it( 'shows the empty state before anything is posted (2b)', async () => {
		await open();

		expect(
			screen.getByText( 'No episodes posted yet.' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'The next episode you publish on show.fm will appear here, with a link to its post.'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).toBeNull();
		expect( screen.queryByText( 'Last 20 events' ) ).toBeNull();
	} );

	it( 'lists recent activity with links to the posts', async () => {
		await open( publishing( { activity: activity() } ) );

		expect( screen.getByText( 'Last 20 events' ) ).toBeInTheDocument();
		const rows = within( screen.getByRole( 'table' ) ).getAllByRole(
			'row'
		);
		expect( rows ).toHaveLength( 4 );
		expect(
			within( rows[ 0 ] )
				.getAllByRole( 'columnheader' )
				.map( ( cell ) => cell.textContent )
		).toEqual( [ 'Time', 'Episode', 'What happened', 'Post' ] );
		expect( within( rows[ 1 ] ).getByText( 'Today, 09:00' ) ).toBeVisible();
		expect(
			within( rows[ 1 ] ).getByRole( 'link', { name: 'Edit post' } )
		).toHaveAttribute(
			'href',
			'https://thelongtable.co/wp-admin/post.php?post=12&action=edit'
		);
		expect(
			within( rows[ 2 ] ).getByRole( 'link', { name: 'View bin' } )
		).toBeInTheDocument();
		expect( within( rows[ 3 ] ).getByText( 'None' ) ).toBeInTheDocument();
		expect(
			within( rows[ 3 ] ).getByText(
				'Skipped. Auto-posting was paused by the show’s plan.'
			)
		).toHaveClass( 'is-muted' );
	} );

	it( 'dims the options and changes the help when auto-posting is off', async () => {
		await open();

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
		);

		expect(
			screen.getByText(
				'New episodes aren’t posted here. Posts that already exist keep updating.'
			)
		).toBeInTheDocument();
		expect(
			screen
				.getByRole( 'combobox', { name: 'Post type' } )
				.closest( '.showfm-publishing__options' )
		).toHaveClass( 'is-dimmed' );
	} );

	it( 'saves every setting and confirms with a snackbar (2c)', async () => {
		await open();

		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Post new episodes automatically',
			} )
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'Author' } ),
			'4'
		);
		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'Post template' } ),
			'single-episode.php'
		);
		await userEvent.click(
			screen.getByRole( 'checkbox', { name: 'Include the transcript' } )
		);
		const saved = {
			autoPost: false,
			postType: 'post',
			category: 3,
			author: 4,
			template: 'single-episode.php',
			transcript: false,
			featuredImage: true,
		};
		apiFetch.mockResolvedValueOnce(
			publishing( { settings: saved, saved: true } )
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: PUBLISHING_PATH,
			method: 'POST',
			data: saved,
		} );
		expect(
			await screen.findByText( 'Settings saved.', {
				selector: '.components-snackbar__content',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows why the server refused a setting', async () => {
		await open();
		apiFetch.mockRejectedValueOnce( {
			code: 'showfm_invalid_setting',
			message: 'Choose someone who can publish this post type.',
			data: { status: 400, field: 'author' },
		} );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText(
				'Choose someone who can publish this post type.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Settings saved.', {
				selector: '.components-snackbar__content',
			} )
		).toBeNull();
	} );

	it( 'marks the refused field invalid and moves focus to it', async () => {
		await open();
		apiFetch.mockRejectedValueOnce( {
			code: 'showfm_invalid_setting',
			message: 'Choose someone who can publish this post type.',
			data: { status: 400, field: 'author' },
		} );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		const author = screen.getByRole( 'combobox', { name: 'Author' } );
		await waitFor( () => expect( author ).toHaveFocus() );
		expect( author ).toHaveAttribute( 'aria-invalid', 'true' );
		expect(
			screen.getByRole( 'combobox', { name: 'Post type' } )
		).not.toHaveAttribute( 'aria-invalid' );

		await userEvent.selectOptions( author, '4' );
		expect( author ).not.toHaveAttribute( 'aria-invalid' );
	} );

	it( 'moves focus to the error when no field is named', async () => {
		await open();
		apiFetch.mockRejectedValueOnce( { code: 'fetch_error' } );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		const notice = (
			await screen.findByText(
				'The settings couldn’t be saved. Try again.',
				{ selector: 'p' }
			)
		).closest( '[tabindex="-1"]' );
		await waitFor( () => expect( notice ).toHaveFocus() );
	} );

	it( 'keeps focus on Save while saving', async () => {
		await open();
		let finish;
		apiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				finish = resolve;
			} )
		);
		const save = screen.getByRole( 'button', { name: 'Save changes' } );

		await userEvent.click( save );

		expect( save ).toHaveFocus();
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( save ).not.toBeDisabled();
		finish( publishing( { saved: true } ) );
		await waitFor( () =>
			expect( save ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
		expect( save ).toHaveFocus();
	} );

	it( 'shows a stored author the post type no longer offers as the first choice', async () => {
		const data = publishing();
		await open( {
			...data,
			settings: { ...data.settings, author: 99, template: 'gone.php' },
		} );

		expect(
			screen.getByRole( 'combobox', { name: 'Author' } )
		).toHaveValue( '1' );
		apiFetch.mockResolvedValueOnce( publishing( { saved: true } ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'POST',
				data: expect.objectContaining( { author: 1, template: '' } ),
			} )
		);
	} );

	it( 'says when saving fails for another reason', async () => {
		await open();
		apiFetch.mockRejectedValueOnce( { code: 'fetch_error' } );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText(
				'The settings couldn’t be saved. Try again.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
	} );

	it( 'shows a sync configuration problem above the settings', async () => {
		await open(
			publishing( {
				problem: {
					code: 'row_author',
					field: 'author',
					message:
						'New episodes aren’t being posted here because nobody on this site can publish the chosen post type. Choose another post type or author, then save.',
				},
			} )
		);

		expect(
			screen.getByText( /New episodes aren’t being posted here/, {
				selector: 'p',
			} )
		).toBeInTheDocument();
	} );

	it( 'disables Category for a post type without categories', async () => {
		await open();

		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'Post type' } ),
			'page'
		);

		const category = screen.getByRole( 'combobox', { name: 'Category' } );
		expect( category ).toBeDisabled();
		expect( category ).toHaveValue( '0' );
		expect(
			screen.getByText( 'Pages don’t have categories.' )
		).toBeInTheDocument();
	} );

	it( 'says when the settings cannot be loaded', async () => {
		apiFetch.mockRejectedValueOnce( { code: 'fetch_error' } );
		render( <PublishingTab /> );

		expect(
			await screen.findByText(
				'The publishing settings couldn’t be loaded. Reload the page to try again.',
				{ selector: 'p' }
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Publishing helpers', () => {
	it( 'keeps the author and template valid for a new post type', () => {
		const data = publishing();

		expect(
			forPostType( data, {
				...data.settings,
				postType: 'page',
				author: 4,
				template: 'single-episode.php',
			} )
		).toEqual( {
			...data.settings,
			postType: 'page',
			author: 1,
			template: '',
			category: 0,
		} );
		expect( forPostType( data, data.settings ) ).toEqual( data.settings );
	} );

	it( 'explains both auto-posting states', () => {
		expect( autoPostHelp( true ) ).toContain( 'a post is created here' );
		expect( autoPostHelp( false ) ).toContain( 'keep updating' );
	} );
} );
