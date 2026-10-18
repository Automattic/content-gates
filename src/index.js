import apiFetch from '@wordpress/api-fetch';
import { InspectorControls } from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';

addFilter(
	'blocks.registerBlockType',
	'content-gates/segment-attribute',
	( settings, name ) => {
		if ( name !== 'core/group' ) {
			return settings;
		}
		return {
			...settings,
			attributes: {
				...settings.attributes,
				contentGateSegmentId: { type: 'number' },
			},
		};
	}
);

function SegmentControl( { attributes, setAttributes } ) {
	const [ segments, setSegments ] = useState( null );
	const [ failed, setFailed ] = useState( false );
	useEffect( () => {
		let mounted = true;
		apiFetch( { path: '/content-gates/v1/segments' } ).then(
			( result ) => mounted && setSegments( result ),
			() => mounted && setFailed( true )
		);
		return () => {
			mounted = false;
		};
	}, [] );
	const selected = attributes.contentGateSegmentId;
	const options = [
		{ label: __( 'No segment', 'content-gates' ), value: '' },
	];
	if ( segments ) {
		segments
			.filter( ( segment ) => segment.active || segment.id === selected )
			.forEach( ( segment ) => {
				options.push( {
					label: segment.active
						? segment.name
						: sprintf(
								/* translators: %s: Segment name. */
								__( '%s (inactive)', 'content-gates' ),
								segment.name
							),
					value: String( segment.id ),
					disabled: ! segment.active,
				} );
			} );
		if (
			selected !== undefined &&
			selected !== null &&
			! segments.some( ( segment ) => segment.id === selected )
		) {
			options.push( {
				label: sprintf(
					/* translators: %s: Missing segment identifier. */
					__( 'Unknown segment #%s', 'content-gates' ),
					String( selected )
				),
				value: String( selected ),
				disabled: true,
			} );
		}
	}
	return (
		<PanelBody title={ __( 'Content Gates', 'content-gates' ) }>
			{ failed && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Segments could not be loaded. Your assignment has not changed. Reopen the editor to retry.',
						'content-gates'
					) }
				</Notice>
			) }
			{ ! segments && ! failed && <Spinner /> }
			{ segments && (
				<SelectControl
					label={ __( 'Segment', 'content-gates' ) }
					value={
						selected === undefined || selected === null
							? ''
							: String( selected )
					}
					options={ options }
					help={ __(
						'Only readers in this segment can receive this Group. Inactive or unknown assignments remain restricted.',
						'content-gates'
					) }
					onChange={ ( value ) =>
						setAttributes( {
							contentGateSegmentId:
								value === '' ? undefined : Number( value ),
						} )
					}
				/>
			) }
		</PanelBody>
	);
}

addFilter(
	'editor.BlockEdit',
	'content-gates/segment-control',
	createHigherOrderComponent(
		( BlockEdit ) => ( props ) => (
			<>
				<BlockEdit { ...props } />
				{ props.name === 'core/group' && props.isSelected && (
					<InspectorControls>
						<SegmentControl { ...props } />
					</InspectorControls>
				) }
			</>
		),
		'withContentGateSegment'
	)
);
