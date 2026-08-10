import {
	BlockControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import FilenameControl from '../../components/filename-control';
import SkillSectionPanel from '../../components/skill-section-panel';

const SKILL_SPEC_ASSETS_URL = 'https://agentskills.io/specification#assets';

function getFileName( media ) {
	if ( media.filename ) {
		return media.filename;
	}

	const url = media.url || media.source_url || '';

	return decodeURIComponent( url.split( '/' ).pop() || '' );
}

export default function Edit( { attributes, setAttributes } ) {
	const isPlaceholder = ! attributes.attachmentId;
	const blockProps = useBlockProps( {
		className: 'agent-pilot-skill-asset',
	} );
	const attachment = useSelect(
		( select ) =>
			attributes.attachmentId
				? select( 'core' ).getMedia( attributes.attachmentId )
				: null,
		[ attributes.attachmentId ]
	);
	const selectAttachment = ( media ) =>
		setAttributes( {
			attachmentId: Number( media.id ) || 0,
			fileName: getFileName( media ),
		} );
	const filenameControl = (
		<FilenameControl
			className="agent-pilot-skill-asset__filename-control"
			directory="assets"
			label={ __( 'Filename', 'wpelevator-agent-pilot' ) }
			value={ attributes.fileName }
			disabled
			readOnly
		/>
	);
	const renderAssetContent = ( actions ) => (
		<div className="agent-pilot-skill-asset__content">
			{ filenameControl }
			{ actions && (
				<div className="agent-pilot-skill-asset__actions">
					{ actions }
				</div>
			) }
		</div>
	);

	return (
		<div { ...blockProps }>
			{ ! isPlaceholder && (
				<BlockControls group="other">
					<MediaReplaceFlow
						allowedTypes={ [] }
						mediaId={ attributes.attachmentId }
						mediaURL={ attachment?.source_url || '' }
						onSelect={ selectAttachment }
						variant="toolbar"
					/>
				</BlockControls>
			) }
			<SkillSectionPanel
				learnMoreUrl={ SKILL_SPEC_ASSETS_URL }
				resourcePath={
					attributes.fileName ? `assets/${ attributes.fileName }` : ''
				}
				title={ __( 'Asset', 'wpelevator-agent-pilot' ) }
			>
				<PanelBody opened>
					{ isPlaceholder ? (
						<MediaPlaceholder
							allowedTypes={ [] }
							multiple={ false }
							onSelect={ selectAttachment }
							placeholder={ renderAssetContent }
						/>
					) : (
						renderAssetContent()
					) }
				</PanelBody>
			</SkillSectionPanel>
		</div>
	);
}
