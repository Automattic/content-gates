/**
 * Block editor acceptance tests.
 *
 * Criteria covered (spec/editor-ui.md "Finish Line / Verification",
 * Editor behavior; spec/segment-admin.md, Admin behavior bullet 2):
 * - A selected Group block shows a "Content Gates" panel with a "Segment"
 *   dropdown that offers "No segment" plus the active segments by name,
 *   and not an inactive segment.
 * - Choosing a segment and saving persists the post ID in the Group's
 *   `contentGatesSegmentId` attribute; reopening shows the same choice.
 * - Choosing "No segment" removes the attribute after save and reopen.
 * - A new Group starts with no segment assigned.
 * - Deactivating an assigned segment keeps the assignment, shows it as
 *   "<name> (inactive)", and keeps it out of new assignment choices.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type {
	Editor,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import type { Locator, Page } from '@playwright/test';
import { ATTRIBUTE, createSegment, setSegmentActive, unique } from './helpers';

/**
 * Select the first Group block in the canvas and open its inspector.
 *
 * @param editor Editor utils.
 * @param page   Editor page.
 * @return The Segment select control.
 */
async function openGroupInspector(
	editor: Editor,
	page: Page
): Promise< Locator > {
	const groupBlock = editor.canvas
		.locator( '[data-type="core/group"]' )
		.first();
	await groupBlock.waitFor();
	await editor.selectBlocks( groupBlock );
	await editor.openDocumentSettingsSidebar();

	const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
	const blockTab = sidebar.getByRole( 'tab', { name: 'Block' } );
	if ( await blockTab.isVisible() ) {
		await blockTab.click();
	}

	const panel = sidebar.getByRole( 'button', {
		name: 'Content Gates',
		exact: true,
	} );
	await expect( panel ).toBeVisible();
	if ( ( await panel.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await panel.click();
	}

	const select = sidebar.getByRole( 'combobox', { name: 'Segment' } );
	await expect( select ).toBeVisible();
	return select;
}

/**
 * Save a post that is already published and wait for the notice.
 *
 * `editor.publishPost()` only handles the first publish; a later save
 * uses the top bar's Save/Update button and shows "updated".
 *
 * @param page Editor page.
 */
async function saveChanges( page: Page ): Promise< void > {
	const topBar = page.getByRole( 'region', { name: 'Editor top bar' } );
	await topBar.getByRole( 'button', { name: /^(Save|Update)$/ } ).click();
	await page
		.getByRole( 'button', { name: 'Dismiss this notice' } )
		.filter( { hasText: /updated|saved/i } )
		.waitFor();
}

/**
 * The first top-level Group block's attributes from the editor store.
 *
 * @param editor Editor utils.
 */
async function groupAttributes(
	editor: Editor
): Promise< Record< string, unknown > > {
	const blocks = await editor.getBlocks();
	const groupBlock = blocks.find( ( block ) => block.name === 'core/group' );
	expect( groupBlock, 'a core/group block exists' ).toBeDefined();
	return groupBlock?.attributes ?? {};
}

/**
 * The saved block markup of a post, straight from the database.
 *
 * @param requestUtils WordPress request utils (admin).
 * @param postId       Post ID.
 */
async function rawContent(
	requestUtils: RequestUtils,
	postId: number
): Promise< string > {
	const post = await requestUtils.rest< { content: { raw: string } } >( {
		path: `/wp/v2/posts/${ postId }`,
		params: { context: 'edit' },
	} );
	return post.content.raw;
}

test.describe( 'editor', () => {
	test( 'Segment dropdown lists active segments, persists the choice, and marks an inactive assignment', async ( {
		admin,
		editor,
		page,
		request,
		requestUtils,
	} ) => {
		const active = await createSegment(
			request,
			unique( 'Editor Active' )
		);
		const inactive = await createSegment(
			request,
			unique( 'Editor Inactive' )
		);
		await setSegmentActive( request, inactive.id, false );

		await admin.createNewPost( { title: unique( 'e2e-editor' ) } );
		await editor.insertBlock( {
			name: 'core/group',
			innerBlocks: [
				{ name: 'core/paragraph', attributes: { content: 'x' } },
			],
		} );

		// A new Group starts with no segment.
		expect(
			( await groupAttributes( editor ) )[ ATTRIBUTE ]
		).toBeUndefined();

		let select = await openGroupInspector( editor, page );
		await expect( select ).toHaveValue( '' );
		let labels = await select.locator( 'option' ).allTextContents();
		expect( labels ).toContain( 'No segment' );
		expect( labels ).toContain( active.name );
		expect( labels.join( '\n' ) ).not.toContain( inactive.name );

		// Choose the active segment, publish, reopen.
		await select.selectOption( { label: active.name } );
		expect( ( await groupAttributes( editor ) )[ ATTRIBUTE ] ).toBe(
			active.id
		);
		const postId = await editor.publishPost();
		expect( postId ).not.toBeNull();

		await admin.editPost( postId as number );
		select = await openGroupInspector( editor, page );
		await expect( select ).toHaveValue( String( active.id ) );
		expect( ( await groupAttributes( editor ) )[ ATTRIBUTE ] ).toBe(
			active.id
		);

		expect( await rawContent( requestUtils, postId as number ) ).toContain(
			`"${ ATTRIBUTE }":${ active.id }`
		);

		// Clear with "No segment", save, reopen: the attribute is gone.
		await select.selectOption( { label: 'No segment' } );
		expect(
			( await groupAttributes( editor ) )[ ATTRIBUTE ]
		).toBeUndefined();
		await saveChanges( page );
		await admin.editPost( postId as number );
		select = await openGroupInspector( editor, page );
		await expect( select ).toHaveValue( '' );
		expect(
			( await groupAttributes( editor ) )[ ATTRIBUTE ]
		).toBeUndefined();

		expect(
			await rawContent( requestUtils, postId as number )
		).not.toContain( ATTRIBUTE );

		// Assign again, then deactivate the segment in admin and reopen:
		// the assignment survives and is marked inactive.
		await select.selectOption( { label: active.name } );
		await saveChanges( page );
		await setSegmentActive( request, active.id, false );
		expect( await rawContent( requestUtils, postId as number ) ).toContain(
			`"${ ATTRIBUTE }":${ active.id }`
		);

		await admin.editPost( postId as number );
		select = await openGroupInspector( editor, page );
		await expect( select ).toHaveValue( String( active.id ) );
		const selected = select.locator( 'option:checked' );
		await expect( selected ).toHaveText( `${ active.name } (inactive)` );
		expect( ( await groupAttributes( editor ) )[ ATTRIBUTE ] ).toBe(
			active.id
		);
		labels = await select.locator( 'option' ).allTextContents();
		// Offered once, as the current value only, never as a plain choice.
		expect(
			labels.filter( ( label ) => label.includes( active.name ) )
		).toEqual( [ `${ active.name } (inactive)` ] );
	} );
} );
