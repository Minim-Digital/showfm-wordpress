/**
 * Dates, durations and the one-line episode summaries the pickers show.
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * "24 Sept 2026" style date in the site's time zone.
 *
 * @param {string} iso ISO date.
 * @return {string} Date.
 */
export function formatDate( iso ) {
	return iso ? dateI18n( 'j M Y', iso ) : '';
}

/**
 * "Goes live on 14 October at 09:00."
 *
 * @param {string} iso ISO date.
 * @return {string} Sentence.
 */
export function goesLive( iso ) {
	const formats = getDateSettings().formats || {};
	return sprintf(
		/* translators: 1: date, such as 14 October. 2: time, such as 09:00. */
		__( 'Goes live on %1$s at %2$s.', 'showfm' ),
		dateI18n( 'j F', iso ),
		dateI18n( formats.time || 'H:i', iso )
	);
}

/**
 * "52 min" or "1 hr 40 min".
 *
 * @param {number|null} seconds Duration.
 * @return {string} Duration, or '' when unknown.
 */
export function formatDuration( seconds ) {
	if ( typeof seconds !== 'number' || seconds <= 0 ) {
		return '';
	}
	const minutes = Math.max( 1, Math.round( seconds / 60 ) );
	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;
	if ( ! hours ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min', 'showfm' ), minutes );
	}
	if ( ! rest ) {
		/* translators: %d: hours. */
		return sprintf( __( '%d hr', 'showfm' ), hours );
	}
	/* translators: 1: hours. 2: minutes. */
	return sprintf( __( '%1$d hr %2$d min', 'showfm' ), hours, rest );
}

/**
 * "S2 · E4 · 24 Sept 2026 · 52 min", or for a scheduled episode
 * "S2 · E5 · Goes live on 14 October at 09:00".
 *
 * @param {Object} episode Picker episode.
 * @return {string} Summary.
 */
export function episodeMeta( episode ) {
	const parts = [];
	if ( typeof episode.season === 'number' ) {
		/* translators: %d: season number. */
		parts.push( sprintf( __( 'S%d', 'showfm' ), episode.season ) );
	}
	if ( episode.type === 'bonus' ) {
		parts.push( __( 'Bonus', 'showfm' ) );
	} else if ( episode.type === 'trailer' ) {
		parts.push( __( 'Trailer', 'showfm' ) );
	} else if ( typeof episode.number === 'number' ) {
		/* translators: %d: episode number. */
		parts.push( sprintf( __( 'E%d', 'showfm' ), episode.number ) );
	}
	if ( episode.date ) {
		parts.push(
			episode.scheduled
				? goesLive( episode.date ).replace( /\.$/, '' )
				: formatDate( episode.date )
		);
	}
	if ( ! episode.scheduled ) {
		const duration = formatDuration( episode.duration );
		if ( duration ) {
			parts.push( duration );
		}
	}
	return parts.join( ' · ' );
}
