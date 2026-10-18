/* eslint-disable no-console */
import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';

const commit = 'f5941dc4060c02f0290b3d7fd39a91807e552f7c';
const expected =
	'2eb0d387b4372a0e48cb23e053561f3a91cf41f353cc958b6c594fff2345fde4';
const response = await fetch(
	`https://raw.githubusercontent.com/Automattic/vip-go-mu-plugins/${ commit }/cache/class-vary-cache.php`
);
if ( ! response.ok ) {
	throw new Error( `VIP fixture download failed: ${ response.status }` );
}
const source = Buffer.from( await response.arrayBuffer() );
if ( createHash( 'sha256' ).update( source ).digest( 'hex' ) !== expected ) {
	throw new Error( 'VIP fixture checksum mismatch; refusing to install.' );
}
await mkdir( 'artifacts/vip-test', { recursive: true } );
await writeFile( 'artifacts/vip-test/class-vary-cache.php', source );
console.log( `Verified unmodified VIP test source at ${ commit }.` );
if ( process.argv.includes( '--install' ) ) {
	for ( const args of [
		[ 'mkdir', '-p', 'wp-content/mu-plugins' ],
		[
			'cp',
			'wp-content/plugins/content-gates/tests/fixtures/vip-loader.php',
			'wp-content/mu-plugins/content-gates-vip-test.php',
		],
	] ) {
		const result = spawnSync(
			'npm',
			[ 'run', 'env:tests', '--', 'run', 'cli', ...args ],
			{ stdio: 'inherit' }
		);
		if ( result.status !== 0 ) {
			throw new Error( 'Test-only MU fixture installation failed.' );
		}
	}
}
