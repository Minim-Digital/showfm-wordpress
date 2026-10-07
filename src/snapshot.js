/**
 * The insert-time snapshot each block stores, so its first render needs no network.
 * The same rules as `Attributes::snapshot()` on the server: only a public episode is
 * snapshotted, links are https on show.fm hosts, and the title is at most 300 characters.
 */
import { getSettings } from './settings';

const MAX_TITLE = 300;

/**
 * Whether staging hosts are allowed too (the site uses the staging API).
 *
 * @return {boolean} Staging.
 */
function staging() {
	return getSettings().api === 'https://api.showfm.dev';
}

/**
 * The URL's host when it is a plain https URL (no credentials or port), else null.
 *
 * @param {unknown} value Candidate URL.
 * @return {string|null} Lower-case host.
 */
function httpsHost( value ) {
	if ( typeof value !== 'string' || ! /^https:\/\/[^\s]+$/i.test( value ) ) {
		return null;
	}
	try {
		const url = new URL( value );
		return url.username || url.password || url.port
			? null
			: url.hostname.toLowerCase();
	} catch {
		return null;
	}
}

/**
 * Whether the URL is a show.fm listen page.
 *
 * @param {unknown} value Candidate URL.
 * @return {boolean} Allowed.
 */
export function isListenUrl( value ) {
	const host = httpsHost( value );
	const roots = staging() ? [ 'show.fm', 'showfm.dev' ] : [ 'show.fm' ];
	return (
		!! host &&
		roots.some( ( root ) => host === root || host.endsWith( `.${ root }` ) )
	);
}

/**
 * Whether the URL is on a show.fm media host.
 *
 * @param {unknown} value Candidate URL.
 * @return {boolean} Allowed.
 */
export function isAudioUrl( value ) {
	const hosts = [ 'm.cdn.media', 'media.podcasterplus.com' ];
	if ( staging() ) {
		hosts.push( 'm.showfm.dev', 'media.podcasterplus.dev' );
	}
	return hosts.includes( httpsHost( value ) );
}

/**
 * @param {unknown} title Title.
 * @return {string} At most 300 characters.
 */
function cap( title ) {
	return typeof title === 'string' ? title.slice( 0, MAX_TITLE ) : '';
}

/**
 * Snapshot for a chosen episode. A scheduled (or otherwise not public) episode stores
 * nothing: the block renders nothing until show.fm confirms it is public.
 *
 * @param {Object} episode Episode from `editor/episode` or the picker.
 * @return {Object} Snapshot attribute.
 */
export function episodeSnapshot( episode ) {
	if ( episode.scheduled ) {
		return {};
	}
	const snapshot = { title: cap( episode.title ) };
	if ( isListenUrl( episode.listen ) ) {
		snapshot.listenUrl = episode.listen;
	}
	if ( isAudioUrl( episode.audio ) ) {
		snapshot.audioUrl = episode.audio;
	}
	return snapshot;
}

/**
 * Snapshot for a show (an episode list, or a player on the latest episode).
 *
 * @param {Object} show Show from `editor/show` or `editor/shows`.
 * @return {Object} Snapshot attribute.
 */
export function showSnapshot( show ) {
	const snapshot = { title: cap( show.title ) };
	if ( isListenUrl( show.listen ) ) {
		snapshot.listenUrl = show.listen;
	}
	return snapshot;
}
