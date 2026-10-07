/**
 * Settings > show.fm > Display.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** The settings, in the order the tab shows them. */
export const DISPLAY_SETTINGS = [
	'showfm_show_credit',
	'showfm_load_on_click',
	'showfm_json_ld',
	'showfm_theme_styles',
];

/** Core settings route, limited to these settings. Admin preloads the same path. */
export const DISPLAY_PATH = `/wp/v2/settings?_fields=${ DISPLAY_SETTINGS.join(
	','
) }`;

/**
 * Labels and help, keyed by setting.
 *
 * @return {Object} Copy for each setting.
 */
function copy() {
	return {
		showfm_show_credit: {
			label: __( 'Show “Powered by show.fm”', 'showfm' ),
			help: __(
				'Adds a small credit under the first player on each page.',
				'showfm'
			),
		},
		showfm_load_on_click: {
			label: __( 'Load players only after a visitor clicks', 'showfm' ),
			help: __(
				'Nothing loads from show.fm until someone presses play. Turn this on if your cookie consent tool needs it.',
				'showfm'
			),
		},
		showfm_json_ld: {
			label: __(
				'Add episode structured data for search engines',
				'showfm'
			),
			help: __(
				'Adds episode details to pages with a player, so search engines can show them.',
				'showfm'
			),
		},
		showfm_theme_styles: {
			label: __( 'Use my theme’s colours and fonts', 'showfm' ),
			help: __(
				'Players use your theme’s font and accent colour. Turn this off to use show.fm’s defaults.',
				'showfm'
			),
		},
	};
}

/**
 * The Display tab: four site-wide switches and Save changes.
 */
export default function DisplayTab() {
	const [ values, setValues ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );

	useEffect( () => {
		apiFetch( { path: DISPLAY_PATH } )
			.then( setValues )
			.catch( () =>
				setMessage( {
					status: 'error',
					text: __(
						'The display settings couldn’t be loaded. Reload the page to try again.',
						'showfm'
					),
				} )
			);
	}, [] );

	const save = async () => {
		setSaving( true );
		setMessage( null );
		try {
			const data = {};
			DISPLAY_SETTINGS.forEach( ( name ) => {
				data[ name ] = !! values[ name ];
			} );
			const saved = await apiFetch( {
				path: DISPLAY_PATH,
				method: 'POST',
				data,
			} );
			setValues( saved );
			setMessage( {
				status: 'success',
				text: __( 'Settings saved.', 'showfm' ),
			} );
		} catch {
			setMessage( {
				status: 'error',
				text: __(
					'The settings couldn’t be saved. Try again.',
					'showfm'
				),
			} );
		}
		setSaving( false );
	};

	const labels = copy();
	return (
		<div className="showfm-tab">
			{ message && (
				<Notice
					className="showfm-notice"
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					<p>{ message.text }</p>
				</Notice>
			) }
			<Card className="showfm-card">
				<CardHeader className="showfm-card__header is-stacked">
					<h2>{ __( 'Players on this site', 'showfm' ) }</h2>
					<p className="showfm-help">
						{ __(
							'Applies to every show.fm block. Colours set on a block still win.',
							'showfm'
						) }
					</p>
				</CardHeader>
				<CardBody className="showfm-toggles">
					{ null === values ? (
						<Spinner />
					) : (
						DISPLAY_SETTINGS.map( ( name ) => (
							<ToggleControl
								key={ name }
								label={ labels[ name ].label }
								help={ labels[ name ].help }
								checked={ !! values[ name ] }
								onChange={ ( checked ) =>
									setValues( {
										...values,
										[ name ]: checked,
									} )
								}
								__nextHasNoMarginBottom
							/>
						) )
					) }
				</CardBody>
				<CardFooter
					justify="flex-start"
					className="showfm-card__footer"
				>
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving || null === values }
						__next40pxDefaultSize
					>
						{ __( 'Save changes', 'showfm' ) }
					</Button>
				</CardFooter>
			</Card>
		</div>
	);
}
