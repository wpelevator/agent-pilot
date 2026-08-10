import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { registerPlugin } from '@wordpress/plugins';

import metadata from './block.json';
import Edit from './edit';
import './index.scss';
import SkillFilesPanel from './skill-files-panel';

function Save() {
	return (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: Save,
} );

registerPlugin( 'agent-pilot-skill-file', {
	render: SkillFilesPanel,
} );
