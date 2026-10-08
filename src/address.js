import { getSettings } from './settings';

/**
 * Turns what someone types for a show into a slug or UUID the API accepts.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const RESERVED = [ 'my', 'api', 'www', 'embed', 'm' ];

/**
 * A show slug or UUID from an address (`the-long-table.show.fm`, `https://show.fm/slug`),
 * a slug, or a UUID. Null when it is none of those.
 *
 * @param {string} input What was typed.
 * @return {string|null} Slug or UUID.
 */
export function parseShowAddress( input ) {
	const value = String( input || '' ).trim();
	if ( ! value ) {
		return null;
	}
	if ( UUID.test( value ) ) {
		return value.toLowerCase();
	}
	if ( value.includes( '.' ) || value.includes( '/' ) ) {
		let url;
		try {
			url = new URL(
				/^[a-z][a-z0-9+.-]*:\/\//i.test( value )
					? value
					: `https://${ value }`
			);
		} catch {
			return null;
		}
		const host = url.hostname.toLowerCase().replace( /^www\./, '' );
		for ( const base of getSettings().listenRoots ) {
			if ( host === base ) {
				const first = url.pathname.split( '/' ).filter( Boolean )[ 0 ];
				const slug = first ? first.toLowerCase() : '';
				return SLUG.test( slug ) ? slug : null;
			}
			if ( host.endsWith( `.${ base }` ) ) {
				const sub = host.slice( 0, -base.length - 1 );
				return SLUG.test( sub ) && ! RESERVED.includes( sub )
					? sub
					: null;
			}
		}
		return null;
	}
	const slug = value.toLowerCase();
	return SLUG.test( slug ) ? slug : null;
}
