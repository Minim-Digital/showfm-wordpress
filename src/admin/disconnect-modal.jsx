/**
 * The confirm dialog for Disconnect.
 */
import { Button, ExternalLink, Modal, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Asks before removing the connection. Below 600px the modal fills the screen, as
 * `@wordpress/components` does. It says plainly that the site's key stays valid at show.fm:
 * the show.fm API has no way for a site key to revoke itself.
 *
 * @param {Object}              props
 * @param {string}              props.sitesUrl  The account's Connected sites page.
 * @param {() => void}          props.onCancel  Closes without disconnecting.
 * @param {() => Promise<void>} props.onConfirm Disconnects.
 * @param {() => void}          props.onDone    Called after a successful disconnect.
 */
export default function DisconnectModal( {
	sitesUrl,
	onCancel,
	onConfirm,
	onDone,
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( false );

	const confirm = async () => {
		setBusy( true );
		setFailed( false );
		try {
			await onConfirm();
			onDone();
		} catch ( error ) {
			setFailed(
				error?.code === 'showfm_state_moved' && error?.message
					? error.message
					: true
			);
			setBusy( false );
		}
	};

	return (
		<Modal
			className="showfm-modal"
			title={ __( 'Disconnect from show.fm?', 'showfm' ) }
			onRequestClose={ busy ? () => {} : onCancel }
			size="medium"
		>
			<div className="showfm-modal__body">
				{ failed && (
					<Notice status="error" isDismissible={ false }>
						{ true === failed
							? __( 'Couldn’t disconnect. Try again.', 'showfm' )
							: failed }
					</Notice>
				) }
				<p>
					{ __(
						'New episodes stop being posted here. Posts that were already created stay as they are.',
						'showfm'
					) }
				</p>
				<p>
					{ __(
						'Blocks keep playing public episodes. You can reconnect at any time.',
						'showfm'
					) }
				</p>
				<p>
					{ __(
						'This site’s key stays valid at show.fm until you disconnect the site there too, under Connected sites.',
						'showfm'
					) }{ ' ' }
					<ExternalLink href={ sitesUrl }>
						{ __( 'Open Connected sites in show.fm', 'showfm' ) }
					</ExternalLink>
				</p>
			</div>
			<div className="showfm-modal__actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'showfm' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy }
					onClick={ confirm }
					__next40pxDefaultSize
				>
					{ __( 'Disconnect', 'showfm' ) }
				</Button>
			</div>
		</Modal>
	);
}
