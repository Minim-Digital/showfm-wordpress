/**
 * What the Migrate tab says for each step. Pure functions of the data from
 * `GET /showfm/v1/admin/migrate` and its rows, so every state can be tested without rendering.
 */
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { listNames } from './connection-view';

/** The tab's view and the base of its step routes. Admin preloads the same path. */
export const MIGRATE_PATH = '/showfm/v1/admin/migrate';

/** Report groups, in the order the report shows them. */
export const GROUPS = [ 'ready', 'choose', 'unmatched', 'already', 'review' ];

/**
 * Rows shown before "Show more", by group. Every embed that needs a choice shows at once,
 * since each one needs an answer.
 */
export const FIRST_ROWS = {
	ready: 5,
	choose: 25,
	unmatched: 5,
	already: 5,
	review: 25,
	changed: 5,
	failed: 25,
};

/** Rows each "Show more" adds. */
export const MORE_ROWS = 50;

/**
 * A count with the site's digit grouping: 1,280.
 *
 * @param {number} count Count.
 * @return {string} Formatted count.
 */
export function formatCount( count ) {
	return Number( count ?? 0 ).toLocaleString();
}

/**
 * Whole per cent done, from 0 to 100.
 *
 * @param {number} done  Done.
 * @param {number} total Total.
 * @return {number} Per cent.
 */
export function percent( done, total ) {
	if ( ! total ) {
		return 0;
	}
	return Math.min( 100, Math.floor( ( done * 100 ) / total ) );
}

/**
 * The title and help above a report group.
 *
 * @param {string} group Group.
 * @return {{title: string, help: string}} Heading.
 */
export function groupHeading( group ) {
	switch ( group ) {
		case 'ready':
			return {
				title: __( 'Ready to swap', 'showfm' ),
				help: __( 'One clear match each.', 'showfm' ),
			};
		case 'choose':
			return {
				title: __( 'Needs your choice', 'showfm' ),
				help: __(
					'More than one episode could match. Pick one, or leave it and it’s skipped.',
					'showfm'
				),
			};
		case 'unmatched':
			return {
				title: __( 'No match', 'showfm' ),
				help: __(
					'We couldn’t find these episodes on your connected shows. They stay as they are.',
					'showfm'
				),
			};
		case 'already':
			return {
				title: __( 'Already show.fm', 'showfm' ),
				help: __( 'These already use a show.fm block.', 'showfm' ),
			};
		default:
			return {
				title: __( 'Check by hand', 'showfm' ),
				help: __(
					'We couldn’t check these safely, so they stay as they are.',
					'showfm'
				),
			};
	}
}

/**
 * The four counts above the report.
 *
 * @param {Object} report The view's report.
 * @return {{key: string, count: number, label: string}[]} Tiles.
 */
export function summaryTiles( report ) {
	const { counts } = report;
	return [
		{
			key: 'ready',
			count: counts.ready,
			label: __( 'Ready to swap', 'showfm' ),
		},
		{
			key: 'choose',
			count: counts.choose,
			label: __( 'Need your choice', 'showfm' ),
		},
		{
			key: 'unmatched',
			count: counts.unmatched,
			label: __( 'No match', 'showfm' ),
		},
		{
			key: 'already',
			count: counts.already,
			label: __( 'Already show.fm', 'showfm' ),
		},
	];
}

/**
 * A row's status: its words and its tone (success, warning or muted).
 *
 * @param {string} status Row status.
 * @return {{label: string, tone: string}} Status.
 */
export function rowStatus( status ) {
	switch ( status ) {
		case 'ready':
		case 'chosen':
			return { label: __( 'Ready', 'showfm' ), tone: 'success' };
		case 'choose':
			return { label: __( 'Choose one', 'showfm' ), tone: 'warning' };
		case 'swapped':
			return { label: __( 'Swapped', 'showfm' ), tone: 'success' };
		case 'already':
			return { label: __( 'Nothing to do', 'showfm' ), tone: 'done' };
		case 'review':
			return { label: __( 'Check by hand', 'showfm' ), tone: 'warning' };
		default:
			return { label: __( 'Left as is', 'showfm' ), tone: 'muted' };
	}
}

/**
 * The report's heading: "Dry run · 43 embeds in 39 posts".
 *
 * @param {Object} report The view's report.
 * @return {string} Heading.
 */
export function reportTitle( report ) {
	return sprintf(
		/* translators: 1: number of embeds, for example "43 embeds", 2: number of posts, for example "39 posts". */
		__( 'Dry run · %1$s in %2$s', 'showfm' ),
		sprintf(
			/* translators: %s: number of embeds. */
			_n( '%s embed', '%s embeds', report.embeds, 'showfm' ),
			formatCount( report.embeds )
		),
		sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post', '%s posts', report.posts, 'showfm' ),
			formatCount( report.posts )
		)
	);
}

/**
 * The swap button and the dialog title: "Swap 32 embeds".
 *
 * @param {number} count Embeds to swap.
 * @return {string} Label.
 */
export function swapLabel( count ) {
	return sprintf(
		/* translators: %s: number of embeds. */
		_n( 'Swap %s embed', 'Swap %s embeds', count, 'showfm' ),
		formatCount( count )
	);
}

/**
 * The note beside the swap button: what the swap leaves alone.
 *
 * @param {Object} report The view's report.
 * @return {string} Note.
 */
export function skipNote( report ) {
	const waiting = report.counts.choose - report.chosen;
	const rest = __(
		'Embeds with no match, and ones already on show.fm, stay as they are.',
		'showfm'
	);
	if ( waiting < 1 ) {
		return rest;
	}
	return sprintf(
		/* translators: 1: sentence about embeds that still need a choice, 2: "Embeds with no match… stay as they are." */
		_x( '%1$s %2$s', 'migrate: skipped embeds, then what stays', 'showfm' ),
		sprintf(
			/* translators: %s: number of embeds. */
			_n(
				'%s embed still needs a choice and will be skipped.',
				'%s embeds still need a choice and will be skipped.',
				waiting,
				'showfm'
			),
			formatCount( waiting )
		),
		rest
	);
}

/**
 * The dialog's first line: how many posts change.
 *
 * @param {number} posts Posts the swap changes.
 * @return {string} Text.
 */
export function confirmText( posts ) {
	return sprintf(
		/* translators: %s: number of posts. */
		_n(
			'We’ll replace them with show.fm Player blocks in %s post. Nothing else in it changes, and it stays published.',
			'We’ll replace them with show.fm Player blocks in %s posts. Nothing else in those posts changes, and they stay published.',
			posts,
			'showfm'
		),
		formatCount( posts )
	);
}

/**
 * The results banner: "Swapped 32 embeds in 29 posts."
 *
 * @param {Object} swap The view's swap.
 * @return {string} Text.
 */
export function swappedText( swap ) {
	return sprintf(
		/* translators: 1: number of embeds, for example "32 embeds", 2: number of posts, for example "29 posts". */
		__( 'Swapped %1$s in %2$s.', 'showfm' ),
		sprintf(
			/* translators: %s: number of embeds. */
			_n( '%s embed', '%s embeds', swap.embeds, 'showfm' ),
			formatCount( swap.embeds )
		),
		sprintf(
			/* translators: %s: number of posts. */
			_n( '%s post', '%s posts', swap.posts, 'showfm' ),
			formatCount( swap.posts )
		)
	);
}

/**
 * What the swap left alone, under the results: "Not changed: 3 embeds that needed a choice,
 * 2 with no match and 6 already on show.fm." Empty when nothing was left.
 *
 * @param {Object} report The view's report.
 * @return {string} Text.
 */
export function notChangedText( report ) {
	const waiting = report.counts.choose - report.chosen;
	const parts = [];
	if ( waiting > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: number of embeds. */
				_n(
					'%s embed that needed a choice',
					'%s embeds that needed a choice',
					waiting,
					'showfm'
				),
				formatCount( waiting )
			)
		);
	}
	if ( report.counts.unmatched > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: number of embeds. */
				__( '%s with no match', 'showfm' ),
				formatCount( report.counts.unmatched )
			)
		);
	}
	if ( report.counts.already > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: number of embeds. */
				__( '%s already on show.fm', 'showfm' ),
				formatCount( report.counts.already )
			)
		);
	}
	if ( report.counts.review > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: number of embeds or posts. */
				__( '%s to check by hand', 'showfm' ),
				formatCount( report.counts.review )
			)
		);
	}
	if ( ! parts.length ) {
		return '';
	}
	return sprintf(
		/* translators: %s: list, for example "3 embeds that needed a choice, 2 with no match and 6 already on show.fm". */
		__( 'Not changed: %s.', 'showfm' ),
		listNames( parts )
	);
}

/**
 * The line above the scan's progress bar.
 *
 * @param {Object} scan The view's scan.
 * @return {string} Text.
 */
export function scanLine( scan ) {
	if ( scan.catalogue ) {
		return __( 'Getting your episodes from show.fm', 'showfm' );
	}
	return sprintf(
		/* translators: 1: posts checked, 2: posts to check. */
		__( 'Checked %1$s of %2$s', 'showfm' ),
		formatCount( scan.checked ),
		formatCount( scan.total )
	);
}

/**
 * The line under the scan's progress bar.
 *
 * @param {Object} scan The view's scan.
 * @return {string} Text.
 */
export function foundText( scan ) {
	const found = sprintf(
		/* translators: %s: number of embeds. */
		_n(
			'Found %s embed so far.',
			'Found %s embeds so far.',
			scan.found,
			'showfm'
		),
		formatCount( scan.found )
	);
	return sprintf(
		/* translators: 1: "Found 17 embeds so far.", 2: what happens if the admin leaves. */
		_x(
			'%1$s %2$s',
			'migrate: embeds found, then what happens on leaving',
			'showfm'
		),
		found,
		__(
			'If you leave this page, the scan carries on from here when you come back.',
			'showfm'
		)
	);
}

/**
 * The line under the swap's progress bar.
 *
 * @param {Object} swap The view's swap.
 * @return {string} Text.
 */
export function swapLine( swap ) {
	return sprintf(
		/* translators: 1: posts done, 2: posts to change. */
		__( 'Changed %1$s of %2$s posts', 'showfm' ),
		formatCount( swap.checked ),
		formatCount( swap.total )
	);
}

/**
 * The intro's note beside Scan posts.
 *
 * @param {number} posts Published posts and pages.
 * @return {string} Note.
 */
export function scanNote( posts ) {
	return sprintf(
		/* translators: %s: number of posts and pages. */
		_n(
			'Checks %s published post or page. Usually takes under a minute.',
			'Checks %s published posts and pages. Usually takes under a minute.',
			posts,
			'showfm'
		),
		formatCount( posts )
	);
}

/**
 * The problem to show from a failed step: `{ reason, message, retryAt }`.
 *
 * @param {Object} error REST error.
 * @return {{reason: string, message: string, retryAt: number}} Problem.
 */
export function problemFrom( error ) {
	if ( error?.data?.reason && error?.message ) {
		return {
			reason: error.data.reason,
			message: error.message,
			retryAt: error.data.retryAt ?? 0,
		};
	}
	return {
		reason: 'failed',
		message: __(
			'Something went wrong. Your progress is saved. Try again.',
			'showfm'
		),
		retryAt: 0,
	};
}

/**
 * What the problem notice offers: `reconnect`, `retry`, `refresh`, `restart` or nothing.
 *
 * @param {string} reason Problem reason.
 * @return {string} Action.
 */
export function problemAction( reason ) {
	switch ( reason ) {
		case 'not_connected':
		case 'connection_lost':
			return 'reconnect';
		case 'busy':
			return 'refresh';
		case 'reconnected':
			return 'restart';
		case 'stale':
		case 'invalid_choice':
		case 'swapped':
			return '';
		default:
			return 'retry';
	}
}

/**
 * Whether the tab should carry on a scan or swap by itself: one is under way, this admin
 * may run it, the admin didn't pause it, and nothing is wrong.
 *
 * @param {Object} view The view.
 * @return {string} `scan`, `swap`, or '' to wait.
 */
export function resumeStep( view ) {
	if ( ! view?.connected || view.problem ) {
		return '';
	}
	if ( view.lease && ! view.lease.mine ) {
		return '';
	}
	if ( view.phase === 'scanning' && ! view.scan?.stopped ) {
		return 'scan';
	}
	if ( view.phase === 'swapping' ) {
		return 'swap';
	}
	return '';
}
