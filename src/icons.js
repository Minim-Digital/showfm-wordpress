/**
 * Block icons from the design (24px, current colour).
 */
import { Path, SVG } from '@wordpress/components';

const icon = ( d ) => (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<Path d={ d } />
	</SVG>
);

export const playerIcon = icon(
	'M4 10h1.5v4H4zM7.5 7H9v10H7.5zM11 4h1.5v16H11zM14.5 8H16v8h-1.5zM18 10.5h1.5v3H18z'
);
export const listIcon = icon(
	'M4 5.5h2v2H4zM8.5 5.75H20v1.5H8.5zM4 11h2v2H4zM8.5 11.25H20v1.5H8.5zM4 16.5h2v2H4zM8.5 16.75H20v1.5H8.5z'
);
export const playIcon = icon(
	'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 1.5a6.5 6.5 0 1 1 0 13 6.5 6.5 0 0 1 0-13ZM10 8.5v7l5.5-3.5L10 8.5Z'
);
export const transcriptIcon = icon(
	'M5 6h14v1.5H5zM5 10h14v1.5H5zM5 14h9v1.5H5zM5 18h6v1.5H5z'
);
