/**
 * Aladdin Evergreen Grid — block entry.
 *
 * Registers the aladdin-evergreen/content-grid block.
 */
import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	// Server-side render — save returns null.
	save: () => null,
} );
