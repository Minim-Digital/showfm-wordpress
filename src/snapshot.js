/**
 * The insert-time snapshot each block stores, so its first render needs no network.
 * Only https links, and the audio URL only for a public episode.
 */

const HTTPS = /^https:\/\/[^\s]+$/i;

/**
 * @param {unknown} value Candidate URL.
 * @return {boolean} Whether it is an https URL.
 */
function isHttps( value ) {
	return typeof value === 'string' && HTTPS.test( value );
}

/**
 * Snapshot for a chosen episode.
 *
 * @param {Object} episode Episode from `editor/episode` or the picker.
 * @return {Object} Snapshot attribute.
 */
export function episodeSnapshot( episode ) {
	const snapshot = { title: episode.title || '' };
	if ( ! episode.scheduled && isHttps( episode.listen ) ) {
		snapshot.listenUrl = episode.listen;
	}
	if ( ! episode.scheduled && isHttps( episode.audio ) ) {
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
	const snapshot = { title: show.title || '' };
	if ( isHttps( show.listen ) ) {
		snapshot.listenUrl = show.listen;
	}
	return snapshot;
}
