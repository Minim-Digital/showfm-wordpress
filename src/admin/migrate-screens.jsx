/**
 * The Migrate tab's screens: intro, scanning, report, confirm, swapping, results and empty.
 * Each draws what the view says and calls back; the tab owns the steps and the focus.
 */
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Path, SVG } from '@wordpress/primitives';

import { needsReconnect } from './connection-view';
import {
	GROUPS,
	MORE_ROWS,
	confirmText,
	formatCount,
	foundText,
	groupHeading,
	notChangedText,
	percent,
	problemAction,
	reportTitle,
	rowStatus,
	scanLine,
	scanNote,
	skipNote,
	summaryTiles,
	swapLabel,
	swapLine,
	swappedText,
} from './migrate-view';

/** Dashicons used by the tab. */
const ICONS = {
	yes: 'M14.83 4.89l1.34.94-5.81 8.38H9.02L5.78 9.67l1.34-1.25 2.57 2.4z',
	yesAlt: 'M10 2c-4.42 0-8 3.58-8 8s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm-.615 12.66h-1.34l-3.24-4.54 1.34-1.25 2.57 2.4 5.14-5.93 1.34.94-5.81 8.38z',
	warning:
		'M10 2c4.42 0 8 3.58 8 8s-3.58 8-8 8-8-3.58-8-8 3.58-8 8-8zm1.13 9.38l.35-6.46H8.52l.35 6.46h2.26zm-.09 3.36c.24-.23.37-.55.37-.96 0-.42-.12-.74-.36-.97s-.59-.35-1.06-.35-.82.12-1.07.35-.37.55-.37.97c0 .41.13.73.38.96.26.23.61.34 1.06.34s.8-.11 1.05-.34z',
	no: 'M14.95 6.46L11.41 10l3.54 3.54-1.41 1.41L10 11.42l-3.53 3.53-1.42-1.42L8.58 10 5.05 6.47l1.42-1.42L10 8.58l3.54-3.53z',
};

/** The icon for each status tone. */
const TONE_ICONS = {
	success: ICONS.yes,
	warning: ICONS.warning,
	muted: ICONS.no,
	done: ICONS.yesAlt,
};

/**
 * A dashicon.
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
 * A card heading that takes focus when its step appears.
 *
 * @param {Object} props
 * @param {Object} props.headingRef Ref the tab focuses.
 * @param {string} props.children   Text.
 */
function Heading( { headingRef, children } ) {
	return (
		<h2 ref={ headingRef } tabIndex={ -1 }>
			{ children }
		</h2>
	);
}

/**
 * The connect form, sent with its nonce like the Connection tab's button.
 *
 * @param {Object} props
 * @param {Object} props.connection Connection data.
 * @param {Object} props.formRef    Ref to the form.
 */
export function ConnectForm( { connection, formRef } ) {
	if ( ! connection?.connect ) {
		return null;
	}
	return (
		<form
			ref={ formRef }
			method="post"
			action={ connection.connect.url }
			hidden
		>
			<input
				type="hidden"
				name="action"
				value={ connection.connect.action }
			/>
			<input
				type="hidden"
				name="_wpnonce"
				value={ connection.connect.nonce }
			/>
		</form>
	);
}

/**
 * The hosts the scan detects.
 *
 * @param {Object}   props
 * @param {string[]} props.hosts Host names.
 */
function Hosts( { hosts } ) {
	return (
		<div className="showfm-migrate__hosts">
			<h3>{ __( 'Embeds we detect', 'showfm' ) }</h3>
			<ul>
				{ hosts.map( ( host ) => (
					<li key={ host }>
						<Icon
							path={ ICONS.yes }
							className="showfm-muted-icon"
						/>
						{ host }
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * The intro (4a), and the same card when the site isn't connected.
 *
 * @param {Object}     props
 * @param {Object}     props.view       The view.
 * @param {Object}     props.connection Connection data.
 * @param {boolean}    props.busy       Whether a step is starting.
 * @param {() => void} props.onScan     Starts the scan.
 * @param {() => void} props.onConnect  Starts the connect flow.
 * @param {Object}     props.headingRef Ref the tab focuses.
 */
export function Intro( {
	view,
	connection,
	busy,
	onScan,
	onConnect,
	headingRef,
} ) {
	return (
		<Card className="showfm-card showfm-migrate__narrow">
			<CardHeader>
				<Heading headingRef={ headingRef }>
					{ __(
						'Swap old podcast embeds for show.fm blocks',
						'showfm'
					) }
				</Heading>
			</CardHeader>
			<CardBody className="showfm-migrate__intro">
				<p>
					{ __(
						'We scan your posts and pages for players from other podcast hosts, then match each one to an episode on your connected shows. You review the matches before anything changes.',
						'showfm'
					) }
				</p>
				<Hosts hosts={ view.hosts } />
				{ view.connected ? (
					<p className="showfm-migrate__promise">
						{ __( 'Nothing changes until you confirm.', 'showfm' ) }
					</p>
				) : (
					<Notice
						className="showfm-notice"
						status="warning"
						isDismissible={ false }
					>
						<p>
							{ __(
								'Connect this site to show.fm to swap old embeds. Matching needs your connected shows, so it can’t run without a connection.',
								'showfm'
							) }
						</p>
					</Notice>
				) }
			</CardBody>
			<CardFooter justify="flex-start" className="showfm-card__footer">
				{ view.connected ? (
					<>
						<Button
							variant="primary"
							onClick={ onScan }
							isBusy={ busy }
							disabled={ busy }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Scan posts', 'showfm' ) }
						</Button>
						<span className="showfm-help">
							{ scanNote( view.posts ) }
						</span>
					</>
				) : (
					<>
						<Button
							variant="primary"
							onClick={ onConnect }
							__next40pxDefaultSize
						>
							{ connection && needsReconnect( connection )
								? __( 'Reconnect to show.fm', 'showfm' )
								: __( 'Connect to show.fm', 'showfm' ) }
						</Button>
						<span className="showfm-help">
							{ __(
								'You’ll sign in on show.fm, then come back here.',
								'showfm'
							) }
						</span>
					</>
				) }
			</CardFooter>
		</Card>
	);
}

/**
 * A progress bar with its value for assistive technology.
 *
 * @param {Object} props
 * @param {number} props.value Per cent.
 * @param {string} props.label Accessible name.
 */
function Progress( { value, label } ) {
	return (
		<div
			className="showfm-migrate__bar"
			role="progressbar"
			aria-label={ label }
			aria-valuenow={ value }
			aria-valuemin={ 0 }
			aria-valuemax={ 100 }
		>
			<div style={ { width: `${ value }%` } } />
		</div>
	);
}

/**
 * Scanning (4b): progress, found so far, and Stop or Resume.
 *
 * @param {Object}     props
 * @param {Object}     props.scan       The view's scan.
 * @param {boolean}    props.stepping   Whether the tab is stepping now.
 * @param {boolean}    props.stopping   Whether Stop is waiting for the last step.
 * @param {boolean}    props.canResume  Whether this admin may carry on.
 * @param {() => void} props.onStop     Pauses.
 * @param {() => void} props.onResume   Carries on.
 * @param {Object}     props.headingRef Ref the tab focuses.
 */
export function Scanning( {
	scan,
	stepping,
	stopping,
	canResume,
	onStop,
	onResume,
	headingRef,
} ) {
	const value = percent( scan.checked, scan.total );
	return (
		<Card className="showfm-card showfm-migrate__narrow">
			<CardHeader>
				<Heading headingRef={ headingRef }>
					{ stepping || ! canResume
						? __( 'Scanning your posts', 'showfm' )
						: __( 'Scan paused', 'showfm' ) }
				</Heading>
			</CardHeader>
			<CardBody className="showfm-migrate__progress">
				<div className="showfm-migrate__progress-line">
					<span className="showfm-migrate__progress-text">
						{ stepping && <Spinner /> }
						{ scanLine( scan ) }
					</span>
					{ ! scan.catalogue && (
						<span className="showfm-muted">{ `${ value }%` }</span>
					) }
				</div>
				<Progress
					value={ value }
					label={ __( 'Scan progress', 'showfm' ) }
				/>
				<p className="showfm-muted">{ foundText( scan ) }</p>
			</CardBody>
			{ canResume && (
				<CardFooter
					justify="flex-start"
					className="showfm-card__footer"
				>
					{ stepping ? (
						<Button
							variant="secondary"
							onClick={ onStop }
							isBusy={ stopping }
							disabled={ stopping }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Stop scanning', 'showfm' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ onResume }
							__next40pxDefaultSize
						>
							{ __( 'Resume scanning', 'showfm' ) }
						</Button>
					) }
				</CardFooter>
			) }
		</Card>
	);
}

/**
 * Swapping: progress through the posts being changed.
 *
 * @param {Object}     props
 * @param {Object}     props.swap       The view's swap.
 * @param {boolean}    props.stepping   Whether the tab is stepping now.
 * @param {boolean}    props.canResume  Whether this admin may carry on.
 * @param {() => void} props.onResume   Carries on.
 * @param {Object}     props.headingRef Ref the tab focuses.
 */
export function Swapping( {
	swap,
	stepping,
	canResume,
	onResume,
	headingRef,
} ) {
	return (
		<Card className="showfm-card showfm-migrate__narrow">
			<CardHeader>
				<Heading headingRef={ headingRef }>
					{ __( 'Swapping embeds', 'showfm' ) }
				</Heading>
			</CardHeader>
			<CardBody className="showfm-migrate__progress">
				<div className="showfm-migrate__progress-line">
					<span className="showfm-migrate__progress-text">
						{ stepping && <Spinner /> }
						{ swapLine( swap ) }
					</span>
					<span className="showfm-muted">{ `${ percent(
						swap.checked,
						swap.total
					) }%` }</span>
				</div>
				<Progress
					value={ percent( swap.checked, swap.total ) }
					label={ __( 'Swap progress', 'showfm' ) }
				/>
				<p className="showfm-muted">
					{ __(
						'WordPress saves a revision of each post before we change it. If you leave this page, the swap carries on from here when you come back.',
						'showfm'
					) }
				</p>
			</CardBody>
			{ canResume && ! stepping && (
				<CardFooter
					justify="flex-start"
					className="showfm-card__footer"
				>
					<Button
						variant="primary"
						onClick={ onResume }
						__next40pxDefaultSize
					>
						{ __( 'Resume swapping', 'showfm' ) }
					</Button>
				</CardFooter>
			) }
		</Card>
	);
}

/**
 * The post cell: its link (or name) and date. Takes focus after "Show more" when asked.
 *
 * @param {Object}  props
 * @param {Object}  props.post  Post title, link and date.
 * @param {boolean} props.focus Whether to take focus.
 * @param {boolean} props.date  Whether to show the date.
 */
function PostCell( { post, focus, date = true } ) {
	const ref = useRef();
	useEffect( () => {
		if ( focus ) {
			ref.current?.focus();
		}
	}, [ focus ] );
	return (
		<>
			{ post.url ? (
				<a
					ref={ ref }
					href={ post.url }
					className="showfm-migrate__post"
				>
					{ post.title }
				</a>
			) : (
				<span
					ref={ ref }
					tabIndex={ -1 }
					className="showfm-migrate__post"
				>
					{ post.title }
				</span>
			) }
			{ date && post.date && (
				<div className="showfm-help">{ post.date }</div>
			) }
		</>
	);
}

/**
 * The matched episode cell: the episode, a pick for an ambiguous embed, or why there is none.
 *
 * @param {Object}                               props
 * @param {Object}                               props.row      Row.
 * @param {boolean}                              props.readOnly Whether picks are locked.
 * @param {(row: Object, value: string) => void} props.onChoose Stores a pick.
 */
function EpisodeCell( { row, readOnly, onChoose } ) {
	if ( row.group === 'choose' ) {
		const options = [
			{ value: '', label: __( 'Choose an episode', 'showfm' ) },
			...row.candidates.map( ( candidate ) => ( {
				value: candidate.id,
				label: candidate.date
					? sprintf(
							/* translators: 1: episode title, 2: date published. */
							__( '%1$s (%2$s)', 'showfm' ),
							candidate.title,
							candidate.date
						)
					: candidate.title,
			} ) ),
		];
		return (
			<SelectControl
				className={ `showfm-migrate__pick${
					row.choice ? ' is-chosen' : ''
				}` }
				label={ sprintf(
					/* translators: %s: post title. */
					__( 'Episode for “%s”', 'showfm' ),
					row.post.title
				) }
				hideLabelFromVision
				value={ row.choice }
				options={ options }
				disabled={ readOnly || ! row.canChoose }
				help={ sprintf(
					/* translators: %s: number of episodes. */
					_n(
						'%s possible match',
						'%s possible matches',
						row.candidates.length,
						'showfm'
					),
					formatCount( row.candidates.length )
				) }
				onChange={ ( value ) => onChoose( row, value ) }
				size="compact"
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
		);
	}
	if ( row.group === 'ready' ) {
		return row.episode;
	}
	if ( row.group === 'review' ) {
		return <span className="showfm-muted">{ row.reason }</span>;
	}
	if ( row.group === 'already' ) {
		return (
			<span className="showfm-muted">
				{ __( 'Already a show.fm block', 'showfm' ) }
			</span>
		);
	}
	return (
		<span className="showfm-muted">
			{ __( 'No matching episode', 'showfm' ) }
		</span>
	);
}

/**
 * A status with its icon.
 *
 * @param {Object} props
 * @param {string} props.status Row status.
 */
function Status( { status } ) {
	const { label, tone } = rowStatus( status );
	return (
		<span className={ `showfm-migrate__status is-${ tone }` }>
			<Icon path={ TONE_ICONS[ tone ] } size={ 18 } />
			{ label }
		</span>
	);
}

/**
 * "Show 27 more" under a group or list.
 *
 * @param {Object}     props
 * @param {Object}     props.list   Loaded rows and total.
 * @param {string}     props.label  Label.
 * @param {() => void} props.onMore Loads more.
 */
function More( { list, label, onMore } ) {
	if ( ! list || list.items.length >= list.total ) {
		return null;
	}
	return (
		<Button
			variant="link"
			onClick={ onMore }
			disabled={ list.loading }
			accessibleWhenDisabled
		>
			{ label }
		</Button>
	);
}

/**
 * How many rows "Show more" adds.
 *
 * @param {Object} list Loaded rows and total.
 * @return {number} Rows.
 */
function remaining( list ) {
	return Math.min( MORE_ROWS, list.total - list.items.length );
}

/**
 * The dry-run report (4c): counts, the grouped table, and Swap or Scan again.
 *
 * @param {Object}                               props
 * @param {Object}                               props.view        The view.
 * @param {Object}                               props.lists       Rows by group.
 * @param {string}                               props.focusRow    Row to focus after Show more.
 * @param {boolean}                              props.readOnly    Reviewing after the swap.
 * @param {(group: string) => void}              props.onMore      Loads more rows.
 * @param {(row: Object, value: string) => void} props.onChoose    Stores a pick.
 * @param {() => void}                           props.onSwap      Opens the confirm dialog.
 * @param {() => void}                           props.onScanAgain Starts a new scan.
 * @param {() => void}                           props.onBack      Back to the results.
 * @param {Object}                               props.headingRef  Ref the tab focuses.
 */
export function Report( {
	view,
	lists,
	focusRow,
	readOnly,
	onMore,
	onChoose,
	onSwap,
	onScanAgain,
	onBack,
	headingRef,
} ) {
	const { report } = view;
	const groups = GROUPS.filter( ( group ) => report.counts[ group ] > 0 );
	return (
		<>
			<ul className="showfm-migrate__tiles">
				{ summaryTiles( report ).map( ( tile ) => (
					<li key={ tile.key }>
						<span className="showfm-migrate__count">
							{ formatCount( tile.count ) }
						</span>
						<span className="showfm-migrate__count-label">
							{ tile.label }
						</span>
					</li>
				) ) }
			</ul>
			<Card className="showfm-card">
				<CardHeader className="showfm-card__header is-baseline showfm-migrate__head">
					<Heading headingRef={ headingRef }>
						{ reportTitle( report ) }
					</Heading>
					<span className="showfm-help">
						{ readOnly
							? sprintf(
									/* translators: %s: how long ago, for example "2 minutes ago". */
									__( 'Scanned %s', 'showfm' ),
									report.ago
								)
							: sprintf(
									/* translators: %s: how long ago, for example "2 minutes ago". */
									__(
										'Scanned %s · nothing has changed yet',
										'showfm'
									),
									report.ago
								) }
					</span>
				</CardHeader>
				<div className="showfm-migrate__table-wrap">
					<table className="showfm-migrate__table">
						<thead>
							<tr>
								<th scope="col">{ __( 'Post', 'showfm' ) }</th>
								<th scope="col">
									{ __( 'Current embed', 'showfm' ) }
								</th>
								<th scope="col" className="is-episode">
									{ __( 'Matched episode', 'showfm' ) }
								</th>
								<th scope="col">
									{ __( 'How it matched', 'showfm' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'showfm' ) }
								</th>
							</tr>
						</thead>
						{ groups.map( ( group ) => {
							const heading = groupHeading( group );
							const list = lists[ group ];
							return (
								<tbody key={ group }>
									<tr className="showfm-migrate__group">
										<th colSpan={ 5 } scope="rowgroup">
											<strong>{ heading.title }</strong>{ ' ' }
											<span className="showfm-muted">
												{ `· ${ formatCount(
													report.counts[ group ]
												) }` }
											</span>
											<div className="showfm-migrate__group-help">
												{ heading.help }
											</div>
										</th>
									</tr>
									{ ! list && (
										<tr>
											<td colSpan={ 5 }>
												<Spinner />
											</td>
										</tr>
									) }
									{ list?.items.map( ( row ) => (
										<tr key={ row.id }>
											<td
												data-label={ __(
													'Post',
													'showfm'
												) }
											>
												<PostCell
													post={ row.post }
													focus={
														focusRow === row.id
													}
												/>
											</td>
											<td
												data-label={ __(
													'Current embed',
													'showfm'
												) }
											>
												{ row.host }
												{ row.ref && (
													<div className="showfm-migrate__ref">
														{ row.ref }
													</div>
												) }
											</td>
											<td
												data-label={ __(
													'Matched episode',
													'showfm'
												) }
												className="is-episode"
											>
												<EpisodeCell
													row={ row }
													readOnly={ readOnly }
													onChoose={ onChoose }
												/>
											</td>
											<td
												data-label={ __(
													'How it matched',
													'showfm'
												) }
												className="showfm-migrate__how"
											>
												{ row.method }
											</td>
											<td
												data-label={ __(
													'Status',
													'showfm'
												) }
											>
												<Status status={ row.status } />
											</td>
										</tr>
									) ) }
									{ list &&
										list.items.length < list.total && (
											<tr className="showfm-migrate__more">
												<td colSpan={ 5 }>
													<More
														list={ list }
														label={ sprintf(
															/* translators: %s: number of rows. */
															__(
																'Show %s more',
																'showfm'
															),
															formatCount(
																remaining(
																	list
																)
															)
														) }
														onMore={ () =>
															onMore( group )
														}
													/>
												</td>
											</tr>
										) }
								</tbody>
							);
						} ) }
					</table>
				</div>
				<CardFooter
					justify="flex-start"
					className="showfm-card__footer showfm-migrate__actions"
				>
					{ readOnly ? (
						<Button
							variant="secondary"
							onClick={ onBack }
							__next40pxDefaultSize
						>
							{ __( 'Back to the results', 'showfm' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							onClick={ onSwap }
							disabled={ report.swap.embeds < 1 }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ swapLabel( report.swap.embeds ) }
						</Button>
					) }
					<Button
						variant="secondary"
						onClick={ onScanAgain }
						__next40pxDefaultSize
					>
						{ __( 'Scan again', 'showfm' ) }
					</Button>
					{ ! readOnly && (
						<span className="showfm-help">
							{ skipNote( report ) }
						</span>
					) }
				</CardFooter>
			</Card>
		</>
	);
}

/**
 * Confirm the swap (4d): the count, and that revisions are the undo.
 *
 * @param {Object}     props
 * @param {Object}     props.report    The view's report.
 * @param {() => void} props.onCancel  Closes.
 * @param {() => void} props.onConfirm Starts the swap.
 */
export function ConfirmSwap( { report, onCancel, onConfirm } ) {
	return (
		<Modal
			className="showfm-modal"
			title={ `${ swapLabel( report.swap.embeds ) }?` }
			onRequestClose={ onCancel }
			size="medium"
		>
			<div className="showfm-modal__body">
				<p>{ confirmText( report.swap.posts ) }</p>
				<p>
					{ __(
						'WordPress keeps a revision of every post we change, so you can restore any of them.',
						'showfm'
					) }
				</p>
			</div>
			<div className="showfm-modal__actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'showfm' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ onConfirm }
					__next40pxDefaultSize
				>
					{ swapLabel( report.swap.embeds ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Results (4e): what changed with links to each post and revision, and what couldn't change.
 *
 * @param {Object}                  props
 * @param {Object}                  props.view       The view.
 * @param {Object}                  props.lists      Rows for `changed` and `failed`.
 * @param {string}                  props.focusRow   Row to focus after Show more.
 * @param {(group: string) => void} props.onMore     Loads more rows.
 * @param {() => void}              props.onReview   Shows the report.
 * @param {Object}                  props.headingRef Ref the tab focuses.
 */
export function Results( {
	view,
	lists,
	focusRow,
	onMore,
	onReview,
	headingRef,
} ) {
	const { swap } = view;
	const changed = lists.changed;
	const failed = lists.failed;
	const left = notChangedText( view.report );
	return (
		<>
			<Notice
				className="showfm-notice"
				status="success"
				isDismissible={ false }
			>
				<p>
					<strong>{ swappedText( swap ) }</strong>{ ' ' }
					{ __(
						'WordPress keeps a revision of every post we change.',
						'showfm'
					) }
				</p>
			</Notice>
			{ swap.failed > 0 && (
				<Notice
					className="showfm-notice"
					status="error"
					isDismissible={ false }
				>
					<p>
						{ sprintf(
							/* translators: %s: number of posts. */
							_n(
								'%s post couldn’t be changed. It’s listed below with the reason.',
								'%s posts couldn’t be changed. They’re listed below with the reasons.',
								swap.failed,
								'showfm'
							),
							formatCount( swap.failed )
						) }
					</p>
				</Notice>
			) }
			<Card className="showfm-card">
				<CardHeader className="showfm-card__header is-baseline showfm-migrate__head">
					<Heading headingRef={ headingRef }>
						{ __( 'What changed', 'showfm' ) }
					</Heading>
					<span className="showfm-help">{ swap.finished }</span>
				</CardHeader>
				{ swap.posts > 0 ? (
					<div className="showfm-migrate__table-wrap">
						<table className="showfm-migrate__table is-results">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Post', 'showfm' ) }
									</th>
									<th scope="col">
										{ __( 'Change', 'showfm' ) }
									</th>
									<th scope="col" className="is-revision">
										{ __( 'Revision', 'showfm' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ ! changed && (
									<tr>
										<td colSpan={ 3 }>
											<Spinner />
										</td>
									</tr>
								) }
								{ changed?.items.map( ( row ) => (
									<tr key={ row.id }>
										<td
											data-label={ __(
												'Post',
												'showfm'
											) }
										>
											<PostCell
												post={ row.post }
												focus={ focusRow === row.id }
												date={ false }
											/>
										</td>
										<td
											data-label={ __(
												'Change',
												'showfm'
											) }
										>
											{ row.change }
										</td>
										<td
											data-label={ __(
												'Revision',
												'showfm'
											) }
											className="is-revision"
										>
											{ row.revisionUrl && (
												<a href={ row.revisionUrl }>
													{ __(
														'Compare revisions',
														'showfm'
													) }
												</a>
											) }
										</td>
									</tr>
								) ) }
								{ changed &&
									changed.items.length < changed.total && (
										<tr className="showfm-migrate__more">
											<td colSpan={ 3 }>
												<More
													list={ changed }
													label={
														changed.total <=
														MORE_ROWS
															? sprintf(
																	/* translators: %s: number of posts. */
																	__(
																		'Show all %s posts',
																		'showfm'
																	),
																	formatCount(
																		changed.total
																	)
																)
															: sprintf(
																	/* translators: %s: number of rows. */
																	__(
																		'Show %s more',
																		'showfm'
																	),
																	formatCount(
																		remaining(
																			changed
																		)
																	)
																)
													}
													onMore={ () =>
														onMore( 'changed' )
													}
												/>
											</td>
										</tr>
									) }
							</tbody>
						</table>
					</div>
				) : (
					<CardBody>
						<p className="showfm-muted">
							{ __( 'No posts were changed.', 'showfm' ) }
						</p>
					</CardBody>
				) }
				{ left && (
					<CardFooter className="showfm-migrate__left">
						<span>
							{ left }{ ' ' }
							<Button variant="link" onClick={ onReview }>
								{ __( 'Review them', 'showfm' ) }
							</Button>
						</span>
					</CardFooter>
				) }
			</Card>
			{ swap.failed > 0 && (
				<Card className="showfm-card">
					<CardHeader>
						<h2>{ __( 'Not changed', 'showfm' ) }</h2>
					</CardHeader>
					<div className="showfm-migrate__table-wrap">
						<table className="showfm-migrate__table is-results">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Post', 'showfm' ) }
									</th>
									<th scope="col">
										{ __(
											'Why it wasn’t changed',
											'showfm'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ failed?.items.map( ( row ) => (
									<tr key={ row.id }>
										<td
											data-label={ __(
												'Post',
												'showfm'
											) }
										>
											<PostCell
												post={ row.post }
												focus={ focusRow === row.id }
												date={ false }
											/>
										</td>
										<td
											data-label={ __(
												'Why it wasn’t changed',
												'showfm'
											) }
										>
											{ row.reason }
										</td>
									</tr>
								) ) }
								{ failed &&
									failed.items.length < failed.total && (
										<tr className="showfm-migrate__more">
											<td colSpan={ 2 }>
												<More
													list={ failed }
													label={ sprintf(
														/* translators: %s: number of rows. */
														__(
															'Show %s more',
															'showfm'
														),
														formatCount(
															remaining( failed )
														)
													) }
													onMore={ () =>
														onMore( 'failed' )
													}
												/>
											</td>
										</tr>
									) }
							</tbody>
						</table>
					</div>
				</Card>
			) }
		</>
	);
}

/**
 * Nothing to migrate (4f).
 *
 * @param {Object}     props
 * @param {Object}     props.view        The view.
 * @param {() => void} props.onScanAgain Starts a new scan.
 * @param {Object}     props.headingRef  Ref the tab focuses.
 */
export function Empty( { view, onScanAgain, headingRef } ) {
	return (
		<Card className="showfm-card showfm-migrate__narrow">
			<CardBody className="showfm-empty showfm-migrate__empty">
				<Icon path={ ICONS.yesAlt } size={ 32 } />
				<h2
					ref={ headingRef }
					tabIndex={ -1 }
					className="showfm-empty__title"
				>
					{ __( 'Nothing to migrate.', 'showfm' ) }
				</h2>
				<p className="showfm-empty__text">
					{ sprintf(
						/* translators: %s: number of posts and pages. */
						_n(
							'We checked %s published post or page and didn’t find embeds from other podcast hosts.',
							'We checked %s published posts and pages and didn’t find embeds from other podcast hosts.',
							view.posts,
							'showfm'
						),
						formatCount( view.posts )
					) }
				</p>
				<Button
					variant="secondary"
					onClick={ onScanAgain }
					__next40pxDefaultSize
				>
					{ __( 'Scan again', 'showfm' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * The notice for a problem, with the action that fixes it.
 *
 * @param {Object}                   props
 * @param {Object}                   props.problem   Problem.
 * @param {Object}                   props.noticeRef Ref the tab focuses.
 * @param {(action: string) => void} props.onAction  Runs the action.
 */
export function ProblemNotice( { problem, noticeRef, onAction } ) {
	const action = problemAction( problem.reason );
	const labels = {
		reconnect: __( 'Reconnect to show.fm', 'showfm' ),
		retry: __( 'Try again', 'showfm' ),
		refresh: __( 'Check again', 'showfm' ),
		restart: __( 'Scan again', 'showfm' ),
	};
	const status = [ 'stale', 'busy', 'rate_limited' ].includes(
		problem.reason
	)
		? 'warning'
		: 'error';
	return (
		<div
			ref={ noticeRef }
			tabIndex={ -1 }
			className="showfm-migrate__problem"
		>
			<Notice
				className="showfm-notice"
				status={ status }
				isDismissible={ false }
				actions={
					action
						? [
								{
									label: labels[ action ],
									onClick: () => onAction( action ),
									variant: 'secondary',
								},
							]
						: []
				}
			>
				<p>{ problem.message }</p>
			</Notice>
		</div>
	);
}
