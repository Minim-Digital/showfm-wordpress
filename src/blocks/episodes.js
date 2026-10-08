/**
 * show.fm Episode list (6b).
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import ShowfmBlockEdit from '../components/block-edit';
import { MiniPlayerCorner, Segmented } from '../components/controls';
import { seasonsOf } from '../components/episode-picker';
import EpisodeSummary from '../components/episode-summary';
import { defaultLevel } from '../components/heading-level';
import { showMeta } from '../components/show-placeholder';
import { useEditorData } from '../hooks';
import { listIcon } from '../icons';

/**
 * The `hide` attribute with one type turned on or off.
 *
 * @param {string}  hide Current value, such as `trailer,bonus`.
 * @param {string}  type `trailer` or `bonus`.
 * @param {boolean} on   Whether to hide it.
 * @return {string|undefined} New value.
 */
export function toggleHidden( hide, type, on ) {
	const types = new Set( ( hide || '' ).split( ',' ).filter( Boolean ) );
	if ( on ) {
		types.add( type );
	} else {
		types.delete( type );
	}
	const value = [ 'trailer', 'bonus' ]
		.filter( ( name ) => types.has( name ) )
		.join( ',' );
	return value || undefined;
}

/**
 * The inspector panels.
 *
 * @param {Object}                       props
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Block setter.
 * @param {Object}                       props.data          Editor answer for the show.
 * @param {() => void}                   props.changeShow    Choose another show.
 * @param {number[]}                     props.seasons       Seasons the show has.
 */
export function EpisodesInspector( {
	attributes,
	setAttributes,
	data,
	changeShow,
	seasons,
} ) {
	const hidden = ( attributes.hide || '' ).split( ',' );
	return (
		<>
			<PanelBody title={ __( 'Show', 'showfm' ) }>
				<EpisodeSummary
					artwork={ data.show?.artwork }
					title={ data.show?.title || attributes.snapshot?.title }
					meta={ data.show ? showMeta( data.show ) : '' }
					buttonLabel={ __( 'Change show', 'showfm' ) }
					onClick={ changeShow }
				/>
			</PanelBody>
			<PanelBody title={ __( 'Episodes', 'showfm' ) }>
				<RangeControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Number of episodes', 'showfm' ) }
					min={ 1 }
					max={ 50 }
					value={ Number( attributes.count ) || 10 }
					onChange={ ( count ) =>
						setAttributes( {
							count: count ? String( count ) : undefined,
						} )
					}
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Season', 'showfm' ) }
					value={ attributes.season || '' }
					onChange={ ( season ) =>
						setAttributes( { season: season || undefined } )
					}
					options={ [
						{
							label: __( 'All seasons', 'showfm' ),
							value: '',
						},
						...seasons.map( ( number ) => ( {
							label: sprintf(
								/* translators: %d: season number. */
								__( 'Season %d', 'showfm' ),
								number
							),
							value: String( number ),
						} ) ),
					] }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Hide trailers', 'showfm' ) }
					checked={ hidden.includes( 'trailer' ) }
					onChange={ ( on ) =>
						setAttributes( {
							hide: toggleHidden(
								attributes.hide,
								'trailer',
								on
							),
						} )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Hide bonus episodes', 'showfm' ) }
					checked={ hidden.includes( 'bonus' ) }
					onChange={ ( on ) =>
						setAttributes( {
							hide: toggleHidden( attributes.hide, 'bonus', on ),
						} )
					}
				/>
			</PanelBody>
			<PanelBody title={ __( 'Appearance', 'showfm' ) }>
				<Segmented
					label={ __( 'Style', 'showfm' ) }
					value={ attributes.variant || 'card' }
					onChange={ ( variant ) => setAttributes( { variant } ) }
					options={ [
						{
							value: 'card',
							label: __( 'Card', 'showfm' ),
						},
						{
							value: 'minimal',
							label: __( 'Minimal', 'showfm' ),
						},
					] }
				/>
				<Segmented
					label={ __( 'Layout', 'showfm' ) }
					value={ attributes.layout || 'auto' }
					onChange={ ( layout ) => setAttributes( { layout } ) }
					help={ __(
						'Auto uses a grid when the block is wide and most episodes have their own artwork.',
						'showfm'
					) }
					options={ [
						{
							value: 'auto',
							label: __( 'Auto', 'showfm' ),
						},
						{
							value: 'list',
							label: __( 'List', 'showfm' ),
						},
						{
							value: 'grid',
							label: __( 'Grid', 'showfm' ),
						},
					] }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Show descriptions', 'showfm' ) }
					checked={ attributes.descriptions !== 'off' }
					onChange={ ( on ) =>
						setAttributes( {
							descriptions: on ? undefined : 'off',
						} )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Mini-player', 'showfm' ) }
					help={ __(
						'Keeps playing in a bar at the bottom of the page while visitors scroll.',
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
			</PanelBody>
		</>
	);
}

export default function EpisodesEdit( props ) {
	const { attributes, setAttributes, clientId } = props;
	const blocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks(),
		[]
	);
	const episodes = useEditorData(
		'episodes',
		{ podcast: attributes.podcast },
		!! attributes.podcast
	);
	const seasons = seasonsOf( episodes.data?.episodes || [] );
	return (
		<ShowfmBlockEdit
			{ ...props }
			setAttributes={ ( next ) =>
				setAttributes(
					next.podcast && ! attributes[ 'heading-level' ]
						? {
								...next,
								'heading-level': defaultLevel(
									blocks,
									clientId
								),
							}
						: next
				)
			}
			type="episodes"
			name="showfm/episodes"
			label={ __( 'show.fm Episode list', 'showfm' ) }
			icon={ listIcon }
			instructions={ __( 'Add a public show from show.fm.', 'showfm' ) }
			needsEpisode={ false }
			headingControl
			renderInspector={ ( context ) => (
				<EpisodesInspector
					{ ...props }
					{ ...context }
					seasons={ seasons }
				/>
			) }
		/>
	);
}
