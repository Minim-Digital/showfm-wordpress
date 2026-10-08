/**
 * Checks languages/showfm.pot against the source.
 *
 * Every string passed to a translation function in the PHP and JavaScript source must be in
 * the .pot, so no string is left untranslatable. A call the script can't read (anything but
 * string literals for the text, context and domain) fails the check rather than being
 * skipped. With `--fresh <file>`, the
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

// Function name => its arguments: s = singular, p = plural, n = number, c = context,
// d = text domain.
const FUNCTIONS = {
	__: 'sd',
	_e: 'sd',
	esc_html__: 'sd',
	esc_html_e: 'sd',
	esc_attr__: 'sd',
	esc_attr_e: 'sd',
	_x: 'scd',
	_ex: 'scd',
	esc_html_x: 'scd',
	esc_attr_x: 'scd',
	_n: 'spnd',
	_n_noop: 'spd',
	_nx: 'spncd',
	_nx_noop: 'spcd',
};
const CALL = new RegExp(
	String.raw`(?<![\w$>:])(${ Object.keys( FUNCTIONS ).join( '|' ) })\s*\(`,
	'g'
);

/**
 * Splits a call's arguments at top-level commas, from just after its opening bracket.
 *
 * @param {string} source Source.
 * @param {number} start  Index after the opening bracket.
 * @return {string[]|null} Arguments, or null when the call never closes.
 */
function splitArguments( source, start ) {
	const args = [];
	let depth = 0;
	let quote = null;
	let from = start;
	for ( let i = start; i < source.length; i++ ) {
		const char = source[ i ];
		if ( quote ) {
			if ( char === '\\' ) {
				i++;
			} else if ( char === quote ) {
				quote = null;
			}
		} else if ( char === "'" || char === '"' || char === '`' ) {
			quote = char;
		} else if ( '([{'.includes( char ) ) {
			depth++;
		} else if ( ')]}'.includes( char ) ) {
			if ( depth === 0 ) {
				args.push( source.slice( from, i ).trim() );
				// A trailing comma leaves one empty argument.
				return args.filter(
					( arg, index ) => arg !== '' || index < args.length - 1
				);
			}
			depth--;
		} else if ( char === ',' && depth === 0 ) {
			args.push( source.slice( from, i ).trim() );
			from = i + 1;
		}
	}
	return null;
}

/**
 * The text of one quoted string literal, or null when the argument is anything else.
 *
 * @param {string}  arg Argument source.
 * @param {boolean} php Whether it is PHP, where single quotes only escape \' and \\.
 * @return {string|null} Text.
 */
function literal( arg, php ) {
	const match =
		/^'((?:[^'\\]|\\.)*)'$/s.exec( arg ) ||
		/^"((?:[^"\\]|\\.)*)"$/s.exec( arg );
	if ( ! match ) {
		return null;
	}
	if ( php && arg.startsWith( "'" ) ) {
		return match[ 1 ].replace( /\\(['\\])/g, '$1' );
	}
	return match[ 1 ].replace(
		/\\(.)/gs,
		( _, c ) => ( { n: '\n', t: '\t' } )[ c ] ?? c
	);
}

/**
 * Finds every translation call in one file. A call it can't read, or one with another
 * text domain, is a problem rather than something to skip.
 *
 * @param {string}   file     Path.
 * @param {string[]} problems Problems found, added to.
 * @return {Array<{key: string, where: string}>} Calls.
 */
function calls( file, problems ) {
	const source = readFileSync( file, 'utf8' );
	const found = [];
	for ( const match of source.matchAll( CALL ) ) {
		const where = `${ file }:${
			source.slice( 0, match.index ).split( '\n' ).length
		}`;
		const roles = FUNCTIONS[ match[ 1 ] ];
		const args = splitArguments( source, match.index + match[ 0 ].length );
		const values = {};
		const readable =
			args &&
			args.length === roles.length &&
			[ ...roles ].every( ( role, i ) => {
				if ( role === 'n' ) {
					return args[ i ] !== '';
				}
				values[ role ] = literal( args[ i ], file.endsWith( '.php' ) );
				return values[ role ] !== null;
			} );
		if ( ! readable ) {
			problems.push(
				`${ where }: can't read this ${ match[ 1 ] }() call. Use string literals for the text, context and domain.`
			);
		} else if ( values.d !== DOMAIN ) {
			problems.push(
				`${ where }: ${ match[ 1 ] }() uses the text domain ${ JSON.stringify(
					values.d
				) }, not "${ DOMAIN }".`
			);
		} else {
			found.push( { key: key( values.c, values.s, values.p ), where } );
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
const problems = [];
const found = sources.flatMap( ( file ) => calls( file, problems ) );
const missing = found.filter( ( call ) => ! committed.has( call.key ) );
problems.push(
	...missing.map(
		( call ) =>
			`${ call.where }: not in ${ POT }: ${ JSON.stringify(
				call.key.split( '\u0004' ).pop()
			) }`
	)
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
