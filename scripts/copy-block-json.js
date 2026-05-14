#!/usr/bin/env node
/**
 * Cross-platform copy of block.json from src → dist after wp-scripts build.
 *
 * Replaces the previous shell `cp` so Windows + restricted CI build cleanly.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC = path.join( __dirname, '..', 'blocks', 'src', 'content-grid', 'block.json' );
const DEST_DIR = path.join( __dirname, '..', 'blocks', 'dist', 'content-grid' );
const DEST = path.join( DEST_DIR, 'block.json' );

if ( ! fs.existsSync( SRC ) ) {
	console.error( `[copy-block-json] Source not found: ${ SRC }` );
	process.exit( 1 );
}

fs.mkdirSync( DEST_DIR, { recursive: true } );
fs.copyFileSync( SRC, DEST );
console.log( `[copy-block-json] ${ SRC } → ${ DEST }` );
