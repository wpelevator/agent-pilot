import { Button, Panel, PanelHeader } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { check, copy, info } from '@wordpress/icons';

import './skill-section-panel.scss';

function copyTextToClipboard( text ) {
	if ( window.navigator?.clipboard?.writeText ) {
		return window.navigator.clipboard
			.writeText( text )
			.then( () => true )
			.catch( () => fallbackCopyTextToClipboard( text ) );
	}

	return Promise.resolve( fallbackCopyTextToClipboard( text ) );
}

function truncateMiddle( value, maxLength = 56 ) {
	if ( value.length <= maxLength ) {
		return value;
	}

	const ellipsis = '...';
	const visibleLength = maxLength - ellipsis.length;
	const startLength = Math.ceil( visibleLength / 2 );
	const endLength = Math.floor( visibleLength / 2 );

	return `${ value.slice( 0, startLength ) }${ ellipsis }${ value.slice(
		-endLength
	) }`;
}

function fallbackCopyTextToClipboard( text ) {
	const textarea = document.createElement( 'textarea' );

	textarea.value = text;
	textarea.setAttribute( 'readonly', '' );
	textarea.style.left = '-9999px';
	textarea.style.position = 'fixed';
	document.body.appendChild( textarea );
	textarea.select();

	try {
		return document.execCommand( 'copy' );
	} finally {
		document.body.removeChild( textarea );
	}
}

export default function SkillSectionPanel( {
	children,
	className = '',
	learnMoreUrl,
	resourcePath = '',
	title,
} ) {
	const [ isCopied, setIsCopied ] = useState( false );
	const displayedResourcePath = truncateMiddle( resourcePath );
	const panelClassName = [ 'agent-pilot-skill-section-panel', className ]
		.filter( Boolean )
		.join( ' ' );
	const copyTimeout = useRef();

	useEffect( () => {
		setIsCopied( false );
	}, [ resourcePath ] );

	useEffect( () => {
		return () => {
			if ( copyTimeout.current ) {
				clearTimeout( copyTimeout.current );
			}
		};
	}, [] );

	const copyResourcePath = () => {
		if ( ! resourcePath ) {
			return;
		}

		copyTextToClipboard( resourcePath ).then( ( didCopy ) => {
			if ( ! didCopy ) {
				return;
			}

			setIsCopied( true );

			if ( copyTimeout.current ) {
				clearTimeout( copyTimeout.current );
			}

			copyTimeout.current = setTimeout( () => {
				setIsCopied( false );
			}, 4000 );
		} );
	};

	return (
		<Panel className={ panelClassName }>
			<PanelHeader>
				<div className="agent-pilot-skill-section-panel__heading">
					<h2>{ title }</h2>
					{ resourcePath && (
						<span className="agent-pilot-skill-section-panel__resource-path">
							<code title={ resourcePath }>
								{ displayedResourcePath }
							</code>
							<Button
								className="agent-pilot-skill-section-panel__copy-button"
								icon={ isCopied ? check : copy }
								label={
									isCopied
										? __(
												'Copied',
												'wpelevator-agent-pilot'
										  )
										: sprintf(
												/* translators: %s: resource path. */
												__(
													'Copy %s',
													'wpelevator-agent-pilot'
												),
												resourcePath
										  )
								}
								onClick={ copyResourcePath }
								showTooltip
								size="compact"
								variant="link"
							/>
						</span>
					) }
				</div>
				{ learnMoreUrl && (
					<Button
						className="agent-pilot-skill-section-panel__icon-button"
						href={ learnMoreUrl }
						icon={ info }
						label={ __( 'Learn more', 'wpelevator-agent-pilot' ) }
						rel="external noreferrer noopener"
						showTooltip
						size="compact"
						target="_blank"
						variant="tertiary"
					/>
				) }
			</PanelHeader>
			{ children }
		</Panel>
	);
}
