import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { anonymous, reader } from './helpers';

/**
 * The Segments control on Group blocks, end to end: assign in the editor,
 * save, reopen, and verify delivery.
 */
test.describe( 'editor assignment', () => {
	const marker = `EDITOR-GATED-${ Date.now() }`;

	test( 'assigns a segment to a Group and the front end honors it', async ( {
		admin,
		editor,
		page,
		baseURL,
	} ) => {
		await admin.createNewPost( { title: `Editor gating ${ Date.now() }` } );
		await editor.insertBlock( {
			name: 'core/group',
			innerBlocks: [
				{ name: 'core/paragraph', attributes: { content: marker } },
			],
		} );
		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="core/group"]' )
		);
		await editor.openDocumentSettingsSidebar();

		const panel = page.locator( '.content-gates-segments' );
		if ( ! ( await panel.isVisible() ) ) {
			await page.getByRole( 'button', { name: 'Segments' } ).click();
		}
		await expect( panel ).toBeVisible();
		// Same order as the settings page: by type, then key. Segments left
		// behind by the admin spec are ignored.
		const labels = await panel
			.getByRole( 'checkbox' )
			.evaluateAll( ( nodes ) =>
				nodes.map(
					( node ) =>
						document
							.querySelector( `label[for="${ node.id }"]` )
							?.textContent?.trim() ?? ''
				)
			);
		expect(
			labels.filter( ( label ) => ! label.startsWith( 'E2E Segment' ) )
		).toEqual( [
			'Donor',
			'Newsletter',
			'Subscriber',
			'AI crawlers',
			'Search crawlers',
			'Metered articles',
		] );
		await panel
			.getByRole( 'checkbox', { name: 'Subscriber', exact: true } )
			.check();

		const postId = await editor.publishPost();
		expect( postId ).toBeTruthy();

		// Attributes persist and the declared set is stored.
		await page.reload();
		await editor.selectBlocks(
			editor.canvas.locator( '[data-type="core/group"]' )
		);
		const blocks = await editor.getBlocks();
		const group = blocks.find( ( block ) => block.name === 'core/group' );
		expect( group?.attributes.contentGatesSegments ).toEqual( [
			'subscriber',
		] );
		expect( group?.attributes.contentGatesMode ).toBe( 'in' );

		// Delivery.
		const link = await page.evaluate( () =>
			( window as any ).wp.data.select( 'core/editor' ).getPermalink()
		);
		const anon = await anonymous( baseURL! );
		const anonResponse = await anon.get( link );
		expect( await anonResponse.text() ).not.toContain( marker );
		expect(
			anonResponse.headers()[ 'x-wpvip-content-gates-declared' ]
		).toContain( 'subscriber' );
		await anon.dispose();

		const sub = await reader( baseURL!, { subscriber: '1' } );
		expect( await ( await sub.get( link ) ).text() ).toContain( marker );
		await sub.dispose();

		// Flip to not-in and clear; attributes change in the editor state.
		await page
			.locator( '.content-gates-segments' )
			.getByRole( 'radio', { name: /none of the selected/ } )
			.check();
		const flipped = ( await editor.getBlocks() ).find(
			( block ) => block.name === 'core/group'
		);
		expect( flipped?.attributes.contentGatesMode ).toBe( 'not-in' );

		await page
			.locator( '.content-gates-segments' )
			.getByRole( 'checkbox', { name: 'Subscriber', exact: true } )
			.uncheck();
		const cleared = ( await editor.getBlocks() ).find(
			( block ) => block.name === 'core/group'
		);
		expect( cleared?.attributes.contentGatesSegments ).toEqual( [] );
		expect( cleared?.attributes.contentGatesMode ).toBe( 'in' );
	} );

	test( 'a save with an unknown key is rejected', async ( {
		requestUtils,
	} ) => {
		const content =
			'<!-- wp:group {"contentGatesSegments":["ghost"]} --><div class="wp-block-group"><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		await expect(
			requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: { title: 'Bad key', status: 'draft', content },
			} )
		).rejects.toMatchObject( { code: 'content_gates_invalid_segment' } );
	} );
} );
