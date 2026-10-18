/**
 * Content Gates block editor integration.
 *
 * Adds the `contentGatesSegmentId` attribute to `core/group` and a Segment
 * dropdown to the Group block inspector. Plain script: no JSX, no build
 * step. The server registers the same attribute through
 * `register_block_type_args` and passes the segment list in
 * `window.contentGatesEditor`.
 *
 * The dropdown offers "No segment" plus the active segments. An assignment
 * to an inactive or unknown segment stays visible as the current value so
 * nothing is dropped silently; it is not offered for new assignments.
 */

/**
 * @param {Object} wp The WordPress global.
 */
( function ( wp ) {
	const { addFilter } = wp.hooks;
	const { createHigherOrderComponent } = wp.compose;
	const { createElement, Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;
	const { __, sprintf } = wp.i18n;

	const GROUP = 'core/group';
	const ATTRIBUTE = 'contentGatesSegmentId';

	const data = window.contentGatesEditor || {};
	const segments = Array.isArray( data.segments ) ? data.segments : [];

	/**
	 * Register the attribute on the client so the editor keeps it.
	 *
	 * @param {Object} settings Block settings.
	 * @param {string} name     Block name.
	 * @return {Object} Settings with the attribute added for core/group.
	 */
	function addSegmentAttribute( settings, name ) {
		if ( name !== GROUP ) {
			return settings;
		}

		const attributes = Object.assign( {}, settings.attributes );
		attributes[ ATTRIBUTE ] = { type: 'integer' };

		return Object.assign( {}, settings, { attributes } );
	}

	addFilter(
		'blocks.registerBlockType',
		'content-gates/segment-attribute',
		addSegmentAttribute
	);

	/**
	 * Build the dropdown options for the current assignment.
	 *
	 * @param {number|undefined} current The assigned segment ID, if any.
	 * @return {Array} SelectControl options.
	 */
	function segmentOptions( current ) {
		const options = [
			{ value: '', label: __( 'No segment', 'content-gates' ) },
		];

		segments.forEach( ( segment ) => {
			if ( segment.active ) {
				options.push( {
					value: String( segment.id ),
					label: segment.name,
				} );
			}
		} );

		if ( current === undefined || current === null ) {
			return options;
		}

		const assigned = segments.find( ( segment ) => segment.id === current );

		if ( assigned && ! assigned.active ) {
			options.push( {
				value: String( current ),
				label: sprintf(
					/* translators: %s: segment name */
					__( '%s (inactive)', 'content-gates' ),
					assigned.name
				),
			} );
		} else if ( ! assigned ) {
			options.push( {
				value: String( current ),
				label: sprintf(
					/* translators: %d: segment ID */
					__( 'Unknown segment #%d', 'content-gates' ),
					current
				),
			} );
		}

		return options;
	}

	const withSegmentControl = createHigherOrderComponent( ( BlockEdit ) => {
		return function ContentGatesSegmentControl( props ) {
			if ( props.name !== GROUP ) {
				return createElement( BlockEdit, props );
			}

			const current = props.attributes[ ATTRIBUTE ];
			const value =
				current === undefined || current === null
					? ''
					: String( current );

			/**
			 * Store the selection as an integer, or remove the attribute.
			 *
			 * @param {string} next The selected option value.
			 */
			function onChange( next ) {
				const change = {};
				change[ ATTRIBUTE ] =
					next === '' ? undefined : parseInt( next, 10 );
				props.setAttributes( change );
			}

			return createElement(
				Fragment,
				null,
				createElement( BlockEdit, props ),
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{
							title: __( 'Content Gates', 'content-gates' ),
							initialOpen: true,
						},
						createElement( SelectControl, {
							label: __( 'Segment', 'content-gates' ),
							value,
							options: segmentOptions( current ),
							onChange,
							help: __(
								'Readers outside this segment receive the page without this Group.',
								'content-gates'
							),
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
						} )
					)
				)
			);
		};
	}, 'withContentGatesSegmentControl' );

	addFilter(
		'editor.BlockEdit',
		'content-gates/segment-control',
		withSegmentControl
	);
} )( window.wp );
