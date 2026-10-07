import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import * as server from '@showfm/embed/server';
const directory = 'node_modules/@showfm/embed/fixtures/fallback';
// The functions includes/class-fallback.php ports. renderTranscriptHTML is not ported:
// the plugin never fetches a transcript in PHP, so it has no cues to render.
const ported = [
	'renderEpisodeHTML',
	'renderEpisodeListHTML',
	'episodeJsonLd',
];
const fixtures = readdirSync( directory )
	.filter( ( name ) => name.endsWith( '.json' ) )
	.sort()
	.map( ( name ) => ( {
		name,
		...JSON.parse( readFileSync( `${ directory }/${ name }` ) ),
	} ) )
	.filter( ( fixture ) => ported.includes( fixture.function ) )
	.map( ( { name, function: fn, args } ) => {
		const output = server[ fn ]( ...args );
		return {
			name,
			function: fn,
			args,
			expected:
				fn === 'episodeJsonLd'
					? server.serializeJsonLd( output )
					: output,
		};
	} );
const extra = JSON.parse( readFileSync( 'tests/fixtures/parity-inputs.json' ) );
for ( const input of extra ) {
	const output = server[ input.function ]( ...input.args );
	fixtures.push( {
		...input,
		expected:
			input.function === 'episodeJsonLd'
				? server.serializeJsonLd( output )
				: output,
	} );
}
writeFileSync(
	'tests/fixtures/parity.json',
	JSON.stringify( fixtures, null, '\t' ) + '\n'
);
