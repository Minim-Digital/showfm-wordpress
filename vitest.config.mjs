/**
 * Unit tests (`npm run test:js`), in jsdom with React: the block editor script in
 * `src/test/` and the settings screen in `tests/js/`.
 */
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react-swc';

export default defineConfig( {
	plugins: [
		react( {
			// The editor source is JSX in .js files, as @wordpress/scripts builds it.
			parserConfig: ( id ) =>
				/\.[cm]?jsx?$/.test( id )
					? { syntax: 'ecmascript', jsx: true }
					: undefined,
		} ),
	],
	test: {
		environment: 'jsdom',
		globals: false,
		restoreMocks: true,
		include: [ 'src/**/*.test.js', 'tests/js/**/*.test.{js,jsx}' ],
		setupFiles: [ './src/test/setup.js', './tests/js/setup.js' ],
		// Some WordPress packages import JSON without import attributes; let Vite load them.
		server: { deps: { inline: [ /@wordpress\// ] } },
	},
} );
