/**
 * The in-block editor states from the design (area 6, 6i to 6p). Editor only: none of this
 * reaches the published page.
 */
import { Button, Icon, Placeholder, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings } from '../settings';
import { goesLive } from '../format';

/**
 * Copy, icon and colour for each state that replaces the block.
 *
 * @param {string} state State from the editor routes.
 * @param {Object} data  The answer.
 * @return {{message: string, icon: string, tone: string}|null} Message.
 */
export function stateCopy( state, data = {} ) {
	switch ( state ) {
		case 'not_found':
			return {
				message: __(
					'We couldn’t find that show or episode.',
					'showfm'
				),
				icon: 'warning',
				tone: 'error',
			};
		case 'not_public':
			return {
				message: __( 'This episode isn’t public yet.', 'showfm' ),
				icon: 'lock',
				tone: 'muted',
			};
		case 'unpublished':
		case 'deleted':
			return {
				message: __( 'No longer available on show.fm.', 'showfm' ),
				icon: 'no-alt',
				tone: 'error',
			};
		case 'archived':
			return {
				message: __( 'Archived in show.fm.', 'showfm' ),
				icon: 'no-alt',
				tone: 'error',
			};
		case 'paused':
			return {
				message: __( 'Paused because of the show’s plan.', 'showfm' ),
				icon: 'warning',
				tone: 'warning',
			};
		case 'external':
			return {
				message: __(
					'This show is hosted outside show.fm, so it can’t be embedded here.',
					'showfm'
				),
				icon: 'info-outline',
				tone: 'muted',
			};
		case 'no_transcript':
			return {
				message: __(
					'There’s no transcript for this episode yet, so visitors won’t see this block.',
					'showfm'
				),
				icon: 'info-outline',
				tone: 'muted',
			};
		case 'scheduled':
			return {
				message: data.episode?.date
					? goesLive( data.episode.date )
					: __( 'This episode is scheduled.', 'showfm' ),
				icon: 'clock',
				tone: 'info',
			};
		case 'error':
			return {
				message: __(
					'Couldn’t reach show.fm. Showing the last saved copy.',
					'showfm'
				),
				icon: 'update',
				tone: 'warning',
			};
		case 'rate_limited':
			return {
				message: __(
					'show.fm is busy right now. Showing the last saved copy.',
					'showfm'
				),
				icon: 'update',
				tone: 'warning',
			};
		default:
			return null;
	}
}

/**
 * Loading, inside the block's placeholder.
 *
 * @param {Object} props
 * @param {Object} props.icon    Block icon.
 * @param {string} props.label   Block name.
 * @param {string} props.message What is loading.
 */
export function LoadingState( { icon, label, message } ) {
	return (
		<Placeholder
			icon={ icon }
			label={ label }
			className="showfm-placeholder showfm-state"
		>
			<div className="showfm-loading" role="status">
				<Spinner />
				{ message }
			</div>
		</Placeholder>
	);
}

/**
 * A state that replaces the block: not found, not public, removed, paused, external.
 *
 * @param {Object}     props
 * @param {Object}     props.icon            Block icon.
 * @param {string}     props.label           Block name.
 * @param {string}     props.state           State.
 * @param {Object}     props.data            The answer.
 * @param {() => void} props.onChooseEpisode Choose another episode.
 * @param {() => void} props.onChangeShow    Use another show.
 */
export function StateMessage( {
	icon,
	label,
	state,
	data = {},
	onChooseEpisode,
	onChangeShow,
} ) {
	const copy = stateCopy( state, data );
	if ( ! copy ) {
		return null;
	}
	const settings = getSettings();
	let button = null;
	let link = null;
	if ( state === 'not_found' ) {
		button = onChooseEpisode && {
			label: __( 'Choose another episode', 'showfm' ),
			onClick: onChooseEpisode,
		};
		link = onChangeShow && {
			label: __( 'Check the address', 'showfm' ),
			onClick: onChangeShow,
		};
	} else if ( state === 'not_public' ) {
		if ( ! settings.connected && settings.canConnect ) {
			link = {
				label: __(
					'Connect to show.fm to preview scheduled episodes',
					'showfm'
				),
				href: settings.connectUrl,
			};
		}
	} else if ( [ 'unpublished', 'deleted', 'archived' ].includes( state ) ) {
		button = onChooseEpisode && {
			label: __( 'Choose another episode', 'showfm' ),
			onClick: onChooseEpisode,
		};
		if ( state !== 'deleted' && data.episode?.appUrl ) {
			link = {
				label: __( 'Open the show on show.fm', 'showfm' ),
				href: data.episode.appUrl,
			};
		}
	} else if ( state === 'paused' ) {
		if ( settings.connected ) {
			link = {
				label: __( 'Go to billing', 'showfm' ),
				href: `${ settings.appUrl }/settings`,
			};
		}
	} else if ( state === 'no_transcript' ) {
		button = onChooseEpisode && {
			label: __( 'Choose another episode', 'showfm' ),
			onClick: onChooseEpisode,
		};
	} else if ( state === 'external' ) {
		button = onChangeShow && {
			label: __( 'Use another show', 'showfm' ),
			onClick: onChangeShow,
		};
	}
	return (
		<Placeholder
			icon={ icon }
			label={ label }
			className={ `showfm-placeholder showfm-state is-${ state }` }
		>
			<div className={ `showfm-state__message is-${ copy.tone }` }>
				<Icon icon={ copy.icon } size={ 20 } />
				<p>{ copy.message }</p>
			</div>
			{ ( button || link ) && (
				<div className="showfm-state__actions">
					{ button && (
						<Button
							variant="secondary"
							size="compact"
							onClick={ button.onClick }
						>
							{ button.label }
						</Button>
					) }
					{ link && (
						<Button
							variant="link"
							href={ link.href }
							onClick={ link.onClick }
							target={ link.href ? '_blank' : undefined }
							rel={ link.href ? 'noreferrer' : undefined }
						>
							{ link.label }
						</Button>
					) }
				</div>
			) }
		</Placeholder>
	);
}

/**
 * The strip above a preview: scheduled, or the last saved copy after an error.
 *
 * @param {Object}     props
 * @param {string}     props.state   State.
 * @param {Object}     props.data    The answer.
 * @param {() => void} props.onRetry Try again.
 */
export function StateStrip( { state, data = {}, onRetry } ) {
	const copy = stateCopy( state, data );
	if ( ! copy ) {
		return null;
	}
	return (
		<div className={ `showfm-strip is-${ copy.tone }` } role="status">
			<Icon icon={ copy.icon } size={ 20 } />
			<span className="showfm-strip__text">
				{ copy.message }{ ' ' }
				{ state === 'scheduled' && (
					<span className="showfm-strip__sub">
						{ __(
							'Visitors won’t see this block until then.',
							'showfm'
						) }
					</span>
				) }
			</span>
			{ onRetry && state !== 'scheduled' && (
				<Button variant="link" onClick={ onRetry }>
					{ __( 'Try again', 'showfm' ) }
				</Button>
			) }
		</div>
	);
}
