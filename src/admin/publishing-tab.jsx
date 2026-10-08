/**
 * Settings > show.fm > Publishing. Only shown while a connection is stored.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Notice,
	SelectControl,
	Snackbar,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Path, SVG } from '@wordpress/primitives';

import { listNames } from './connection-view';

/** The tab's data and its save route. Admin preloads the same path. */
export const PUBLISHING_PATH = '/showfm/v1/admin/publishing';

/** Dashicons used by the tab. */
const ICONS = {
	down: 'M5 6l5 5 5-5 2 1-7 7-7-7z',
	update: 'M10.2 3.28c3.53 0 6.43 2.61 6.92 6h2.08l-3.5 4-3.5-4h2.32c-.45-1.97-2.21-3.45-4.32-3.45-1.45 0-2.73.71-3.54 1.78L4.95 5.66C6.23 4.2 8.11 3.28 10.2 3.28zm-.4 13.44c-3.52 0-6.43-2.61-6.92-6H.8l3.5-4c1.17 1.33 2.33 2.67 3.5 4H5.48c.45 1.97 2.21 3.45 4.32 3.45 1.45 0 2.73-.71 3.54-1.78l1.71 1.95c-1.28 1.46-3.15 2.38-5.25 2.38z',
	backup: 'M13.65 2.88c3.93 2.01 5.48 6.84 3.47 10.77s-6.83 5.48-10.77 3.47c-1.87-.96-3.2-2.56-3.86-4.4l1.64-1.03c.45 1.57 1.52 2.95 3.08 3.76 3.01 1.54 6.69.35 8.23-2.66 1.55-3.01.36-6.69-2.65-8.24C9.78 3.01 6.1 4.2 4.56 7.21l1.88.97-4.95 3.08-.39-5.82 1.78.91C4.9 2.4 9.75.89 13.65 2.88zm-4.36 7.83C9.11 10.53 9 10.28 9 10c0-.07.03-.12.04-.19h-.01L10 5l.97 4.81L14 13l-4.5-2.12.02-.02c-.08-.04-.16-.09-.23-.15z',
	trash: 'M12 4h3c.6 0 1 .4 1 1v1H3V5c0-.6.5-1 1-1h3c.2-1.1 1.3-2 2.5-2s2.3.9 2.5 2zM8 4h3c-.2-.6-.9-1-1.5-1S8.2 3.4 8 4zM4 7h11l-.9 10.1c0 .5-.5.9-1 .9H5.9c-.5 0-.9-.4-1-.9L4 7z',
};

/**
 * A 20px dashicon.
 *
 * @param {Object} props
 * @param {string} props.path      Path data.
 * @param {number} props.size      Size in pixels.
 * @param {string} props.className Class name.
 */
function Icon( { path, size = 20, className } ) {
	return (
		<SVG
			width={ size }
			height={ size }
			viewBox="0 0 20 20"
			aria-hidden="true"
			className={ className }
		>
			<Path d={ path } />
		</SVG>
	);
}

/**
 * The help under "Post new episodes automatically".
 *
 * @param {boolean} on Whether auto-posting is on.
 * @return {string} Help text.
 */
export function autoPostHelp( on ) {
	return on
		? __(
				'When an episode is published on show.fm, a post is created here. Scheduled episodes become scheduled posts.',
				'showfm'
			)
		: __(
				'New episodes aren’t posted here. Posts that already exist keep updating.',
				'showfm'
			);
}

/**
 * Keeps the author and template valid for a newly chosen post type: the first author
 * offered for it when the chosen one can't publish it, Default when its template list lacks
 * the chosen one, and no category when the type has none.
 *
 * @param {Object} data     The tab's data.
 * @param {Object} settings Settings with the new post type.
 * @return {Object} Settings.
 */
export function forPostType( data, settings ) {
	const type = settings.postType;
	const authors = data.authors[ type ] ?? [];
	const templates = data.templates[ type ] ?? [];
	const info = data.postTypes.find( ( item ) => item.value === type );
	const next = { ...settings };
	if ( ! authors.some( ( item ) => item.value === next.author ) ) {
		next.author = authors[ 0 ]?.value ?? 0;
	}
	if ( ! templates.some( ( item ) => item.value === next.template ) ) {
		next.template = '';
	}
	if ( ! info?.categories ) {
		next.category = 0;
	}
	return next;
}

/** The id of each select, by the field name the REST route uses in `data.field`. */
export const FIELD_IDS = {
	postType: 'showfm-publishing-post-type',
	category: 'showfm-publishing-category',
	author: 'showfm-publishing-author',
	template: 'showfm-publishing-template',
};

/**
 * The four selects: post type, category, author and post template.
 *
 * @param {Object}                     props
 * @param {Object}                     props.data     The tab's data.
 * @param {Object}                     props.settings Current settings.
 * @param {(settings: Object) => void} props.onChange Called with the new settings.
 * @param {string}                     props.invalid  The field the server refused, or ''.
 */
function Selects( { data, settings, onChange, invalid } ) {
	const type = data.postTypes.find(
		( item ) => item.value === settings.postType
	);
	const hasCategories = !! type?.categories;
	const toOptions = ( items ) =>
		items.map( ( item ) => ( {
			value: String( item.value ),
			label: item.label,
		} ) );
	const authors = data.authors[ settings.postType ] ?? [];

	return (
		<div className="showfm-publishing__selects">
			<SelectControl
				id={ FIELD_IDS.postType }
				aria-invalid={ invalid === 'postType' || undefined }
				label={ __( 'Post type', 'showfm' ) }
				help={ __(
					'Any public post type that supports the block editor.',
					'showfm'
				) }
				value={ settings.postType }
				options={ toOptions( data.postTypes ) }
				onChange={ ( value ) =>
					onChange(
						forPostType( data, { ...settings, postType: value } )
					)
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				id={ FIELD_IDS.category }
				aria-invalid={ invalid === 'category' || undefined }
				label={ __( 'Category', 'showfm' ) }
				help={
					hasCategories
						? __( 'Added to every new episode post.', 'showfm' )
						: sprintf(
								/* translators: %s: post type name, for example "Pages". */
								__( '%s don’t have categories.', 'showfm' ),
								type?.label ?? ''
							)
				}
				value={ String( settings.category ) }
				options={ [
					{
						value: '0',
						label: __( 'Default category', 'showfm' ),
					},
					...toOptions( data.categories ),
				] }
				disabled={ ! hasCategories }
				onChange={ ( value ) =>
					onChange( { ...settings, category: Number( value ) } )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				id={ FIELD_IDS.author }
				aria-invalid={ invalid === 'author' || undefined }
				label={ __( 'Author', 'showfm' ) }
				help={
					authors.length
						? __( 'Shown as the post’s author.', 'showfm' )
						: __(
								'Nobody on this site can publish this post type.',
								'showfm'
							)
				}
				value={ String( settings.author ) }
				options={ toOptions( authors ) }
				disabled={ ! authors.length }
				onChange={ ( value ) =>
					onChange( { ...settings, author: Number( value ) } )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<SelectControl
				id={ FIELD_IDS.template }
				aria-invalid={ invalid === 'template' || undefined }
				label={ __( 'Post template', 'showfm' ) }
				help={ __(
					'From your theme. Default uses the normal post template.',
					'showfm'
				) }
				value={ settings.template }
				options={ toOptions(
					data.templates[ settings.postType ] ?? []
				) }
				onChange={ ( value ) =>
					onChange( { ...settings, template: value } )
				}
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

/**
 * Recent activity: the last 20 sync events, or the empty state.
 *
 * @param {Object}   props
 * @param {Object[]} props.events Events, newest first.
 */
function Activity( { events } ) {
	return (
		<Card className="showfm-card">
			<CardHeader className="showfm-card__header is-baseline">
				<h2>{ __( 'Recent activity', 'showfm' ) }</h2>
				{ events.length > 0 && (
					<span className="showfm-help">
						{ __( 'Last 20 events', 'showfm' ) }
					</span>
				) }
			</CardHeader>
			{ events.length > 0 ? (
				<div className="showfm-activity">
					<table className="showfm-activity__table">
						<thead>
							<tr>
								<th scope="col" className="is-time">
									{ __( 'Time', 'showfm' ) }
								</th>
								<th scope="col">
									{ __( 'Episode', 'showfm' ) }
								</th>
								<th scope="col">
									{ __( 'What happened', 'showfm' ) }
								</th>
								<th scope="col" className="is-post">
									{ __( 'Post', 'showfm' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ events.map( ( event ) => (
								<tr key={ event.id }>
									<td className="is-time">{ event.time }</td>
									<td>
										<div className="showfm-activity__episode">
											{ event.episode }
										</div>
										{ event.show && (
											<div className="showfm-help">
												{ event.show }
											</div>
										) }
									</td>
									<td
										className={
											event.muted ? 'is-muted' : undefined
										}
									>
										{ event.what }
									</td>
									<td className="is-post">
										{ event.link ? (
											<a href={ event.link.url }>
												{ event.link.label }
											</a>
										) : (
											<span className="showfm-muted">
												{ __( 'None', 'showfm' ) }
											</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) : (
				<CardBody className="showfm-empty">
					<Icon path={ ICONS.backup } size={ 32 } />
					<p className="showfm-empty__title">
						{ __( 'No episodes posted yet.', 'showfm' ) }
					</p>
					<p className="showfm-empty__text">
						{ __(
							'The next episode you publish on show.fm will appear here, with a link to its post.',
							'showfm'
						) }
					</p>
				</CardBody>
			) }
		</Card>
	);
}

/**
 * How sync works: what happens on edit, unpublish and delete. Explained, not configurable.
 */
function HowSyncWorks() {
	const rules = [
		[
			ICONS.down,
			__( 'Sync is one way, from show.fm to WordPress.', 'showfm' ),
		],
		[
			ICONS.update,
			__(
				'If you edit a synced post here, show.fm then only updates its date and status.',
				'showfm'
			),
		],
		[
			ICONS.backup,
			__( 'Unpublished episodes go back to draft.', 'showfm' ),
		],
		[ ICONS.trash, __( 'Deleted episodes go to the bin.', 'showfm' ) ],
	];
	return (
		<Card className="showfm-card showfm-rules">
			<CardHeader>
				<h2>{ __( 'How sync works', 'showfm' ) }</h2>
			</CardHeader>
			<CardBody>
				<ul>
					{ rules.map( ( [ path, text ] ) => (
						<li key={ text }>
							<Icon
								path={ path }
								className="showfm-rules__icon"
							/>
							<span>{ text }</span>
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}

/**
 * The Publishing tab: the auto-posting settings with Save changes, recent activity, and how
 * sync works.
 */
export default function PublishingTab() {
	const [ data, setData ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ saved, setSaved ] = useState( false );
	const [ invalid, setInvalid ] = useState( '' );
	const errorRef = useRef();

	// After a refused save, focus the field the server named, or the error notice.
	useEffect( () => {
		if ( ! error ) {
			return;
		}
		const field =
			invalid && document.getElementById( FIELD_IDS[ invalid ] );
		( field || errorRef.current )?.focus();
	}, [ error, invalid ] );

	// The settings as the selects show them: an author, template or category the post type
	// no longer offers becomes the first valid choice, so what is saved is what is shown.
	const take = ( next ) => {
		setData( next );
		setSettings( forPostType( next, next.settings ) );
	};

	useEffect( () => {
		apiFetch( { path: PUBLISHING_PATH } )
			.then( take )
			.catch( () => setLoadFailed( true ) );
	}, [] );

	const save = async () => {
		setSaving( true );
		setError( null );
		setInvalid( '' );
		setSaved( false );
		try {
			take(
				await apiFetch( {
					path: PUBLISHING_PATH,
					method: 'POST',
					data: settings,
				} )
			);
			setSaved( true );
		} catch ( failure ) {
			setInvalid(
				failure?.code === 'showfm_invalid_setting' &&
					FIELD_IDS[ failure?.data?.field ]
					? failure.data.field
					: ''
			);
			setError(
				failure?.code === 'showfm_invalid_setting' && failure?.message
					? failure.message
					: __(
							'The settings couldn’t be saved. Try again.',
							'showfm'
						)
			);
		}
		setSaving( false );
	};

	if ( loadFailed ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>
					{ __(
						'The publishing settings couldn’t be loaded. Reload the page to try again.',
						'showfm'
					) }
				</p>
			</Notice>
		);
	}
	if ( ! data ) {
		return <Spinner />;
	}

	return (
		<div className="showfm-publishing">
			<div className="showfm-tab">
				{ data.problem && (
					<Notice
						className="showfm-notice"
						status="warning"
						isDismissible={ false }
					>
						<p>{ data.problem.message }</p>
					</Notice>
				) }
				{ error && (
					<div ref={ errorRef } tabIndex={ -1 }>
						<Notice
							className="showfm-notice"
							status="error"
							onRemove={ () => setError( null ) }
						>
							<p>{ error }</p>
						</Notice>
					</div>
				) }
				<Card className="showfm-card">
					<CardHeader className="showfm-card__header is-stacked">
						<h2>{ __( 'Auto-posting', 'showfm' ) }</h2>
						{ data.shows.length > 0 && (
							<p className="showfm-help">
								{ sprintf(
									/* translators: %s: show names, for example "The Long Table and Second Helpings". */
									__( 'Applies to %s.', 'showfm' ),
									listNames( data.shows )
								) }
							</p>
						) }
					</CardHeader>
					<CardBody className="showfm-publishing__body">
						<ToggleControl
							label={ __(
								'Post new episodes automatically',
								'showfm'
							) }
							help={ autoPostHelp( settings.autoPost ) }
							checked={ settings.autoPost }
							onChange={ ( checked ) =>
								setSettings( {
									...settings,
									autoPost: checked,
								} )
							}
							__nextHasNoMarginBottom
						/>
						<div
							className={ `showfm-publishing__options${
								settings.autoPost ? '' : ' is-dimmed'
							}` }
						>
							<Selects
								data={ data }
								settings={ settings }
								onChange={ ( next ) => {
									setInvalid( '' );
									setSettings( next );
								} }
								invalid={ invalid }
							/>
							<div className="showfm-publishing__toggles">
								<ToggleControl
									label={ __(
										'Include the transcript',
										'showfm'
									) }
									help={ __(
										'Adds the transcript under the player, so search engines and screen readers can use it. It starts the way you chose when you approved the connection.',
										'showfm'
									) }
									checked={ settings.transcript }
									onChange={ ( checked ) =>
										setSettings( {
											...settings,
											transcript: checked,
										} )
									}
									__nextHasNoMarginBottom
								/>
								<ToggleControl
									label={ __(
										'Use the episode artwork as the featured image',
										'showfm'
									) }
									help={ __(
										'Falls back to the show artwork when an episode has none.',
										'showfm'
									) }
									checked={ settings.featuredImage }
									onChange={ ( checked ) =>
										setSettings( {
											...settings,
											featuredImage: checked,
										} )
									}
									__nextHasNoMarginBottom
								/>
							</div>
						</div>
					</CardBody>
					<CardFooter
						justify="flex-start"
						className="showfm-card__footer"
					>
						<Button
							variant="primary"
							onClick={ save }
							isBusy={ saving }
							disabled={ saving }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Save changes', 'showfm' ) }
						</Button>
					</CardFooter>
				</Card>
				<Activity events={ data.activity } />
			</div>
			<HowSyncWorks />
			{ saved && (
				<div className="showfm-snackbar">
					<Snackbar onRemove={ () => setSaved( false ) }>
						{ __( 'Settings saved.', 'showfm' ) }
					</Snackbar>
				</div>
			) }
		</div>
	);
}
