<?php
/**
 * Minimal WordPress function stubs for PHPUnit unit tests.
 *
 * Only stub functions that the plugin actually calls. Add more as needed.
 * Brain\Monkey will intercept these at test time so they can be mocked.
 */

declare( strict_types=1 );

// Escaping & sanitization.
function esc_html( string $text ): string { return $text; }
function esc_attr( string $text ): string { return $text; }
function esc_url( string $url ): string { return $url; }
function esc_url_raw( string $url ): string { return $url; }
function sanitize_text_field( string $str ): string { return $str; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ); }
function wp_kses_post( string $data ): string { return $data; }
function absint( mixed $maybeint ): int { return abs( (int) $maybeint ); }

// i18n.
function __( string $text, string $domain = 'default' ): string { return $text; }
function _e( string $text, string $domain = 'default' ): void { echo $text; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_attr__( string $text, string $domain = 'default' ): string { return $text; }
function esc_html_e( string $text, string $domain = 'default' ): void { echo $text; }

// Hooks.
function add_action( string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function add_filter( string $tag, callable $function_to_add, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function do_action( string $tag, mixed ...$args ): void {}
function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed { return $value; }
function remove_action( string $tag, callable $function_to_remove, int $priority = 10 ): bool { return true; }
function remove_filter( string $tag, callable $function_to_remove, int $priority = 10 ): bool { return true; }

// Options.
function get_option( string $option, mixed $default = false ): mixed { return $default; }
function update_option( string $option, mixed $value, bool|string $autoload = true ): bool { return true; }
function delete_option( string $option ): bool { return true; }

// Plugin helpers.
function plugin_dir_path( string $file ): string { return trailingslashit( dirname( $file ) ); }
function plugin_dir_url( string $file ): string { return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugins_url( string $path = '', string $plugin = '' ): string { return 'http://example.com/wp-content/plugins/' . $path; }
function trailingslashit( string $string ): string { return rtrim( $string, '/\\' ) . '/'; }

// Misc.
function is_admin(): bool { return false; }
function wp_die( string|WP_Error $message = '', string|int $title = '', array $args = [] ): void { throw new \RuntimeException( is_string( $message ) ? $message : 'wp_die' ); }
function current_user_can( string $capability, mixed ...$args ): bool { return false; }
