/**
 * Prettier: the WordPress config, with spaces for YAML (YAML cannot indent with tabs).
 */
module.exports = {
	...require( '@wordpress/prettier-config' ),
	overrides: [
		{
			files: [ '*.yml', '*.yaml' ],
			options: { useTabs: false, tabWidth: 2 },
		},
	],
};
