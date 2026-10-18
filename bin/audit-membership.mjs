/* eslint-disable no-console */
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';

/**
 * Audit first-party PHP only; third-party deployed code needs a separate audit.
 *
 * @param {string} directory Source directory.
 */
async function audit( directory ) {
	for ( const entry of await readdir( directory, { withFileTypes: true } ) ) {
		const file = path.join( directory, entry.name );
		if ( entry.isDirectory() ) {
			await audit( file );
		} else if (
			file.endsWith( '.php' ) &&
			file !== 'inc/cache/class-vip-adapter.php'
		) {
			const source = await readFile( file, 'utf8' );
			if (
				/Vary_Cache\s*::\s*(?:is_user_in_group(?:_segment)?|get_groups)\s*\(/.test(
					source
				)
			) {
				throw new Error(
					`Direct VIP membership read outside adapter: ${ file }`
				);
			}
		}
	}
}
await audit( 'inc' );
console.log( 'First-party membership-read boundary verified.' );
