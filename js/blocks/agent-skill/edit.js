import {
	InnerBlocks,
	store as blockEditorStore,
	useBlockProps,
} from '@wordpress/block-editor';
import { createBlock } from '@wordpress/blocks';
import { Button, PanelBody, TextareaControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { info } from '@wordpress/icons';
import { cleanForSlug, safeDecodeURIComponent } from '@wordpress/url';

import InputControl from '../../components/input-control';
import SkillSectionPanel from '../../components/skill-section-panel';

const ALLOWED_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/code',
	'core/preformatted',
	'core/quote',
	'core/separator',
	'core/image',
	'agent-pilot/agent-skill-script',
	'agent-pilot/agent-skill-reference',
	'agent-pilot/agent-skill-asset',
];
const RESOURCE_BLOCKS = [
	{
		label: __( 'Add Reference', 'wpelevator-agent-pilot' ),
		name: 'agent-pilot/agent-skill-reference',
	},
	{
		label: __( 'Add Script', 'wpelevator-agent-pilot' ),
		name: 'agent-pilot/agent-skill-script',
	},
	{
		label: __( 'Add File', 'wpelevator-agent-pilot' ),
		name: 'agent-pilot/agent-skill-asset',
	},
];
const DESCRIPTION_LIMIT = 1024;
const COMPATIBILITY_LIMIT = 500;
const COMPATIBILITY_META_KEY = 'agent_pilot__compatibility';
const POST_TYPE = 'agent_skill';
const SKILL_SPEC_FRONTMATTER_URL =
	'https://agentskills.io/specification#frontmatter';
const SKILL_SPEC_INSTRUCTIONS_URL =
	'https://agentskills.io/specification#body-content';

function SkillSpecInfoLink( { href } ) {
	return (
		<Button
			className="agent-pilot-skill-spec-link"
			href={ href }
			icon={ info }
			label={ __( 'Learn more', 'wpelevator-agent-pilot' ) }
			onClick={ ( event ) => event.stopPropagation() }
			rel="external noreferrer noopener"
			showTooltip
			size="compact"
			target="_blank"
			variant="tertiary"
		/>
	);
}

function appendRequiredIndicator( label ) {
	const suffix = `(${ __( 'Required', 'wpelevator-agent-pilot' ) })`;

	if ( typeof label === 'string' ) {
		return `${ label } ${ suffix }`;
	}

	return (
		<>
			{ label } { suffix }
		</>
	);
}

function SkillEditor( { clientId } ) {
	// `getEditedPostSlug()` falls back to the post ID for a slugless draft,
	// which is meaningless as a skill name, so read the attribute itself.
	const { slug, title } = useSelect( ( select ) => {
		const { getEditedPostAttribute } = select( editorStore );
		return {
			slug: getEditedPostAttribute( 'slug' ),
			title: getEditedPostAttribute( 'title' ),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const { insertBlock } = useDispatch( blockEditorStore );
	const [ excerpt, setExcerpt ] = useEntityProp(
		'postType',
		POST_TYPE,
		'excerpt'
	);
	const [ meta = {}, setMeta ] = useEntityProp(
		'postType',
		POST_TYPE,
		'meta'
	);
	const description = excerpt || '';
	const compatibility = meta[ COMPATIBILITY_META_KEY ] || '';

	const setCompatibility = ( value ) => {
		setMeta( {
			...meta,
			[ COMPATIBILITY_META_KEY ]: value,
		} );
	};
	const appendResourceBlock = ( blockName ) => {
		insertBlock( createBlock( blockName ), undefined, clientId );
	};

	return (
		<>
			<SkillSectionPanel
				learnMoreUrl={ SKILL_SPEC_FRONTMATTER_URL }
				title={ __( 'Skill Front Matter', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody className="agent-pilot-skill-fields" opened>
					<InputControl
						label={ appendRequiredIndicator(
							__( 'Name', 'wpelevator-agent-pilot' )
						) }
						value={ safeDecodeURIComponent( slug || '' ) }
						onChange={ ( value ) => {
							editPost( { slug: value || '' } );
						} }
						// Sanitize on blur so hyphens survive while typing.
						onBlur={ ( event ) => {
							editPost( {
								slug: cleanForSlug( event.target.value ),
							} );
						} }
						required
						placeholder={ cleanForSlug( title || '' ) }
						help={ __(
							'Skill name using lowercase letters, numbers, and hyphens (similar to post slugs).',
							'wpelevator-agent-pilot'
						) }
						autoComplete="off"
						spellCheck="false"
					/>
					<TextareaControl
						label={ appendRequiredIndicator(
							__( 'Description', 'wpelevator-agent-pilot' )
						) }
						rows="2"
						value={ description }
						onChange={ setExcerpt }
						required
						maxLength={ DESCRIPTION_LIMIT }
						help={ __(
							'Explain what this skill does and when to use it. Used by agents to determine when to use the skill.',
							'wpelevator-agent-pilot'
						) }
					/>
					<TextareaControl
						label={ __(
							'Compatibility',
							'wpelevator-agent-pilot'
						) }
						rows="2"
						value={ compatibility }
						onChange={ setCompatibility }
						maxLength={ COMPATIBILITY_LIMIT }
						help={ __(
							'Describe optional environment requirements, such as required tools, network access, or intended agent.',
							'wpelevator-agent-pilot'
						) }
					/>
				</PanelBody>
			</SkillSectionPanel>
			<fieldset className="agent-pilot-skill-fieldset agent-pilot-skill-instructions">
				<legend className="agent-pilot-skill-section-title">
					<span>
						{ appendRequiredIndicator(
							__( 'Skill Instructions', 'wpelevator-agent-pilot' )
						) }
					</span>
					<SkillSpecInfoLink href={ SKILL_SPEC_INSTRUCTIONS_URL } />
				</legend>
				<div className="agent-pilot-skill-instructions-content">
					<InnerBlocks
						allowedBlocks={ ALLOWED_BLOCKS }
						template={ [ [ 'core/paragraph' ] ] }
						templateLock={ false }
					/>
				</div>
				<div
					className="agent-pilot-skill-resource-actions"
					role="group"
					aria-label={ __(
						'Add skill resource',
						'wpelevator-agent-pilot'
					) }
				>
					{ RESOURCE_BLOCKS.map( ( resourceBlock ) => (
						<Button
							key={ resourceBlock.name }
							variant="secondary"
							onClick={ () =>
								appendResourceBlock( resourceBlock.name )
							}
						>
							{ resourceBlock.label }
						</Button>
					) ) }
				</div>
			</fieldset>
		</>
	);
}

export default function Edit( { clientId } ) {
	return (
		<div { ...useBlockProps( { className: 'agent-pilot-skill-editor' } ) }>
			<SkillEditor clientId={ clientId } />
		</div>
	);
}
