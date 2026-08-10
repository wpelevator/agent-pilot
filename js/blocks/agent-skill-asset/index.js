import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './index.scss';

registerBlockType( metadata.name, {
	edit: Edit,
} );
