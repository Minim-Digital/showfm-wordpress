/**
 * Editor script for the four show.fm blocks and the "show.fm" post panel.
 */
import { registerBlockType } from '@wordpress/blocks';
import episodesMetadata from '../blocks/episodes/block.json';
import playMetadata from '../blocks/play/block.json';
import playerMetadata from '../blocks/player/block.json';
import transcriptMetadata from '../blocks/transcript/block.json';
import EpisodesEdit from './blocks/episodes';
import PlayEdit from './blocks/play';
import PlayerEdit from './blocks/player';
import TranscriptEdit from './blocks/transcript';
import { listIcon, playIcon, playerIcon, transcriptIcon } from './icons';
import './sidebar';
import './editor.scss';

const blocks = [
	[ playerMetadata, PlayerEdit, playerIcon ],
	[ episodesMetadata, EpisodesEdit, listIcon ],
	[ playMetadata, PlayEdit, playIcon ],
	[ transcriptMetadata, TranscriptEdit, transcriptIcon ],
];

for ( const [ metadata, edit, icon ] of blocks ) {
	registerBlockType( metadata.name, {
		...metadata,
		icon,
		edit,
		// Dynamic blocks: the server renders them from the cache (WP-2a).
		save: () => null,
	} );
}
