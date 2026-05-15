<?php
/**
 * PHPUnit bootstrap — loads Brain\Monkey and minimal WP stubs, then requires plugin files.
 *
 * @package AxellCore
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Activate Patchwork stream wrapper BEFORE defining stub functions so that
// Brain\Monkey can mock them per test. Must come before stubs are required.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

// WordPress function stubs — required (not eval'd) so Patchwork can intercept them.
require_once __DIR__ . '/stubs/functions.php';

// WordPress constants.
defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
define( 'AXELLCORE_VERSION', '0.1.0' );
define( 'AXELLCORE_PATH', dirname( __DIR__ ) . '/' );
define( 'AXELLCORE_URL', 'http://example.com/wp-content/plugins/axellcore/' );

// Load plugin includes here as the plugin grows, e.g.:
// require_once AXELLCORE_PATH . 'includes/class-foo.php';
