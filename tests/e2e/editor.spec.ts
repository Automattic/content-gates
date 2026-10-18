import {
	test,
	expect,
	type Editor,
} from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

interface SegmentRecord {
	id: number;
	name: string;
	active: boolean;
}

/**
 * Select the real Group and expose its block settings.
 *
 * @param page   Browser page.
 * @param editor WordPress editor utility.
 */
async function openSegmentControl( page: Page, editor: Editor ) {
	const group = editor.canvas.locator( '[data-type="core/group"]' ).first();
	await expect( group ).toBeVisible();
	await editor.selectBlocks( group );
	await editor.openDocumentSettingsSidebar();
	const blockTab = page.getByRole( 'tab', { name: 'Block', exact: true } );
	if ( await blockTab.isVisible() ) {
		await blockTab.click();
	}
	const select = page.getByRole( 'combobox', {
		name: 'Segment',
		exact: true,
	} );
	await expect( select ).toBeVisible();
	return select;
}

test( 'segment admin lifecycle and Group assignment survive save and reopen', async ( {
	page,
	admin,
	editor,
	requestUtils,
} ) => {
	test.setTimeout( 120_000 );
	const name = `Browser subscribers ${ Date.now() }`;
	const renamed = `${ name } renamed`;
	await admin.visitAdminPage( 'options-general.php', 'page=content-gates' );
	await expect(
		page.getByRole( 'heading', { name: 'Content Gates', exact: true } )
	).toBeVisible();
	await page.locator( '#segment-name-0' ).fill( name );
	await page
		.getByRole( 'button', { name: 'Add segment', exact: true } )
		.click();
	await expect( page ).toHaveURL( /page=content-gates/ );
	const segments: SegmentRecord[] = await requestUtils.rest( {
		path: '/content-gates/v1/segments',
	} );
	const segment = segments.find( ( candidate ) => candidate.name === name );
	expect( segment ).toBeDefined();
	if ( ! segment ) {
		throw new Error(
			'Created segment is missing from the metadata endpoint.'
		);
	}
	const id = String( segment.id );
	expect( segment.active ).toBe( true );
	const form = page
		.locator( 'form' )
		.filter( { has: page.locator( `#segment-name-${ id }` ) } );
	await form.getByLabel( 'Name', { exact: true } ).fill( renamed );
	await form
		.getByRole( 'button', { name: 'Save segment', exact: true } )
		.click();
	await page.reload();
	await expect( page.locator( `#segment-name-${ id }` ) ).toHaveValue(
		renamed
	);
	await expect(
		form.getByRole( 'button', { name: /delete|trash/i } )
	).toHaveCount( 0 );

	const post: { id: number } = await requestUtils.rest( {
		path: '/wp/v2/posts',
		method: 'POST',
		data: { title: name, status: 'draft', content: '' },
	} );
	await admin.editPost( post.id );
	await editor.insertBlock( {
		name: 'core/group',
		innerBlocks: [
			{
				name: 'core/paragraph',
				attributes: { content: 'Browser protected content' },
			},
		],
	} );
	let control = await openSegmentControl( page, editor );
	await expect( control ).toHaveValue( '' );
	await control.selectOption( { label: renamed } );
	await editor.saveDraft();
	let saved: { content: { raw: string } } = await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }`,
		params: { context: 'edit' },
	} );
	expect( saved.content.raw ).toContain( `"contentGateSegmentId":${ id }` );
	await admin.editPost( post.id );
	control = await openSegmentControl( page, editor );
	await expect( control ).toHaveValue( id );

	await admin.visitAdminPage( 'options-general.php', 'page=content-gates' );
	await form.getByLabel( 'Status', { exact: true } ).selectOption( 'draft' );
	await form
		.getByRole( 'button', { name: 'Save segment', exact: true } )
		.click();
	await page.reload();
	await expect( page.locator( `#segment-status-${ id }` ) ).toHaveValue(
		'draft'
	);
	await expect( page.locator( `#segment-name-${ id }` ) ).toHaveValue(
		renamed
	);
	await admin.editPost( post.id );
	control = await openSegmentControl( page, editor );
	await expect( control ).toHaveValue( id );
	await expect( control.locator( `option[value="${ id }"]` ) ).toHaveText(
		`${ renamed } (inactive)`
	);
	await expect( control.locator( `option[value="${ id }"]` ) ).toBeDisabled();
	await control.selectOption( '' );
	await expect( control.locator( `option[value="${ id }"]` ) ).toHaveCount(
		0
	);
	await editor.saveDraft();
	saved = await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }`,
		params: { context: 'edit' },
	} );
	expect( saved.content.raw ).not.toContain( 'contentGateSegmentId' );
	expect( saved.content.raw ).toContain( 'Browser protected content' );
	await admin.editPost( post.id );
	control = await openSegmentControl( page, editor );
	await expect( control ).toHaveValue( '' );
	await expect( control.locator( `option[value="${ id }"]` ) ).toHaveCount(
		0
	);

	await admin.visitAdminPage( 'options-general.php', 'page=content-gates' );
	await form
		.getByLabel( 'Status', { exact: true } )
		.selectOption( 'publish' );
	await form
		.getByRole( 'button', { name: 'Save segment', exact: true } )
		.click();
	await page.reload();
	await expect( page.locator( `#segment-status-${ id }` ) ).toHaveValue(
		'publish'
	);
	await admin.editPost( post.id );
	control = await openSegmentControl( page, editor );
	await expect( control.locator( `option[value="${ id }"]` ) ).toHaveText(
		renamed
	);
	await expect( control ).toHaveValue( '' );
} );
