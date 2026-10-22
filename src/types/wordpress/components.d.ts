/**
 * Minimal `@wordpress/components` declarations (see ./element.d.ts).
 */

import type { ComponentType, ReactNode } from '@wordpress/element';

export const PanelBody: ComponentType< {
	title?: string;
	className?: string;
	initialOpen?: boolean;
	children?: ReactNode;
} >;

export const CheckboxControl: ComponentType< {
	label: string;
	help?: ReactNode;
	checked: boolean;
	className?: string;
	onChange: ( checked: boolean ) => void;
} >;

export interface RadioControlOption {
	label: string;
	value: string;
}

export const RadioControl: ComponentType< {
	label: string;
	help?: ReactNode;
	selected: string;
	options: RadioControlOption[];
	disabled?: boolean;
	className?: string;
	onChange: ( value: string ) => void;
} >;

export const Spinner: ComponentType< Record< string, never > >;

export const Notice: ComponentType< {
	status?: 'error' | 'warning' | 'info' | 'success';
	isDismissible?: boolean;
	className?: string;
	children?: ReactNode;
} >;
