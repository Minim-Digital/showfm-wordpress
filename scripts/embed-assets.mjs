import { createHash } from 'node:crypto';
import { cpSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import assert from 'node:assert/strict';

const source = 'node_modules/@showfm/embed/dist/cdn';
const target = 'assets/showfm-embed';
const pkg = JSON.parse(
	readFileSync( 'node_modules/@showfm/embed/package.json' )
);
assert.equal( pkg.version, '1.5.0' );
assert.equal(
	JSON.parse( readFileSync( 'package.json' ) ).devDependencies[
		'@showfm/embed'
	],
	pkg.version
);
function hashes( root, path = '' ) {
	return readdirSync( join( root, path ), { withFileTypes: true } )
		.sort( ( a, b ) => a.name.localeCompare( b.name ) )
		.flatMap( ( entry ) => {
			const file = join( path, entry.name );
			return entry.isDirectory()
				? hashes( root, file )
				: [
						[
							file,
							createHash( 'sha256' )
								.update( readFileSync( join( root, file ) ) )
								.digest( 'hex' ),
						],
					];
		} );
}
if ( ! process.argv.includes( '--check' ) ) {
	rmSync( target, { recursive: true, force: true } );
	cpSync( source, target, { recursive: true } );
	cpSync(
		'node_modules/@showfm/embed/LICENSE',
		'assets/showfm-embed-LICENSE'
	);
}
assert.deepEqual( hashes( target ), hashes( source ) );
process.stdout.write(
	`Verified @showfm/embed ${ pkg.version }: SHA-256 matches for every CDN file.\n`
);
