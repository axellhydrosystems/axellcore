<?php
/**
 * Plugin Name:       Axell Core
 *
 * @package           Axellcore
 * Description:       Core functionality for Axell Hydrosystems.
 * Version:           0.2.11
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      7.0
 * Author:            Axell Hydrosystems
 * Author URI:        https://github.com/axellhydrosystems
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axellcore
 * Directory:         https://github.com/axellhydrosystems/axellcore
 */

defined( 'ABSPATH' ) || exit;

define( 'AXELLCORE_VERSION', '0.2.11' );
define( 'AXELLCORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AXELLCORE_URL', plugin_dir_url( __FILE__ ) );

require_once AXELLCORE_PATH . 'includes/updates.php';
require_once AXELLCORE_PATH . 'includes/media.php';

add_filter(
	'axellcore_post_types_ordering',
	function ( array $types ): array {
		return array_merge(
			$types,
			array( 'spa', 'banheiras', 'banheiras-de-imersao', 'bicas-de-piso', 'colunas-de-banho', 'bandejas', 'acessorios' )
		);
	}
);

require_once AXELLCORE_PATH . 'includes/post-ordering.php';

// SelfDirectory provides self-hosted update checking via GitHub Releases.
// It is bundled as a git submodule and optional — the plugin works fully
// without it (e.g. local/Studio environments where the submodule was not
// initialised). Production deploys must include the submodule.
$_axellcore_selfdirectory = AXELLCORE_PATH . 'lib/selfdirectory/class-selfdirectory.php';
if ( file_exists( $_axellcore_selfdirectory ) ) {
	require_once $_axellcore_selfdirectory;
	add_action(
		'selfd_register',
		function () {
			selfd( __FILE__ );
		}
	);
}
unset( $_axellcore_selfdirectory );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'axellcore', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
