<?php
/**
 * Plugin Name:       show.fm
 * Plugin URI:        https://github.com/Minim-Digital/showfm-wordpress
 * Description:       Podcast player, episode lists and auto-posting for show.fm shows.
 * Version:           1.0.1
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            show.fm
 * Author URI:        https://show.fm
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       showfm
 *
 * Copyright (C) 2026 show.fm Ltd
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * @package ShowFM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHOWFM_VERSION', '1.0.1' );
define( 'SHOWFM_FILE', __FILE__ );
define( 'SHOWFM_DIR', __DIR__ );

require_once SHOWFM_DIR . '/includes/class-autoloader.php';

ShowFM\Autoloader::register();

register_activation_hook( SHOWFM_FILE, array( 'ShowFM\Plugin', 'activate' ) );
register_deactivation_hook( SHOWFM_FILE, array( 'ShowFM\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'ShowFM\Plugin', 'boot' ) );
