import { useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import FilenameControl from '../../components/filename-control';
import SkillSectionPanel from '../../components/skill-section-panel';

const SKILL_SPEC_SCRIPTS_URL = 'https://agentskills.io/specification#scripts';

const ScriptCodeEditor = lazy( () => import( './code-editor' ) );

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( {
		className: 'agent-pilot-skill-script',
	} );
	const filenameControl = (
		<FilenameControl
			directory="scripts"
			label={ __( 'Filename', 'wpelevator-agent-pilot' ) }
			help={ __(
				'Use a filename without slashes. The scripts/ directory prefix is added automatically.',
				'wpelevator-agent-pilot'
			) }
			value={ attributes.fileName }
			onChange={ ( fileName ) => setAttributes( { fileName } ) }
			required
		/>
	);
	const codeLabel = __( 'Script content', 'wpelevator-agent-pilot' );
	const codePlaceholder = __( 'Write script…', 'wpelevator-agent-pilot' );
	const codeControl = (
		<Suspense
			fallback={
				<textarea
					aria-label={ codeLabel }
					className="agent-pilot-skill-script__textarea"
					placeholder={ codePlaceholder }
					readOnly
					value={ attributes.content || '' }
				/>
			}
		>
			<ScriptCodeEditor
				fileName={ attributes.fileName }
				label={ codeLabel }
				value={ attributes.content }
				onChange={ ( content ) => setAttributes( { content } ) }
				placeholder={ codePlaceholder }
			/>
		</Suspense>
	);

	return (
		<div { ...blockProps }>
			<SkillSectionPanel
				learnMoreUrl={ SKILL_SPEC_SCRIPTS_URL }
				resourcePath={
					attributes.fileName
						? `scripts/${ attributes.fileName }`
						: ''
				}
				title={ __( 'Script', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody className="agent-pilot-skill-script__fields" opened>
					{ filenameControl }
					{ codeControl }
				</PanelBody>
			</SkillSectionPanel>
		</div>
	);
}
