/**
 * show.fm Transcript (6d): follows a Player (or Episode list) on the page, or shows one
 * episode's transcript.
 */
import {
	BlockControls,
	InspectorControls,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ShowfmBlockEdit from '../components/block-edit';
import ElementPreview from '../components/element-preview';
import { StateMessage } from '../components/state-message';
import { useEditorData } from '../hooks';
import { transcriptIcon } from '../icons';

const FOLLOWABLE = [ 'showfm/player', 'showfm/episodes' ];

/**
 * A name for a followable block in the select: its saved title, else its kind.
 *
 * @param {Object} block Block.
 * @param {number} index Position among followable blocks.
 * @return {string} Label.
 */
export function followLabel( block, index ) {
	const title = block.attributes.snapshot?.title;
	const kind =
		block.name === 'showfm/episodes'
			? __( 'Episode list', 'showfm' )
			: __( 'Player', 'showfm' );
	if ( title ) {
		return title.length > 32 ? `${ title.slice( 0, 30 ) }…` : title;
	}
	return sprintf(
		/* translators: 1: block kind, such as Player. 2: its number on the page. */
		__( '%1$s %2$d', 'showfm' ),
		kind,
		index + 1
	);
}

/**
 * An element id for a block that does not have a unique one yet.
 *
 * @param {Object}   block Block.
 * @param {string[]} taken Ids other blocks use.
 * @return {string} Id.
 */
export function elementIdFor( block, taken ) {
	const id = block.attributes.id;
	// The server keeps only ids with the showfm- prefix (Attributes::ELEMENT_ID).
	if (
		typeof id === 'string' &&
		/^showfm-[A-Za-z0-9_-]{1,57}$/.test( id ) &&
		! taken.includes( id )
	) {
		return id;
	}
	const prefix =
		block.name === 'showfm/episodes' ? 'showfm-episodes' : 'showfm-player';
	return `${ prefix }-${ block.clientId.slice( 0, 8 ) }`;
}

export default function TranscriptEdit( props ) {
	const { attributes, setAttributes } = props;
	const blockProps = useBlockProps();
	const [ ownEpisode, setOwnEpisode ] = useState( false );
	const picking = useState( false );
	const followable = useSelect( ( select ) => {
		const store = select( blockEditorStore );
		return store
			.getBlocksByName( FOLLOWABLE )
			.map( ( id ) => store.getBlock( id ) )
			.filter( Boolean );
	}, [] );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	const followedAnswer = useEditorData(
		'episode',
		{ id: attributes.episode },
		!! attributes.for && !! attributes.episode
	);
	const noTranscript =
		followedAnswer.data?.state === 'ok' &&
		followedAnswer.data.episode?.transcript === false;
	const followed = followable.find(
		( block ) => attributes.for && block.attributes.id === attributes.for
	);

	// Follow the episode of the block this transcript follows.
	const followedEpisode = followed?.attributes.episode;
	useEffect( () => {
		if ( followed && followedEpisode !== attributes.episode ) {
			setAttributes( { episode: followedEpisode } );
		}
	}, [ followed, followedEpisode, attributes.episode, setAttributes ] );

	const follow = ( targetId ) => {
		const target = followable.find(
			( block ) => block.clientId === targetId
		);
		if ( ! target ) {
			setAttributes( { for: undefined } );
			return;
		}
		const taken = followable
			.filter( ( block ) => block.clientId !== target.clientId )
			.map( ( block ) => block.attributes.id );
		const id = elementIdFor( target, taken );
		if ( id !== target.attributes.id ) {
			updateBlockAttributes( target.clientId, { id } );
		}
		setAttributes( {
			for: id,
			episode: target.attributes.episode,
			podcast: undefined,
			snapshot: {},
		} );
	};

	const followPanel = (
		<PanelBody title={ __( 'Follows', 'showfm' ) }>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Player', 'showfm' ) }
				value={ followed?.clientId || '' }
				onChange={ follow }
				help={ __(
					'Highlights along with this player. You can pick any Player block on the page.',
					'showfm'
				) }
				options={ [
					{
						label: attributes.episode
							? __( 'This episode only', 'showfm' )
							: __( 'Whatever plays on the page', 'showfm' ),
						value: '',
					},
					...followable.map( ( block, index ) => ( {
						label: followLabel( block, index ),
						value: block.clientId,
					} ) ),
				] }
			/>
		</PanelBody>
	);
	const sizePanel = (
		<PanelBody title={ __( 'Size', 'showfm' ) }>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Height', 'showfm' ) }
				help={ __( 'In pixels.', 'showfm' ) }
				min={ 120 }
				max={ 2000 }
				step={ 10 }
				value={ Number( attributes.height ) || 320 }
				onChange={ ( height ) =>
					setAttributes( {
						height: height ? String( height ) : undefined,
					} )
				}
			/>
		</PanelBody>
	);

	if ( attributes.for || ( ! attributes.episode && ! ownEpisode ) ) {
		if ( ! attributes.for && followable.length ) {
			return (
				<div { ...blockProps }>
					<Placeholder
						icon={ transcriptIcon }
						label={ __( 'show.fm Transcript', 'showfm' ) }
						instructions={ __(
							'Choose the player this transcript follows.',
							'showfm'
						) }
						className="showfm-placeholder"
					>
						<div className="showfm-shows">
							{ followable.map( ( block, index ) => (
								<button
									type="button"
									key={ block.clientId }
									className="showfm-show"
									onClick={ () => follow( block.clientId ) }
								>
									<span className="showfm-show__name">
										{ followLabel( block, index ) }
									</span>
								</button>
							) ) }
						</div>
						<Button
							variant="link"
							className="showfm-switch"
							onClick={ () => setOwnEpisode( true ) }
						>
							{ __(
								'Show one episode’s transcript instead',
								'showfm'
							) }
						</Button>
					</Placeholder>
				</div>
			);
		}
		if ( attributes.for ) {
			return (
				<div { ...blockProps }>
					<BlockControls group="other">
						<ToolbarGroup>
							<ToolbarButton
								onClick={ () =>
									setAttributes( { for: undefined } )
								}
							>
								{ __( 'Change player', 'showfm' ) }
							</ToolbarButton>
						</ToolbarGroup>
					</BlockControls>
					<InspectorControls>
						{ followPanel }
						{ sizePanel }
					</InspectorControls>
					{ followed && noTranscript && (
						<StateMessage
							icon={ transcriptIcon }
							label={ __( 'show.fm Transcript', 'showfm' ) }
							state="no_transcript"
						/>
					) }
					{ followed && ! noTranscript && (
						<ElementPreview
							type="transcript"
							attributes={ attributes }
						/>
					) }
					{ ! followed && (
						<Placeholder
							icon={ transcriptIcon }
							label={ __( 'show.fm Transcript', 'showfm' ) }
							instructions={ __(
								'The player this transcript followed is no longer on the page.',
								'showfm'
							) }
							className="showfm-placeholder"
						>
							<Button
								variant="secondary"
								onClick={ () =>
									setAttributes( { for: undefined } )
								}
							>
								{ __( 'Change player', 'showfm' ) }
							</Button>
						</Placeholder>
					) }
				</div>
			);
		}
	}

	return (
		<ShowfmBlockEdit
			{ ...props }
			editState={ picking }
			type="transcript"
			name="showfm/transcript"
			label={ __( 'show.fm Transcript', 'showfm' ) }
			icon={ transcriptIcon }
			instructions={ __(
				'Add a public show from show.fm. You’ll choose the episode next.',
				'showfm'
			) }
			needsEpisode
			allowLatest={ false }
			renderInspector={ () => (
				<>
					{ followPanel }
					{ sizePanel }
				</>
			) }
		/>
	);
}
