/* eslint-disable no-console */
/**
 * Command-line entry for the edge simulator.
 *
 *     npm run edge:sim -- --origin http://localhost:8888 --port 8890 --verbose
 *
 * Flags: --origin (default http://localhost:8888), --port (default 8890),
 * --secret (meter signing secret, default local-dev-secret), --verbose.
 */

import { startEdgeSim } from './server.ts';

/**
 * Parse `--flag value` and `--flag` arguments.
 *
 * @param argv Arguments after the script name.
 * @return Flag values; boolean flags map to `true`.
 */
function parseArgs( argv: string[] ): Map< string, string | true > {
	const out = new Map< string, string | true >();
	for ( let i = 0; i < argv.length; i++ ) {
		const arg = argv[ i ];
		if ( ! arg.startsWith( '--' ) ) {
			continue;
		}
		const eq = arg.indexOf( '=' );
		if ( eq >= 0 ) {
			out.set( arg.slice( 2, eq ), arg.slice( eq + 1 ) );
			continue;
		}
		const next = argv[ i + 1 ];
		if ( next !== undefined && ! next.startsWith( '--' ) ) {
			out.set( arg.slice( 2 ), next );
			i++;
		} else {
			out.set( arg.slice( 2 ), true );
		}
	}
	return out;
}

const args = parseArgs( process.argv.slice( 2 ) );
const origin = String( args.get( 'origin' ) ?? 'http://localhost:8888' );
const port = Number.parseInt( String( args.get( 'port' ) ?? '8890' ), 10 );
const secret = String( args.get( 'secret' ) ?? 'local-dev-secret' );
const verbose = args.get( 'verbose' ) === true;

const sim = await startEdgeSim( {
	origin,
	port,
	secret,
	log: verbose ? console.log : undefined,
} );

console.log( 'edge-sim listening on ' + sim.url + ' -> ' + origin );
console.log( 'stats: GET ' + sim.url + '/__edge/stats' );
console.log(
	'purge: POST ' +
		sim.url +
		'/__edge/purge  reset: POST ' +
		sim.url +
		'/__edge/reset'
);

const shutdown = () => {
	sim.close().then( () => process.exit( 0 ) );
};
process.on( 'SIGINT', shutdown );
process.on( 'SIGTERM', shutdown );
