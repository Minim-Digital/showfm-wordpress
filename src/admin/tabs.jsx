/**
 * The settings tabs. Publishing and Migrate have `requiresConnection: true`, so they appear
 * only while a connection is stored.
 */
import { __ } from '@wordpress/i18n';

import ConnectionTab from './connection-tab';
import DisplayTab from './display-tab';
import MigrateTab from './migrate-tab';
import PublishingTab from './publishing-tab';

/**
 * Every tab, in display order.
 *
 * Each has a `name` (the `tab` query argument), a `title`, `requiresConnection`,
 * `showsAdminNotice`, and a `render( { view, onDisconnect } )` that returns the tab's
 * content. `wide` gives the tab the wider layout with a side column. `hidesNotice( notice )`
 * hides an admin notice the tab already shows in its own words.
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
			name: 'publishing',
			title: __( 'Publishing', 'showfm' ),
			requiresConnection: true,
			showsAdminNotice: true,
			wide: true,
			hidesNotice: ( notice ) =>
				notice.key.startsWith( 'sync:row_post_type:' ) ||
				notice.key.startsWith( 'sync:row_author:' ),
			render: () => <PublishingTab />,
		},
		{
			name: 'display',
			title: __( 'Display', 'showfm' ),
			requiresConnection: false,
			showsAdminNotice: true,
			render: () => <DisplayTab />,
		},
		{
			name: 'migrate',
			title: __( 'Migrate', 'showfm' ),
			requiresConnection: true,
			showsAdminNotice: true,
			wide: true,
			render: ( { view } ) => <MigrateTab connection={ view } />,
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
