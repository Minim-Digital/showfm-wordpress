import { describe, expect, it } from 'vitest';

import {
	connectionNotice,
	connectionStatus,
	disconnectedMessage,
	disconnectedNotice,
	hasConnection,
	keyRow,
	listNames,
	needsReconnect,
	updatesRow,
} from '../../src/admin/connection-view';
import { view } from './fixtures';

describe( 'connection view', () => {
	it( 'treats only a stored connection as connected for the tabs', () => {
		expect( hasConnection( view( { state: 'not_connected' } ) ) ).toBe(
			false
		);
		for ( const state of [
			'connected',
			'expired',
			'refused',
			'unreadable',
		] ) {
			expect( hasConnection( view( { state } ) ) ).toBe( true );
		}
		expect( hasConnection( null ) ).toBe( false );
	} );

	it( 'makes Reconnect primary only when the key no longer works', () => {
		expect( needsReconnect( view() ) ).toBe( false );
		expect( needsReconnect( view( { state: 'expiring' } ) ) ).toBe( false );
		for ( const state of [ 'expired', 'refused', 'unreadable' ] ) {
			expect( needsReconnect( view( { state } ) ) ).toBe( true );
		}
	} );

	it.each( [
		[ 'connected', {}, 'Connected', 'success' ],
		[ 'expiring', { daysLeft: 30 }, 'Expires in 30 days', 'warning' ],
		[ 'expiring', { daysLeft: 1 }, 'Expires in 1 day', 'warning' ],
		[ 'expired', {}, 'Expired', 'error' ],
		[ 'refused', {}, 'Disconnected', 'error' ],
		[ 'unreadable', {}, 'Needs reconnecting', 'error' ],
		[ 'paused', {}, 'Auto-posting paused', 'warning' ],
		[ 'scheduled', {}, 'Scheduled checks', 'info' ],
	] )( 'shows the %s status', ( state, extra, label, tone ) => {
		expect( connectionStatus( view( { state, ...extra } ) ) ).toEqual( {
			label,
			tone,
		} );
	} );

	it( 'describes the key for each state', () => {
		expect( keyRow( view() ) ).toEqual( {
			text: 'Key expires on 4 October 2027',
			note: 'Reconnecting renews it for another year.',
			error: false,
		} );
		expect( keyRow( view( { state: 'expired' } ) ).text ).toBe(
			'Key expired on 4 October 2027'
		);
		expect(
			keyRow(
				view( {
					state: 'refused',
					key: {
						masked: '',
						expiresOn: '',
						refusedOn: '6 October 2026',
					},
				} )
			)
		).toEqual( {
			text: 'Key cancelled on 6 October 2026',
			note: 'Changing the account password cancels every key.',
			error: true,
		} );
		expect( keyRow( view( { state: 'unreadable' } ) ).text ).toBe(
			'Key can’t be read'
		);
		expect(
			keyRow(
				view( { key: { masked: '', expiresOn: '', refusedOn: '' } } )
			).text
		).toBe( 'Key never expires' );
	} );

	it( 'describes updates for each state', () => {
		expect( updatesRow( view() ) ).toEqual( {
			text: 'Last checked 3 minutes ago',
			note: 'show.fm tells this site straight away when an episode changes.',
		} );
		expect(
			updatesRow( view( { state: 'scheduled', nextCheck: 12 } ) ).note
		).toBe( 'Next check in 12 minutes.' );
		expect( updatesRow( view( { state: 'expired' } ) ).note ).toBe(
			'Checks stopped when the key expired.'
		);
		expect( updatesRow( view( { lastChecked: '' } ) ).text ).toBe(
			'Not checked yet'
		);
	} );

	it( 'lists show names in a sentence', () => {
		expect( listNames( [] ) ).toBe( '' );
		expect( listNames( [ 'A' ] ) ).toBe( 'A' );
		expect( listNames( [ 'A', 'B' ] ) ).toBe( 'A and B' );
		expect( listNames( [ 'A', 'B', 'C' ] ) ).toBe( 'A, B and C' );
	} );

	it( 'has no notice for a healthy connection', () => {
		expect( connectionNotice( view() ) ).toBeNull();
	} );

	it.each( [
		[
			'expiring',
			{ daysLeft: 30, key: { expiresOn: '6 November 2026' } },
			'warning',
			'Your show.fm connection expires in 30 days.',
			'Reconnect before 6 November 2026 to keep posting new episodes here.',
			'reconnect',
		],
		[
			'expiring',
			{ daysLeft: 7, key: { expiresOn: '14 October 2026' } },
			'warning',
			'Your show.fm connection expires in 7 days.',
			'After 14 October 2026, new episodes stop being posted here.',
			'reconnect',
		],
		[
			'expired',
			{},
			'error',
			'Your show.fm connection has expired.',
			'New episodes aren’t being posted here. Blocks still play public episodes.',
			'reconnect',
		],
		[
			'refused',
			{},
			'error',
			'show.fm disconnected this site.',
			expect.stringContaining( 'account password changes' ),
			'reconnect',
		],
		[
			'paused',
			{},
			'warning',
			'Auto-posting is paused because the show’s plan doesn’t include connected sites.',
			'Embeds still work.',
			'link',
		],
		[
			'scheduled',
			{},
			'info',
			'Using scheduled checks.',
			expect.stringContaining( 'every 15 minutes' ),
			undefined,
		],
		[
			'unreadable',
			{},
			'error',
			'Reconnect to show.fm.',
			'This site’s security keys changed, so the saved connection can’t be used any more.',
			'reconnect',
		],
	] )(
		'shows the %s notice',
		( state, extra, status, title, text, action ) => {
			const notice = connectionNotice( view( { state, ...extra } ) );
			expect( notice ).toMatchObject( { status, title, text } );
			expect( notice.action?.type ).toBe( action );
			expect( notice.dismissible ).toBe( false );
		}
	);

	it( 'links the paused notice to the plan', () => {
		expect(
			connectionNotice( view( { state: 'paused' } ) ).action
		).toEqual( {
			type: 'link',
			label: 'Check your plan',
			url: 'https://my.show.fm/pricing',
		} );
	} );

	it( 'puts a failed connect first, with Try again when it helps', () => {
		const failed = connectionNotice(
			view( {
				state: 'not_connected',
				result: {
					status: 'failed',
					error: 'cancelled',
					message: 'The connection was cancelled.',
					action: 'retry',
				},
			} )
		);
		expect( failed ).toEqual( {
			status: 'error',
			title: 'Couldn’t connect to show.fm.',
			text: 'The connection was cancelled.',
			action: { type: 'retry', label: 'Try again' },
			dismissible: true,
			result: true,
		} );

		const https = connectionNotice(
			view( {
				state: 'not_connected',
				result: {
					status: 'failed',
					error: 'https_required',
					message: 'This site’s address must use https.',
					action: 'none',
				},
			} )
		);
		expect( https.action ).toBeNull();
	} );

	it( 'confirms a successful connect with the show names', () => {
		const notice = connectionNotice(
			view( {
				result: {
					status: 'connected',
					error: '',
					message: '',
					action: 'none',
				},
			} )
		);
		expect( notice ).toMatchObject( {
			status: 'success',
			title: 'Connected to show.fm.',
			text: 'New episodes of The Long Table and Second Helpings will be posted here.',
			dismissible: true,
		} );
	} );

	it( 'marks the stored connect outcome so dismissing can clear it', () => {
		const success = connectionNotice(
			view( {
				result: {
					status: 'connected',
					error: '',
					message: '',
					action: 'none',
				},
			} )
		);
		expect( success.result ).toBe( true );
		expect( connectionNotice( view( { state: 'expired' } ) ).result ).toBe(
			undefined
		);
	} );

	it( 'says after Disconnect that the key was revoked at show.fm', () => {
		const disconnected = {
			revoke: 'revoked',
			keyRevoked: true,
			message: 'This site’s key was revoked at show.fm.',
			sitesUrl: 'https://my.show.fm/p/the-long-table/settings/sites',
		};
		expect( disconnectedNotice( disconnected ) ).toEqual( {
			status: 'success',
			title: 'Disconnected from show.fm.',
			text: 'This site’s key was revoked at show.fm.',
			action: null,
			dismissible: true,
		} );
		expect( disconnectedMessage( disconnected ) ).toBe(
			'Disconnected from show.fm. This site’s key was revoked at show.fm.'
		);
	} );

	it( 'says after Disconnect when show.fm already refused the key', () => {
		const notice = disconnectedNotice( {
			revoke: 'refused',
			keyRevoked: true,
			message:
				'show.fm had already stopped accepting this site’s key, so there was nothing left to revoke.',
			sitesUrl: 'https://my.show.fm/dashboard',
		} );
		expect( notice.status ).toBe( 'success' );
		expect( notice.action ).toBeNull();
	} );

	it( 'warns after Disconnect when the key must be revoked at show.fm, with a link', () => {
		expect(
			disconnectedNotice( {
				revoke: 'not_revoked',
				keyRevoked: false,
				message:
					'show.fm couldn’t be reached to revoke this site’s key, so it may still work. Revoke it in show.fm under Connected sites.',
				sitesUrl: 'https://my.show.fm/p/the-long-table/settings/sites',
			} )
		).toEqual( {
			status: 'warning',
			title: 'Disconnected from show.fm.',
			text: 'show.fm couldn’t be reached to revoke this site’s key, so it may still work. Revoke it in show.fm under Connected sites.',
			action: {
				type: 'link',
				label: 'Open Connected sites in show.fm',
				url: 'https://my.show.fm/p/the-long-table/settings/sites',
			},
			dismissible: true,
		} );
	} );

	it( 'never says Connected once the site is not connected', () => {
		const success = {
			status: 'connected',
			error: '',
			message: '',
			action: 'none',
		};
		expect(
			connectionNotice(
				view( { state: 'not_connected', result: success } )
			)
		).toBeNull();
		for ( const state of [ 'expired', 'refused', 'unreadable' ] ) {
			expect(
				connectionNotice( view( { state, result: success } ) ).title
			).not.toBe( 'Connected to show.fm.' );
		}
	} );

	it( 'lets the state notice win over a successful connect', () => {
		const notice = connectionNotice(
			view( {
				state: 'paused',
				result: {
					status: 'connected',
					error: 'verify_failed',
					message: 'x',
					action: 'none',
				},
			} )
		);
		expect( notice.status ).toBe( 'warning' );
		expect( notice.title ).toContain( 'Auto-posting is paused' );
	} );
} );
