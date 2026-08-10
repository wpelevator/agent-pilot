import { InnerBlocks, useBlockProps } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import FilenameControl from '../../components/filename-control';
import SkillSectionPanel from '../../components/skill-section-panel';
import PostSelector from './post-selector';

const DEFAULT_FORMAT = 'md';
const SKILL_SPEC_REFERENCES_URL =
	'https://agentskills.io/specification#references';
const FORMAT_OPTIONS = [
	{
		a11yLabel: __( 'Markdown (.md)', 'wpelevator-agent-pilot' ),
		label: '.md',
		value: 'md',
	},
	{
		a11yLabel: __( 'HTML (.html)', 'wpelevator-agent-pilot' ),
		label: '.html',
		value: 'html',
	},
];
const FORMAT_VALUES = FORMAT_OPTIONS.map( ( option ) => option.value );

const getFormat = ( fileName, format = DEFAULT_FORMAT ) => {
	const extension = ( fileName || '' )
		.toLowerCase()
		.match( /\.([a-z]+)$/ )?.[ 1 ];

	if ( FORMAT_VALUES.includes( extension ) ) {
		return extension;
	}

	return FORMAT_VALUES.includes( format ) ? format : DEFAULT_FORMAT;
};

const getFileName = ( fileName ) =>
	( fileName || '' )
		.toLowerCase()
		.split( '.' )[ 0 ]
		.replace( /[^a-z-]/g, '' );

export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps( {
		className: 'agent-pilot-skill-reference',
	} );
	const rawFileName = attributes.fileName || '';
	const rawFormat = attributes.format || DEFAULT_FORMAT;
	const format = getFormat( rawFileName, rawFormat );
	const fileName = getFileName( rawFileName );
	const [ isCustomContentOpen, setIsCustomContentOpen ] = useState(
		! attributes.postId
	);

	useEffect( () => {
		if ( rawFileName !== fileName || rawFormat !== format ) {
			setAttributes( { fileName, format } );
		}
	}, [ fileName, format, rawFileName, rawFormat, setAttributes ] );

	useEffect( () => {
		setIsCustomContentOpen( ! attributes.postId );
	}, [ attributes.postId ] );

	return (
		<div { ...blockProps }>
			<SkillSectionPanel
				learnMoreUrl={ SKILL_SPEC_REFERENCES_URL }
				resourcePath={
					fileName ? `references/${ fileName }.${ format }` : ''
				}
				title={ __( 'Reference', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody
					className="agent-pilot-skill-reference__content"
					opened
				>
					<FilenameControl
						directory="references"
						suffixLabel={ __(
							'Reference Format',
							'wpelevator-agent-pilot'
						) }
						suffixOptions={ FORMAT_OPTIONS }
						suffixValue={ format }
						label={ __(
							'Reference Filename',
							'wpelevator-agent-pilot'
						) }
						value={ fileName }
						onChange={ ( nextFileName ) =>
							setAttributes( {
								fileName: getFileName( nextFileName ),
								format: getFormat( nextFileName, format ),
							} )
						}
						onSuffixChange={ ( nextFormat ) =>
							setAttributes( {
								fileName,
								format: getFormat( '', nextFormat ),
							} )
						}
						required
					/>
					<PostSelector
						postId={ attributes.postId }
						onChange={ ( postId ) => setAttributes( { postId } ) }
					/>
				</PanelBody>
				<PanelBody
					onToggle={ setIsCustomContentOpen }
					opened={ isCustomContentOpen }
					title={ __( 'Custom Content', 'wpelevator-agent-pilot' ) }
				>
					<div className="agent-pilot-skill-reference__custom-content">
						<InnerBlocks templateLock={ false } />
					</div>
				</PanelBody>
			</SkillSectionPanel>
		</div>
	);
}
