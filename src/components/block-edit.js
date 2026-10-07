/**
 * The shared flow for every block: choose a show, choose an episode, then preview the real
 * element with whatever state show.fm reports (6e to 6p).
 */
import {
	BlockControls,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import { request } from '../api';
import { useEditorData } from '../hooks';
import { episodeSnapshot, showSnapshot } from '../snapshot';
import ElementPreview from './element-preview';
import EpisodePicker, { LATEST } from './episode-picker';
import HeadingLevelDropdown from './heading-level';
import ShowPlaceholder, { Artwork } from './show-placeholder';
import { LoadingState, StateMessage, StateStrip } from './state-message';
import { formatDate } from '../format';

/** States that keep a preview, with a strip above it. */
const STRIP_STATES = [ 'scheduled', 'error', 'rate_limited' ];

/**
 * A scheduled episode cannot load in the element yet (the public API answers 404), so the
 * editor shows its title and date, dimmed (6k).
 *
 * @param {Object} props
 * @param {Object} props.episode Episode from the keyed API.
 */
function ScheduledPreview( { episode } ) {
	return (
		<div className="showfm-scheduled" aria-hidden="true">
			<Artwork src={ episode.artwork } size={ 64 } />
			<span className="showfm-scheduled__text">
				<strong>{ episode.title }</strong>
				<span className="showfm-muted">
					{ [ episode.podcast?.title, formatDate( episode.date ) ]
						.filter( Boolean )
						.join( ' · ' ) }
				</span>
			</span>
		</div>
	);
}

/**
 * @param {Object}                       props
 * @param {string}                       props.type            Element type.
 * @param {string}                       props.name            Block name.
 * @param {string}                       props.label           Block title.
 * @param {Object}                       props.icon            Block icon.
 * @param {string}                       props.instructions    Placeholder text before a show is chosen.
 * @param {boolean}                      props.needsEpisode    Whether an episode (or Latest) is chosen.
 * @param {boolean}                      props.allowLatest     Whether "Latest episode" is offered.
 * @param {boolean}                      props.headingControl  Whether the toolbar has a heading level.
 * @param {(context: Object) => Object}  props.renderInspector Inspector panels, given the loaded data.
 * @param {Object}                       props.attributes      Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes   Block setter.
 * @param {Object}                       props.editState       Shared picker state from the block.
 */
export default function ShowfmBlockEdit( {
	type,
	name,
	label,
	icon,
	instructions,
	needsEpisode,
	allowLatest = true,
	headingControl = false,
	renderInspector,
	attributes,
	setAttributes,
	editState,
} ) {
	const blockProps = useBlockProps();
	const [ ownPicking, setOwnPicking ] = useState( false );
	const [ picking, setPicking ] = editState || [ ownPicking, setOwnPicking ];
	const [ changingShow, setChangingShow ] = useState( false );
	const { episode, podcast, snapshot = {} } = attributes;
	const configured =
		!! episode || ( !! podcast && ( ! needsEpisode || !! snapshot.title ) );

	const target = episode
		? [ 'episode', { id: episode, podcast } ]
		: [ 'show', { ref: podcast } ];
	const answer = useEditorData(
		target[ 0 ],
		target[ 1 ],
		configured && ! changingShow
	);
	const data = answer.data || {};
	const showRef = podcast || data.episode?.podcast?.id;
	const showAnswer = useEditorData(
		'show',
		{ ref: showRef },
		!! showRef && ( picking || ! configured )
	);

	const chooseShow = ( show ) => {
		setChangingShow( false );
		const next = { podcast: show.id };
		if ( needsEpisode ) {
			next.episode = undefined;
			next.snapshot = {};
			setPicking( true );
		} else {
			next.snapshot = showSnapshot( show );
		}
		setAttributes( next );
	};

	const chooseEpisode = ( choice, show ) => {
		setPicking( false );
		if ( choice === LATEST ) {
			setAttributes( {
				episode: undefined,
				podcast: show.id,
				snapshot: showSnapshot( show ),
			} );
			return;
		}
		setAttributes( {
			episode: choice.id,
			podcast: show.id,
			snapshot: episodeSnapshot( choice ),
		} );
		// The picker has no links; the episode route has the public listen and audio URLs.
		request( 'episode', { id: choice.id, podcast: show.id } ).then(
			( detail ) => {
				if ( detail.state === 'ok' && detail.episode ) {
					setAttributes( {
						snapshot: episodeSnapshot( detail.episode ),
					} );
				}
			}
		);
	};

	const changeShow = () => {
		setPicking( false );
		setChangingShow( true );
	};

	const pickerShow = showAnswer.data?.show;
	let body;
	const needsShow =
		changingShow ||
		( ! configured && ! podcast ) ||
		( picking && ! showRef && ! answer.loading );
	if ( needsShow ) {
		body = (
			<ShowPlaceholder
				icon={ icon }
				label={ label }
				instructions={ instructions }
				onSelect={ chooseShow }
			/>
		);
	} else if ( needsEpisode && ( picking || ! configured ) ) {
		if ( showAnswer.loading || ( ! pickerShow && ! showAnswer.data ) ) {
			body = (
				<LoadingState
					icon={ icon }
					label={ label }
					message={ __( 'Loading show…', 'showfm' ) }
				/>
			);
		} else if ( ! pickerShow ) {
			body = (
				<StateMessage
					icon={ icon }
					label={ label }
					state={ showAnswer.data?.state || 'not_found' }
					data={ showAnswer.data }
					onChangeShow={ changeShow }
				/>
			);
		} else {
			body = (
				<EpisodePicker
					icon={ icon }
					label={ label }
					show={ pickerShow }
					allowLatest={ allowLatest }
					selected={ episode || ( snapshot.title ? LATEST : '' ) }
					onChoose={ ( choice ) =>
						chooseEpisode( choice, pickerShow )
					}
					onCancel={ () =>
						configured
							? setPicking( false )
							: setAttributes( { podcast: undefined } )
					}
					onChangeShow={ changeShow }
				/>
			);
		}
	} else if ( answer.loading ) {
		body = (
			<LoadingState
				icon={ icon }
				label={ label }
				message={
					episode
						? __( 'Loading episode…', 'showfm' )
						: __( 'Loading show…', 'showfm' )
				}
			/>
		);
	} else if ( data.state === 'ok' ) {
		body = <ElementPreview type={ type } attributes={ attributes } />;
	} else if ( STRIP_STATES.includes( data.state ) ) {
		body = (
			<>
				<StateStrip
					state={ data.state }
					data={ data }
					onRetry={ answer.retry }
				/>
				<div className="showfm-strip__preview">
					{ data.state === 'scheduled' ? (
						<ScheduledPreview episode={ data.episode || {} } />
					) : (
						<ServerSideRender
							block={ name }
							attributes={ attributes }
						/>
					) }
				</div>
			</>
		);
	} else {
		body = (
			<StateMessage
				icon={ icon }
				label={ label }
				state={ data.state || 'error' }
				data={ data }
				onChooseEpisode={
					needsEpisode ? () => setPicking( true ) : undefined
				}
				onChangeShow={ changeShow }
			/>
		);
	}

	const ready = configured && ! changingShow && ! picking;
	return (
		<div { ...blockProps }>
			{ ready && (
				<BlockControls group="block">
					{ headingControl && attributes[ 'heading-level' ] && (
						<HeadingLevelDropdown
							value={ attributes[ 'heading-level' ] }
							onChange={ ( level ) =>
								setAttributes( { 'heading-level': level } )
							}
						/>
					) }
				</BlockControls>
			) }
			{ ready && (
				<BlockControls group="other">
					<ToolbarGroup>
						<ToolbarButton
							onClick={
								needsEpisode
									? () => setPicking( true )
									: changeShow
							}
						>
							{ needsEpisode
								? __( 'Change episode', 'showfm' )
								: __( 'Change show', 'showfm' ) }
						</ToolbarButton>
					</ToolbarGroup>
				</BlockControls>
			) }
			{ ready && renderInspector && (
				<InspectorControls>
					{ renderInspector( {
						data,
						startPicking: () => setPicking( true ),
						changeShow,
					} ) }
				</InspectorControls>
			) }
			{ body }
		</div>
	);
}
