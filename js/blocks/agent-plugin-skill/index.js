import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import SkillSectionPanel from '../../components/skill-section-panel';
import PostSelector from '../agent-skill-reference/post-selector';
import metadata from './block.json';

const AGENT_SKILLS_SPECIFICATION_URL = 'https://agentskills.io/specification';

function Edit( { attributes, setAttributes } ) {
	return (
		<div
			{ ...useBlockProps( {
				className: 'agent-pilot-agent-plugin-skill',
			} ) }
		>
			<SkillSectionPanel
				learnMoreUrl={ AGENT_SKILLS_SPECIFICATION_URL }
				title={ __( 'Plugin Skill', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody opened>
					<PostSelector
						label={ __(
							'Agent Plugin Skill',
							'wpelevator-agent-pilot'
						) }
						help={ __(
							'Search for an Agent Skill to include in this plugin.',
							'wpelevator-agent-pilot'
						) }
						postId={ attributes.skillId }
						subtype="agent_skill"
						onChange={ ( skillId ) =>
							setAttributes( { skillId: skillId || undefined } )
						}
					/>
				</PanelBody>
			</SkillSectionPanel>
		</div>
	);
}
registerBlockType( metadata.name, { edit: Edit, save: () => null } );
