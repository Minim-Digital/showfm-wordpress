/**
 * The settings tabs. Publishing (WP-4b) and Migrate (WP-5b) add an entry here with
 * `requiresConnection: true`, so they appear only while a connection is stored.
 */
import { __ } from '@wordpress/i18n';

import ConnectionTab from './connection-tab';
import DisplayTab from './display-tab';

/**
 * Every tab, in display order.
 *
 * Each has a `name` (the `tab` query argument), a `title`, `requiresConnection`, and a
 * `render( { view, onDisconnect } )` that returns the tab's content.
 *
 * @return {Object[]} Tabs.
 */
export function allTabs() {
	return [
		{
			name: 'connection',
			title: __( 'Connection', 'showfm' ),
			requiresConnection: false,
			showsAdminNotice: false,
			render: ( props ) => <ConnectionTab { ...props } />,
		},
		{
			name: 'display',
			title: __( 'Display', 'showfm' ),
			requiresConnection: false,
			showsAdminNotice: true,
			render: () => <DisplayTab />,
		},
	];
}

/**
 * The tabs to show now.
 *
 * @param {boolean} connected Whether a connection is stored.
 * @return {Object[]} Tabs.
 */
export function visibleTabs( connected ) {
	return allTabs().filter( ( tab ) => connected || ! tab.requiresConnection );
}
