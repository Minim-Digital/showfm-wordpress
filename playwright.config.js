/**
 * Playwright config: the `@wordpress/scripts` defaults, pointed at tests/e2e.
 *
 * The smoke test runs against the wp-env development site (port 8888). PHPUnit runs in
 * `tests-cli`, and the core test installer resets that site's tables, so the tests site
 * on port 8889 is not usable for browser tests after a PHPUnit run.
 */
process.env.WP_BASE_URL ??= 'http://localhost:8888';

const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: './tests/e2e',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run env:start',
	},
};
