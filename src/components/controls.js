/**
 * Inspector controls shared by the blocks.
 */
import { useSettings } from '@wordpress/block-editor';
import * as components from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const { BaseControl, ColorPalette } = components;
// Stable from WordPress 6.9; earlier versions (the plugin supports 6.6) only have the
// experimental name for the same component.
const ToggleGroupControl =
	components.ToggleGroupControl ||
	components.__experimentalToggleGroupControl;
const ToggleGroupControlOption =
	components.ToggleGroupControlOption ||
	components.__experimentalToggleGroupControlOption;

const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

/**
 * A segmented control, as in the design.
 *
 * @param {Object}                  props
 * @param {string}                  props.label    Label.
 * @param {string}                  props.value    Current value.
 * @param {Object[]}                props.options  `{ value, label }` pairs.
 * @param {(value: string) => void} props.onChange Setter.
 * @param {string}                  props.help     Help text.
 */
export function Segmented( { label, value, options, onChange, help } ) {
	return (
		<ToggleGroupControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			isBlock
			label={ label }
			value={ value }
			onChange={ onChange }
			help={ help }
		>
			{ options.map( ( option ) => (
				<ToggleGroupControlOption
					key={ option.value }
					value={ option.value }
					label={ option.label }
				/>
			) ) }
		</ToggleGroupControl>
	);
}

/**
 * Hex colours from the palettes, the theme's first. The elements take hex only.
 *
 * @param {...Array} palettes Palettes.
 * @return {Object[]} Colours.
 */
export function hexColours( ...palettes ) {
	const seen = new Set();
	return palettes
		.flat()
		.filter(
			( entry ) =>
				entry &&
				HEX.test( entry.color ) &&
				! seen.has( entry.color.toLowerCase() ) &&
				seen.add( entry.color.toLowerCase() )
		);
}

/**
 * The accent colour: swatches from the theme's palette, or the default one.
 *
 * @param {Object}                  props
 * @param {string}                  props.value    Hex colour.
 * @param {(value: string) => void} props.onChange Setter.
 */
export function AccentControl( { value, onChange } ) {
	const [ theme = [], defaults = [] ] = useSettings(
		'color.palette.theme',
		'color.palette.default'
	);
	return (
		<BaseControl
			__nextHasNoMarginBottom
			id="showfm-accent"
			label={ __( 'Accent colour', 'showfm' ) }
			help={ __(
				'The show’s colour is used until you choose one. These are your theme’s colours.',
				'showfm'
			) }
		>
			<ColorPalette
				colors={ hexColours( theme.length ? theme : defaults ) }
				value={ value }
				disableCustomColors
				clearable
				onChange={ ( colour ) =>
					onChange( HEX.test( colour || '' ) ? colour : undefined )
				}
			/>
		</BaseControl>
	);
}
