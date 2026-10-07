/**
 * What the Connection tab says for each state. Pure functions of the data from
 * `GET /showfm/v1/admin/connection`, so every state can be tested without rendering.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/** States where the stored key no longer works and Reconnect is the main action. */
const BROKEN = [ 'expired', 'refused', 'unreadable' ];

/**
 * Whether the site has a stored connection, working or not. The Publishing and Migrate
 * tabs exist only then.
 *
 * @param {Object} view Connection data.
 * @return {boolean} Whether a connection is stored.
 */
export function hasConnection( view ) {
	return !! view && view.state !== 'not_connected';
}

/**
 * Whether Reconnect is the primary button.
 *
 * @param {Object} view Connection data.
 * @return {boolean} Whether the key no longer works.
 */
export function needsReconnect( view ) {
	return BROKEN.includes( view.state );
}

/**
 * The status shown in the card header: a label and a tone for its dot.
 *
 * @param {Object} view Connection data.
 * @return {{label: string, tone: string}} Status.
 */
export function connectionStatus( view ) {
	switch ( view.state ) {
		case 'expiring':
			return {
				label: sprintf(
					/* translators: %d: number of days. */
					_n(
						'Expires in %d day',
						'Expires in %d days',
						view.daysLeft,
						'showfm'
					),
					view.daysLeft
				),
				tone: 'warning',
			};
		case 'expired':
			return { label: __( 'Expired', 'showfm' ), tone: 'error' };
		case 'refused':
			return { label: __( 'Disconnected', 'showfm' ), tone: 'error' };
		case 'unreadable':
			return {
				label: __( 'Needs reconnecting', 'showfm' ),
				tone: 'error',
			};
		case 'paused':
			return {
				label: __( 'Auto-posting paused', 'showfm' ),
				tone: 'warning',
			};
		case 'scheduled':
			return { label: __( 'Scheduled checks', 'showfm' ), tone: 'info' };
		default:
			return { label: __( 'Connected', 'showfm' ), tone: 'success' };
	}
}

/**
 * The Key row: a line, an optional note and whether the line is an error.
 *
 * @param {Object} view Connection data.
 * @return {{text: string, note: string, error: boolean}} Key row.
 */
export function keyRow( view ) {
	const { expiresOn, refusedOn } = view.key;
	switch ( view.state ) {
		case 'expired':
			return {
				/* translators: %s: a date. */
				text: sprintf( __( 'Key expired on %s', 'showfm' ), expiresOn ),
				note: __( 'Reconnect to get a new key.', 'showfm' ),
				error: true,
			};
		case 'refused':
			return {
				text: refusedOn
					? sprintf(
							/* translators: %s: a date. */
							__( 'Key cancelled on %s', 'showfm' ),
							refusedOn
						)
					: __( 'Key cancelled', 'showfm' ),
				note: __(
					'Changing the account password cancels every key.',
					'showfm'
				),
				error: true,
			};
		case 'unreadable':
			return {
				text: __( 'Key can’t be read', 'showfm' ),
				note: __( 'This site’s security keys changed.', 'showfm' ),
				error: true,
			};
		default:
			return {
				text: expiresOn
					? sprintf(
							/* translators: %s: a date. */
							__( 'Key expires on %s', 'showfm' ),
							expiresOn
						)
					: __( 'Key never expires', 'showfm' ),
				note: __(
					'Reconnecting renews it for another year.',
					'showfm'
				),
				error: false,
			};
	}
}

/**
 * The Updates row: when the site last checked show.fm, and how it hears about changes.
 *
 * @param {Object} view Connection data.
 * @return {{text: string, note: string}} Updates row.
 */
export function updatesRow( view ) {
	const text = view.lastChecked
		? /* translators: %s: how long ago, for example "3 minutes ago". */
			sprintf( __( 'Last checked %s', 'showfm' ), view.lastChecked )
		: __( 'Not checked yet', 'showfm' );
	const notes = {
		expired: __( 'Checks stopped when the key expired.', 'showfm' ),
		refused: __( 'Checks stopped when the key was cancelled.', 'showfm' ),
		unreadable: __( 'Checks stopped until you reconnect.', 'showfm' ),
		scheduled: view.nextCheck
			? sprintf(
					/* translators: %d: number of minutes. */
					_n(
						'Next check in %d minute.',
						'Next check in %d minutes.',
						view.nextCheck,
						'showfm'
					),
					view.nextCheck
				)
			: __( 'Checks every 15 minutes.', 'showfm' ),
	};
	return {
		text,
		note:
			notes[ view.state ] ??
			__(
				'show.fm tells this site straight away when an episode changes.',
				'showfm'
			),
	};
}

/**
 * A list of show names in a sentence: "A", "A and B", "A, B and C".
 *
 * @param {string[]} names Show names.
 * @return {string} The list.
 */
export function listNames( names ) {
	if ( names.length < 2 ) {
		return names[ 0 ] ?? '';
	}
	const start = names.slice( 0, -1 ).reduce( ( list, name ) =>
		/* translators: 1: show names so far, 2: the next show name. */
		sprintf( __( '%1$s, %2$s', 'showfm' ), list, name )
	);
	return sprintf(
		/* translators: 1: show names separated by commas, 2: the last show name. */
		__( '%1$s and %2$s', 'showfm' ),
		start,
		names[ names.length - 1 ]
	);
}

/**
 * The one notice the Connection tab shows, or null. A failed connect attempt comes first,
 * then the state's own message, then the outcome of a successful connect.
 *
 * Actions: `reconnect` and `retry` start the connect flow; `link` opens `url`. A notice
 * with `result` is the stored connect outcome: it stays until the admin dismisses it.
 *
 * @param {Object} view Connection data.
 * @return {Object|null} Notice: status, title, text, action and whether it can be dismissed.
 */
export function connectionNotice( view ) {
	const { result } = view;
	if ( result && result.status === 'failed' ) {
		const action =
			result.action === 'retry'
				? { type: 'retry', label: __( 'Try again', 'showfm' ) }
				: null;
		return {
			status: 'error',
			title: __( 'Couldn’t connect to show.fm.', 'showfm' ),
			text: result.message,
			action,
			dismissible: true,
			result: true,
		};
	}

	const reconnect = { type: 'reconnect', label: __( 'Reconnect', 'showfm' ) };
	const { expiresOn } = view.key;
	switch ( view.state ) {
		case 'expiring':
			return {
				status: 'warning',
				title: sprintf(
					/* translators: %d: number of days. */
					_n(
						'Your show.fm connection expires in %d day.',
						'Your show.fm connection expires in %d days.',
						view.daysLeft,
						'showfm'
					),
					view.daysLeft
				),
				text:
					view.daysLeft <= 7
						? sprintf(
								/* translators: %s: a date. */
								__(
									'After %s, new episodes stop being posted here.',
									'showfm'
								),
								expiresOn
							)
						: sprintf(
								/* translators: %s: a date. */
								__(
									'Reconnect before %s to keep posting new episodes here.',
									'showfm'
								),
								expiresOn
							),
				action: reconnect,
				dismissible: false,
			};
		case 'expired':
			return {
				status: 'error',
				title: __( 'Your show.fm connection has expired.', 'showfm' ),
				text: __(
					'New episodes aren’t being posted here. Blocks still play public episodes.',
					'showfm'
				),
				action: reconnect,
				dismissible: false,
			};
		case 'refused':
			return {
				status: 'error',
				title: __( 'show.fm disconnected this site.', 'showfm' ),
				text: __(
					'This happens when the account password changes or the site is removed in show.fm. Reconnect to start posting new episodes again. Blocks still play public episodes.',
					'showfm'
				),
				action: reconnect,
				dismissible: false,
			};
		case 'paused':
			return {
				status: 'warning',
				title: __(
					'Auto-posting is paused because the show’s plan doesn’t include connected sites.',
					'showfm'
				),
				text: __( 'Embeds still work.', 'showfm' ),
				action: {
					type: 'link',
					label: __( 'Check your plan', 'showfm' ),
					url: view.planUrl,
				},
				dismissible: false,
			};
		case 'scheduled':
			return {
				status: 'info',
				title: __( 'Using scheduled checks.', 'showfm' ),
				text: __(
					'show.fm can’t reach this site, so it checks for changes every 15 minutes instead. New posts can take up to 15 minutes to appear.',
					'showfm'
				),
				action: null,
				dismissible: false,
			};
		case 'unreadable':
			return {
				status: 'error',
				title: __( 'Reconnect to show.fm.', 'showfm' ),
				text: __(
					'This site’s security keys changed, so the saved connection can’t be used any more.',
					'showfm'
				),
				action: reconnect,
				dismissible: false,
			};
	}

	if ( result && result.status === 'connected' ) {
		if ( result.error ) {
			return {
				status: 'warning',
				title: __( 'Connected to show.fm.', 'showfm' ),
				text: result.message,
				action: null,
				dismissible: true,
				result: true,
			};
		}
		const names = view.shows
			.map( ( show ) => show.title )
			.filter( Boolean );
		return {
			status: 'success',
			title: __( 'Connected to show.fm.', 'showfm' ),
			text: names.length
				? sprintf(
						/* translators: %s: show names, for example "The Long Table and Second Helpings". */
						__(
							'New episodes of %s will be posted here.',
							'showfm'
						),
						listNames( names )
					)
				: __( 'New episodes will be posted here.', 'showfm' ),
			action: null,
			dismissible: true,
			result: true,
		};
	}
	return null;
}

/**
 * The notice after Disconnect. The show.fm API has no way for a site key to revoke
 * itself, so the key stays valid until the site is disconnected in show.fm too.
 *
 * @param {Object} disconnected `disconnected` from the disconnect answer.
 * @return {Object} Notice.
 */
export function disconnectedNotice( disconnected ) {
	return {
		status: 'info',
		title: __( 'Disconnected from show.fm.', 'showfm' ),
		text: __(
			'This site’s key stays valid at show.fm until you disconnect the site there too.',
			'showfm'
		),
		action: {
			type: 'link',
			label: __( 'Open Connected sites in show.fm', 'showfm' ),
			url: disconnected.sitesUrl,
		},
		dismissible: true,
	};
}

/**
 * The one polite message spoken after Disconnect.
 *
 * @return {string} Message.
 */
export function disconnectedMessage() {
	return __(
		'Disconnected from show.fm. This site’s key stays valid at show.fm until you disconnect the site there too.',
		'showfm'
	);
}
