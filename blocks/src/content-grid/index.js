/**
 * Aladdin Evergreen Grid — block entry.
 *
 * Registers the aladdin-evergreen/content-grid block.
 * The SCSS imports here make wp-scripts emit the editor + frontend CSS bundles.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	// Server-side render — save returns null.
	save: () => null,
} );
