import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

function resultToOption( result ) {
	return {
		label:
			decodeEntities( result.title ) ||
			__( '(no title)', 'wpelevator-agent-pilot' ),
		value: String( result.id ),
	};
}

export default function PostSelector( {
	help = __(
		'Use an existing post or page instead of the custom content below.',
		'wpelevator-agent-pilot'
	),
	label = __( 'Use Existing Content', 'wpelevator-agent-pilot' ),
	postId,
	onChange,
	subtype,
} ) {
	const [ selectedOption, setSelectedOption ] = useState( null );
	const [ searchOptions, setSearchOptions ] = useState( [] );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ isResolvingSelection, setIsResolvingSelection ] = useState( false );
	const [ isSearching, setIsSearching ] = useState( false );

	useEffect( () => {
		let isCurrent = true;

		if ( ! postId ) {
			setSelectedOption( null );
			return () => {
				isCurrent = false;
			};
		}

		setIsResolvingSelection( true );
		apiFetch( {
			path: addQueryArgs( '/wp/v2/search', {
				include: postId,
				per_page: 1,
				type: 'post',
				_fields: 'id,title,url,subtype',
				...( subtype ? { subtype } : {} ),
			} ),
		} )
			.then( ( results ) => {
				if ( isCurrent ) {
					setSelectedOption(
						results[ 0 ] ? resultToOption( results[ 0 ] ) : null
					);
				}
			} )
			.catch( () => {
				if ( isCurrent ) {
					setSelectedOption( null );
				}
			} )
			.finally( () => {
				if ( isCurrent ) {
					setIsResolvingSelection( false );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ postId, subtype ] );

	useEffect( () => {
		let isCurrent = true;
		const timeoutId = setTimeout( () => {
			setIsSearching( true );
			apiFetch( {
				path: addQueryArgs( '/wp/v2/search', {
					search: searchTerm,
					per_page: 20,
					type: 'post',
					_fields: 'id,title,url,subtype',
					...( subtype ? { subtype } : {} ),
				} ),
			} )
				.then( ( results ) => {
					if ( isCurrent ) {
						setSearchOptions( results.map( resultToOption ) );
					}
				} )
				.catch( () => {
					if ( isCurrent ) {
						setSearchOptions( [] );
					}
				} )
				.finally( () => {
					if ( isCurrent ) {
						setIsSearching( false );
					}
				} );
		}, 250 );

		return () => {
			isCurrent = false;
			clearTimeout( timeoutId );
		};
	}, [ searchTerm, subtype ] );

	const options = selectedOption
		? [
				selectedOption,
				...searchOptions.filter(
					( option ) => option.value !== selectedOption.value
				),
		  ]
		: searchOptions;

	return (
		<ComboboxControl
			label={ label }
			help={ help }
			value={ postId ? String( postId ) : null }
			options={ options }
			onFilterValueChange={ setSearchTerm }
			onChange={ ( value ) => onChange( Number( value ) || 0 ) }
			placeholder={ __(
				'Search posts, pages, and other content…',
				'wpelevator-agent-pilot'
			) }
			isLoading={ isResolvingSelection || isSearching }
		/>
	);
}
