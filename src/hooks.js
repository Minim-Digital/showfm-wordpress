/**
 * Data hooks for the editor routes.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { editorPath, forget, request } from './api';

/**
 * Loads one editor route. Pass `enabled: false` to wait.
 *
 * @param {string}  route   Route under `showfm/v1/editor/`.
 * @param {Object}  args    Query arguments.
 * @param {boolean} enabled Whether to load now.
 * @return {{loading: boolean, data: Object|null, retry: () => void}} Result.
 */
export function useEditorData( route, args = {}, enabled = true ) {
	const key = enabled ? editorPath( route, args ) : null;
	const [ result, setResult ] = useState( { key: null, data: null } );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		if ( ! key ) {
			return undefined;
		}
		let live = true;
		request( route, args ).then( ( data ) => {
			if ( live ) {
				setResult( { key, data } );
			}
		} );
		return () => {
			live = false;
		};
		// `key` already captures `route` and `args`.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key, attempt ] );

	const retry = useCallback( () => {
		forget( route, args );
		setResult( { key: null, data: null } );
		setAttempt( ( value ) => value + 1 );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key ] );

	const current = key !== null && result.key === key;
	return {
		loading: key !== null && ! current,
		data: current ? result.data : null,
		retry,
	};
}
