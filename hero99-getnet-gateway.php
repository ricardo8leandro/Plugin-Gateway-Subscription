<?php defined('ABSPATH') || exit;
/**
 * Plugin Name:          Hero99 - Getnet Gateway
 * Description:          Includes Getnet as a payment method in WooCommerce Subscriptions. You will need Getnet API keys for the integration to work. Get them from your manager.
 * Author:               Hero99
 * Author URI:           
 * Version:              1.2.3
 * License:              GPLv3 or later
 * WC requires at least: 5.0
 * WC tested up to:      5.4.1
 * Requires at least:    5.4
 * Tested up to:         5.7.2
 * Requires PHP:         7.1
 * Text Domain: 		    getnet
 * Domain Path: 		    /app/languages
 *
 * Hero99 - Getnet Gateway is free software: you can
 * redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation,
 * either version 3 of the License, or any later version.
 *
 * Hero99 - Getnet Gateway is distributed in the hope that
 * it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Hero99 - Getnet Gateway. If not, see
 * <https://www.gnu.org/licenses/gpl-3.0.txt>.
 */

// Plugin constants.
define('WC_HERO99_VERSION', '1.2.2');
define('WC_HERO99_FILE', __FILE__);
define('WC_HERO99_APP', __DIR__ . '/app');

if (!class_exists('WC_Hero99')):
   require_once WC_HERO99_APP . '/controller/Init.php';
endif;
