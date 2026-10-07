/**
 * Unit tests for the settings screen (`npm run test:js`), in jsdom with React.
 */
import react from '@vitejs/plugin-react-swc';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	plugins: [ react() ],
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.{js,jsx}' ],
		setupFiles: [ './tests/js/setup.js' ],
	},
} );
