import apiFetch from '@wordpress/api-fetch';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import App, { CONNECTION_PATH } from '../../src/admin/app';
import { allTabs } from '../../src/admin/tabs';
import { view } from './fixtures';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const NOTICE = {
	key: 'expiry30:1791363600',
	type: 'warning',
	text: 'Your show.fm connection expires in 30 days.',
	action: { label: 'Reconnect', url: 'https://example.org/reconnect' },
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

	it( 'shows the header and the Connection and Display tabs', async () => {
		serve( view() );
		render( <App /> );

		expect(
			screen.getByRole( 'heading', { name: 'show.fm', level: 1 } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Connection' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			screen.getByRole( 'tab', { name: 'Display' } )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'tab' ) ).toHaveLength( 2 );
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
		// The Connection tab shows its own notice, without the admin notice's link.
		expect(
			screen.queryByRole( 'link', { name: 'Reconnect' } )
		).toBeNull();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Display' } ) );
		expect(
			screen.getByText( NOTICE.text, { selector: 'p' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Reconnect' } )
		).toHaveAttribute( 'href', NOTICE.action.url );
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
		apiFetch.mockResolvedValueOnce( view( { state: 'not_connected' } ) );
		await userEvent.click(
			screen.getAllByRole( 'button', { name: 'Disconnect' } ).pop()
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/showfm/v1/admin/disconnect',
			method: 'POST',
		} );
	} );

	it( 'says when the settings cannot load', async () => {
		apiFetch.mockRejectedValue( new Error( 'x' ) );
		render( <App /> );

		expect(
			await screen.findByText( /couldn’t be loaded/, { selector: 'p' } )
		).toBeInTheDocument();
	} );
} );
