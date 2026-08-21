import CodeMirror from 'codemirror/lib/codemirror';
import 'codemirror/addon/display/placeholder';
import 'codemirror/mode/meta';
import 'codemirror/mode/javascript/javascript';
import { useEffect, useRef } from '@wordpress/element';

const DEFAULT_SCRIPT_MODE = {
	mode: 'javascript',
	modeName: 'javascript',
};
const PLAIN_TEXT_MODE = {
	mode: 'text/plain',
	modeName: 'null',
};

const MODE_LOADERS_BY_NAME = {
	clike: () => import( 'codemirror/mode/clike/clike' ),
	css: () => import( 'codemirror/mode/css/css' ),
	dockerfile: () => import( 'codemirror/mode/dockerfile/dockerfile' ),
	gfm: () => import( 'codemirror/mode/gfm/gfm' ),
	go: () => import( 'codemirror/mode/go/go' ),
	htmlmixed: () => import( 'codemirror/mode/htmlmixed/htmlmixed' ),
	jsx: () => import( 'codemirror/mode/jsx/jsx' ),
	lua: () => import( 'codemirror/mode/lua/lua' ),
	markdown: () => import( 'codemirror/mode/markdown/markdown' ),
	perl: () => import( 'codemirror/mode/perl/perl' ),
	php: () => import( 'codemirror/mode/php/php' ),
	powershell: () => import( 'codemirror/mode/powershell/powershell' ),
	properties: () => import( 'codemirror/mode/properties/properties' ),
	python: () => import( 'codemirror/mode/python/python' ),
	ruby: () => import( 'codemirror/mode/ruby/ruby' ),
	rust: () => import( 'codemirror/mode/rust/rust' ),
	sass: () => import( 'codemirror/mode/sass/sass' ),
	shell: () => import( 'codemirror/mode/shell/shell' ),
	sql: () => import( 'codemirror/mode/sql/sql' ),
	toml: () => import( 'codemirror/mode/toml/toml' ),
	xml: () => import( 'codemirror/mode/xml/xml' ),
	yaml: () => import( 'codemirror/mode/yaml/yaml' ),
};
const SUPPORTED_SCRIPT_MODES = new Set( [
	'javascript',
	...Object.keys( MODE_LOADERS_BY_NAME ),
] );
const loadedScriptModes = new Map();

const SCRIPT_MODE_OVERRIDES_BY_EXTENSION = {
	cjs: DEFAULT_SCRIPT_MODE,
	cts: {
		mode: 'application/typescript',
		modeName: 'javascript',
	},
	env: {
		mode: 'text/x-properties',
		modeName: 'properties',
	},
	fish: {
		mode: 'text/x-sh',
		modeName: 'shell',
	},
	json5: {
		mode: 'application/json',
		modeName: 'javascript',
	},
	jsonc: {
		mode: 'application/json',
		modeName: 'javascript',
	},
	mjs: DEFAULT_SCRIPT_MODE,
	mts: {
		mode: 'application/typescript',
		modeName: 'javascript',
	},
	zsh: {
		mode: 'text/x-sh',
		modeName: 'shell',
	},
};

function getFileExtension( fileName ) {
	const trimmedFileName = ( fileName || '' ).trim();
	const extensionStart = trimmedFileName.lastIndexOf( '.' );

	if ( extensionStart <= 0 ) {
		return '';
	}

	return trimmedFileName.slice( extensionStart + 1 ).toLowerCase();
}

function getScriptMode( fileName ) {
	const trimmedFileName = ( fileName || '' ).trim();

	if ( ! trimmedFileName ) {
		return DEFAULT_SCRIPT_MODE;
	}

	const extension = getFileExtension( trimmedFileName );

	if ( extension && SCRIPT_MODE_OVERRIDES_BY_EXTENSION[ extension ] ) {
		return SCRIPT_MODE_OVERRIDES_BY_EXTENSION[ extension ];
	}

	const modeInfo = CodeMirror.findModeByFileName( trimmedFileName );

	if (
		! modeInfo ||
		modeInfo.mode === 'null' ||
		! SUPPORTED_SCRIPT_MODES.has( modeInfo.mode )
	) {
		return PLAIN_TEXT_MODE;
	}

	return {
		mode: modeInfo.mime || modeInfo.mimes?.[ 0 ] || modeInfo.mode,
		modeName: modeInfo.mode,
	};
}

function loadScriptMode( modeName ) {
	const loadMode = MODE_LOADERS_BY_NAME[ modeName ];

	if ( ! loadMode ) {
		return Promise.resolve();
	}

	if ( ! loadedScriptModes.has( modeName ) ) {
		loadedScriptModes.set( modeName, loadMode() );
	}

	return loadedScriptModes.get( modeName );
}

export default function ScriptCodeEditor( {
	value,
	onChange,
	placeholder,
	label,
	fileName,
} ) {
	const editorRef = useRef();
	const editorRootRef = useRef();
	const { mode, modeName } = getScriptMode( fileName );
	const onChangeRef = useRef( onChange );
	const valueRef = useRef( value || '' );

	useEffect( () => {
		onChangeRef.current = onChange;
	}, [ onChange ] );

	useEffect( () => {
		const nextValue = value || '';
		valueRef.current = nextValue;

		if ( editorRef.current ) {
			const editorValue = editorRef.current.getValue();

			if ( editorValue !== nextValue ) {
				editorRef.current.setValue( nextValue );
			}
		}
	}, [ value ] );

	useEffect( () => {
		if ( ! editorRootRef.current ) {
			return;
		}

		const editor = CodeMirror( editorRootRef.current, {
			indentUnit: 2,
			inputStyle: 'textarea',
			lineNumbers: true,
			lineWrapping: true,
			mode: PLAIN_TEXT_MODE.mode,
			placeholder,
			tabSize: 2,
			value: valueRef.current,
		} );

		editorRef.current = editor;
		editor.setOption( 'extraKeys', {
			Tab: ( codeMirror ) => {
				if ( codeMirror.somethingSelected() ) {
					codeMirror.indentSelection( 'add' );
					return;
				}

				codeMirror.replaceSelection( '\t' );
			},
		} );
		editor.getInputField().setAttribute( 'aria-label', label );
		editor.getInputField().setAttribute( 'placeholder', placeholder );
		editor.on( 'change', ( codeMirror ) => {
			const nextValue = codeMirror.getValue();

			if ( nextValue !== valueRef.current ) {
				valueRef.current = nextValue;
				onChangeRef.current( nextValue );
			}
		} );

		return () => {
			editor.getWrapperElement().remove();
			editorRef.current = null;
		};
	}, [ label, placeholder ] );

	useEffect( () => {
		let isCurrent = true;

		loadScriptMode( modeName )
			.then( () => {
				if ( isCurrent && editorRef.current ) {
					editorRef.current.setOption( 'mode', mode );
				}
			} )
			.catch( () => {
				if ( isCurrent && editorRef.current ) {
					editorRef.current.setOption( 'mode', PLAIN_TEXT_MODE.mode );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ label, mode, modeName, placeholder ] );

	return <div className="agent-pilot-code-editor" ref={ editorRootRef } />;
}
