<?php
/**
 * Plugin Name:       Axell Core
 *
 * @package           Axellcore
 * Description:       Core functionality for Axell Hydrosystems.
 * Version:           0.2.5
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

define( 'AXELLCORE_VERSION', '0.2.5' );
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

/**
 * Allow upload of CAD and 3D model file types used by Axell Hydrosystems.
 *
 * Adds MIME type support for:
 *   - .skp  — SketchUp model
 *   - .dwg  — AutoCAD drawing
 *   - .rfa  — Autodesk Revit family file
 *
 * @param array<string,string> $mimes Allowed MIME types keyed by extension.
 * @return array<string,string>
 */
add_filter(
	'upload_mimes',
	function ( array $mimes ): array {
		$mimes['skp'] = 'application/vnd.sketchup.skp';
		$mimes['dwg'] = 'image/vnd.dwg';
		$mimes['rfa'] = 'application/octet-stream';
		return $mimes;
	}
);

/**
 * Override the real file type check for CAD and 3D model extensions.
 *
 * Wp_check_filetype_and_ext() validates the file's binary signature against
 * its extension. CAD formats lack a recognised signature in WordPress core,
 * so the check would fail and block the upload even with upload_mimes set.
 * This filter lets the declared extension and MIME type pass through.
 *
 * @param array<string,string|bool> $data     Checked data (ext, type, proper_filename).
 * @param string                    $file     Full path to the uploaded file.
 * @param string                    $filename Filename with extension.
 * @param array<string,string>      $mimes    Allowed MIME types.
 * @param string|false              $real_mime Real detected MIME type or false.
 * @return array<string,string|bool>
 */
add_filter(
	'wp_check_filetype_and_ext',
	function ( array $data, string $file, string $filename, ?array $mimes, $real_mime ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! empty( $data['ext'] ) ) {
			return $data;
		}

		$ext     = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$allowed = array(
			'skp' => 'application/vnd.sketchup.skp',
			'dwg' => 'image/vnd.dwg',
			'rfa' => 'application/octet-stream',
		);

		if ( isset( $allowed[ $ext ] ) ) {
			$data['ext']  = $ext;
			$data['type'] = $allowed[ $ext ];
		}

		return $data;
	},
	10,
	5
);
