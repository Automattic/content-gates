import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { request } from '@playwright/test';

const segmentName = 'Prototype Subscribers';

async function createSegment( admin, page ): Promise< number > {
	await admin.visitAdminPage( 'options-general.php', 'page=content-gates' );

	const existingRow = page
		.locator( `input[name="segment_name"][value="${ segmentName }"]` )
		.locator( 'xpath=ancestor::tr' );
	if ( 0 === ( await existingRow.count() ) ) {
		await page
			.getByRole( 'heading', { name: 'Add segment' } )
			.locator( '..' )
			.getByRole( 'textbox', { name: 'Segment name' } )
			.fill( segmentName );
		await page.getByRole( 'button', { name: 'Add segment' } ).click();
	}

	const row = page
		.locator( `input[name="segment_name"][value="${ segmentName }"]` )
		.locator( 'xpath=ancestor::tr' );
	await expect( row ).toHaveCount( 1 );
	const idText = await row.locator( 'td' ).nth( 1 ).innerText();
	return Number( idText.trim() );
}

test.describe.serial( 'content gates prototype', () => {
	test( 'manages durable segments without deletion controls', async ( {
		admin,
		page,
	} ) => {
		const segmentId = await createSegment( admin, page );
		let row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );

		await expect( row ).toContainText( 'Active' );
		await expect(
			row.getByRole( 'button', { name: /delete|trash/i } )
		).toHaveCount( 0 );

		const renamedSegment = `${ segmentName } Renamed`;
		await row
			.getByRole( 'textbox', { name: 'Segment name' } )
			.fill( renamedSegment );
		await row.getByRole( 'button', { name: 'Save name' } ).click();
		await expect( page.getByText( 'Segment name saved.' ) ).toBeVisible();
		row = page
			.locator(
				`input[name="segment_name"][value="${ renamedSegment }"]`
			)
			.locator( 'xpath=ancestor::tr' );
		await expect( row.locator( 'td' ).nth( 1 ) ).toHaveText(
			String( segmentId )
		);
		await row
			.getByRole( 'textbox', { name: 'Segment name' } )
			.fill( segmentName );
		await row.getByRole( 'button', { name: 'Save name' } ).click();
		await expect( page.getByText( 'Segment name saved.' ) ).toBeVisible();
		row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );

		await row.getByRole( 'button', { name: 'Deactivate' } ).click();
		await expect( page.getByText( 'Segment deactivated.' ) ).toBeVisible();
		row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );
		await expect( row ).toContainText( 'Inactive' );
		await expect( row.locator( 'td' ).nth( 1 ) ).toHaveText(
			String( segmentId )
		);

		await row.getByRole( 'button', { name: 'Activate' } ).click();
		await expect( page.getByText( 'Segment activated.' ) ).toBeVisible();
		row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );
		await expect( row ).toContainText( 'Active' );
		await expect(
			page.getByRole( 'button', { name: /delete|trash/i } )
		).toHaveCount( 0 );
	} );

	test( 'assigns and removes membership through the protected REST endpoint', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const segmentId = await createSegment( admin, page );
		const { nonce, rootURL } = await requestUtils.setupRest();
		const endpoint = `${ rootURL }content-gates/v1/memberships/${ segmentId }`;
		const assignment = await requestUtils.request.post( endpoint, {
			data: { member: true },
			headers: { 'X-WP-Nonce': nonce },
		} );

		expect( assignment.status() ).toBe( 200 );
		expect( assignment.headers()[ 'cache-control' ] ).toContain(
			'no-store'
		);
		expect( await assignment.json() ).toMatchObject( {
			api_version: '1.0',
			segment_id: segmentId,
			member: true,
		} );
		let storageState = await requestUtils.request.storageState();
		let membershipCookie = storageState.cookies.find(
			( cookie ) => 'vip-go-seg' === cookie.name
		);
		expect( membershipCookie?.value ).toContain(
			`cg_1_${ segmentId }_--_1`
		);

		const removal = await requestUtils.request.post( endpoint, {
			data: { member: false },
			headers: { 'X-WP-Nonce': nonce },
		} );
		expect( removal.status() ).toBe( 200 );
		storageState = await requestUtils.request.storageState();
		membershipCookie = storageState.cookies.find(
			( cookie ) => 'vip-go-seg' === cookie.name
		);
		expect( membershipCookie ).toBeUndefined();
	} );

	test( 'persists Group assignment and clears it with No segment', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		const segmentId = await createSegment( admin, page );
		const post = await requestUtils.createPost( {
			status: 'draft',
			date_gmt: new Date().toISOString(),
			title: 'Content Gates editor assignment',
			content:
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Editor marker</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
		} );

		await admin.editPost( post.id );
		await editor.canvas.locator( '.wp-block-group' ).first().click();
		await page
			.getByRole( 'button', { name: 'Select parent block: Group' } )
			.click();
		const segmentSelect = page.getByRole( 'combobox', {
			name: 'Segment',
		} );
		await expect( segmentSelect ).toHaveValue( '' );

		await segmentSelect.selectOption( String( segmentId ) );
		await editor.saveDraft();
		await admin.editPost( post.id );
		await editor.canvas.locator( '.wp-block-group' ).first().click();
		await page
			.getByRole( 'button', { name: 'Select parent block: Group' } )
			.click();
		await expect(
			page.getByRole( 'combobox', { name: 'Segment' } )
		).toHaveValue( String( segmentId ) );
		await expect( await editor.getEditedPostContent() ).toContain(
			`"contentGateSegmentId":${ segmentId }`
		);

		await admin.visitAdminPage(
			'options-general.php',
			'page=content-gates'
		);
		let row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );
		await row.getByRole( 'button', { name: 'Deactivate' } ).click();
		await admin.editPost( post.id );
		await editor.canvas.locator( '.wp-block-group' ).first().click();
		await page
			.getByRole( 'button', { name: 'Select parent block: Group' } )
			.click();
		await expect(
			page
				.getByRole( 'combobox', { name: 'Segment' } )
				.locator( `option[value="${ segmentId }"]` )
		).toHaveText( `${ segmentName } (inactive)` );

		await admin.visitAdminPage(
			'options-general.php',
			'page=content-gates'
		);
		row = page
			.locator( `input[name="segment_name"][value="${ segmentName }"]` )
			.locator( 'xpath=ancestor::tr' );
		await row.getByRole( 'button', { name: 'Activate' } ).click();
		await admin.editPost( post.id );
		await editor.canvas.locator( '.wp-block-group' ).first().click();
		await page
			.getByRole( 'button', { name: 'Select parent block: Group' } )
			.click();

		await page
			.getByRole( 'combobox', { name: 'Segment' } )
			.selectOption( '' );
		await editor.saveDraft();
		await admin.editPost( post.id );
		await editor.canvas.locator( '.wp-block-group' ).first().click();
		await page
			.getByRole( 'button', { name: 'Select parent block: Group' } )
			.click();
		await expect(
			page.getByRole( 'combobox', { name: 'Segment' } )
		).toHaveValue( '' );
		await expect( await editor.getEditedPostContent() ).not.toContain(
			'contentGateSegmentId'
		);
	} );

	test( 'withholds restricted bytes from page, REST, and feed responses', async ( {
		admin,
		page,
		requestUtils,
		baseURL,
	} ) => {
		const segmentId = await createSegment( admin, page );
		const groupKey = `cg_1_${ segmentId }`;
		const restrictedMarker = `RESTRICTED_${ segmentId }_9f76c8`;
		const post = await requestUtils.createPost( {
			status: 'publish',
			date_gmt: new Date().toISOString(),
			title: 'Content Gates raw response',
			content: `<!-- wp:paragraph --><p>PUBLIC_${ segmentId }</p><!-- /wp:paragraph --><!-- wp:group {"contentGateSegmentId":${ segmentId }} --><div class="wp-block-group"><!-- wp:paragraph --><p>${ restrictedMarker }</p><!-- /wp:paragraph --></div><!-- /wp:group -->`,
		} );

		const anonymous = await request.newContext( { baseURL } );
		const deniedPage = await anonymous.get( post.link );
		expect( await deniedPage.text() ).not.toContain( restrictedMarker );
		expect( deniedPage.headers()[ 'x-content-gate-vary' ] ).toBe(
			groupKey
		);

		const deniedRest = await anonymous.get(
			`/?rest_route=/wp/v2/posts/${ post.id }`
		);
		const deniedRestBody = await deniedRest.text();
		expect( deniedRestBody ).not.toContain( restrictedMarker );
		expect( deniedRest.headers()[ 'x-content-gate-vary' ] ).toBe(
			groupKey
		);

		const deniedFeed = await anonymous.get( '/?feed=rss2' );
		expect( await deniedFeed.text() ).not.toContain( restrictedMarker );
		expect( deniedFeed.headers()[ 'x-content-gate-vary' ] ).toBe(
			groupKey
		);

		const entitled = await request.newContext( {
			baseURL,
			extraHTTPHeaders: {
				Cookie: `vip-go-seg=vc-v1__${ groupKey }_--_1`,
			},
		} );
		const allowedPage = await entitled.get( post.link );
		expect( await allowedPage.text() ).toContain( restrictedMarker );
		expect( allowedPage.headers()[ 'x-content-gate-vary' ] ).toBe(
			groupKey
		);

		const allowedRest = await entitled.get(
			`/?rest_route=/wp/v2/posts/${ post.id }`
		);
		expect( await allowedRest.text() ).toContain( restrictedMarker );

		const allowedFeed = await entitled.get( '/?feed=rss2' );
		expect( await allowedFeed.text() ).toContain( restrictedMarker );
		expect( allowedFeed.headers()[ 'x-content-gate-vary' ] ).toBe(
			groupKey
		);

		await anonymous.dispose();
		await entitled.dispose();
	} );

	test( 'leaves ungated responses on the shared variant', async ( {
		baseURL,
		requestUtils,
	} ) => {
		const post = await requestUtils.createPost( {
			status: 'publish',
			date_gmt: new Date().toISOString(),
			title: 'Ungated response',
			content:
				'<!-- wp:paragraph --><p>Ungated marker</p><!-- /wp:paragraph -->',
		} );
		const context = await request.newContext( { baseURL } );
		const response = await context.get( post.link );

		expect( await response.text() ).toContain( 'Ungated marker' );
		expect( response.headers() ).not.toHaveProperty(
			'x-content-gate-vary'
		);
		await context.dispose();
	} );
} );
