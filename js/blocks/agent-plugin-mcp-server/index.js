import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { BaseControl, PanelBody, TextControl } from '@wordpress/components';
import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import SkillSectionPanel from '../../components/skill-section-panel';
import metadata from './block.json';
import 'codemirror/lib/codemirror.css';
import '../../components/code-editor.scss';
import './index.scss';

const MCP_SERVER_AUTHORING_URL =
	'https://agent-plugins.org/plugin-authors/mcp-servers';
const DEFAULT_MCP_SERVER_DEFINITION = `{
  "type": "streamable-http",
  "url": "https://..."
}`;

const CodeEditor = lazy( () => import( '../agent-skill-script/code-editor' ) );

function Edit( { attributes, setAttributes } ) {
	const definitionLabel = __(
		'Server definition (JSON)',
		'wpelevator-agent-pilot'
	);
	const definitionHelp = __(
		'Agent Pilot preserves this JSON object without validating its MCP schema. Do not include secrets.',
		'wpelevator-agent-pilot'
	);
	const definitionPlaceholder = DEFAULT_MCP_SERVER_DEFINITION;

	return (
		<div
			{ ...useBlockProps( {
				className: 'agent-pilot-agent-plugin-mcp-server',
			} ) }
		>
			<SkillSectionPanel
				learnMoreUrl={ MCP_SERVER_AUTHORING_URL }
				title={ __(
					'Agent Plugin MCP Server',
					'wpelevator-agent-pilot'
				) }
			>
				<PanelBody
					className="agent-pilot-agent-plugin-mcp-server__fields"
					opened
				>
					<TextControl
						label={ __( 'Server name', 'wpelevator-agent-pilot' ) }
						value={ attributes.name || '' }
						onChange={ ( name ) => setAttributes( { name } ) }
					/>
					<BaseControl
						className="agent-pilot-agent-plugin-mcp-server__definition"
						help={ definitionHelp }
					>
						<BaseControl.VisualLabel>
							{ definitionLabel }
						</BaseControl.VisualLabel>
						<Suspense
							fallback={
								<textarea
									aria-label={ definitionLabel }
									className="agent-pilot-code-editor__textarea"
									placeholder={ definitionPlaceholder }
									readOnly
									value={ attributes.definition || '{}' }
								/>
							}
						>
							<CodeEditor
								fileName="mcp.json"
								label={ definitionLabel }
								onChange={ ( definition ) =>
									setAttributes( { definition } )
								}
								placeholder={ definitionPlaceholder }
								value={ attributes.definition || '{}' }
							/>
						</Suspense>
					</BaseControl>
				</PanelBody>
			</SkillSectionPanel>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
