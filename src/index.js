import { registerBlockType } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';
import player from '../blocks/player/block.json';
import episodes from '../blocks/episodes/block.json';
import play from '../blocks/play/block.json';
import transcript from '../blocks/transcript/block.json';

for ( const metadata of [ player, episodes, play, transcript ] ) {
	registerBlockType( metadata.name, {
		...metadata,
		edit: function Edit( { attributes } ) {
			return createElement(
				'div',
				useBlockProps(),
				attributes.episode || attributes.podcast
					? createElement( ServerSideRender, {
							block: metadata.name,
							attributes,
						} )
					: __( 'Choose a show.fm episode or show.', 'showfm' )
			);
		},
		save: () => null,
	} );
}
