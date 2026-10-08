/**
 * Checks the release zip's file list against bin/release-files.txt.
 *
 * Usage: node scripts/check-zip.mjs dist/showfm-<version>.zip
 *
 * Every file in the zip must match a line of the allow-list, and every line must match at
 * least one file, so the zip holds exactly the distributable files.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const [ zip ] = process.argv.slice( 2 );
if ( ! zip ) {
	process.stderr.write( 'Usage: node scripts/check-zip.mjs <zip>\n' );
	process.exit( 2 );
}

const patterns = readFileSync( 'bin/release-files.txt', 'utf8' )
	.split( '\n' )
	.map( ( line ) => line.trim() )
	.filter( ( line ) => line && ! line.startsWith( '#' ) );

/**
 * A pattern as a regular expression: `*` matches within one path segment.
 *
 * @param {string} pattern Pattern.
 * @return {RegExp} Expression.
 */
const toRegExp = ( pattern ) =>
	new RegExp(
		'^' +
			pattern
				.split( '*' )
				.map( ( part ) => part.replace( /[.+?^${}()|[\]\\]/g, '\\$&' ) )
				.join( '[^/]*' ) +
			'$'
	);

const rules = patterns.map( ( pattern ) => ( {
	pattern,
	regexp: toRegExp( pattern ),
	used: false,
} ) );
const files = execFileSync( 'unzip', [ '-Z1', zip ], { encoding: 'utf8' } )
	.split( '\n' )
	.filter( ( file ) => file && ! file.endsWith( '/' ) );

const problems = [];
for ( const file of files ) {
	const rule = rules.find( ( { regexp } ) => regexp.test( file ) );
	if ( rule ) {
		rule.used = true;
	} else {
		problems.push( `Not on the allow-list: ${ file }` );
	}
}
for ( const rule of rules.filter( ( { used } ) => ! used ) ) {
	problems.push( `Missing from the zip: ${ rule.pattern }` );
}

if ( problems.length ) {
	process.stderr.write(
		`${ zip } does not match bin/release-files.txt:\n${ problems.join(
			'\n'
		) }\n`
	);
	process.exit( 1 );
}
process.stdout.write(
	`${ zip }: all ${ files.length } files match bin/release-files.txt.\n`
);
