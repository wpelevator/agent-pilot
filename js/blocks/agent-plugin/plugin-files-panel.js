import { Button, ExternalLink, Flex, FlexItem } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

const POST_TYPE = 'agent_plugin';

function ArtifactLink( { fileName, label, unavailableTitle, url } ) {
	return (
		<Flex align="center" justify="space-between">
			<FlexItem className="editor-post-panel__row-label">
				{ label }
			</FlexItem>
			<FlexItem className="editor-post-panel__row-control">
				{ url ? (
					<ExternalLink href={ url } title={ url }>
						{ fileName }
					</ExternalLink>
				) : (
					<Button
						disabled
						accessibleWhenDisabled
						variant="link"
						text={ fileName }
						title={ unavailableTitle }
					/>
				) }
			</FlexItem>
		</Flex>
	);
}

export default function PluginFilesPanel() {
	const { manifestUrl, mcpUrl, packageUrl, postStatus, postType } = useSelect(
		( select ) => {
			const {
				getCurrentPost,
				getCurrentPostType,
				getEditedPostAttribute,
			} = select( editorStore );
			const post = getCurrentPost();

			return {
				manifestUrl: post?.plugin_manifest_url || '',
				mcpUrl: post?.plugin_mcp_url || '',
				packageUrl: post?.plugin_package_url || '',
				postStatus: getEditedPostAttribute( 'status' ),
				postType: getCurrentPostType(),
			};
		},
		[]
	);

	if ( POST_TYPE !== postType || 'auto-draft' === postStatus ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="agent-pilot-plugin-files"
			title={ __( 'Agent Plugin', 'wpelevator-agent-pilot' ) }
			className="agent-pilot-plugin-files-panel"
		>
			<ArtifactLink
				fileName="plugin.json"
				label={ __( 'Plugin Manifest', 'wpelevator-agent-pilot' ) }
				unavailableTitle={ __(
					'Save with a valid name to generate the plugin manifest URL.',
					'wpelevator-agent-pilot'
				) }
				url={ manifestUrl }
			/>
			<ArtifactLink
				fileName="mcp.json"
				label={ __( 'MCP Configuration', 'wpelevator-agent-pilot' ) }
				unavailableTitle={ __(
					'Add an MCP server and save to generate the MCP configuration URL.',
					'wpelevator-agent-pilot'
				) }
				url={ mcpUrl }
			/>
			<ArtifactLink
				fileName="plugin.zip"
				label={ __( 'Plugin Archive', 'wpelevator-agent-pilot' ) }
				unavailableTitle={ __(
					'Save with a valid name to generate the plugin archive URL.',
					'wpelevator-agent-pilot'
				) }
				url={ packageUrl }
			/>
		</PluginDocumentSettingPanel>
	);
}
