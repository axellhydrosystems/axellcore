<?php
/**
 * Plugin Name:       Axell Core
 *
 * @package           Axellcore
 * Description:       Core functionality for Axell Hydrosystems.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      6.8
 * Author:            Axell Hydrosystems
 * Author URI:        https://github.com/axellhydrosystems
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axellcore
 * Directory:         https://github.com/axellhydrosystems/axellcore
 */

defined( 'ABSPATH' ) || exit;

define( 'AXELLCORE_VERSION', '0.1.0' );
define( 'AXELLCORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AXELLCORE_URL', plugin_dir_url( __FILE__ ) );

require_once AXELLCORE_PATH . 'lib/selfdirectory/class-selfdirectory.php';

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'axellcore', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

add_action(
	'selfd_register',
	function () {
		selfd( __FILE__ );
	}
);
