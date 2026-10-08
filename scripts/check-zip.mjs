/**
 * Checks the release zip's file list against bin/release-files.txt.
 *
 * Usage: node scripts/check-zip.mjs dist/showfm-<version>.zip
 *
 * Every file in the zip must match a line of the allow-list, and every line must match
 * exactly one file, so the zip holds exactly the distributable files and none is missing.
 *
 * No shipped script may name show.fm's script CDN, so no path through the plugin can load
 * remote code (WordPress.org guideline 8). CDN_ALLOWED lists the files allowed to, and why.
 *
 * No shipped file of any kind may name a show.fm development domain: the plugin's built-in
 * hosts are production only, and a development environment comes from the site's own code.
 *
 * The readme (above its changelog) must name the bundled `@showfm/embed` version, and only it,
 * in its text and in the source link, so the version a reviewer follows is the one shipped.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const [ zip ] = process.argv.slice( 2 );

const CDN = 'embed.cdn.media';
// Scripts allowed to contain CDN. None: the package's CDN click loader stays out of the zip
// (.distignore), and no other shipped script names it. The PHP oEmbed handler names the host
// to recognise iframes, but PHP is not loaded by a browser.
const CDN_ALLOWED = [];
// show.fm's development domains, built from parts so this file doesn't name them itself.
const DEV_HOSTS = [ 'showfm', 'podcasterplus' ].map(
	( name ) => `${ name }.dev`
);
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
	used: 0,
} ) );
const files = execFileSync( 'unzip', [ '-Z1', zip ], { encoding: 'utf8' } )
	.split( '\n' )
	.filter( ( file ) => file && ! file.endsWith( '/' ) );

const problems = [];
for ( const file of files ) {
	const rule = rules.find( ( { regexp } ) => regexp.test( file ) );
	if ( rule ) {
		rule.used++;
	} else {
		problems.push( `Not on the allow-list: ${ file }` );
	}
}
for ( const file of files.filter(
	( name ) => name.endsWith( '.js' ) && ! CDN_ALLOWED.includes( name )
) ) {
	const source = execFileSync( 'unzip', [ '-p', zip, file ], {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
	} );
	if ( source.includes( CDN ) ) {
		problems.push( `${ file } names ${ CDN }: shipped scripts must not.` );
	}
}
for ( const file of files ) {
	// Every byte as one character, so a host in a binary or a non-UTF-8 file is still seen.
	const content = execFileSync( 'unzip', [ '-p', zip, file ], {
		maxBuffer: 64 * 1024 * 1024,
	} )
		.toString( 'latin1' )
		.toLowerCase();
	for ( const host of DEV_HOSTS ) {
		if ( content.includes( host ) ) {
			problems.push(
				`${ file } names ${ host }: shipped files must name production hosts only.`
			);
		}
	}
}
// The readme's bundled-package version, against the exact version package.json pins.
const pinned = JSON.parse( readFileSync( 'package.json', 'utf8' ) )
	.devDependencies[ '@showfm/embed' ];
const readme = execFileSync( 'unzip', [ '-p', zip, 'showfm/readme.txt' ], {
	encoding: 'utf8',
} ).split( '== Changelog ==' )[ 0 ];
const named = [
	...readme.matchAll( /@showfm\/embed (\d+\.\d+\.\d+)/g ),
	...readme.matchAll( /showfm-embed\/tree\/v(\d+\.\d+\.\d+)/g ),
].map( ( match ) => match[ 1 ] );
if ( named.length < 2 || named.some( ( version ) => version !== pinned ) ) {
	problems.push(
		`readme.txt must name the bundled @showfm/embed ${ pinned } in its text and source link (found: ${
			named.join( ', ' ) || 'none'
		}).`
	);
}
for ( const rule of rules ) {
	if ( rule.used === 0 ) {
		problems.push( `Missing from the zip: ${ rule.pattern }` );
	} else if ( rule.used > 1 ) {
		problems.push(
			`${ rule.used } files match ${ rule.pattern }, not one`
		);
	}
}

if ( problems.length ) {
	process.stderr.write(
		`${ zip } failed the release checks:\n${ problems.join( '\n' ) }\n`
	);
	process.exit( 1 );
}
process.stdout.write(
	`${ zip }: all ${ files.length } files match bin/release-files.txt, no script names ${ CDN }, and no file names a development host.\n`
);
