/**
 * Settings > show.fm > Connection.
 */
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Path, SVG } from '@wordpress/primitives';

import {
	connectionNotice,
	connectionStatus,
	keyRow,
	needsReconnect,
	updatesRow,
} from './connection-view';
import DisconnectModal from './disconnect-modal';

/**
 * The dashicons "yes" tick.
 *
 * @param {Object}  props
 * @param {boolean} props.muted Grey instead of green.
 */
function Tick( { muted } ) {
	return (
		<SVG
			width="20"
			height="20"
			viewBox="0 0 20 20"
			aria-hidden="true"
			className={ `showfm-tick${ muted ? ' is-muted' : '' }` }
		>
			<Path d="M14.83 4.89l1.34.94-5.81 8.38H9.02L5.78 9.67l1.34-1.25 2.57 2.4z" />
		</SVG>
	);
}

/**
 * The form that starts the connect flow at admin-post.php. Reconnect, Try again and
 * Connect all submit it, so the flow runs through the same capability and nonce checks.
 *
 * @param {Object}  props
 * @param {Object}  props.connect   Form target, action and nonce.
 * @param {Object}  props.formRef   Ref to the form element.
 * @param {Element} props.children  Visible content, such as the submit button.
 * @param {string}  props.className Class name.
 */
function ConnectForm( { connect, formRef, children, className } ) {
	return (
		<form
			ref={ formRef }
			method="post"
			action={ connect.url }
			className={ className }
		>
			<input type="hidden" name="action" value={ connect.action } />
			<input type="hidden" name="_wpnonce" value={ connect.nonce } />
			{ children }
		</form>
	);
}

/**
 * The tab's one notice, with its one action.
 *
 * @param {Object}     props
 * @param {Object}     props.notice    Notice from `connectionNotice()`.
 * @param {() => void} props.onConnect Starts the connect flow.
 */
function ConnectionNotice( { notice, onConnect } ) {
	const [ dismissed, setDismissed ] = useState( false );
	if ( dismissed ) {
		return null;
	}
	const actions = [];
	if ( notice.action?.type === 'link' ) {
		actions.push( { label: notice.action.label, url: notice.action.url } );
	} else if ( notice.action ) {
		actions.push( {
			label: notice.action.label,
			onClick: onConnect,
			variant: 'secondary',
		} );
	}
	return (
		<Notice
			className="showfm-notice"
			status={ notice.status }
			isDismissible={ notice.dismissible }
			onRemove={ () => setDismissed( true ) }
			actions={ actions }
		>
			<p>
				<strong>{ notice.title }</strong> { notice.text }
			</p>
		</Notice>
	);
}

/**
 * Not connected: what connecting adds and the Connect button.
 *
 * @param {Object} props
 * @param {Object} props.view    Connection data.
 * @param {Object} props.formRef Ref to the connect form.
 */
function ConnectCard( { view, formRef } ) {
	const adds = [
		__(
			'Post new episodes as WordPress posts, including scheduled posts.',
			'showfm'
		),
		__(
			'See scheduled episodes in the block editor before they go live.',
			'showfm'
		),
		__(
			'Swap embeds from other podcast hosts for show.fm blocks.',
			'showfm'
		),
	];
	const without = [
		__(
			'Player, Episode list, Play button and Transcript blocks.',
			'showfm'
		),
		__( 'Any public show. Type its address into the block.', 'showfm' ),
	];
	return (
		<Card className="showfm-card">
			<CardHeader>
				<h2>{ __( 'Connect to show.fm', 'showfm' ) }</h2>
			</CardHeader>
			<CardBody className="showfm-connect">
				<p className="showfm-connect__intro">
					{ __(
						'Connect this site to your show.fm account to post new episodes here automatically. Connecting needs a paid show.fm plan.',
						'showfm'
					) }
				</p>
				<div className="showfm-connect__lists">
					<div>
						<h3>{ __( 'Connecting adds', 'showfm' ) }</h3>
						<ul>
							{ adds.map( ( text ) => (
								<li key={ text }>
									<Tick />
									<span>{ text }</span>
								</li>
							) ) }
						</ul>
					</div>
					<div>
						<h3>{ __( 'Works without connecting', 'showfm' ) }</h3>
						<ul>
							{ without.map( ( text ) => (
								<li key={ text }>
									<Tick muted />
									<span>{ text }</span>
								</li>
							) ) }
						</ul>
					</div>
				</div>
			</CardBody>
			<CardFooter justify="flex-start" className="showfm-card__footer">
				<ConnectForm
					connect={ view.connect }
					formRef={ formRef }
					className="showfm-connect__form"
				>
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
					>
						{ __( 'Connect to show.fm', 'showfm' ) }
					</Button>
					<span className="showfm-help">
						{ __(
							'You’ll sign in on show.fm, choose your shows, then come back here.',
							'showfm'
						) }
					</span>
				</ConnectForm>
			</CardFooter>
		</Card>
	);
}

/**
 * One row of the connection details.
 *
 * @param {Object}  props
 * @param {string}  props.label    Row label.
 * @param {Element} props.children Row value.
 */
function Row( { label, children } ) {
	return (
		<div className="showfm-row">
			<dt>{ label }</dt>
			<dd>{ children }</dd>
		</div>
	);
}

/**
 * A connected show: artwork, name and address.
 *
 * @param {Object}  props
 * @param {Object}  props.show   Show.
 * @param {boolean} props.paused Whether auto-posting is paused.
 */
function Show( { show, paused } ) {
	return (
		<li className="showfm-show">
			{ show.artwork ? (
				<img
					className="showfm-show__art"
					src={ show.artwork }
					alt=""
					width="36"
					height="36"
				/>
			) : (
				<span className="showfm-show__art" aria-hidden="true">
					{ ( show.title || '?' ).charAt( 0 ) }
				</span>
			) }
			<span className="showfm-show__text">
				<span className="showfm-show__name">{ show.title }</span>
				{ show.address && (
					<span className="showfm-muted">{ show.address }</span>
				) }
			</span>
			{ paused && (
				<span className="showfm-show__paused">
					{ __( 'Auto-posting paused', 'showfm' ) }
				</span>
			) }
		</li>
	);
}

/**
 * A stored connection: account, site, shows, key and updates, with Reconnect and
 * Disconnect.
 *
 * @param {Object}     props
 * @param {Object}     props.view         Connection data.
 * @param {Object}     props.formRef      Ref to the connect form.
 * @param {() => void} props.onDisconnect Opens the confirm dialog.
 */
function ConnectionCard( { view, formRef, onDisconnect } ) {
	const status = connectionStatus( view );
	const key = keyRow( view );
	const updates = updatesRow( view );
	const primary = needsReconnect( view );

	return (
		<Card className="showfm-card">
			<CardHeader className="showfm-card__header">
				<h2>{ __( 'Connection', 'showfm' ) }</h2>
				<span className="showfm-status">
					<span
						className={ `showfm-status__dot is-${ status.tone }` }
						aria-hidden="true"
					/>
					{ status.label }
				</span>
			</CardHeader>
			<CardBody className="showfm-details">
				<dl>
					{ view.account && (
						<Row label={ __( 'show.fm account', 'showfm' ) }>
							{ view.account }
						</Row>
					) }
					<Row label={ __( 'This site', 'showfm' ) }>
						<code>{ view.site }</code>
					</Row>
					{ view.shows.length > 0 && (
						<Row label={ __( 'Connected shows', 'showfm' ) }>
							<ul className="showfm-shows">
								{ view.shows.map( ( show ) => (
									<Show
										key={ show.id }
										show={ show }
										paused={ view.state === 'paused' }
									/>
								) ) }
							</ul>
						</Row>
					) }
					<Row label={ __( 'Key', 'showfm' ) }>
						<span className="showfm-stack">
							<span className={ key.error ? 'showfm-error' : '' }>
								{ key.text }
							</span>
							<span className="showfm-muted">
								{ view.key.masked && (
									<>
										<span className="showfm-masked">
											{ view.key.masked }
										</span>{ ' ' }
									</>
								) }
								{ key.note }
							</span>
						</span>
					</Row>
					<Row label={ __( 'Updates', 'showfm' ) }>
						<span className="showfm-stack">
							<span>{ updates.text }</span>
							<span className="showfm-muted">
								{ updates.note }
							</span>
						</span>
					</Row>
				</dl>
			</CardBody>
			<CardFooter justify="flex-start" className="showfm-card__footer">
				<ConnectForm
					connect={ view.connect }
					formRef={ formRef }
					className="showfm-actions"
				>
					<Button
						variant={ primary ? 'primary' : 'secondary' }
						type="submit"
						__next40pxDefaultSize
					>
						{ __( 'Reconnect', 'showfm' ) }
					</Button>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ onDisconnect }
						__next40pxDefaultSize
					>
						{ __( 'Disconnect', 'showfm' ) }
					</Button>
				</ConnectForm>
			</CardFooter>
		</Card>
	);
}

/**
 * The Connection tab.
 *
 * @param {Object}              props
 * @param {Object}              props.view         Connection data.
 * @param {() => Promise<void>} props.onDisconnect Disconnects; resolves when done.
 */
export default function ConnectionTab( { view, onDisconnect } ) {
	const formRef = useRef();
	const [ confirming, setConfirming ] = useState( false );
	const notice = connectionNotice( view );
	const connect = () => formRef.current?.requestSubmit();

	return (
		<div className="showfm-tab">
			{ notice && (
				<ConnectionNotice notice={ notice } onConnect={ connect } />
			) }
			{ view.state === 'not_connected' ? (
				<ConnectCard view={ view } formRef={ formRef } />
			) : (
				<ConnectionCard
					view={ view }
					formRef={ formRef }
					onDisconnect={ () => setConfirming( true ) }
				/>
			) }
			{ confirming && (
				<DisconnectModal
					onCancel={ () => setConfirming( false ) }
					onConfirm={ onDisconnect }
					onDone={ () => setConfirming( false ) }
				/>
			) }
		</div>
	);
}
