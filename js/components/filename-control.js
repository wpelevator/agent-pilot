import { useEffect, useRef } from '@wordpress/element';

import InputControl, {
	InputControlPrefixWrapper,
	InputControlSuffixWrapper,
} from './input-control';

const FILENAME_WITH_EXTENSION_PATTERN = '[A-Za-z0-9][A-Za-z0-9._\\-]*';

function getPatternMatcher( pattern ) {
	if ( ! pattern ) {
		return null;
	}

	try {
		return new RegExp( `^(?:${ pattern })$` );
	} catch {
		return null;
	}
}

function FilenameSuffixControl( {
	disabled = false,
	isSuffixSelectTabbable = true,
	label,
	onChange,
	options = [],
	value,
} ) {
	if ( ! options.length ) {
		return null;
	}

	if ( 1 === options.length ) {
		return (
			<span className="agent-pilot-filename-control__suffix-label">
				{ options[ 0 ].label }
			</span>
		);
	}

	const handleOnChange = ( event ) => {
		const nextValue = event.target.value;
		const data = options.find( ( option ) => option.value === nextValue );

		onChange?.( nextValue, { event, data } );
	};

	return (
		<select
			aria-label={ label }
			className="agent-pilot-filename-control__suffix-select"
			disabled={ disabled }
			onChange={ handleOnChange }
			tabIndex={ isSuffixSelectTabbable ? undefined : -1 }
			value={ value }
		>
			{ options.map( ( option ) => (
				<option
					aria-label={ option.a11yLabel }
					key={ option.value }
					value={ option.value }
				>
					{ option.label }
				</option>
			) ) }
		</select>
	);
}

export default function FilenameControl( {
	directory,
	disabled = false,
	isSuffixSelectTabbable,
	label,
	onChange,
	onSuffixChange,
	suffix,
	suffixLabel,
	suffixOptions,
	suffixValue,
	pattern,
	value,
	__unstableStateReducer: stateReducer,
	...controlProps
} ) {
	const hasSuffixOptions = Boolean( suffixOptions?.length );
	const lastAllowedValue = useRef( value || '' );
	const effectivePattern =
		pattern ||
		( hasSuffixOptions ? undefined : FILENAME_WITH_EXTENSION_PATTERN );
	const patternMatcher = getPatternMatcher( effectivePattern );

	useEffect( () => {
		lastAllowedValue.current = value || '';
	}, [ value ] );
	const isValueAllowed = ( nextValue ) => {
		if ( ! patternMatcher || '' === nextValue ) {
			return true;
		}

		return patternMatcher.test(
			hasSuffixOptions ? nextValue.toLowerCase() : nextValue
		);
	};
	const reduceFilenameInputState = ( state, action ) => {
		const nextState = stateReducer ? stateReducer( state, action ) : state;

		if (
			[ 'CHANGE', 'COMMIT' ].includes( action.type ) &&
			! isValueAllowed( action.payload.value || '' )
		) {
			return {
				...nextState,
				_event: undefined,
				value: lastAllowedValue.current,
			};
		}

		lastAllowedValue.current = nextState.value || '';

		return nextState;
	};
	const controlSuffix = hasSuffixOptions ? (
		<InputControlSuffixWrapper>
			<FilenameSuffixControl
				disabled={ disabled }
				isSuffixSelectTabbable={ isSuffixSelectTabbable }
				label={ suffixLabel }
				onChange={ onSuffixChange }
				options={ suffixOptions }
				value={ suffixValue }
			/>
		</InputControlSuffixWrapper>
	) : (
		suffix
	);

	return (
		<InputControl
			{ ...controlProps }
			__unstableStateReducer={ reduceFilenameInputState }
			disabled={ disabled }
			label={ label }
			pattern={ effectivePattern }
			prefix={
				<InputControlPrefixWrapper aria-hidden="true">
					{ directory }/
				</InputControlPrefixWrapper>
			}
			suffix={ controlSuffix }
			value={ value || '' }
			onChange={
				onChange
					? ( nextValue ) => onChange( nextValue || '' )
					: undefined
			}
		/>
	);
}
