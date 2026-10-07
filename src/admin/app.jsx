/**
 * Settings > show.fm: the page header, the tabs and the current tab.
 */
import apiFetch from '@wordpress/api-fetch';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs, getQueryArg } from '@wordpress/url';

import { hasConnection } from './connection-view';
import Logo from './logo';
import { visibleTabs } from './tabs';

/** The Connection tab's data. Admin preloads the same path. */
export const CONNECTION_PATH = '/showfm/v1/admin/connection';

/**
 * Keeps the open tab in the address, so a reload or a shared link opens it again.
 *
 * @param {string} name Tab name.
 */
function rememberTab( name ) {
	const { href } = window.location;
	if ( getQueryArg( href, 'tab' ) !== name ) {
		window.history.replaceState(
			null,
			'',
			addQueryArgs( href, { tab: name } )
		);
	}
}

/**
 * The admin notice, shown on every tab except Connection, which has its own.
 *
 * @param {Object} props
 * @param {Object} props.notice Notice from the connection data.
 */
function AdminNotice( { notice } ) {
	const [ dismissed, setDismissed ] = useState( false );
	if ( dismissed ) {
		return null;
	}
	const dismiss = () => {
		setDismissed( true );
		apiFetch( {
			path: '/showfm/v1/admin/notices/dismiss',
			method: 'POST',
			data: { key: notice.key },
		} ).catch( () => {} );
	};
	return (
		<Notice
			className="showfm-notice"
			status={ notice.type }
			onRemove={ dismiss }
			actions={ [
				{ label: notice.action.label, url: notice.action.url },
			] }
		>
			<p>{ notice.text }</p>
		</Notice>
	);
}

/**
 * The settings page.
 */
export default function App() {
	const [ view, setView ] = useState( null );
	const [ failed, setFailed ] = useState( false );

	useEffect( () => {
		apiFetch( { path: CONNECTION_PATH } )
			.then( setView )
			.catch( () => setFailed( true ) );
	}, [] );

	const disconnect = useCallback( async () => {
		const next = await apiFetch( {
			path: '/showfm/v1/admin/disconnect',
			method: 'POST',
		} );
		setView( next );
	}, [] );

	const tabs = visibleTabs( hasConnection( view ) );
	const requested = getQueryArg( window.location.href, 'tab' );
	const initial = tabs.some( ( tab ) => tab.name === requested )
		? requested
		: 'connection';

	let content;
	if ( failed ) {
		content = (
			<Notice status="error" isDismissible={ false }>
				<p>
					{ __(
						'The show.fm settings couldn’t be loaded. Reload the page to try again.',
						'showfm'
					) }
				</p>
			</Notice>
		);
	} else if ( ! view ) {
		content = <Spinner />;
	}

	return (
		<div className="showfm-settings__page">
			<div className="showfm-settings__header">
				<h1>
					<Logo />
					{ __( 'show.fm', 'showfm' ) }
				</h1>
			</div>
			{ content ? (
				<div className="showfm-settings__content">{ content }</div>
			) : (
				<TabPanel
					className="showfm-settings__tabs"
					tabs={ tabs.map( ( { name, title } ) => ( {
						name,
						title,
					} ) ) }
					initialTabName={ initial }
					onSelect={ rememberTab }
				>
					{ ( { name } ) => {
						const tab = tabs.find( ( item ) => item.name === name );
						return (
							<div className="showfm-settings__content">
								{ tab.showsAdminNotice && view.notice && (
									<AdminNotice
										key={ view.notice.key }
										notice={ view.notice }
									/>
								) }
								{ tab.render( {
									view,
									onDisconnect: disconnect,
								} ) }
							</div>
						);
					} }
				</TabPanel>
			) }
		</div>
	);
}
