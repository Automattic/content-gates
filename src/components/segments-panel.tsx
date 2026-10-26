/**
 * The "Segments" panel in a Group block's settings sidebar.
 */

import { InspectorControls } from '@wordpress/block-editor';
import {
	CheckboxControl,
	Notice,
	PanelBody,
	RadioControl,
	Spinner,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import {
	ATTR_MODE,
	ATTR_SEGMENTS,
	MODE_IN,
	MODE_NOT_IN,
	readMode,
	readSegments,
	type GateMode,
} from '../attributes';
import { buildOptions, toggleKey, useSegments } from '../segments';

export interface SegmentsPanelProps {
	attributes: Record< string, unknown >;
	setAttributes: ( attributes: Record< string, unknown > ) => void;
}

const MODE_OPTIONS = [
	{
		label: __( 'Readers in any selected segment', 'content-gates' ),
		value: MODE_IN,
	},
	{
		label: __(
			'Readers in none of the selected segments',
			'content-gates'
		),
		value: MODE_NOT_IN,
	},
];

function helpText( assigned: string[], mode: GateMode ): string {
	if ( assigned.length === 0 ) {
		return __(
			'This Group is unrestricted. Select a segment to limit who receives it.',
			'content-gates'
		);
	}

	if ( mode === MODE_NOT_IN ) {
		return __(
			'Readers in any selected segment receive no markup for this Group.',
			'content-gates'
		);
	}

	return __(
		'Readers outside the selected segments receive no markup for this Group.',
		'content-gates'
	);
}

/**
 * Render the Segments panel.
 *
 * @param props               Component props.
 * @param props.attributes    Block attributes.
 * @param props.setAttributes Block attribute setter.
 * @return The panel inside `InspectorControls`.
 */
export function SegmentsPanel( {
	attributes,
	setAttributes,
}: SegmentsPanelProps ): JSX.Element {
	const assigned = readSegments( attributes );
	const mode = readMode( attributes );
	const list = useSegments();
	const options = buildOptions( list.segments, assigned );

	const update = ( keys: string[], nextMode: GateMode ): void => {
		if ( keys.length === 0 ) {
			// No segments means unrestricted; reset the mode so the saved
			// block carries neither attribute.
			setAttributes( { [ ATTR_SEGMENTS ]: [], [ ATTR_MODE ]: MODE_IN } );
			return;
		}
		setAttributes( { [ ATTR_SEGMENTS ]: keys, [ ATTR_MODE ]: nextMode } );
	};

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Segments', 'content-gates' ) }
				className="content-gates-segments"
				initialOpen={ true }
			>
				<p className="content-gates-segments__help">
					{ helpText( assigned, mode ) }
				</p>

				{ list.status === 'loading' && <Spinner /> }

				{ list.status === 'error' && (
					<Notice status="error" isDismissible={ false }>
						{ list.message }
					</Notice>
				) }

				{ list.status === 'ready' && options.length === 0 && (
					<p className="content-gates-segments__empty">
						{ __(
							'No active segments. Create one under Settings → Content Gates.',
							'content-gates'
						) }
					</p>
				) }

				{ options.map( ( option ) => (
					<CheckboxControl
						key={ option.key }
						className="content-gates-segments__segment"
						label={ option.label }
						help={
							option.crawler
								? __(
										'Crawler segment: select it so search and AI crawlers receive this Group and the post carries paywall structured data for them.',
										'content-gates'
									)
								: undefined
						}
						checked={ option.checked }
						onChange={ ( checked ) =>
							update(
								toggleKey( assigned, option.key, checked ),
								mode
							)
						}
					/>
				) ) }

				<RadioControl
					className="content-gates-segments__mode"
					label={ __( 'Show to', 'content-gates' ) }
					selected={ mode }
					options={ MODE_OPTIONS }
					disabled={ assigned.length === 0 }
					onChange={ ( value ) =>
						update(
							assigned,
							value === MODE_NOT_IN ? MODE_NOT_IN : MODE_IN
						)
					}
				/>
			</PanelBody>
		</InspectorControls>
	);
}
