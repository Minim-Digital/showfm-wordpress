/**
 * The real show.fm element inside the editor iframe. `v1.js` is enqueued there by
 * `Assets::editor_assets`, so this is the element visitors get.
 */
import { createElement } from '@wordpress/element';
import { getSettings } from '../settings';

/** Element attributes each block passes through, as in `Attributes::names()`. */
export const ELEMENT_ATTRIBUTES = {
	player: [
		'id',
		'episode',
		'podcast',
		'theme',
		'accent',
		'size',
		'wave',
		'heading-level',
		'transcript',
	],
	episodes: [
		'id',
		'podcast',
		'theme',
		'accent',
		'variant',
		'layout',
		'count',
		'season',
		'hide',
		'descriptions',
		'mini-player',
		'heading-level',
	],
	play: [
		'episode',
		'podcast',
		'theme',
		'accent',
		'variant',
		'size',
		'mini-player',
	],
	transcript: [ 'episode', 'for', 'height', 'theme', 'accent' ],
};

/** Height each element reserves before it upgrades, so the editor does not jump. */
const RESERVED = {
	player: ( attributes ) =>
		attributes.size === 'compact' ? '83px' : '252px',
	episodes: () => '240px',
	play: () => '40px',
	transcript: ( attributes ) =>
		`${ ( Number( attributes.height ) || 320 ) + 57 }px`,
};

/**
 * The attributes the element gets, as strings.
 *
 * @param {string} type       Element type.
 * @param {Object} attributes Block attributes.
 * @return {Object} Element attributes.
 */
export function elementAttributes( type, attributes ) {
	const settings = getSettings();
	const out = {};
	for ( const name of ELEMENT_ATTRIBUTES[ type ] ) {
		const value = attributes[ name ];
		if ( value !== undefined && value !== null && value !== '' ) {
			out[ name ] = String( value );
		}
	}
	out.api = settings.api;
	out.credit = 'off';
	// The WordPress plugin: with credit="off", no show's preview shows "Powered by".
	out.platform = 'wordpress';
	return out;
}

/**
 * @param {Object} props
 * @param {string} props.type       Element type.
 * @param {Object} props.attributes Block attributes.
 */
export default function ElementPreview( { type, attributes } ) {
	return createElement( `showfm-${ type }`, {
		...elementAttributes( type, attributes ),
		className: 'showfm-element',
		style: {
			display: type === 'play' ? 'inline-block' : 'block',
			minHeight: RESERVED[ type ]( attributes ),
		},
	} );
}
