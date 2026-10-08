/**
 * Builds the WordPress.org banners and icons in .wordpress-org/ from Claude Design D-W9.
 *
 * Usage: npm run wporg:assets
 *
 * banner.html is rendered in Chromium at 1544 × 500 and 772 × 250. Its player is the real
 * <showfm-player> from assets/showfm-embed, answered from fixtures and playing at 20:10.
 * The icons are icon.svg at 256 and 128 pixels. The screenshots come from wp-env instead:
 * see tests/e2e/screenshots.spec.js.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { deflateSync } from 'node:zlib';
import { chromium } from '@playwright/test';

const ORIGIN = 'https://wporg-assets.test';
const OUT = '.wordpress-org';
const EPISODE = '22222222-2222-4222-8222-222222222222';
const DURATION = 3138;
const TYPES = {
	'.html': 'text/html',
	'.js': 'text/javascript',
	'.css': 'text/css',
	'.svg': 'image/svg+xml',
};

/**
 * A CRC-32, for PNG chunks. zlib.crc32 needs Node 22.15, and the plugin supports 22.12.
 *
 * @param {Buffer} bytes Bytes.
 * @return {number} CRC.
 */
function crc32( bytes ) {
	/* eslint-disable no-bitwise -- CRC-32 is bitwise by definition. */
	let crc = 0xffffffff;
	for ( const byte of bytes ) {
		crc ^= byte;
		for ( let bit = 0; bit < 8; bit++ ) {
			crc = crc & 1 ? ( crc >>> 1 ) ^ 0xedb88320 : crc >>> 1;
		}
	}
	return ( crc ^ 0xffffffff ) >>> 0;
	/* eslint-enable no-bitwise */
}

/**
 * A solid-colour PNG, standing in for the episode artwork.
 *
 * @param {number[]} rgb  Colour.
 * @param {number}   size Width and height.
 * @return {Buffer} PNG.
 */
function solidPng( rgb, size ) {
	const chunk = ( type, data ) => {
		const head = Buffer.alloc( 4 );
		head.writeUInt32BE( data.length );
		const body = Buffer.concat( [ Buffer.from( type ), data ] );
		const crc = Buffer.alloc( 4 );
		crc.writeUInt32BE( crc32( body ) );
		return Buffer.concat( [ head, body, crc ] );
	};
	const header = Buffer.alloc( 13 );
	header.writeUInt32BE( size, 0 );
	header.writeUInt32BE( size, 4 );
	header.set( [ 8, 2, 0, 0, 0 ], 8 );
	const row = Buffer.concat( [
		Buffer.from( [ 0 ] ),
		Buffer.from( Array( size ).fill( rgb ).flat() ),
	] );
	return Buffer.concat( [
		Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ),
		chunk( 'IHDR', header ),
		chunk(
			'IDAT',
			deflateSync( Buffer.concat( Array( size ).fill( row ) ) )
		),
		chunk( 'IEND', Buffer.alloc( 0 ) ),
	] );
}

/**
 * Silent 8 kHz mono WAV audio, so the player can really play.
 *
 * @param {number} seconds Length.
 * @return {Buffer} WAV.
 */
function silentWav( seconds ) {
	const rate = 8000;
	const data = rate * seconds;
	const head = Buffer.alloc( 44 );
	head.write( 'RIFF', 0 );
	head.writeUInt32LE( 36 + data, 4 );
	head.write( 'WAVEfmt ', 8 );
	head.writeUInt32LE( 16, 16 );
	head.writeUInt16LE( 1, 20 );
	head.writeUInt16LE( 1, 22 );
	head.writeUInt32LE( rate, 24 );
	head.writeUInt32LE( rate, 28 );
	head.writeUInt16LE( 1, 32 );
	head.writeUInt16LE( 8, 34 );
	head.write( 'data', 36 );
	head.writeUInt32LE( data, 40 );
	return Buffer.concat( [ head, Buffer.alloc( data, 128 ) ] );
}

const podcast = {
	id: '33333333-3333-4333-8333-333333333333',
	slug: 'the-long-table',
	title: 'The Long Table',
	artwork: { url: 'https://m.cdn.media/wporg/artwork.png' },
	brand_color: '#7E22CE',
	links: { listen: 'https://the-long-table.show.fm' },
	branding: { show_powered_by: false },
};
const episode = {
	id: EPISODE,
	slug: 'sourdough',
	title: 'Sourdough, salt and the slow return of the village bakery',
	description: 'An episode of The Long Table.',
	season_number: 2,
	episode_number: 4,
	episode_type: 'full',
	published_at: '2026-09-24T09:00:00.000Z',
	audio: {
		url: 'https://m.cdn.media/wporg/sourdough.wav',
		content_type: 'audio/wav',
		duration_seconds: DURATION,
	},
	artwork: { url: 'https://m.cdn.media/wporg/artwork.png' },
	links: { listen: 'https://the-long-table.show.fm/e/sourdough' },
	transcript: null,
	podcast,
};

const files = {
	'/banner.html': 'scripts/wporg-assets/banner.html',
	'/showfm-logo.svg': 'scripts/wporg-assets/showfm-logo.svg',
	'/icon.svg': `${ OUT }/icon.svg`,
};
const pages = {
	'/icon.html': `<!doctype html><style>html,body{margin:0;background:transparent}img{display:block;width:100vw;height:100vh}</style><img src="icon.svg" alt="">`,
};
const artwork = solidPng( [ 0xc8, 0x55, 0x3d ], 64 );
const audio = silentWav( DURATION );

const browser = await chromium.launch( {
	args: [ '--autoplay-policy=no-user-gesture-required' ],
} );

/**
 * Opens a page with every request answered locally or from fixtures.
 *
 * @param {number} scale Device scale factor.
 * @return {Promise<import('@playwright/test').Page>} Page.
 */
async function open( scale ) {
	const page = await browser.newPage( {
		viewport: { width: 1544, height: 500 },
		deviceScaleFactor: scale,
	} );
	await page.route( `${ ORIGIN }/**`, ( route ) => {
		const path = new URL( route.request().url() ).pathname;
		if ( pages[ path ] ) {
			return route.fulfill( {
				body: pages[ path ],
				contentType: 'text/html',
			} );
		}
		const file = path.startsWith( '/embed/' )
			? join( 'assets/showfm-embed', normalize( path.slice( 7 ) ) )
			: files[ path ];
		if ( ! file || file.includes( '..' ) ) {
			return route.fulfill( { status: 404 } );
		}
		return route.fulfill( {
			body: readFileSync( file ),
			contentType: TYPES[ extname( file ) ],
		} );
	} );
	await page.route( 'https://api.show.fm/**', ( route ) =>
		route.fulfill( {
			status: route.request().url().includes( EPISODE ) ? 200 : 404,
			contentType: 'application/json',
			headers: { 'access-control-allow-origin': '*' },
			body: JSON.stringify( { data: episode } ),
		} )
	);
	await page.route( 'https://m.cdn.media/**', ( route ) => {
		const cors = { 'access-control-allow-origin': '*' };
		if ( route.request().url().endsWith( '.png' ) ) {
			return route.fulfill( {
				body: artwork,
				contentType: 'image/png',
				headers: cors,
			} );
		}
		// Answer byte ranges, so the browser can seek in the audio.
		const range = /bytes=(\d+)-(\d*)/.exec(
			route.request().headers().range || ''
		);
		const start = range ? Number( range[ 1 ] ) : 0;
		const end = range?.[ 2 ] ? Number( range[ 2 ] ) : audio.length - 1;
		return route.fulfill( {
			status: range ? 206 : 200,
			body: audio.subarray( start, end + 1 ),
			contentType: 'audio/wav',
			headers: {
				...cors,
				'accept-ranges': 'bytes',
				...( range && {
					'content-range': `bytes ${ start }-${ end }/${ audio.length }`,
				} ),
			},
		} );
	} );
	return page;
}

/**
 * Renders the banner at one scale.
 *
 * @param {number} scale Device scale factor.
 * @param {string} name  Output file name.
 */
async function banner( scale, name ) {
	const page = await open( scale );
	await page.goto( `${ ORIGIN }/banner.html` );
	await page.evaluate( () => document.fonts.ready );
	const play = page.getByRole( 'button', { name: 'Play', exact: true } );
	await play.click();
	await page.getByRole( 'button', { name: 'Pause', exact: true } ).waitFor();
	// Seek to 20:10 by clicking the waveform, as a listener would.
	const slider = page.getByRole( 'slider' ).first();
	const box = await slider.boundingBox();
	await slider.click( {
		position: { x: ( box.width * 1210 ) / DURATION, y: box.height / 2 },
	} );
	await page.waitForTimeout( 1500 );
	await page.mouse.move( 0, 0 );
	writeFileSync(
		join( OUT, name ),
		await page.screenshot( {
			clip: { x: 0, y: 0, width: 1544, height: 500 },
		} )
	);
	await page.close();
}

/**
 * Renders the icon at one size.
 *
 * @param {number} size Pixels.
 */
async function icon( size ) {
	const page = await open( 1 );
	await page.setViewportSize( { width: size, height: size } );
	await page.goto( `${ ORIGIN }/icon.html` );
	await page.locator( 'img' ).evaluate( ( img ) => img.decode() );
	writeFileSync(
		join( OUT, `icon-${ size }x${ size }.png` ),
		await page.screenshot( { omitBackground: true } )
	);
	await page.close();
}

await banner( 1, 'banner-1544x500.png' );
await banner( 0.5, 'banner-772x250.png' );
await icon( 256 );
await icon( 128 );
await browser.close();
process.stdout.write(
	`Wrote the banners and icons to ${ OUT }/. Check them by eye before committing.\n`
);
