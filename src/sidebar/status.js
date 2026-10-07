/**
 * The sync status box at the top of the "show.fm" post panel (area 7, 7a to 7e), from the
 * `showfm_sync` REST field (WP-4a's post meta).
 */
import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * "today at 09:00" or "on 24 Sept 2026 at 09:00", in the site's time zone.
 *
 * @param {string} iso Time of the last update.
 * @param {Date}   now Now, for tests.
 * @return {string} When.
 */
export function updatedWhen( iso, now = new Date() ) {
	const formats = getDateSettings().formats || {};
	const time = dateI18n( formats.time || 'H:i', iso );
	if ( dateI18n( 'Y-m-d', iso ) === dateI18n( 'Y-m-d', now ) ) {
		/* translators: %s: time, such as 09:00. */
		return sprintf( __( 'today at %s', 'showfm' ), time );
	}
	return sprintf(
		/* translators: 1: date. 2: time. */
		__( 'on %1$s at %2$s', 'showfm' ),
		dateI18n( 'j M Y', iso ),
		time
	);
}

/**
 * What the status box says, or null when the post is not synced from show.fm.
 *
 * @param {Object|null} sync `showfm_sync` field.
 * @param {Date}        now  Now, for tests.
 * @return {{tone: string, icon: string, title: string, sub: string}|null} Status.
 */
export function syncStatus( sync, now = new Date() ) {
	if ( ! sync || ( ! sync.synced && ! sync.state ) ) {
		return null;
	}
	switch ( sync.state ) {
		case 'deleted':
			return {
				tone: 'error',
				icon: 'trash',
				title: __( 'The episode was deleted on show.fm.', 'showfm' ),
				sub: __(
					'This post isn’t synced any more. Choose another episode to connect it again.',
					'showfm'
				),
			};
		case 'unpublished':
			return {
				tone: 'warning',
				icon: 'backup',
				title: __(
					'The episode was unpublished on show.fm.',
					'showfm'
				),
				sub: __(
					'This post went back to draft. It will publish again if the episode does.',
					'showfm'
				),
			};
		case 'removed':
			return {
				tone: 'warning',
				icon: 'backup',
				title: __(
					'show.fm stopped posting this episode to this site.',
					'showfm'
				),
				sub: __( 'This post went back to draft.', 'showfm' ),
			};
		case 'paused':
			return {
				tone: 'warning',
				icon: 'warning',
				title: __( 'Paused because of the show’s plan.', 'showfm' ),
				sub: __(
					'show.fm updates this post again when the plan allows it.',
					'showfm'
				),
			};
		case 'detached':
		case 'local_detached':
			return {
				tone: 'warning',
				icon: 'warning',
				title: __( 'No longer synced from show.fm.', 'showfm' ),
				sub:
					sync.state === 'detached'
						? __(
								'This site no longer has access to the show.',
								'showfm'
							)
						: __(
								'This post was moved to the bin here, so show.fm stopped updating it.',
								'showfm'
							),
			};
	}
	if ( sync.edited ) {
		return {
			tone: 'warning',
			icon: 'edit',
			title: __(
				'Edited here, so show.fm now only updates the date and status.',
				'showfm'
			),
			sub: __(
				'Changes on show.fm to the title, description or artwork no longer reach this post.',
				'showfm'
			),
		};
	}
	return {
		tone: 'success',
		icon: 'yes-alt',
		title: __( 'In sync with show.fm', 'showfm' ),
		sub: sync.syncedAt
			? sprintf(
					/* translators: %s: when, such as "today at 09:00". */
					__( 'Updated from show.fm %s.', 'showfm' ),
					updatedWhen( sync.syncedAt, now )
				)
			: '',
	};
}

/**
 * Whether any block, at any depth, binds to the `showfm/episode` source.
 *
 * @param {Object[]} blocks Blocks.
 * @return {boolean} Whether one does.
 */
export function hasEpisodeBindings( blocks ) {
	return ( blocks || [] ).some(
		( block ) =>
			Object.values( block.attributes?.metadata?.bindings || {} ).some(
				( binding ) => binding?.source === 'showfm/episode'
			) || hasEpisodeBindings( block.innerBlocks )
	);
}
