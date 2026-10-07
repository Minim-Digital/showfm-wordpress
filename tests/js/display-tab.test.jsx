import apiFetch from '@wordpress/api-fetch';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import DisplayTab, { DISPLAY_PATH } from '../../src/admin/display-tab';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

const DEFAULTS = {
	showfm_show_credit: false,
	showfm_load_on_click: false,
	showfm_json_ld: true,
	showfm_theme_styles: true,
};

describe( 'Display tab', () => {
	it( 'reads only its own settings from the core settings route', () => {
		expect( DISPLAY_PATH ).toBe(
			'/wp/v2/settings?_fields=showfm_show_credit,showfm_load_on_click,showfm_json_ld,showfm_theme_styles'
		);
	} );

	it( 'shows the four switches with their saved values', async () => {
		apiFetch.mockResolvedValueOnce( DEFAULTS );
		render( <DisplayTab /> );

		expect(
			await screen.findByRole( 'checkbox', {
				name: 'Show “Powered by show.fm”',
			} )
		).not.toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Load players only after a visitor clicks',
			} )
		).not.toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Add episode structured data for search engines',
			} )
		).toBeChecked();
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Use my theme’s colours and fonts',
			} )
		).toBeChecked();
		expect( apiFetch ).toHaveBeenCalledWith( { path: DISPLAY_PATH } );
	} );

	it( 'saves the switches together', async () => {
		apiFetch.mockResolvedValueOnce( DEFAULTS );
		render( <DisplayTab /> );

		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'Load players only after a visitor clicks',
			} )
		);
		apiFetch.mockResolvedValueOnce( {
			...DEFAULTS,
			showfm_load_on_click: true,
		} );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: DISPLAY_PATH,
			method: 'POST',
			data: { ...DEFAULTS, showfm_load_on_click: true },
		} );
		expect(
			await screen.findByText( 'Settings saved.', { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'says when saving fails', async () => {
		apiFetch.mockResolvedValueOnce( DEFAULTS );
		render( <DisplayTab /> );
		await screen.findByRole( 'checkbox', {
			name: 'Show “Powered by show.fm”',
		} );

		apiFetch.mockRejectedValueOnce( new Error( 'x' ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		expect(
			await screen.findByText(
				'The settings couldn’t be saved. Try again.',
				{
					selector: 'p',
				}
			)
		).toBeInTheDocument();
	} );
} );
