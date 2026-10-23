#!/usr/bin/env node
/* eslint-disable no-console -- This is a CLI; console is its output. */
/**
 * Read-only environment preflight.
 *
 * Checks the toolchain, Docker, dependencies, the two wp-env instances, and
 * who owns ports 8888/8889. Prints the WP_BASE_URL to use for Playwright.
 * Exits non-zero only when a required tool is missing.
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { createConnection } from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'..'
);
const CONFIGS = [
	{ label: 'dev', file: '.wp-env.json', variant: '', script: 'env' },
	{
		label: 'tests',
		file: '.wp-env.tests.json',
		variant: 'tests',
		script: 'env:tests',
	},
];
const PORTS = [
	{ label: 'dev', port: 8888 },
	{ label: 'tests', port: 8889 },
];

let failures = 0;

/**
 * Print a passing check.
 *
 * @param {string} msg Message.
 */
function ok( msg ) {
	console.log( `  ok    ${ msg }` );
}

/**
 * Print a non-fatal warning.
 *
 * @param {string} msg Message.
 */
function warn( msg ) {
	console.log( `  WARN  ${ msg }` );
}

/**
 * Print a failing check and remember it for the exit code.
 *
 * @param {string} msg Message.
 */
function fail( msg ) {
	failures += 1;
	console.log( `  FAIL  ${ msg }` );
}

/**
 * Report a boolean check.
 *
 * @param {boolean} passed  Whether the check passed.
 * @param {string}  passMsg Message when passing.
 * @param {string}  failMsg Message when failing.
 * @param {boolean} fatal   Whether a failure should affect the exit code.
 */
function check( passed, passMsg, failMsg, fatal = true ) {
	if ( passed ) {
		ok( passMsg );
	} else if ( fatal ) {
		fail( failMsg );
	} else {
		warn( failMsg );
	}
}

/**
 * Run a command and return trimmed stdout, or null on failure.
 *
 * @param {string}   cmd  Executable.
 * @param {string[]} args Arguments.
 * @return {string|null} Output or null.
 */
function run( cmd, args ) {
	try {
		return execFileSync( cmd, args, {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'ignore' ],
		} ).trim();
	} catch {
		return null;
	}
}

/**
 * Whether something accepts TCP connections on the port.
 *
 * @param {number} port Port number.
 * @return {Promise<boolean>} True when in use.
 */
function portInUse( port ) {
	return new Promise( ( resolve ) => {
		const socket = createConnection( { host: '127.0.0.1', port } );
		socket.once( 'connect', () => {
			socket.destroy();
			resolve( true );
		} );
		socket.once( 'error', () => resolve( false ) );
	} );
}

/**
 * Mirror the descriptive work-dir (and compose project) name used by wp-env.
 *
 * @param {string} configFile Config file name relative to the repo root.
 * @param {string} variant    Variant label, e.g. "tests".
 * @return {string} Directory name.
 */
function workDirName( configFile, variant ) {
	const abs = path.join( ROOT, configFile );
	const hash = createHash( 'md5' )
		.update( abs )
		.digest( 'hex' )
		.slice( 0, 8 );
	return [ 'wp-env', path.basename( ROOT ), variant, hash ]
		.filter( Boolean )
		.join( '-' );
}

/**
 * List running Docker containers.
 *
 * @return {Array<{Names: string, Ports: string}>} Containers.
 */
function dockerContainers() {
	const out = run( 'docker', [ 'ps', '--format', '{{json .}}' ] );
	if ( ! out ) {
		return [];
	}
	return out
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => JSON.parse( line ) );
}

console.log( 'Toolchain' );
const nodeMajor = Number( process.versions.node.split( '.' )[ 0 ] );
check(
	nodeMajor >= 22,
	`node ${ process.versions.node }`,
	`node ${ process.versions.node } (need >= 22 for the edge simulator and its tests)`
);
const php = run( 'php', [ '-r', 'echo PHP_VERSION;' ] );
check(
	php !== null,
	`php ${ php }`,
	'php not on PATH (only needed for host-side lint/analyze)',
	false
);
check(
	run( 'composer', [ '--version' ] ) !== null,
	'composer',
	'composer not on PATH'
);
check(
	run( 'docker', [ '--version' ] ) !== null,
	'docker CLI',
	'docker CLI not on PATH'
);
check(
	run( 'docker', [ 'info' ] ) !== null,
	'docker daemon running',
	'docker daemon not running'
);

console.log( '\nDependencies' );
check(
	existsSync( path.join( ROOT, 'vendor/autoload.php' ) ),
	'vendor/ present',
	'vendor/ missing (run composer install)'
);
check(
	existsSync( path.join( ROOT, 'node_modules/@wordpress/env' ) ),
	'node_modules/ present',
	'node_modules/ missing (run npm install)'
);
check(
	existsSync( path.join( ROOT, 'build/index.asset.php' ) ),
	'build/ present (editor Segments control)',
	'build/ missing: Group blocks have no Segments control (run npm run build)',
	false
);
check(
	existsSync( path.join( ROOT, 'edge-workers/node_modules/.bin/asc' ) ),
	'edge-workers/node_modules/ present (SDK + AssemblyScript)',
	'edge-workers/node_modules/ missing: edge:build cannot compile (run npm run edge:install); tests and the simulator do not need it',
	false
);

console.log( '\nwp-env instances' );
const containers = dockerContainers();
const urls = {};
for ( const cfg of CONFIGS ) {
	const project = workDirName( cfg.file, cfg.variant );
	const wordpress = containers.find( ( c ) =>
		c.Names.startsWith( `${ project }-wordpress` )
	);
	if ( ! wordpress ) {
		warn( `${ cfg.label }: not running (npm run ${ cfg.script } start)` );
		continue;
	}
	const match = /0\.0\.0\.0:(\d+)->80\/tcp/.exec( wordpress.Ports ?? '' );
	const port = match ? Number( match[ 1 ] ) : null;
	urls[ cfg.label ] = port ? `http://localhost:${ port }` : null;
	const where = port ? ` on ${ urls[ cfg.label ] }` : '';
	ok( `${ cfg.label }: running${ where } (${ project })` );
}

console.log( '\nPorts' );
for ( const { label, port } of PORTS ) {
	const inUse = await portInUse( port );
	const ours = urls[ label ] === `http://localhost:${ port }`;
	if ( ! inUse ) {
		ok( `:${ port } free` );
	} else if ( ours ) {
		ok( `:${ port } held by our ${ label } instance` );
	} else {
		warn(
			`:${ port } is held by something else. Playwright would silently reuse it.`
		);
	}
}

console.log( '\nEdge simulator' );
if ( await portInUse( 8890 ) ) {
	ok( ':8890 answers (npm run edge:sim, or something else)' );
} else {
	console.log(
		'  off   not running; `npm run edge:sim` puts it in front of the dev site on :8890'
	);
}

console.log( '\nPlaywright' );
if ( urls.tests ) {
	console.log( `  Use:  WP_BASE_URL=${ urls.tests } npm run test:e2e` );
	console.log( '        (our container, so the seed runs as usual)' );
} else if ( await portInUse( 8889 ) ) {
	console.log(
		'  :8889 belongs to something else and our tests instance is not running.'
	);
	console.log(
		'  Best:  npm run env:tests start   (autoPort picks a free port; rerun doctor)'
	);
	console.log(
		'  Or, against that foreign site, which must already carry the seed:'
	);
	console.log(
		'  Use:   CONTENT_GATES_E2E_EXTERNAL=1 WP_BASE_URL=http://localhost:8889 npm run test:e2e'
	);
} else {
	console.log(
		'  Tests instance is not running. `npm run test:e2e` will start it.'
	);
}

if ( failures > 0 ) {
	console.log( `\n${ failures } required check(s) failed.` );
	process.exit( 1 );
}
