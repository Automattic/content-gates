import { request as playwrightRequest } from '@playwright/test';
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

interface CreatedPost {
	id: number;
	link: string;
}

test.describe( 'segment gating', () => {
	test( 'persists a segment and withholds content until assignment', async ( { editor, page, requestUtils } ) => {
		const segmentName = `Prototype Subscribers ${ Date.now() }`;

		await page.goto( '/wp-admin/options-general.php?page=content-gates' );
		await expect( page.getByRole( 'heading', { name: 'Content Gates', level: 1 } ) ).toBeVisible();
		await page.getByLabel( 'Name', { exact: true } ).last().fill( segmentName );
		await page.getByRole( 'button', { name: 'Add segment' } ).click();

		const segmentRow = page.getByRole( 'row' ).filter( {
			has: page.locator( `input[name="segment_name"][value="${ segmentName }"]` ),
		} );
		await expect( segmentRow ).toBeVisible();
		const segmentId = Number( ( await segmentRow.getByRole( 'cell' ).first().textContent() )?.trim() );
		expect( segmentId ).toBeGreaterThan( 0 );

		const publicMarker = 'CONTENT_GATES_PUBLIC_E2E';
		const protectedMarker = 'CONTENT_GATES_PROTECTED_E2E';
		const content =
			`<!-- wp:paragraph --><p>${ publicMarker }</p><!-- /wp:paragraph -->` +
			`<!-- wp:group {"contentGateSegmentId":${ segmentId }} --><div class="wp-block-group"><!-- wp:paragraph --><p>${ protectedMarker }</p><!-- /wp:paragraph --></div><!-- /wp:group -->`;
		const post = ( await requestUtils.createPost( {
			title: 'Content Gates E2E fixture',
			content,
			status: 'publish',
			date_gmt: new Date().toISOString(),
		} ) ) as CreatedPost;

		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		const welcomeDialog = page.getByRole( 'dialog', { name: 'Welcome to the editor' } );
		if ( await welcomeDialog.isVisible() ) {
			await welcomeDialog.getByRole( 'button', { name: 'Close' } ).click();
		}
		await expect( page.locator( '#content-gates-editor-js' ) ).toHaveCount( 1 );
		const assignedGroup = editor.canvas.locator( '[data-type="core/group"]' ).filter( {
			hasText: protectedMarker,
		} );
		await expect( assignedGroup ).toBeVisible();
		await editor.selectBlocks( assignedGroup );
		await expect( page.getByRole( 'combobox', { name: 'Segment' } ) ).toHaveValue( String( segmentId ) );

		const anonymous = await playwrightRequest.newContext( {
			baseURL: new URL( post.link ).origin,
		} );

		const anonymousPage = await anonymous.get( post.link );
		expect( anonymousPage.status() ).toBe( 200 );
		const anonymousHtml = await anonymousPage.text();
		expect( anonymousHtml ).toContain( publicMarker );
		expect( anonymousHtml ).not.toContain( protectedMarker );
		expect( anonymousPage.headers()[ 'x-content-gates-vary' ] ).toBe( `cg-${ segmentId }` );

		const anonymousRest = await anonymous.get( `/wp-json/wp/v2/posts/${ post.id }` );
		expect( anonymousRest.status() ).toBe( 200 );
		expect( await anonymousRest.text() ).not.toContain( protectedMarker );

		const anonymousFeed = await anonymous.get( '/?feed=rss2' );
		expect( anonymousFeed.status() ).toBe( 200 );
		expect( await anonymousFeed.text() ).not.toContain( protectedMarker );

		const assignment = await requestUtils.rest( {
			method: 'POST',
			path: `/content-gates/v1/memberships/${ segmentId }`,
			data: { active: true },
		} );
		expect( assignment ).toMatchObject( {
			contract_version: '1',
			segment_id: segmentId,
			active: true,
			applies_on: 'next_request',
		} );

		const entitledPage = await requestUtils.request.get( post.link );
		expect( entitledPage.status() ).toBe( 200 );
		expect( await entitledPage.text() ).toContain( protectedMarker );

		await anonymous.dispose();
	} );
} );
