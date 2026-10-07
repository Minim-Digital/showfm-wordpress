/**
 * Placeholder for an empty block: a show address when the site is not connected (6e), or
 * the connected account's shows (6f).
 */
import {
	Button,
	Icon,
	Placeholder,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronRight } from '@wordpress/icons';
import { parseShowAddress } from '../address';
import { request } from '../api';
import { useEditorData } from '../hooks';
import { getSettings } from '../settings';
import { stateCopy } from './state-message';

/**
 * Artwork square, or a plain tile when the show has none.
 *
 * @param {Object} props
 * @param {string} props.src  Image URL.
 * @param {number} props.size Pixels.
 */
export function Artwork( { src, size = 40 } ) {
	return src ? (
		<img
			className="showfm-art"
			src={ src }
			alt=""
			width={ size }
			height={ size }
		/>
	) : (
		<span
			className="showfm-art is-empty"
			style={ { width: size, height: size } }
		/>
	);
}

/**
 * "the-long-table.show.fm · 8 episodes".
 *
 * @param {Object} show Show.
 * @return {string} Summary.
 */
export function showMeta( show ) {
	const parts = [];
	if ( show.listen ) {
		parts.push( show.listen.replace( /^https:\/\//, '' ) );
	}
	if ( typeof show.episodes === 'number' ) {
		parts.push(
			sprintf(
				/* translators: %d: number of episodes. */
				_n( '%d episode', '%d episodes', show.episodes, 'showfm' ),
				show.episodes
			)
		);
	}
	if ( show.hosting === 'external' ) {
		parts.push( __( 'Hosted outside show.fm', 'showfm' ) );
	}
	return parts.join( ' · ' );
}

/**
 * The show step for every block.
 *
 * @param {Object}                 props
 * @param {Object}                 props.icon         Block icon.
 * @param {string}                 props.label        Block name.
 * @param {string}                 props.instructions What the show is for.
 * @param {(show: Object) => void} props.onSelect     Called with the chosen show.
 */
export default function ShowPlaceholder( {
	icon,
	label,
	instructions,
	onSelect,
} ) {
	const settings = getSettings();
	const [ mode, setMode ] = useState(
		settings.connected ? 'list' : 'address'
	);
	const [ address, setAddress ] = useState( '' );
	const [ problem, setProblem ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const shows = useEditorData( 'shows', {}, mode === 'list' );
	const list = shows.data?.shows || [];
	const listFailed =
		shows.data && ( ! shows.data.connected || ! list.length );

	const lookUp = ( event ) => {
		event.preventDefault();
		const ref = parseShowAddress( address );
		if ( ! ref ) {
			setProblem(
				__(
					'Enter a show address, such as the-long-table.show.fm, or its slug.',
					'showfm'
				)
			);
			return;
		}
		setBusy( true );
		setProblem( null );
		request( 'show', { ref } ).then( ( answer ) => {
			setBusy( false );
			if ( answer.state === 'ok' && answer.show ) {
				onSelect( answer.show );
			} else {
				setProblem( stateCopy( answer.state, answer )?.message );
			}
		} );
	};

	const choose = ( show ) => {
		if ( show.hosting === 'external' ) {
			setProblem( stateCopy( 'external' ).message );
			return;
		}
		onSelect( show );
	};

	if ( mode === 'list' && ! listFailed ) {
		return (
			<Placeholder
				icon={ icon }
				label={ label }
				instructions={ __( 'Choose a show.', 'showfm' ) }
				className="showfm-placeholder"
			>
				{ shows.loading && (
					<div className="showfm-loading" role="status">
						<Spinner />
						{ __( 'Loading your shows…', 'showfm' ) }
					</div>
				) }
				{ ! shows.loading && (
					<div className="showfm-shows">
						{ list.map( ( show ) => (
							<button
								type="button"
								key={ show.id }
								className="showfm-show"
								onClick={ () => choose( show ) }
							>
								<Artwork src={ show.artwork } />
								<span className="showfm-show__text">
									<span className="showfm-show__name">
										{ show.title }
									</span>
									<span className="showfm-muted">
										{ showMeta( show ) }
									</span>
								</span>
								<Icon icon={ chevronRight } />
							</button>
						) ) }
					</div>
				) }
				{ problem && (
					<p className="showfm-problem" role="alert">
						{ problem }
					</p>
				) }
				<Button
					variant="link"
					className="showfm-switch"
					onClick={ () => {
						setProblem( null );
						setMode( 'address' );
					} }
				>
					{ __( 'Use the address of another public show', 'showfm' ) }
				</Button>
			</Placeholder>
		);
	}

	return (
		<Placeholder
			icon={ icon }
			label={ label }
			instructions={ instructions }
			className="showfm-placeholder"
		>
			<form className="showfm-address" onSubmit={ lookUp }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Show address or slug', 'showfm' ) }
					placeholder="the-long-table.show.fm"
					value={ address }
					onChange={ setAddress }
				/>
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Continue', 'showfm' ) }
				</Button>
			</form>
			{ problem && (
				<p className="showfm-problem" role="alert">
					{ problem }
				</p>
			) }
			{ ! settings.connected && (
				<p className="showfm-muted showfm-connect">
					{ __(
						'Connect this site to show.fm to pick from your own shows and see scheduled episodes.',
						'showfm'
					) }{ ' ' }
					{ settings.canConnect && settings.connectUrl && (
						<a href={ settings.connectUrl }>
							{ __( 'Connect to show.fm', 'showfm' ) }
						</a>
					) }
				</p>
			) }
			{ settings.connected && ! listFailed && (
				<Button
					variant="link"
					className="showfm-switch"
					onClick={ () => {
						setProblem( null );
						setMode( 'list' );
					} }
				>
					{ __( 'Choose from your shows', 'showfm' ) }
				</Button>
			) }
		</Placeholder>
	);
}
