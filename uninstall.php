<?php
/**
 * Uninstall: removes the plugin's own data. Never deletes posts.
 *
 * @package ShowFM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-autoloader.php';

ShowFM\Autoloader::register();
ShowFM\Uninstaller::run();
