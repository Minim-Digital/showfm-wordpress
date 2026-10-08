/**
 * show.fm Play button (6c).
 */
import { Notice, PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ShowfmBlockEdit from '../components/block-edit';
import { MiniPlayerCorner, Segmented } from '../components/controls';
import EpisodeSummary from '../components/episode-summary';
import { playIcon } from '../icons';
import { summaryOf } from './player';

/**
 * The inspector panels.
 *
 * @param {Object}                       props
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Block setter.
 * @param {Object}                       props.data          Editor answer for the episode.
 * @param {() => void}                   props.startPicking  Open the episode picker.
 */
export function PlayInspector( {
	attributes,
	setAttributes,
	data,
	startPicking,
} ) {
	const miniPlayer = attributes[ 'mini-player' ] !== 'off';
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
					label={ __( 'Variant', 'showfm' ) }
					value={ attributes.variant || 'label' }
					onChange={ ( variant ) => setAttributes( { variant } ) }
					options={ [
						{
							value: 'icon',
							label: __( 'Icon', 'showfm' ),
						},
						{
							value: 'label',
							label: __( 'Label', 'showfm' ),
						},
						{
							value: 'link',
							label: __( 'Text link', 'showfm' ),
						},
					] }
				/>
				<Segmented
					label={ __( 'Size', 'showfm' ) }
					value={ attributes.size || 'sm' }
					onChange={ ( size ) => setAttributes( { size } ) }
					options={ [
						{ value: 'sm', label: __( 'Small', 'showfm' ) },
						{ value: 'lg', label: __( 'Large', 'showfm' ) },
					] }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Playback', 'showfm' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Mini-player', 'showfm' ) }
					help={ __(
						'On by default. After the first press, visitors get seek, speed and time left.',
						'showfm'
					) }
					checked={ miniPlayer }
					onChange={ ( on ) =>
						setAttributes( {
							'mini-player': on ? undefined : 'off',
						} )
					}
				/>
				{ miniPlayer && (
					<MiniPlayerCorner
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
				) }
				{ ! miniPlayer && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Visitors can only play and pause.', 'showfm' ) }
					</Notice>
				) }
			</PanelBody>
		</>
	);
}

export default function PlayEdit( props ) {
	return (
		<ShowfmBlockEdit
			{ ...props }
			type="play"
			name="showfm/play"
			label={ __( 'show.fm Play button', 'showfm' ) }
			icon={ playIcon }
			instructions={ __(
				'Add a public show from show.fm. You’ll choose the episode next.',
				'showfm'
			) }
			needsEpisode
			renderInspector={ ( context ) => (
				<PlayInspector { ...props } { ...context } />
			) }
		/>
	);
}
