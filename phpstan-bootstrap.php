<?php
/**
 * PHPStan bootstrap — defines constants required for static analysis.
 */

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPINC', 'wp-includes' );
define( 'AXELLCORE_VERSION', '0.1.0' );
define( 'AXELLCORE_PATH', __DIR__ . '/' );
define( 'AXELLCORE_URL', 'https://example.com/wp-content/plugins/axellcore/' );

if ( ! function_exists( 'selfd' ) ) {
	function selfd( string $file ): void {} // phpcs:ignore
}
