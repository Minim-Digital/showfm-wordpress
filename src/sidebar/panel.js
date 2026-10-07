/**
 * The "show.fm" panel's content: status, the episode for this post, and Open in show.fm.
 */
import { ComboboxControl, ExternalLink, Icon } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import { episodeMeta } from '../format';
import { getSettings } from '../settings';
import { syncStatus } from './status';

/**
 * Episodes to pick from: the connected account's shows, else the current episode's show.
 *
 * @param {string}  episodeId Current episode.
 * @param {boolean} enabled   Whether the panel is showing.
 * @return {{options: Object[], appUrl: string|null}} Options and the episode's app link.
 */
export function useEpisodeOptions( episodeId, enabled = true ) {
	const [ result, setResult ] = useState( { options: [], appUrl: null } );
	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		let live = true;
		const settings = getSettings();
		const current = episodeId
			? request( 'episode', { id: episodeId } )
			: Promise.resolve( {} );
		const shows =
			settings.connected && settings.privateData
				? request( 'shows' ).then( ( answer ) =>
						( answer.shows || [] )
							.filter( ( show ) => show.hosting !== 'external' )
							.slice( 0, 5 )
							.map( ( show ) => show.id )
					)
				: current.then( ( answer ) =>
						answer.episode?.podcast?.id
							? [ answer.episode.podcast.id ]
							: []
					);
		Promise.all( [ current, shows ] )
			.then( ( [ detail, ids ] ) =>
				Promise.all(
					ids.map( ( podcast ) => request( 'episodes', { podcast } ) )
				).then( ( lists ) => {
					const seen = new Set();
					const options = [];
					for ( const list of lists ) {
						for ( const episode of list.episodes || [] ) {
							if ( ! seen.has( episode.id ) ) {
								seen.add( episode.id );
								options.push( {
									value: episode.id,
									label: episode.title,
									meta: episodeMeta( episode ),
								} );
							}
						}
					}
					if ( detail.episode && ! seen.has( detail.episode.id ) ) {
						options.unshift( {
							value: detail.episode.id,
							label: detail.episode.title,
							meta: episodeMeta( detail.episode ),
						} );
					}
					if ( live ) {
						setResult( {
							options,
							appUrl: detail.episode?.appUrl || null,
						} );
					}
				} )
			)
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [ episodeId, enabled ] );
	return result;
}

/**
 * @param {Object}                  props
 * @param {Object}                  props.sync      `showfm_sync` field.
 * @param {string}                  props.episodeId `_showfm_episode_id`.
 * @param {(value: string) => void} props.onChange  Called with the new episode id.
 * @param {Object[]}                props.options   Episode options.
 * @param {string}                  props.appUrl    The episode in show.fm, if known.
 */
export default function SyncPanel( {
	sync,
	episodeId,
	onChange,
	options,
	appUrl,
} ) {
	const status = syncStatus( sync );
	const deleted = sync?.state === 'deleted';
	return (
		<div className="showfm-panel">
			{ status && (
				<div className={ `showfm-panel__status is-${ status.tone }` }>
					<Icon icon={ status.icon } size={ 20 } />
					<span>
						<strong>{ status.title }</strong>
						{ status.sub && (
							<span className="showfm-panel__sub">
								{ status.sub }
							</span>
						) }
					</span>
				</div>
			) }
			<ComboboxControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Episode for this post', 'showfm' ) }
				placeholder={ __( 'Choose an episode', 'showfm' ) }
				help={ __(
					'Heading and Paragraph blocks connected to the episode title or description show this episode.',
					'showfm'
				) }
				value={ deleted ? '' : episodeId || '' }
				options={ options }
				onChange={ ( value ) => onChange( value || '' ) }
				__experimentalRenderItem={ ( { item } ) => (
					<span className="showfm-panel__option">
						<span>{ item.label }</span>
						{ item.meta && (
							<span className="showfm-panel__sub">
								{ item.meta }
							</span>
						) }
					</span>
				) }
			/>
			{ appUrl && ! deleted && (
				<ExternalLink href={ appUrl }>
					{ __( 'Open in show.fm', 'showfm' ) }
				</ExternalLink>
			) }
		</div>
	);
}
