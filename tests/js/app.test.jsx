import apiFetch from '@wordpress/api-fetch';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import App, { CONNECTION_PATH } from '../../src/admin/app';
import { allTabs } from '../../src/admin/tabs';
import { publishing, view } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const NOTICE = {
	key: 'expiry30:1791363600',
	type: 'warning',
	text: 'Your show.fm connection expires in 30 days.',
	action: { type: 'reconnect', label: 'Reconnect', url: '' },
};

const PLAN_NOTICE = {
	key: 'paused:1700000000',
	type: 'warning',
	text: 'Auto-posting is paused.',
	action: {
		type: 'link',
		label: 'Check your plan',
		url: 'https://my.show.fm/pricing',
	},
};

/**
 * Answers the routes the app calls.
 *
 * @param {Object} connection Connection data.
 */
function serve( connection ) {
	apiFetch.mockImplementation( ( { path } ) => {
		if ( path === CONNECTION_PATH ) {
			return Promise.resolve( connection );
		}
		if ( path === '/showfm/v1/admin/publishing' ) {
			return Promise.resolve( publishing() );
		}
		if ( path.startsWith( '/wp/v2/settings' ) ) {
			return Promise.resolve( {
				showfm_show_credit: false,
				showfm_load_on_click: false,
				showfm_json_ld: true,
				showfm_theme_styles: true,
			} );
		}
		return Promise.resolve( { dismissed: true } );
	} );
}

describe( 'Settings app', () => {
	beforeEach( () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm'
		);
	} );

	it( 'shows the header and the Connection, Publishing and Display tabs while connected', async () => {
		serve( view() );
		render( <App /> );

		expect(
			screen.getByRole( 'heading', { name: 'show.fm', level: 1 } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Connection' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen.getAllByRole( 'tab' ).map( ( tab ) => tab.textContent )
		).toEqual( [ 'Connection', 'Publishing', 'Display' ] );
	} );

	it( 'opens the Publishing tab, wider, without repeating its own problem as an admin notice', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=publishing'
		);
		serve(
			view( {
				notice: {
					key: 'sync:row_author:3f2b8c1e',
					type: 'warning',
					text: 'New episodes aren’t being posted here because the chosen author can’t publish posts.',
					action: {
						type: 'link',
						label: 'Check the Publishing settings',
						url: '/wp-admin/options-general.php?page=showfm&tab=publishing',
					},
				},
			} )
		);
		render( <App /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Auto-posting' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'tab', { name: 'Publishing' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen
				.getByRole( 'heading', { name: 'Auto-posting' } )
				.closest( '.showfm-settings__content' )
		).toHaveClass( 'is-wide' );
		expect(
			screen.queryByText( /the chosen author can’t publish posts/ )
		).toBeNull();
	} );

	it( 'shows other admin notices on the Publishing tab', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=publishing'
		);
		serve( view( { notice: PLAN_NOTICE } ) );
		render( <App /> );

		expect(
			await screen.findByText( PLAN_NOTICE.text, { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'hides the Publishing tab while not connected', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=publishing'
		);
		serve( view( { state: 'not_connected' } ) );
		render( <App /> );

		expect(
			await screen.findByRole( 'tab', { name: 'Connection' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen.queryByRole( 'tab', { name: 'Publishing' } )
		).toBeNull();
	} );

	it( 'shows no empty tabs: every tab renders content', () => {
		for ( const tab of allTabs() ) {
			expect( typeof tab.render ).toBe( 'function' );
		}
	} );

	it( 'opens the tab named in the address and keeps it there', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=display'
		);
		serve( view() );
		render( <App /> );

		expect(
			await screen.findByRole( 'tab', { name: 'Display' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		await userEvent.click(
			screen.getByRole( 'tab', { name: 'Connection' } )
		);
		expect( window.location.search ).toContain( 'tab=connection' );
	} );

	it( 'shows the admin notice on Display but not on Connection', async () => {
		serve( view( { state: 'expiring', daysLeft: 30, notice: NOTICE } ) );
		render( <App /> );

		await screen.findByRole( 'tab', { name: 'Connection' } );
		// The Connection tab shows its own notice instead.
		expect(
			screen.queryByText( NOTICE.text, { selector: 'p' } )
		).toBeNull();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Display' } ) );
		expect(
			screen.getByText( NOTICE.text, { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'reconnects from the admin notice with a POST form, not a link', async () => {
		const submit = vi
			.spyOn( window.HTMLFormElement.prototype, 'requestSubmit' )
			.mockImplementation( () => {} );
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=display'
		);
		serve( view( { notice: NOTICE } ) );
		const { container } = render( <App /> );

		const button = await screen.findByRole( 'button', {
			name: 'Reconnect',
		} );
		expect(
			screen.queryByRole( 'link', { name: 'Reconnect' } )
		).toBeNull();
		const form = container.querySelector( 'form[hidden]' );
		expect( form ).toHaveAttribute( 'method', 'post' );
		expect( form ).toHaveAttribute(
			'action',
			'https://thelongtable.co/wp-admin/admin-post.php'
		);
		expect( form.querySelector( '[name="_wpnonce"]' ) ).toHaveValue(
			'abc123'
		);

		await userEvent.click( button );
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps link actions as links', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=display'
		);
		serve( view( { notice: PLAN_NOTICE } ) );
		render( <App /> );

		expect(
			await screen.findByRole( 'link', { name: 'Check your plan' } )
		).toHaveAttribute( 'href', 'https://my.show.fm/pricing' );
	} );

	it( 'brings the admin notice back when dismissing fails', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=display'
		);
		serve( view( { notice: NOTICE } ) );
		render( <App /> );
		const text = await screen.findByText( NOTICE.text, { selector: 'p' } );

		apiFetch.mockRejectedValueOnce( new Error( 'x' ) );
		await userEvent.click(
			text
				.closest( '.components-notice' )
				.querySelector( '.components-notice__dismiss' )
		);

		expect(
			await screen.findByText( NOTICE.text, { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'dismisses the admin notice for this user', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/options-general.php?page=showfm&tab=display'
		);
		serve( view( { notice: NOTICE } ) );
		render( <App /> );

		const text = await screen.findByText( NOTICE.text, { selector: 'p' } );
		await userEvent.click(
			text
				.closest( '.components-notice' )
				.querySelector( '.components-notice__dismiss' )
		);

		expect(
			screen.queryByText( NOTICE.text, { selector: 'p' } )
		).toBeNull();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/showfm/v1/admin/notices/dismiss',
			method: 'POST',
			data: { key: NOTICE.key },
		} );
	} );

	it( 'returns to the connect card after disconnecting', async () => {
		serve( view() );
		render( <App /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Disconnect' } )
		);
		apiFetch.mockResolvedValueOnce(
			view( {
				state: 'not_connected',
				disconnected: {
					revoke: 'not_revoked',
					keyRevoked: false,
					message:
						'show.fm couldn’t be reached to revoke this site’s key, so it may still work. Revoke it in show.fm under Connected sites.',
					sitesUrl:
						'https://my.show.fm/p/the-long-table/settings/sites',
				},
			} )
		);
		await userEvent.click(
			screen.getAllByRole( 'button', { name: 'Disconnect' } ).pop()
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/showfm/v1/admin/disconnect',
			method: 'POST',
			data: { state: '3f2b8c1e-9a4d-4e6f-8b7c-1d2e3f4a5b6c' },
		} );
		expect(
			screen.getByText( 'Disconnected from show.fm.', {
				selector: 'strong',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: /Open Connected sites in show.fm/,
			} )
		).toHaveAttribute(
			'href',
			'https://my.show.fm/p/the-long-table/settings/sites'
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'heading', { name: 'Connect to show.fm' } )
			).toHaveFocus()
		);
	} );

	it( 'disconnects nothing when the connection changed elsewhere, and shows the fresh state', async () => {
		serve( view() );
		render( <App /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Disconnect' } )
		);
		apiFetch.mockRejectedValueOnce( {
			code: 'showfm_state_moved',
			message:
				'The connection changed in another tab or by another admin, so nothing was disconnected. Check it, then try again.',
			data: {
				status: 409,
				view: view( {
					state: 'expiring',
					daysLeft: 30,
					stateId: 'other',
				} ),
			},
		} );
		await userEvent.click(
			screen.getAllByRole( 'button', { name: 'Disconnect' } ).pop()
		);

		expect(
			await screen.findByText( /so nothing was disconnected/, {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Expires in 30 days' ) ).toBeInTheDocument();
	} );

	it( 'says when the settings cannot load', async () => {
		apiFetch.mockRejectedValue( new Error( 'x' ) );
		render( <App /> );

		expect(
			await screen.findByText( /couldn’t be loaded/, { selector: 'p' } )
		).toBeInTheDocument();
	} );
} );
