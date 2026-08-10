import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import 'codemirror/lib/codemirror.css';
import './index.scss';

function Save( { attributes } ) {
	return (
		<pre { ...useBlockProps.save() }>
			<code>{ attributes.content }</code>
		</pre>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: Save,
} );
