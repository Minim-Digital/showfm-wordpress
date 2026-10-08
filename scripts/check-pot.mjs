/**
 * Checks languages/showfm.pot against the source.
 *
 * Every literal string passed to a translation function in the PHP and JavaScript source
 * must be in the .pot, so no string is left untranslatable. With `--fresh <file>`, the
 * committed .pot must also hold exactly the same entries as a freshly generated one, so it
 * is never stale. Line references and the creation date are ignored.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const POT = 'languages/showfm.pot';
const DOMAIN = 'showfm';

/**
 * Reads the entries of a .pot file as `context\u0004msgid` keys.
 *
 * @param {string} file Path.
 * @return {Set<string>} Keys, with the plural form appended after a NUL for plurals.
 */
function potEntries( file ) {
	const entries = new Set();
	let entry = {};
	let field = null;
	const flush = () => {
		if ( entry.msgid ) {
			entries.add(
				key( entry.msgctxt, entry.msgid, entry.msgid_plural )
			);
		}
		entry = {};
		field = null;
	};
	for ( const line of readFileSync( file, 'utf8' ).split( '\n' ) ) {
		const start = line.match( /^(msgctxt|msgid|msgid_plural) "(.*)"$/ );
		if ( start ) {
			// A context or a singular after a complete entry starts the next one.
			if ( 'msgid_plural' !== start[ 1 ] && entry.msgid !== undefined ) {
				flush();
			}
			field = start[ 1 ];
			entry[ field ] = unquote( start[ 2 ] );
		} else if ( field && /^".*"$/.test( line ) ) {
			entry[ field ] += unquote( line.slice( 1, -1 ) );
		} else if ( /^msgstr/.test( line ) ) {
			field = null;
		}
	}
	flush();
	return entries;
}

/**
 * Undoes .pot string escaping.
 *
 * @param {string} text Escaped text.
 * @return {string} Text.
 */
function unquote( text ) {
	return text.replace(
		/\\(.)/g,
		( _, c ) => ( { n: '\n', t: '\t' } )[ c ] ?? c
	);
}

/**
 * An entry key.
 *
 * @param {string|undefined} context Context.
 * @param {string}           msgid   Singular.
 * @param {string|undefined} plural  Plural.
 * @return {string} Key.
 */
function key( context, msgid, plural ) {
	return `${ context ?? '' }\u0004${ msgid }${ plural ? `\u0000${ plural }` : '' }`;
}

/**
 * Lists files under a directory.
 *
 * @param {string}   dir     Directory.
 * @param {RegExp}   pattern File name pattern.
 * @param {string[]} skip    Directory names to skip.
 * @return {string[]} Paths.
 */
function files( dir, pattern, skip = [] ) {
	return readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return skip.includes( entry.name )
				? []
				: files( path, pattern, skip );
		}
		return pattern.test( entry.name ) ? [ path ] : [];
	} );
}

const STRING = String.raw`('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")`;
const SEP = String.raw`\s*,\s*`;
// Function name => the order of its string arguments: s = singular, p = plural, c = context.
const FUNCTIONS = {
	__: 's',
	_e: 's',
	esc_html__: 's',
	esc_html_e: 's',
	esc_attr__: 's',
	esc_attr_e: 's',
	_x: 'sc',
	_ex: 'sc',
	esc_html_x: 'sc',
	esc_attr_x: 'sc',
	_n: 'sp',
	_n_noop: 'sp',
	_nx: 'spc',
	_nx_noop: 'spc',
};

/**
 * Finds literal translation calls for the text domain in one file.
 *
 * @param {string} file Path.
 * @return {Array<{key: string, where: string}>} Calls.
 */
function calls( file ) {
	const source = readFileSync( file, 'utf8' );
	const found = [];
	for ( const [ name, order ] of Object.entries( FUNCTIONS ) ) {
		// _n and _nx take a number after the plural; _noop variants do not.
		const parts = [ ...order ].map( () => STRING );
		const number =
			name === '_n' || name === '_nx' ? String.raw`${ SEP }[^,]+?` : '';
		const args =
			'p' === order[ 1 ]
				? parts[ 0 ] +
					SEP +
					parts[ 1 ] +
					number +
					( parts[ 2 ] ? SEP + parts[ 2 ] : '' )
				: parts.join( SEP );
		const pattern = new RegExp(
			String.raw`(?<![\w$])${ name.replace(
				/\$/g,
				'\\$'
			) }\(\s*${ args }${ SEP }['"]${ DOMAIN }['"]\s*[,)]`,
			'g'
		);
		for ( const match of source.matchAll( pattern ) ) {
			const values = match
				.slice( 1, 1 + order.length )
				.map( ( literal ) => literal.slice( 1, -1 ) )
				.map( ( text ) => text.replace( /\\(['"\\])/g, '$1' ) );
			const arg = Object.fromEntries(
				[ ...order ].map( ( role, i ) => [ role, values[ i ] ] )
			);
			const line = source.slice( 0, match.index ).split( '\n' ).length;
			found.push( {
				key: key( arg.c, arg.s, arg.p ),
				where: `${ file }:${ line }`,
			} );
		}
	}
	return found;
}

const committed = potEntries( POT );
const sources = [
	'showfm.php',
	'uninstall.php',
	...files( 'includes', /\.php$/ ),
	...files( 'src', /\.jsx?$/, [ 'test' ] ),
];
const found = sources.flatMap( calls );
const missing = found.filter( ( call ) => ! committed.has( call.key ) );
const problems = missing.map(
	( call ) =>
		`${ call.where }: not in ${ POT }: ${ JSON.stringify(
			call.key.split( '\u0004' ).pop()
		) }`
);

const freshIndex = process.argv.indexOf( '--fresh' );
if ( freshIndex > -1 ) {
	const fresh = potEntries( process.argv[ freshIndex + 1 ] );
	for ( const entry of fresh ) {
		if ( ! committed.has( entry ) ) {
			problems.push(
				`Missing from ${ POT }: ${ JSON.stringify( entry ) }. Run npm run i18n:pot.`
			);
		}
	}
	for ( const entry of committed ) {
		if ( ! fresh.has( entry ) ) {
			problems.push(
				`No longer in the source: ${ JSON.stringify( entry ) }. Run npm run i18n:pot.`
			);
		}
	}
}

if ( problems.length ) {
	process.stderr.write( problems.join( '\n' ) + '\n' );
	process.exit( 1 );
}
process.stdout.write(
	`${ POT }: ${ committed.size } entries. All ${ found.length } translation calls in ${ sources.length } source files are there.\n`
);
