/**
 * Settings > show.fm > Migrate. Only shown while a connection is stored.
 *
 * Scans published posts and pages for players from other podcast hosts, shows a dry-run
 * report, lets the admin pick an episode where more than one could match, then swaps the
 * matched embeds for show.fm Player blocks. Each scan and swap step is one short request;
 * the tab keeps stepping while the page is open, and a reload carries on from the last step.
 */
import { speak } from '@wordpress/a11y';
import apiFetch from '@wordpress/api-fetch';
import { Notice, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import {
	ConfirmResume,
	ConfirmSwap,
	ConnectForm,
	Empty,
	Intro,
	ProblemNotice,
	Report,
	Results,
	Scanning,
	Swapping,
} from './migrate-screens';
import {
	FIRST_ROWS,
	GROUPS,
	MIGRATE_PATH,
	MORE_ROWS,
	percent,
	problemFrom,
	progressText,
	resumeStep,
} from './migrate-view';

/** How often the tab checks on a scan or swap another admin is running, in ms. */
export const POLL_MS = 5000;

/**
 * The Migrate tab.
 *
 * @param {Object} props
 * @param {Object} props.connection Connection data, for the connect form.
 */
export default function MigrateTab( { connection } ) {
	const [ view, setView ] = useState( null );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const [ stepping, setStepping ] = useState( false );
	const [ stopping, setStopping ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const [ reviewing, setReviewing ] = useState( false );
	const [ lists, setLists ] = useState( {} );
	const [ focusRow, setFocusRow ] = useState( '' );
	const [ resuming, setResuming ] = useState( false );
	const [ saving, setSaving ] = useState( 0 );
	const [ refocus, setRefocus ] = useState( 0 );
	const headingRef = useRef();
	const picks = useRef( {} );
	const latest = useRef( null );
	const announced = useRef( '' );
	const noticeRef = useRef();
	const formRef = useRef();
	const mounted = useRef( true );
	const running = useRef( false );
	const inflight = useRef( null );
	const shown = useRef( '' );

	useEffect( () => {
		mounted.current = true;
		return () => {
			mounted.current = false;
			running.current = false;
		};
	}, [] );

	const take = useCallback( ( next ) => {
		setView( next );
		setProblem( next.problem ?? null );
	}, [] );

	const load = useCallback(
		() =>
			apiFetch( { path: MIGRATE_PATH } ).then( ( next ) => {
				if ( mounted.current ) {
					take( next );
				}
				return next;
			} ),
		[ take ]
	);

	// Steps until the scan or swap is done, the admin stops it, or a step fails.
	const go = useCallback( async ( kind, first ) => {
		if ( running.current ) {
			return;
		}
		running.current = true;
		setStepping( true );
		setProblem( null );
		let data = first;
		try {
			while ( running.current && mounted.current ) {
				inflight.current = apiFetch( {
					path: `${ MIGRATE_PATH }/${ kind }`,
					method: 'POST',
					data,
				} );
				const next = await inflight.current;
				if ( ! mounted.current ) {
					return;
				}
				setView( next );
				if ( resumeStep( next ) !== kind ) {
					setProblem( next.problem ?? null );
					break;
				}
				data = { run: next.run };
			}
		} catch ( error ) {
			if ( mounted.current ) {
				if ( error?.data?.view ) {
					setView( error.data.view );
				}
				setProblem( problemFrom( error ) );
			}
		} finally {
			inflight.current = null;
			running.current = false;
			if ( mounted.current ) {
				setStepping( false );
			}
		}
	}, [] );

	useEffect( () => {
		load()
			.then( ( next ) => {
				const kind = resumeStep( next );
				if ( kind ) {
					go( kind, { run: next.run } );
				}
			} )
			.catch( () => setLoadFailed( true ) );
	}, [ load, go ] );

	// Another admin's scan or swap: follow its progress.
	const watching =
		!! view?.lease &&
		! view.lease.mine &&
		[ 'scanning', 'swapping' ].includes( view.phase );
	useEffect( () => {
		if ( ! watching ) {
			return;
		}
		const timer = setInterval( () => {
			load()
				.then( ( next ) => {
					// A scan the other admin left carries on here; their swap waits
					// for someone to choose Resume swapping.
					if ( resumeStep( next ) === 'scan' && ! next.lease ) {
						go( 'scan', { run: next.run } );
					}
				} )
				.catch( () => {} );
		}, POLL_MS );
		return () => clearInterval( timer );
	}, [ watching, load, go ] );

	const screen = ( () => {
		if ( ! view ) {
			return '';
		}
		if ( ! view.connected ) {
			return 'disconnected';
		}
		if ( view.phase === 'results' && reviewing ) {
			return 'review';
		}
		return view.phase;
	} )();

	// Each new step's heading takes focus, except on the first render.
	useEffect( () => {
		if ( ! screen ) {
			return;
		}
		if ( shown.current && shown.current !== screen ) {
			headingRef.current?.focus();
		}
		shown.current = screen;
	}, [ screen ] );

	// A new problem takes focus, so it is read out.
	useEffect( () => {
		if ( problem ) {
			noticeRef.current?.focus();
		}
	}, [ problem ] );

	// When a button the admin used goes away (Stop, Resume), focus returns to the heading.
	useEffect( () => {
		if ( refocus ) {
			headingRef.current?.focus();
		}
	}, [ refocus ] );

	latest.current = view;

	// Progress is read out politely each quarter of the way, not on every step.
	const quarter = ( () => {
		if ( ! stepping || ! view ) {
			return '';
		}
		if ( view.phase === 'scanning' && ! view.scan?.catalogue ) {
			return `scan:${ Math.floor(
				percent( view.scan.checked, view.scan.total ) / 25
			) }`;
		}
		if ( view.phase === 'swapping' ) {
			return `swap:${ Math.floor(
				percent( view.swap.checked, view.swap.total ) / 25
			) }`;
		}
		return '';
	} )();
	useEffect( () => {
		if ( ! quarter || quarter === announced.current ) {
			return;
		}
		const first = ! announced.current.startsWith(
			quarter.split( ':' )[ 0 ]
		);
		announced.current = quarter;
		if ( ! first ) {
			speak( progressText( latest.current ), 'polite' );
		}
	}, [ quarter ] );

	// A rate limit lifts at its retry time: the notice goes and the scan carries on.
	useEffect( () => {
		if ( problem?.reason !== 'rate_limited' || ! problem.retryAt ) {
			return;
		}
		const timer = setTimeout(
			() => {
				setProblem( null );
				const now = latest.current;
				if ( resumeStep( { ...now, problem: null } ) === 'scan' ) {
					go( 'scan', { run: now.run } );
				}
			},
			Math.max( 0, problem.retryAt * 1000 - Date.now() ) + 1000
		);
		return () => clearTimeout( timer );
	}, [ problem, go ] );

	// Rows for the report or the results, from the first page of each group.
	const run = view?.run;
	const listing = [ 'report', 'review', 'results' ].includes( screen );
	const loadRows = useCallback(
		async ( group, offset, limit ) => {
			setLists( ( current ) => ( {
				...current,
				[ group ]: current[ group ]
					? { ...current[ group ], loading: true }
					: current[ group ],
			} ) );
			try {
				const page = await apiFetch( {
					path: addQueryArgs( `${ MIGRATE_PATH }/rows`, {
						run,
						group,
						offset,
						limit,
					} ),
				} );
				if ( ! mounted.current ) {
					return;
				}
				setLists( ( current ) => ( {
					...current,
					[ group ]: {
						items: [
							...( offset
								? ( current[ group ]?.items ?? [] )
								: [] ),
							...page.rows,
						],
						total: page.total,
						loading: false,
					},
				} ) );
				if ( offset && page.rows[ 0 ] ) {
					setFocusRow( page.rows[ 0 ].id );
				}
			} catch ( error ) {
				if ( mounted.current ) {
					if ( error?.data?.view ) {
						take( error.data.view );
					}
					setProblem( problemFrom( error ) );
				}
			}
		},
		[ run, take ]
	);

	// Rows change when the run does, and when the swap finishes.
	const listKey = `${ run }:${ view?.phase === 'results' ? 'done' : 'open' }`;
	useEffect( () => {
		setLists( {} );
		setFocusRow( '' );
	}, [ listKey ] );

	useEffect( () => {
		if ( ! listing || ! view?.report ) {
			return;
		}
		const groups =
			screen === 'results'
				? [ 'changed', ...( view.swap.failed > 0 ? [ 'failed' ] : [] ) ]
				: GROUPS.filter( ( group ) => view.report.counts[ group ] > 0 );
		groups.forEach( ( group ) => {
			if ( ! lists[ group ] ) {
				loadRows( group, 0, FIRST_ROWS[ group ] );
			}
		} );
		// Load each group once per list key; later views only change counts.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ listing, screen, listKey ] );

	const more = ( group ) => {
		const list = lists[ group ];
		loadRows( group, list.items.length, MORE_ROWS );
	};

	// Shows a pick in its row.
	const showPick = ( id, choice ) =>
		setLists( ( current ) => ( {
			...current,
			choose: {
				...current.choose,
				items: current.choose.items.map( ( item ) =>
					item.id === id
						? {
								...item,
								choice,
								status: choice ? 'chosen' : 'choose',
							}
						: item
				),
			},
		} ) );

	// Picks are saved one request at a time per row. A change made while one is saving is
	// sent after it, and the row ends on the pick the server stored last. Swap waits.
	const choose = async ( row, value ) => {
		showPick( row.id, value );
		const pending = picks.current[ row.id ];
		if ( pending ) {
			pending.wanted = value;
			return;
		}
		const entry = { wanted: value, stored: row.choice };
		picks.current[ row.id ] = entry;
		setSaving( ( count ) => count + 1 );
		try {
			let sent;
			do {
				sent = entry.wanted;
				const next = await apiFetch( {
					path: `${ MIGRATE_PATH }/choice`,
					method: 'POST',
					data: {
						run: view.run,
						post: row.post.id,
						embed: row.embed,
						episode: sent,
					},
				} );
				entry.stored = next.choice;
				if ( mounted.current ) {
					take( next );
				}
			} while ( entry.wanted !== sent );
		} catch ( error ) {
			if ( mounted.current ) {
				if ( error?.data?.view ) {
					setView( error.data.view );
				}
				setProblem( problemFrom( error ) );
			}
		} finally {
			delete picks.current[ row.id ];
			if ( mounted.current ) {
				showPick( row.id, entry.stored );
				setSaving( ( count ) => count - 1 );
			}
		}
	};

	const stop = async () => {
		running.current = false;
		setStopping( true );
		// Wait for the step in flight; its own handler reports a failure.
		await inflight.current?.catch( () => {} );
		try {
			const next = await apiFetch( {
				path: `${ MIGRATE_PATH }/stop`,
				method: 'POST',
			} );
			if ( mounted.current ) {
				take( next );
			}
		} catch ( error ) {
			if ( mounted.current ) {
				setProblem( problemFrom( error ) );
			}
		}
		if ( mounted.current ) {
			setStopping( false );
			setRefocus( ( count ) => count + 1 );
		}
	};

	const scanAgain = () => {
		setReviewing( false );
		go( 'scan', { restart: true } );
	};

	// Resuming keeps focus on the heading, since the button goes. A swap carries on straight
	// away only for the admin who confirmed it; anyone else confirms first.
	const resume = () => {
		if ( view.phase === 'swapping' && ! view.swap?.mine ) {
			setResuming( true );
			return;
		}
		setRefocus( ( count ) => count + 1 );
		go( view.phase === 'swapping' ? 'swap' : 'scan', { run: view.run } );
	};

	const act = ( action ) => {
		if ( action === 'reconnect' ) {
			formRef.current?.requestSubmit();
		} else if ( action === 'restart' ) {
			scanAgain();
		} else if ( action === 'refresh' ) {
			setProblem( null );
			load().catch( () => setLoadFailed( true ) );
		} else if ( view.phase === 'swapping' || view.phase === 'scanning' ) {
			resume();
		} else {
			setProblem( null );
			load().catch( () => setLoadFailed( true ) );
		}
	};

	if ( loadFailed ) {
		return (
			<Notice status="error" isDismissible={ false }>
				<p>
					{ __(
						'The migration couldn’t be loaded. Reload the page to try again.',
						'showfm'
					) }
				</p>
			</Notice>
		);
	}
	if ( ! view ) {
		return <Spinner />;
	}

	const canResume = view.connected && ( ! view.lease || view.lease.mine );
	let content;
	switch ( screen ) {
		case 'disconnected':
		case 'intro':
			content = (
				<Intro
					view={ view }
					connection={ connection }
					busy={ stepping }
					onScan={ () => go( 'scan', {} ) }
					onConnect={ () => formRef.current?.requestSubmit() }
					headingRef={ headingRef }
				/>
			);
			break;
		case 'scanning':
			content = (
				<Scanning
					scan={ view.scan }
					stepping={ stepping }
					stopping={ stopping }
					canResume={ canResume }
					onStop={ stop }
					onResume={ resume }
					headingRef={ headingRef }
				/>
			);
			break;
		case 'swapping':
			content = (
				<Swapping
					swap={ view.swap }
					stepping={ stepping }
					canResume={ canResume }
					onResume={ resume }
					headingRef={ headingRef }
				/>
			);
			break;
		case 'empty':
			content = (
				<Empty
					view={ view }
					onScanAgain={ scanAgain }
					headingRef={ headingRef }
				/>
			);
			break;
		case 'results':
			content = (
				<Results
					view={ view }
					lists={ lists }
					focusRow={ focusRow }
					onMore={ more }
					onReview={ () => setReviewing( true ) }
					headingRef={ headingRef }
				/>
			);
			break;
		default:
			content = (
				<Report
					view={ view }
					lists={ lists }
					focusRow={ focusRow }
					readOnly={ screen === 'review' }
					onMore={ more }
					onChoose={ choose }
					onSwap={ () => setConfirming( true ) }
					saving={ saving > 0 }
					onScanAgain={ scanAgain }
					onBack={ () => setReviewing( false ) }
					headingRef={ headingRef }
				/>
			);
	}

	const showProblem =
		problem &&
		! ( screen === 'disconnected' && problem.reason === 'not_connected' );
	return (
		<div className="showfm-tab showfm-migrate">
			{ view.lease && ! view.lease.mine && (
				<Notice
					className="showfm-notice"
					status="warning"
					isDismissible={ false }
				>
					<p>
						{ sprintf(
							/* translators: %s: the name of the admin running the scan or swap. */
							__(
								'%s is running a scan or swap. This page follows their progress.',
								'showfm'
							),
							view.lease.name
						) }
					</p>
				</Notice>
			) }
			{ showProblem && (
				<ProblemNotice
					problem={ problem }
					noticeRef={ noticeRef }
					onAction={ act }
				/>
			) }
			{ content }
			<ConnectForm connection={ connection } formRef={ formRef } />
			{ confirming && view.report && (
				<ConfirmSwap
					report={ view.report }
					onCancel={ () => setConfirming( false ) }
					onConfirm={ () => {
						setConfirming( false );
						go( 'swap', { run: view.run, confirm: true } );
					} }
				/>
			) }
			{ resuming && view.swap && (
				<ConfirmResume
					swap={ view.swap }
					onCancel={ () => setResuming( false ) }
					onConfirm={ () => {
						setResuming( false );
						setRefocus( ( count ) => count + 1 );
						go( 'swap', { run: view.run, confirm: true } );
					} }
				/>
			) }
		</div>
	);
}
