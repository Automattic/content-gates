const { InspectorControls } = window.wp.blockEditor;
const { SelectControl, PanelBody } = window.wp.components;
const { createHigherOrderComponent } = window.wp.compose;
const { createElement, Fragment } = window.wp.element;
const { addFilter } = window.wp.hooks;
const { __, sprintf } = window.wp.i18n;

const attributeName = 'contentGateSegmentId';
const settings = window.contentGatesEditorSettings ?? { segments: [] };

function addSegmentAttribute( blockSettings, blockName ) {
	if ( blockName !== 'core/group' ) {
		return blockSettings;
	}

	return {
		...blockSettings,
		attributes: {
			...blockSettings.attributes,
			[ attributeName ]: { type: 'number' },
		},
	};
}

const withSegmentControl = createHigherOrderComponent(
	BlockEdit => props => {
		if ( props.name !== 'core/group' ) {
			return createElement( BlockEdit, props );
		}

		const selectedId = props.attributes[ attributeName ];
		const activeSegments = settings.segments.filter( segment => segment.active );
		const selectedInactive = settings.segments.find( segment => segment.id === selectedId && ! segment.active );
		const options = [
			{ label: __( 'No segment', 'content-gates' ), value: '' },
			...activeSegments.map( segment => ( {
				label: segment.name,
				value: String( segment.id ),
			} ) ),
		];

		if ( selectedInactive ) {
			options.push( {
				label: sprintf(
					/* translators: %s: segment name. */
					__( '%s (Inactive)', 'content-gates' ),
					selectedInactive.name
				),
				value: String( selectedInactive.id ),
				disabled: true,
			} );
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
						value: selectedId ? String( selectedId ) : '',
						options,
						onChange: value =>
							props.setAttributes( {
								[ attributeName ]: value === '' ? undefined : Number( value ),
							} ),
						help: __( 'Restricted Group content is omitted from responses for readers outside this segment.', 'content-gates' ),
					} )
				)
			)
		);
	},
	'withContentGateSegmentControl'
);

addFilter( 'blocks.registerBlockType', 'content-gates/segment-attribute', addSegmentAttribute );
addFilter( 'editor.BlockEdit', 'content-gates/segment-control', withSegmentControl );
