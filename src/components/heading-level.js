/**
 * The heading level control, as core Heading has it. Block-library does not export its
 * dropdown, so this rebuilds it with ToolbarDropdownMenu (plan 5.3.2).
 */
import { ToolbarDropdownMenu } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	headingLevel2,
	headingLevel3,
	headingLevel4,
	headingLevel5,
	headingLevel6,
} from '@wordpress/icons';

/** The elements accept `heading-level` 2 to 6. */
export const LEVELS = {
	2: headingLevel2,
	3: headingLevel3,
	4: headingLevel4,
	5: headingLevel5,
	6: headingLevel6,
};

/**
 * @param {Object}                  props
 * @param {string}                  props.value    Current level, '2' to '6'.
 * @param {(value: string) => void} props.onChange Called with the new level as a string.
 */
export default function HeadingLevelDropdown( { value, onChange } ) {
	// The same default as defaultLevel() with no heading above.
	const current = LEVELS[ value ] ? value : '2';
	return (
		<ToolbarDropdownMenu
			popoverProps={ { className: 'showfm-heading-levels' } }
			icon={ LEVELS[ current ] }
			label={ __( 'Change level', 'showfm' ) }
			controls={ Object.keys( LEVELS ).map( ( level ) => ( {
				icon: LEVELS[ level ],
				/* translators: %s: heading level, such as 2. */
				title: sprintf( __( 'Heading %s', 'showfm' ), level ),
				isActive: level === current,
				role: 'menuitemradio',
				onClick: () => onChange( level ),
			} ) ) }
		/>
	);
}

/**
 * The default level: one below the nearest heading above the block, else 2.
 *
 * @param {Object[]} blocks   Top-level blocks, in order.
 * @param {string}   clientId This block.
 * @return {string} Level.
 */
export function defaultLevel( blocks, clientId ) {
	const index = blocks.findIndex( ( block ) => block.clientId === clientId );
	for ( let i = index - 1; i >= 0; i-- ) {
		if ( blocks[ i ].name === 'core/heading' ) {
			const level = Number( blocks[ i ].attributes.level ) || 2;
			return String( Math.min( 6, Math.max( 2, level + 1 ) ) );
		}
	}
	return '2';
}
