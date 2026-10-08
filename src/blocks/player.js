/**
 * show.fm Player (6a).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import ShowfmBlockEdit from '../components/block-edit';
import {
	AccentControl,
	MiniPlayerCorner,
	Segmented,
} from '../components/controls';
import EpisodeSummary from '../components/episode-summary';
import { defaultLevel } from '../components/heading-level';
import { episodeMeta } from '../format';
import { playerIcon } from '../icons';

/**
 * Inspector summary for an episode block: the episode, or the show for Latest episode.
 *
 * @param {Object} data       Editor answer.
 * @param {Object} attributes Block attributes.
 * @return {{artwork: string, title: string, meta: string}} Summary.
 */
export function summaryOf( data, attributes ) {
	const episode = data.episode;
	if ( episode ) {
		return {
			artwork: episode.artwork,
			title: episode.title,
			meta: [ episode.podcast?.title, episodeMeta( episode ) ]
				.filter( Boolean )
				.join( ' · ' ),
		};
	}
	if ( data.show ) {
		return {
			artwork: data.show.artwork,
			title: __( 'Latest episode', 'showfm' ),
			meta: data.show.title,
		};
	}
	return {
		artwork: null,
		title: attributes.snapshot?.title || '',
		meta: '',
	};
}

/**
 * The inspector panels.
 *
 * @param {Object}                       props
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Block setter.
 * @param {Object}                       props.data          Editor answer for the episode.
 * @param {() => void}                   props.startPicking  Open the episode picker.
 * @param {Object[]}                     props.blocks        Top-level blocks, for the heading level.
 * @param {string}                       props.clientId      This block.
 */
export function PlayerInspector( {
	attributes,
	setAttributes,
	data,
	startPicking,
	blocks,
	clientId,
} ) {
	return (
		<>
			<PanelBody title={ __( 'Episode', 'showfm' ) }>
				<EpisodeSummary
					{ ...summaryOf( data, attributes ) }
					buttonLabel={ __( 'Change episode', 'showfm' ) }
					onClick={ startPicking }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Appearance', 'showfm' ) }>
				<Segmented
					label={ __( 'Size', 'showfm' ) }
					value={ attributes.size || 'standard' }
					onChange={ ( size ) => setAttributes( { size } ) }
					options={ [
						{
							value: 'standard',
							label: __( 'Standard', 'showfm' ),
						},
						{
							value: 'compact',
							label: __( 'Compact', 'showfm' ),
						},
					] }
				/>
				<Segmented
					label={ __( 'Theme', 'showfm' ) }
					value={ attributes.theme || 'auto' }
					onChange={ ( theme ) => setAttributes( { theme } ) }
					help={ __(
						'Auto follows the visitor’s light or dark setting.',
						'showfm'
					) }
					options={ [
						{
							value: 'auto',
							label: __( 'Auto', 'showfm' ),
						},
						{
							value: 'light',
							label: __( 'Light', 'showfm' ),
						},
						{
							value: 'dark',
							label: __( 'Dark', 'showfm' ),
						},
					] }
				/>
				<AccentControl
					value={ attributes.accent }
					onChange={ ( accent ) => setAttributes( { accent } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Waveform', 'showfm' ) }
					checked={ attributes.wave !== 'false' }
					onChange={ ( on ) =>
						setAttributes( { wave: on ? 'true' : 'false' } )
					}
				/>
			</PanelBody>
			<PanelBody title={ __( 'Features', 'showfm' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Transcript', 'showfm' ) }
					help={ __(
						'Adds a Transcript button. It opens under the player.',
						'showfm'
					) }
					checked={ [ 'on', 'open' ].includes(
						attributes.transcript
					) }
					onChange={ ( on ) =>
						setAttributes( {
							transcript: on ? 'on' : undefined,
						} )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Mini-player', 'showfm' ) }
					help={ __(
						'When a visitor scrolls past the player while it plays, a bar at the bottom of the page keeps it playing.',
						'showfm'
					) }
					checked={ attributes[ 'mini-player' ] === 'on' }
					onChange={ ( on ) =>
						setAttributes( {
							'mini-player': on ? 'on' : undefined,
						} )
					}
				/>
				{ attributes[ 'mini-player' ] === 'on' && (
					<MiniPlayerCorner
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show the title as a heading', 'showfm' ) }
					help={ __( 'Choose the level in the toolbar.', 'showfm' ) }
					checked={ !! attributes[ 'heading-level' ] }
					onChange={ ( on ) =>
						setAttributes( {
							'heading-level': on
								? defaultLevel( blocks, clientId )
								: undefined,
						} )
					}
				/>
			</PanelBody>
		</>
	);
}

export default function PlayerEdit( props ) {
	const blocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks(),
		[]
	);
	return (
		<ShowfmBlockEdit
			{ ...props }
			type="player"
			name="showfm/player"
			label={ __( 'show.fm Player', 'showfm' ) }
			icon={ playerIcon }
			instructions={ __(
				'Add a public show from show.fm. You’ll choose the episode next.',
				'showfm'
			) }
			needsEpisode
			headingControl
			renderInspector={ ( context ) => (
				<PlayerInspector
					{ ...props }
					{ ...context }
					blocks={ blocks }
				/>
			) }
		/>
	);
}
