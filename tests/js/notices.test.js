import apiFetch from '@wordpress/api-fetch';
import { describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: vi.fn( () => Promise.resolve() ),
} ) );

await import( '../../src/notices' );

describe( 'admin notice dismissal', () => {
	it( 'records the dismissed notice for this user', () => {
		document.body.innerHTML = `
			<div class="notice is-dismissible showfm-notice" data-showfm-notice="expiry7:1791363600">
				<p>Your show.fm connection expires in 7 days.</p>
				<button type="button" class="notice-dismiss"><span>Dismiss</span></button>
			</div>`;

		document.querySelector( '.notice-dismiss span' ).click();

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/showfm/v1/admin/notices/dismiss',
			method: 'POST',
			data: { key: 'expiry7:1791363600' },
		} );
	} );

	it( 'ignores other notices', () => {
		document.body.innerHTML = `
			<div class="notice is-dismissible"><button type="button" class="notice-dismiss"></button></div>`;

		document.querySelector( '.notice-dismiss' ).click();

		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
