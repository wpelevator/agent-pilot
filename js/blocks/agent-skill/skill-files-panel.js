import { Button, ExternalLink, Flex, FlexItem } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

const POST_TYPE = 'agent_skill';

export default function SkillFilesPanel() {
	const { fileUrl, zipUrl, postStatus, postType } = useSelect( ( select ) => {
		const { getCurrentPost, getCurrentPostType, getEditedPostAttribute } =
			select( editorStore );
		const post = getCurrentPost();

		return {
			fileUrl: post?.skill_file_url || '',
			zipUrl: post?.skill_zip_url || '',
			postStatus: getEditedPostAttribute( 'status' ),
			postType: getCurrentPostType(),
		};
	}, [] );

	if ( POST_TYPE !== postType || 'auto-draft' === postStatus ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="agent-pilot-skill-files"
			title={ __( 'Agent Skill', 'wpelevator-agent-pilot' ) }
			className="agent-pilot-skill-files-panel"
		>
			<Flex align="center" justify="space-between">
				<FlexItem className="editor-post-panel__row-label">
					{ __( 'Skill File', 'wpelevator-agent-pilot' ) }
				</FlexItem>
				<FlexItem className="editor-post-panel__row-control">
					{ fileUrl ? (
						<ExternalLink href={ fileUrl } title={ fileUrl }>
							SKILL.md
						</ExternalLink>
					) : (
						<Button
							disabled
							accessibleWhenDisabled
							variant="link"
							text="SKILL.md"
							title={ __(
								'Save with a valid name to generate the skill file URL.',
								'wpelevator-agent-pilot'
							) }
						/>
					) }
				</FlexItem>
			</Flex>
			<Flex align="center" justify="space-between">
				<FlexItem className="editor-post-panel__row-label">
					{ __( 'Skill Archive', 'wpelevator-agent-pilot' ) }
				</FlexItem>
				<FlexItem className="editor-post-panel__row-control">
					{ zipUrl ? (
						<ExternalLink href={ zipUrl } title={ zipUrl }>
							skill.zip
						</ExternalLink>
					) : (
						<Button
							disabled
							accessibleWhenDisabled
							variant="link"
							text="skill.zip"
							title={ __(
								'Save with a valid name to generate the skill archive URL.',
								'wpelevator-agent-pilot'
							) }
						/>
					) }
				</FlexItem>
			</Flex>
		</PluginDocumentSettingPanel>
	);
}
