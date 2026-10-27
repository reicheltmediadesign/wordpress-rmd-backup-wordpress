#!/usr/bin/env bash
# Writes dist/update.json, the metadata file plugin-update-checker reads.
# Values come from the plugin header, readme.txt and CHANGELOG.md, so the
# release notes on GitHub can be edited freely without breaking updates.
set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p dist
node - <<'NODE'
const fs = require( 'fs' );

const header = fs.readFileSync( 'rmd-backup-wordpress.php', 'utf8' );
const readme = fs.readFileSync( 'readme.txt', 'utf8' );
const changelog = fs.readFileSync( 'CHANGELOG.md', 'utf8' );

const field = ( source, name ) => {
	const match = new RegExp( '^\\s*\\*?\\s*' + name + ':\\s*(.+)$', 'mi' ).exec( source );
	return match ? match[ 1 ].trim() : '';
};

const version = field( header, 'Version' );
const repo = 'https://github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress';

// Changelog section of this version from CHANGELOG.md → simple HTML.
const sectionMatch = new RegExp( '^## \\[' + version.replace( /\./g, '\\.' ) + '\\][^\\n]*\\n([\\s\\S]*?)(?=^## \\[|(?![\\s\\S]))', 'm' ).exec( changelog );
const escapeHtml = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
const inline = ( s ) => escapeHtml( s ).replace( /`([^`]+)`/g, '<code>$1</code>' ).replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
let changelogHtml = '';
if ( sectionMatch ) {
	let inList = false;
	for ( const line of sectionMatch[ 1 ].split( '\n' ) ) {
		if ( /^### /.test( line ) ) {
			if ( inList ) { changelogHtml += '</ul>'; inList = false; }
			changelogHtml += '<h4>' + inline( line.replace( /^### /, '' ) ) + '</h4>';
		} else if ( /^- /.test( line ) ) {
			if ( ! inList ) { changelogHtml += '<ul>'; inList = true; }
			changelogHtml += '<li>' + inline( line.replace( /^- /, '' ) ) + '</li>';
		}
	}
	if ( inList ) { changelogHtml += '</ul>'; }
}
changelogHtml += '<p><a href="' + repo + '/blob/main/CHANGELOG.md">' + escapeHtml( 'Full changelog on GitHub' ) + '</a></p>';

const descriptionMatch = /== Description ==\s*([\s\S]*?)\n== /.exec( readme );
const description = descriptionMatch
	? descriptionMatch[ 1 ].trim().split( /\n\n+/ ).map( ( p ) => {
		if ( /^\* /m.test( p ) ) {
			return '<ul>' + p.split( '\n' ).filter( ( l ) => /^\* /.test( l ) ).map( ( l ) => '<li>' + inline( l.replace( /^\* /, '' ) ) + '</li>' ).join( '' ) + '</ul>';
		}
		return '<p>' + inline( p ) + '</p>';
	} ).join( '' )
	: '';

const info = {
	name: field( header, 'Plugin Name' ),
	slug: 'rmd-backup-wordpress',
	version,
	download_url: repo + '/releases/download/v' + version + '/rmd-backup-wordpress.zip',
	homepage: repo,
	requires: field( readme, 'Requires at least' ),
	tested: field( readme, 'Tested up to' ),
	requires_php: field( readme, 'Requires PHP' ),
	last_updated: new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ),
	author: '<a href="https://reicheltmedia.design">reichelt media.design</a>',
	author_homepage: 'https://reicheltmedia.design',
	sections: {
		description,
		changelog: changelogHtml,
	},
};

fs.writeFileSync( 'dist/update.json', JSON.stringify( info, null, 2 ) + '\n' );
console.log( 'Wrote dist/update.json for version ' + version );
NODE
