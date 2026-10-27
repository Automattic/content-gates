import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { startEdgeSim } from '../../edge-workers/sim/server.ts';
import { SEED } from './helpers';

/**
 * The purge link from origin to the edge: a segment deactivation and a post
 * save at origin empty the simulator's store, through the dev fixture's
 * purge bridge. On WPVIP the same actions call the platform's purges.
 *
 * The simulator listens on every interface so the tests container can reach
 * it at host.docker.internal; the fixture learns the port from an option.
 */

function wp( ...args: string[] ): string {
	return execFileSync(
		'npx',
		[
			'wp-env',
			'--config=.wp-env.tests.json',
			'run',
			'cli',
			'--env-cwd=wp-content/plugins/content-gates',
			'wp',
			...args,
		],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
	);
}

test.describe( 'purge bridge', () => {
	test.skip(
		!! process.env.CONTENT_GATES_E2E_EXTERNAL,
		'needs the wp-env tests container and its fixture'
	);

	let edge: { url: string; close: () => Promise< void > };

	test.beforeAll( async ( { baseURL } ) => {
		edge = await startEdgeSim( {
			origin: baseURL!,
			host: '0.0.0.0',
			port: 0,
			secret: 'e2e-secret',
		} );
		const port = new URL( edge.url ).port;
		wp(
			'option',
			'update',
			'content_gates_dev_edge_url',
			`http://host.docker.internal:${ port }`
		);
	} );

	test.afterAll( async () => {
		wp( 'option', 'delete', 'content_gates_dev_edge_url' );
		wp( 'content-gates', 'activate', 'subscriber' );
		await edge.close();
	} );

	async function storedCount(): Promise< number > {
		const ctx = await request.newContext( { baseURL: edge.url } );
		const stats = await ( await ctx.get( '/__edge/stats' ) ).json();
		await ctx.dispose();
		return ( stats.entries as unknown[] ).length;
	}

	/**
	 * The fixture posts the purge without waiting for a reply, so poll.
	 *
	 * @param count Expected number of stored copies.
	 */
	async function expectStored( count: number ): Promise< void > {
		await expect.poll( storedCount, { timeout: 5000 } ).toBe( count );
	}

	test( 'deactivating a segment purges every stored copy', async () => {
		const ctx = await request.newContext( { baseURL: edge.url } );
		await ctx.post( '/__edge/reset' );
		await ctx.get( SEED.gatedArticle );
		await ctx.get( SEED.publicPost );
		await expectStored( 2 );

		wp( 'content-gates', 'deactivate', 'subscriber' );
		await expectStored( 0 );
		await ctx.dispose();
	} );

	test( 'saving a post purges its URL and leaves others', async () => {
		const ctx = await request.newContext( { baseURL: edge.url } );
		await ctx.post( '/__edge/reset' );
		await ctx.get( SEED.gatedArticle );
		await ctx.get( SEED.publicPost );
		await expectStored( 2 );

		const id = wp(
			'post',
			'list',
			'--name=public-post',
			'--field=ID',
			'--post_type=post'
		).trim();
		wp( 'post', 'update', id, '--post_title=Public post (saved again)' );
		await expectStored( 1 );
		await ctx.dispose();
	} );
} );
