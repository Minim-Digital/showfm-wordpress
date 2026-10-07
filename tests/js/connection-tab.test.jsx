import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ConnectionTab from '../../src/admin/connection-tab';
import { view } from './fixtures';

describe( 'Connection tab', () => {
	let submit;

	beforeEach( () => {
		submit = vi
			.spyOn( window.HTMLFormElement.prototype, 'requestSubmit' )
			.mockImplementation( () => {} );
	} );

	it( 'explains connecting and posts the connect form', () => {
		const { container } = render(
			<ConnectionTab view={ view( { state: 'not_connected' } ) } />
		);

		expect(
			screen.getByRole( 'heading', { name: 'Connect to show.fm' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Connecting adds' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Works without connecting' )
		).toBeInTheDocument();

		const form = container.querySelector( 'form' );
		expect( form ).toHaveAttribute(
			'action',
			'https://thelongtable.co/wp-admin/admin-post.php'
		);
		expect( form ).toHaveAttribute( 'method', 'post' );
		expect( form.querySelector( '[name="action"]' ) ).toHaveValue(
			'showfm_connect'
		);
		expect( form.querySelector( '[name="_wpnonce"]' ) ).toHaveValue(
			'abc123'
		);
		expect(
			within( form ).getByRole( 'button', { name: 'Connect to show.fm' } )
		).toHaveAttribute( 'type', 'submit' );
	} );

	it( 'shows the account, site, shows, key and updates', () => {
		render( <ConnectionTab view={ view() } /> );

		expect( screen.getByText( 'Connected' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Maya Lindgren' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'https://thelongtable.co' )
		).toBeInTheDocument();
		expect( screen.getByText( 'The Long Table' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'second-helpings.show.fm' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Key expires on 4 October 2027' )
		).toBeInTheDocument();
		expect( screen.getByText( '••••7f3a' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Last checked 3 minutes ago' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'img' ) ).toBeNull();
		expect(
			document.querySelector( 'img.showfm-show__art' )
		).toHaveAttribute( 'src', 'https://m.cdn.media/art.jpg' );
		expect(
			screen.queryByText( 'Auto-posting paused', {
				selector: '.showfm-show__paused',
			} )
		).toBeNull();
	} );

	it( 'leaves out rows show.fm has not told the site yet', () => {
		render( <ConnectionTab view={ view( { account: '', shows: [] } ) } /> );

		expect( screen.queryByText( 'show.fm account' ) ).toBeNull();
		expect( screen.queryByText( 'Connected shows' ) ).toBeNull();
		expect( screen.getByText( 'This site' ) ).toBeInTheDocument();
	} );

	it( 'marks each show as paused when the plan pauses auto-posting', () => {
		render( <ConnectionTab view={ view( { state: 'paused' } ) } /> );

		expect(
			screen.getAllByText( 'Auto-posting paused', {
				selector: '.showfm-show__paused',
			} )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'link', { name: 'Check your plan' } )
		).toHaveAttribute( 'href', 'https://my.show.fm/pricing' );
	} );

	it( 'reconnects from the notice through the connect form', async () => {
		render(
			<ConnectionTab
				view={ view( {
					state: 'expiring',
					daysLeft: 7,
					key: { ...view().key, expiresOn: '14 October 2026' },
				} ) }
			/>
		);

		expect(
			screen.getByText( 'Your show.fm connection expires in 7 days.' )
		).toBeInTheDocument();
		const notice = document.querySelector( '.showfm-notice' );
		await userEvent.click(
			within( notice ).getByRole( 'button', { name: 'Reconnect' } )
		);

		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'makes Reconnect the primary button once the key stops working', () => {
		render( <ConnectionTab view={ view( { state: 'expired' } ) } /> );

		const footer = document.querySelector( '.showfm-actions' );
		expect(
			within( footer ).getByRole( 'button', { name: 'Reconnect' } )
		).toHaveClass( 'is-primary' );
		expect(
			screen.getByText( 'Key expired on 4 October 2027' )
		).toHaveClass( 'showfm-error' );
	} );

	it( 'shows a failed connect with Try again', async () => {
		render(
			<ConnectionTab
				view={ view( {
					state: 'not_connected',
					result: {
						status: 'failed',
						error: 'expired',
						message: 'The connection request expired. Try again.',
						action: 'retry',
					},
				} ) }
			/>
		);

		expect(
			screen.getByText( 'Couldn’t connect to show.fm.' )
		).toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Try again' } )
		);
		expect( submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets the admin dismiss the success notice', async () => {
		render(
			<ConnectionTab
				view={ view( {
					result: {
						status: 'connected',
						error: '',
						message: '',
						action: 'none',
					},
				} ) }
			/>
		);

		expect(
			screen.getByText( 'Connected to show.fm.' )
		).toBeInTheDocument();
		await userEvent.click(
			within( document.querySelector( '.showfm-notice' ) ).getByRole(
				'button'
			)
		);
		expect( screen.queryByText( 'Connected to show.fm.' ) ).toBeNull();
	} );

	it( 'asks before disconnecting, and can be cancelled', async () => {
		const onDisconnect = vi.fn().mockResolvedValue();
		render(
			<ConnectionTab view={ view() } onDisconnect={ onDisconnect } />
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Disconnect from show.fm?',
		} );
		expect(
			within( dialog ).getByText( /Posts that were already created stay/ )
		).toBeInTheDocument();

		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( onDisconnect ).not.toHaveBeenCalled();
	} );

	it( 'disconnects when confirmed', async () => {
		const onDisconnect = vi.fn().mockResolvedValue();
		render(
			<ConnectionTab view={ view() } onDisconnect={ onDisconnect } />
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect' } )
		);
		await userEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Disconnect',
			} )
		);

		expect( onDisconnect ).toHaveBeenCalledTimes( 1 );
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
	} );

	it( 'keeps the dialog open with a message when disconnecting fails', async () => {
		const onDisconnect = vi.fn().mockRejectedValue( new Error( 'x' ) );
		render(
			<ConnectionTab view={ view() } onDisconnect={ onDisconnect } />
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Disconnect' } )
		);
		await userEvent.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Disconnect',
			} )
		);

		expect(
			await screen.findByText( 'Couldn’t disconnect. Try again.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );
} );
