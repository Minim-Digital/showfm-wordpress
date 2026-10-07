/**
 * Settings the plugin prints before the editor script (`Editor::enqueue`). Nothing secret.
 */
const DEFAULTS = {
	connected: false,
	reconnect: false,
	canConnect: false,
	connectUrl: '',
	appUrl: 'https://my.show.fm',
	api: 'https://api.show.fm',
};

/**
 * The editor settings, with safe defaults when the inline script is missing.
 *
 * @return {typeof DEFAULTS} Settings.
 */
export function getSettings() {
	const fromPage =
		typeof window !== 'undefined' && window.showfmEditor
			? window.showfmEditor
			: {};
	return { ...DEFAULTS, ...fromPage };
}
