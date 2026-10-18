/**
 * Add segment assignment to core Group blocks.
 */

( function () {
	'use strict';

	const attributeName = 'contentGateSegmentId';
	const segments =
		window.contentGatesEditor &&
		Array.isArray( window.contentGatesEditor.segments )
			? window.contentGatesEditor.segments
			: [];
	const { InspectorControls } = window.wp.blockEditor;
	const { PanelBody, SelectControl } = window.wp.components;
	const { createHigherOrderComponent } = window.wp.compose;
	const { createElement, Fragment } = window.wp.element;
	const { addFilter } = window.wp.hooks;
	const { __, sprintf } = window.wp.i18n;

	/**
	 * Register the persisted segment post ID.
	 *
	 * @param {Object} settings Block settings.
	 * @param {string} name     Block name.
	 * @return {Object} Block settings.
	 */
	function addSegmentAttribute( settings, name ) {
		if ( 'core/group' !== name ) {
			return settings;
		}

		return {
			...settings,
			attributes: {
				...settings.attributes,
				[ attributeName ]: {
					type: 'integer',
				},
			},
		};
	}

	/**
	 * Build options for one block instance.
	 *
	 * @param {number|undefined} selectedId Current segment ID.
	 * @return {Array<Object>} Select options.
	 */
	function getOptions( selectedId ) {
		const options = [
			{
				label: __( 'No segment', 'content-gates' ),
				value: '',
			},
		];
		const selected = Number( selectedId );

		segments.forEach( ( segment ) => {
			const id = Number( segment.id );
			if ( segment.active ) {
				options.push( { label: segment.name, value: String( id ) } );
			} else if ( id === selected ) {
				options.push( {
					label: sprintf(
						/* translators: %s: segment name. */
						__( '%s (inactive)', 'content-gates' ),
						segment.name
					),
					value: String( id ),
				} );
			}
		} );

		if (
			Number.isInteger( selected ) &&
			0 < selected &&
			! segments.some( ( segment ) => Number( segment.id ) === selected )
		) {
			options.push( {
				label: sprintf(
					/* translators: %d: missing segment ID. */
					__( 'Segment #%d (unavailable)', 'content-gates' ),
					selected
				),
				value: String( selected ),
			} );
		}

		return options;
	}

	const withSegmentControl = createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			if ( 'core/group' !== props.name ) {
				return createElement( BlockEdit, props );
			}

			const selectedId = props.attributes[ attributeName ];

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
							value: selectedId ? String( selectedId ) : '',
							options: getOptions( selectedId ),
							onChange: ( value ) => {
								props.setAttributes( {
									[ attributeName ]: value
										? Number( value )
										: undefined,
								} );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				)
			);
		},
		'withContentGateSegmentControl'
	);

	addFilter(
		'blocks.registerBlockType',
		'content-gates/group-segment-attribute',
		addSegmentAttribute
	);
	addFilter(
		'editor.BlockEdit',
		'content-gates/group-segment-control',
		withSegmentControl
	);
} )();
