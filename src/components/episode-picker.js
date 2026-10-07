/**
 * The episode step (6g): search, season, "Latest episode", and scheduled episodes when the
 * site is connected and the show is the account's own.
 */
import {
	Button,
	Icon,
	Placeholder,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { update } from '@wordpress/icons';
import { episodeMeta } from '../format';
import { useEditorData } from '../hooks';
import { Artwork } from './show-placeholder';
import { StateMessage } from './state-message';

export const LATEST = 'latest';

/**
 * Newest first, scheduled (future) episodes on top.
 *
 * @param {Object[]} episodes Picker episodes.
 * @return {Object[]} Sorted copy.
 */
export function sortEpisodes( episodes ) {
	return [ ...episodes ].sort( ( a, b ) =>
		String( b.date || '' ).localeCompare( String( a.date || '' ) )
	);
}

/**
 * Episodes matching the search text and season.
 *
 * @param {Object[]} episodes Picker episodes.
 * @param {string}   search   Search text.
 * @param {string}   season   Season number, or ''.
 * @return {Object[]} Matches.
 */
export function filterEpisodes( episodes, search, season ) {
	const needle = search.trim().toLowerCase();
	return episodes.filter(
		( episode ) =>
			( season === '' || String( episode.season ) === season ) &&
			( ! needle || episode.title.toLowerCase().includes( needle ) )
	);
}

/**
 * The seasons present, highest first.
 *
 * @param {Object[]} episodes Picker episodes.
 * @return {number[]} Season numbers.
 */
export function seasonsOf( episodes ) {
	return [
		...new Set(
			episodes
				.map( ( episode ) => episode.season )
				.filter( ( season ) => typeof season === 'number' )
		),
	].sort( ( a, b ) => b - a );
}

/**
 * @param {Object}                          props
 * @param {Object}                          props.icon         Block icon.
 * @param {string}                          props.label        Block name.
 * @param {Object}                          props.show         Show: id, title, artwork.
 * @param {boolean}                         props.allowLatest  Whether "Latest episode" is offered.
 * @param {string}                          props.selected     Current episode id, or LATEST.
 * @param {(choice: Object|string) => void} props.onChoose     Called with the episode, or LATEST.
 * @param {() => void}                      props.onCancel     Leave the picker.
 * @param {() => void}                      props.onChangeShow Choose another show.
 */
export default function EpisodePicker( {
	icon,
	label,
	show,
	allowLatest = true,
	selected = '',
	onChoose,
	onCancel,
	onChangeShow,
} ) {
	const { loading, data, retry } = useEditorData( 'episodes', {
		podcast: show.id,
	} );
	const [ search, setSearch ] = useState( '' );
	const [ season, setSeason ] = useState( '' );
	const [ choice, setChoice ] = useState( selected );
	const episodes = useMemo(
		() => sortEpisodes( data?.episodes || [] ),
		[ data ]
	);
	const seasons = seasonsOf( episodes );
	const matches = filterEpisodes( episodes, search, season );
	const showLatest = allowLatest && ! search && season === '';

	if ( data && data.state !== 'ok' ) {
		return (
			<StateMessage
				icon={ icon }
				label={ label }
				state={ data.state === 'rate_limited' ? 'error' : data.state }
				data={ data }
				onChangeShow={ onChangeShow }
				onChooseEpisode={ data.state === 'error' ? retry : undefined }
			/>
		);
	}

	const options = [
		...( showLatest
			? [
					{
						id: LATEST,
						title: __( 'Latest episode', 'showfm' ),
						meta: __( 'Always plays the newest episode', 'showfm' ),
						latest: true,
					},
				]
			: [] ),
		...matches.map( ( episode ) => ( {
			id: episode.id,
			title: episode.title,
			meta: episodeMeta( episode ),
			artwork: episode.artwork || show.artwork,
			tag:
				( episode.scheduled && __( 'Scheduled', 'showfm' ) ) ||
				( episode.type === 'bonus' && __( 'Bonus', 'showfm' ) ) ||
				( episode.type === 'trailer' && __( 'Trailer', 'showfm' ) ) ||
				'',
			episode,
		} ) ),
	];

	const confirm = () => {
		if ( choice === LATEST ) {
			onChoose( LATEST );
			return;
		}
		const picked = episodes.find( ( episode ) => episode.id === choice );
		if ( picked ) {
			onChoose( picked );
		}
	};

	return (
		<Placeholder
			icon={ icon }
			label={ label }
			className="showfm-placeholder showfm-picker"
		>
			<div className="showfm-picker__show">
				<Artwork src={ show.artwork } size={ 20 } />
				<span>{ show.title }</span>
				<span aria-hidden="true">·</span>
				<Button variant="link" onClick={ onChangeShow }>
					{ __( 'Change', 'showfm' ) }
				</Button>
			</div>
			<div className="showfm-picker__filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search episodes', 'showfm' ) }
					placeholder={ __( 'Search episodes', 'showfm' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					hideLabelFromVision
					label={ __( 'Season', 'showfm' ) }
					value={ season }
					onChange={ setSeason }
					options={ [
						{ label: __( 'All seasons', 'showfm' ), value: '' },
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
			</div>
			{ loading && (
				<div className="showfm-loading" role="status">
					<Spinner />
					{ __( 'Loading episodes…', 'showfm' ) }
				</div>
			) }
			{ ! loading && (
				<fieldset className="showfm-episodes">
					<legend className="screen-reader-text">
						{ __( 'Episode', 'showfm' ) }
					</legend>
					{ options.map( ( option ) => (
						<label
							key={ option.id }
							htmlFor={ `showfm-episode-${ show.id }-${ option.id }` }
							className={
								'showfm-episode' +
								( choice === option.id ? ' is-checked' : '' )
							}
						>
							<input
								id={ `showfm-episode-${ show.id }-${ option.id }` }
								type="radio"
								name={ `showfm-episode-${ show.id }` }
								value={ option.id }
								checked={ choice === option.id }
								onChange={ () => setChoice( option.id ) }
							/>
							{ option.latest ? (
								<span className="showfm-art is-latest">
									<Icon icon={ update } size={ 20 } />
								</span>
							) : (
								<Artwork src={ option.artwork } size={ 32 } />
							) }
							<span className="showfm-episode__text">
								<span className="showfm-episode__title">
									{ option.title }
								</span>
								<span className="showfm-muted">
									{ option.meta }
								</span>
							</span>
							{ option.tag && (
								<span className="showfm-tag">
									{ option.tag }
								</span>
							) }
						</label>
					) ) }
					{ ! options.length && (
						<p className="showfm-muted showfm-empty">
							{ episodes.length
								? __( 'No episodes match.', 'showfm' )
								: __(
										'This show has no episodes yet.',
										'showfm'
									) }
						</p>
					) }
				</fieldset>
			) }
			<div className="showfm-picker__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={ ! choice || loading }
					onClick={ confirm }
				>
					{ __( 'Use this episode', 'showfm' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'showfm' ) }
				</Button>
			</div>
		</Placeholder>
	);
}
