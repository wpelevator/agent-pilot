import {
	InnerBlocks,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { createBlock, registerBlockType } from '@wordpress/blocks';
import {
	Button,
	PanelBody,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { cleanForSlug, safeDecodeURIComponent } from '@wordpress/url';

import InputControl from '../../components/input-control';
import SkillSectionPanel from '../../components/skill-section-panel';
import metadata from './block.json';
import './index.scss';
import PluginFilesPanel from './plugin-files-panel';
const CHILDREN = [
	'agent-pilot/agent-plugin-skill',
	'agent-pilot/agent-plugin-mcp-server',
];
const COMPONENT_BLOCKS = [
	{
		label: __( 'Add Skill', 'wpelevator-agent-pilot' ),
		name: 'agent-pilot/agent-plugin-skill',
	},
	{
		label: __( 'Add MCP Server', 'wpelevator-agent-pilot' ),
		name: 'agent-pilot/agent-plugin-mcp-server',
	},
];

function Edit( { attributes, clientId, setAttributes } ) {
	const { editPost } = useDispatch( editorStore );
	const { insertBlock } = useDispatch( blockEditorStore );
	const [ excerpt, setExcerpt ] = useEntityProp(
		'postType',
		'agent_plugin',
		'excerpt'
	);
	const { slug, title } = useSelect( ( select ) => {
		const { getEditedPostAttribute } = select( editorStore );
		return {
			slug: getEditedPostAttribute( 'slug' ),
			title: getEditedPostAttribute( 'title' ),
		};
	}, [] );
	const appendComponentBlock = ( blockName ) => {
		insertBlock( createBlock( blockName ), undefined, clientId );
	};
	return (
		<div { ...useBlockProps() }>
			<SkillSectionPanel
				title={ __( 'Agent Plugin', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody className="agent-pilot-agent-plugin__fields" opened>
					<InputControl
						label={ __( 'Name', 'wpelevator-agent-pilot' ) }
						value={ safeDecodeURIComponent( slug || '' ) }
						onChange={ ( value ) =>
							editPost( { slug: value || '' } )
						}
						onBlur={ ( event ) =>
							editPost( {
								slug: cleanForSlug( event.target.value ),
							} )
						}
						placeholder={ cleanForSlug( title || '' ) }
						help={ __(
							'Lowercase letters, numbers, hyphens, and periods.',
							'wpelevator-agent-pilot'
						) }
						required
					/>
					<TextareaControl
						label={ __( 'Description', 'wpelevator-agent-pilot' ) }
						value={ excerpt || '' }
						onChange={ setExcerpt }
					/>
					<TextControl
						label={ __( 'License', 'wpelevator-agent-pilot' ) }
						value={ attributes.license || '' }
						onChange={ ( license ) => setAttributes( { license } ) }
					/>
				</PanelBody>
			</SkillSectionPanel>
			<div className="agent-pilot-agent-plugin-components">
				<InnerBlocks
					allowedBlocks={ CHILDREN }
					templateLock={ false }
				/>
				<div
					className="agent-pilot-agent-plugin-component-actions"
					role="group"
					aria-label={ __(
						'Add plugin component',
						'wpelevator-agent-pilot'
					) }
				>
					{ COMPONENT_BLOCKS.map( ( componentBlock ) => (
						<Button
							key={ componentBlock.name }
							variant="secondary"
							onClick={ () =>
								appendComponentBlock( componentBlock.name )
							}
						>
							{ componentBlock.label }
						</Button>
					) ) }
				</div>
			</div>
		</div>
	);
}
function Save() {
	return (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	);
}
registerBlockType( metadata.name, { edit: Edit, save: Save } );

registerPlugin( 'agent-pilot-plugin-files', {
	render: PluginFilesPanel,
} );
