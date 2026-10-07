/**
 * Entry point for Settings > show.fm.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './admin/app';
import './admin/admin.scss';

domReady( () => {
	const root = document.getElementById( 'showfm-settings' );
	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
