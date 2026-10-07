/**
 * Reads from the plugin's editor routes (`showfm/v1/editor/*`). The site key stays on the
 * server; the browser only ever sees the normalised answers.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const answers = new Map();

/** States worth asking again for, so they are not kept. */
const RETRYABLE = [ 'error', 'rate_limited' ];

/**
 * The request path for a route and its query.
 *
 * @param {string} route Route under `showfm/v1/editor/`.
 * @param {Object} args  Query arguments.
 * @return {string} Path.
 */
export function editorPath( route, args = {} ) {
	const query = Object.fromEntries(
		Object.entries( args ).filter(
			( [ , value ] ) => value !== undefined && value !== ''
		)
	);
	return addQueryArgs( `/showfm/v1/editor/${ route }`, query );
}

/**
 * One answer from an editor route. Each path is asked once per page load unless it failed.
 *
 * @param {string} route Route under `showfm/v1/editor/`.
 * @param {Object} args  Query arguments.
 * @return {Promise<Object>} The answer, always with a `state`.
 */
export function request( route, args = {} ) {
	const path = editorPath( route, args );
	if ( ! answers.has( path ) ) {
		const answer = apiFetch( { path } )
			.then( ( body ) =>
				body && typeof body.state === 'string'
					? body
					: { state: 'error' }
			)
			.catch( () => ( { state: 'error' } ) )
			.then( ( body ) => {
				if ( RETRYABLE.includes( body.state ) ) {
					answers.delete( path );
				}
				return body;
			} );
		answers.set( path, answer );
	}
	return answers.get( path );
}

/**
 * Forgets one answer, or all of them.
 *
 * @param {string} [route] Route.
 * @param {Object} [args]  Query arguments.
 */
export function forget( route, args = {} ) {
	if ( route ) {
		answers.delete( editorPath( route, args ) );
	} else {
		answers.clear();
	}
}
