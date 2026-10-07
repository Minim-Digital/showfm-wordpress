/**
 * Unit tests for the editor script (`npm run test:js`), in jsdom with React.
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
		include: [ 'src/**/*.test.js' ],
		setupFiles: [ './src/test/setup.js' ],
		// Some WordPress packages import JSON without import attributes; let Vite load them.
		server: { deps: { inline: [ /@wordpress\// ] } },
	},
} );
