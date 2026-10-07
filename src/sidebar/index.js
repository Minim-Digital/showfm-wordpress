/**
 * Registers the "show.fm" panel in the post sidebar. It shows only on posts created by
 * show.fm, or where someone picked an episode or bound a block to one.
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useDispatch, useSelect } from '@wordpress/data';
// `wp.editor` comes with WordPress; the build maps the import to that global.
/* eslint-disable import/no-unresolved */
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
/* eslint-enable import/no-unresolved */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import SyncPanel, { useEpisodeOptions } from './panel';
import { hasEpisodeBindings } from './status';

function ShowfmPostPanel() {
	const { sync, episodeId, bound } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			sync: editor.getCurrentPostAttribute( 'showfm_sync' ) || null,
			episodeId:
				editor.getEditedPostAttribute( 'meta' )?._showfm_episode_id ||
				'',
			bound: hasEpisodeBindings( select( blockEditorStore ).getBlocks() ),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const visible = !! ( sync?.synced || sync?.state || episodeId || bound );
	const { options, appUrl } = useEpisodeOptions( episodeId, visible );
	if ( ! visible ) {
		return null;
	}
	return (
		<PluginDocumentSettingPanel
			name="showfm"
			title={ __( 'show.fm', 'showfm' ) }
			className="showfm-post-panel"
		>
			<SyncPanel
				sync={ sync }
				episodeId={ episodeId }
				options={ options }
				appUrl={ appUrl }
				onChange={ ( id ) =>
					editPost( { meta: { _showfm_episode_id: id } } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'showfm-post-panel', { render: ShowfmPostPanel } );
