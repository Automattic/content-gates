/**
 * Segment admin acceptance tests.
 *
 * Criteria covered (spec/segment-admin.md "Finish Line / Verification",
 * Admin behavior):
 * - Settings menu contains "Content Gates"; it opens a page titled
 *   "Content Gates" with the segment list editor.
 * - Create a segment; it is listed Active with an integer ID and a key.
 * - Rename keeps the same ID and key.
 * - Deactivate -> Inactive, reactivate -> Active, same ID.
 * - No individual or bulk trash/delete control on the page.
 * - Deletion is rejected through REST (`/wp/v2/cg_segment` is not a route)
 *   and through `post.php?action=delete` (the segment is still listed).
 *   The nonce-less post.php request dies before the capability check, so
 *   the capability mapping itself is proven by PHPUnit, not here.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Locator, Page } from '@playwright/test';
import { ADMIN_PAGE, unique } from './helpers';

/**
 * The table row whose rename input holds the given name.
 *
 * @param page Admin page on the settings screen.
 * @param name Segment name.
 */
function rowByName( page: Page, name: string ): Locator {
	return page
		.locator( 'table.widefat tbody tr' )
		.filter( { has: page.locator( `input[name="segment_name"]` ) } )
		.filter( {
			has: page.locator(
				`input[name="segment_name"][value="${ name }"]`
			),
		} );
}

/**
 * Read the ID, key and status cells of a row.
 *
 * @param row Table row.
 */
async function readRow(
	row: Locator
): Promise< { id: string; key: string; status: string } > {
	const cells = row.locator( 'td' );
	return {
		id: ( await cells.nth( 0 ).innerText() ).trim(),
		key: ( await cells.nth( 2 ).innerText() ).trim(),
		status: ( await cells.nth( 3 ).innerText() ).trim(),
	};
}

test.describe( 'segment admin', () => {
	test( 'Settings menu links to a page titled Content Gates', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'options-general.php' );
		const link = page
			.locator( '#menu-settings' )
			.getByRole( 'link', { name: 'Content Gates' } );
		await expect( link ).toBeVisible();
		await link.click();
		await expect( page ).toHaveURL( /page=content-gates/ );
		await expect(
			page.getByRole( 'heading', { level: 1, name: 'Content Gates' } )
		).toBeVisible();
		await expect( page.locator( 'table.widefat' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Add segment' } )
		).toBeVisible();
	} );

	test( 'create, rename, deactivate and reactivate keep ID and key', async ( {
		page,
	} ) => {
		const name = unique( 'Admin Segment' );
		await page.goto( ADMIN_PAGE );
		await page.locator( '#content-gates-new-name' ).fill( name );
		await page.getByRole( 'button', { name: 'Add segment' } ).click();
		await expect( page ).toHaveURL( /content_gates_notice=created/ );
		await expect( page.locator( '.notice-success' ) ).toContainText(
			'Segment created.'
		);

		const created = await readRow( rowByName( page, name ) );
		expect( created.id ).toMatch( /^[1-9]\d*$/ );
		expect( created.key ).toMatch( /^[A-Za-z0-9_-]+$/ );
		expect( created.status ).toBe( 'Active' );

		// Rename: same ID, same key.
		const renamed = unique( 'Renamed Segment' );
		const row = rowByName( page, name );
		await row.locator( 'input[name="segment_name"]' ).fill( renamed );
		await row.getByRole( 'button', { name: 'Rename' } ).click();
		await expect( page ).toHaveURL( /content_gates_notice=renamed/ );
		await expect( rowByName( page, name ) ).toHaveCount( 0 );
		const afterRename = await readRow( rowByName( page, renamed ) );
		expect( afterRename.id ).toBe( created.id );
		expect( afterRename.key ).toBe( created.key );
		expect( afterRename.status ).toBe( 'Active' );

		// Deactivate: Inactive, same ID; the page reload shows the saved state.
		await rowByName( page, renamed )
			.getByRole( 'button', { name: 'Deactivate' } )
			.click();
		await expect( page ).toHaveURL( /content_gates_notice=deactivated/ );
		await page.goto( ADMIN_PAGE );
		const afterDeactivate = await readRow( rowByName( page, renamed ) );
		expect( afterDeactivate.id ).toBe( created.id );
		expect( afterDeactivate.key ).toBe( created.key );
		expect( afterDeactivate.status ).toBe( 'Inactive' );

		// Reactivate: Active, same ID.
		await rowByName( page, renamed )
			.getByRole( 'button', { name: 'Activate' } )
			.click();
		await expect( page ).toHaveURL( /content_gates_notice=activated/ );
		await page.goto( ADMIN_PAGE );
		const afterActivate = await readRow( rowByName( page, renamed ) );
		expect( afterActivate.id ).toBe( created.id );
		expect( afterActivate.key ).toBe( created.key );
		expect( afterActivate.status ).toBe( 'Active' );
	} );

	test( 'the page offers no delete or trash control', async ( { page } ) => {
		await page.goto( ADMIN_PAGE );
		const wrap = page.locator( '#wpbody-content .wrap' );
		await expect( wrap ).toBeVisible();
		const text = await wrap.innerText();
		expect( text ).not.toMatch( /\b(delete|trash|bulk actions)\b/i );
		await expect( wrap.locator( 'input[type="checkbox"]' ) ).toHaveCount(
			0
		);
		await expect( wrap.locator( '.row-actions' ) ).toHaveCount( 0 );
	} );

	test( 'deletion is rejected through REST and post.php', async ( {
		page,
		request,
	} ) => {
		const name = unique( 'Undeletable' );
		await page.goto( ADMIN_PAGE );
		await page.locator( '#content-gates-new-name' ).fill( name );
		await page.getByRole( 'button', { name: 'Add segment' } ).click();
		await expect( page ).toHaveURL( /content_gates_notice=created/ );
		const { id } = await readRow( rowByName( page, name ) );

		// `show_in_rest => false`: there is no cg_segment route at all.
		for ( const method of [ 'DELETE', 'GET', 'POST' ] ) {
			const response = await request.fetch(
				`/wp-json/wp/v2/cg_segment/${ id }`,
				{ method, maxRedirects: 0 }
			);
			expect( response.status(), `${ method } cg_segment/${ id }` ).toBe(
				404
			);
			const body = ( await response.json() ) as { code?: string };
			expect( body.code ).toBe( 'rest_no_route' );
		}
		const collection = await request.get( '/wp-json/wp/v2/cg_segment', {
			maxRedirects: 0,
		} );
		expect( collection.status() ).toBe( 404 );

		// The posts endpoint must not expose the segment under another type.
		const asPost = await request.get( `/wp-json/wp/v2/posts/${ id }`, {
			maxRedirects: 0,
		} );
		expect( asPost.status() ).not.toBe( 200 );

		// Admin delete and trash links, as the admin user. Without the
		// per-post nonce WordPress dies with "link expired"; the point here
		// is that the record survives either way.
		for ( const action of [ 'delete', 'trash' ] ) {
			const response = await request.get(
				`/wp-admin/post.php?post=${ id }&action=${ action }`,
				{ maxRedirects: 0 }
			);
			const text = await response.text();
			const refused =
				response.status() === 403 ||
				/expired|not allowed/i.test( text );
			expect(
				refused,
				`post.php?action=${ action } returned ${ response.status() } and did not refuse`
			).toBe( true );
		}

		await page.goto( ADMIN_PAGE );
		const still = await readRow( rowByName( page, name ) );
		expect( still.id ).toBe( id );
		expect( still.status ).toBe( 'Active' );
	} );
} );
