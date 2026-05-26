<?php
/**
 * Plugin Name:       Axell Core
 *
 * @package           Axellcore
 * Description:       Core functionality for Axell Hydrosystems.
 * Version:           0.2.9
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

define( 'AXELLCORE_VERSION', '0.2.9' );
define( 'AXELLCORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'AXELLCORE_URL', plugin_dir_url( __FILE__ ) );

// ── DB update infrastructure ─────────────────────────────────────────────────

/**
 * Ordered map of db versions → migration callbacks.
 *
 * Each key is the db_version that will be recorded after all its callbacks
 * run successfully. Add new entries here; never remove existing ones.
 *
 * @var array<string, list<string>> $_axellcore_db_updates
 */
$GLOBALS['_axellcore_db_updates'] = array(
	'0.2.9' => array(
		'axell_update_029_rfa_mime_type',
		'axell_update_029_db_version',
	),
);

/**
 * Current recorded db version for this site.
 *
 * Returns null when the option has never been set (fresh install before 0.2.9).
 *
 * @return string|null
 */
function axellcore_get_db_version(): ?string {
	$v = get_option( 'axellcore_db_version', null );
	return is_string( $v ) ? $v : null;
}

/**
 * Whether the site's db version is behind the latest known migration.
 *
 * A null db_version (sites that existed before the update system was
 * introduced) is treated as behind so the 0.2.9 migration runs on them.
 *
 * @return bool
 */
function axellcore_needs_db_update(): bool {
	$current = axellcore_get_db_version();
	$latest  = array_key_last( $GLOBALS['_axellcore_db_updates'] );
	if ( is_null( $current ) ) {
		return true;
	}
	return version_compare( $current, $latest, '<' );
}

/**
 * Persist the recorded db version.
 *
 * @param string|null $version Version to record; defaults to the latest key in
 *                             $GLOBALS['_axellcore_db_updates'].
 * @return void
 */
function axellcore_update_db_version( ?string $version = null ): void {
	$version = $version ?? array_key_last( $GLOBALS['_axellcore_db_updates'] );
	update_option( 'axellcore_db_version', $version, false );
}

/**
 * Run all pending migration callbacks in order.
 *
 * Iterates over $GLOBALS['_axellcore_db_updates'], skipping versions that are
 * already at or below the current db_version. Each callback is called once;
 * if it returns false the migration is considered incomplete and the loop stops
 * (useful for large batched operations, though none exist yet).
 *
 * @return void
 */
function axellcore_run_updates(): void {
	$current = axellcore_get_db_version();

	foreach ( $GLOBALS['_axellcore_db_updates'] as $version => $callbacks ) {
		if ( ! is_null( $current ) && version_compare( $current, $version, '>=' ) ) {
			continue;
		}
		foreach ( $callbacks as $callback ) {
			if ( function_exists( $callback ) ) {
				$result = call_user_func( $callback );
				if ( false === $result ) {
					return; // Callback signals it needs another pass.
				}
			}
		}
	}
}

/**
 * Trigger pending db updates on every request where the plugin version has
 * been bumped (i.e. after a plugin update). Runs on `plugins_loaded` so all
 * WordPress functions are available.
 */
add_action(
	'plugins_loaded',
	function (): void {
		if ( axellcore_needs_db_update() ) {
			axellcore_run_updates();
		}
	}
);

// ── Migration: 0.2.9 ─────────────────────────────────────────────────────────

/**
 * Fix .rfa attachments stored with MIME type application/octet-stream.
 *
 * Versions ≤ 0.2.7 approved .rfa uploads when the browser sent the generic
 * application/octet-stream fallback but stored that generic type in post_mime_type.
 * The correct MIME is application/x-ole-storage (defined in axellcore_allowed_mimes).
 *
 * Updates post_mime_type and the _wp_attachment_metadata postmeta in bulk.
 *
 * @return void
 */
function axell_update_029_rfa_mime_type(): void {
	global $wpdb;

	// Find all .rfa attachments still carrying the generic octet-stream type.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			   AND post_mime_type = %s
			   AND post_title LIKE %s",
			'application/octet-stream',
			'%.rfa'
		)
	);

	if ( empty( $ids ) ) {
		return;
	}

	// Bulk-update post_mime_type.
	// All values in $ids come from $wpdb->get_col() — integer IDs, no user input.
	$int_ids      = array_map( 'intval', $ids );
	$placeholders = implode( ', ', array_fill( 0, count( $int_ids ), '%d' ) );
	$table        = $wpdb->posts;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE `$table` SET post_mime_type = %s WHERE ID IN ($placeholders)",
			array_merge( array( 'application/x-ole-storage' ), $int_ids )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	// Update the 'mime-type' key inside _wp_attachment_metadata for each attachment.
	foreach ( $ids as $id ) {
		$meta = get_post_meta( (int) $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && isset( $meta['mime-type'] ) ) {
			$meta['mime-type'] = 'application/x-ole-storage';
			update_post_meta( (int) $id, '_wp_attachment_metadata', $meta );
		}
	}

	// Invalidate any cached attachment data for affected IDs.
	array_map( 'clean_attachment_cache', array_map( 'intval', $ids ) );
}

/**
 * Bump db_version to 0.2.9.
 *
 * Always the last callback in the 0.2.9 group so the version is only
 * recorded after all preceding migrations completed successfully.
 *
 * @return void
 */
function axell_update_029_db_version(): void {
	axellcore_update_db_version( '0.2.9' );
}

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

/**
 * Extensions that must never be allowed regardless of what filters add.
 *
 * Applied as a hard blocklist after `axellcore_allowed_mimes` runs, so no
 * external plugin can accidentally (or maliciously) unlock executable types
 * by hooking into our filter.
 *
 * Filterable via `axellcore_blocked_exts` — only to add more entries, never
 * to remove the defaults (the merge ensures defaults always stay in).
 *
 * @return list<string>
 */
function axellcore_blocked_exts(): array {
	$defaults = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'phtml',
		'phar',
		'js',
		'jsx',
		'ts',
		'tsx',
		'exe',
		'sh',
		'bash',
		'py',
		'rb',
		'pl',
		'cgi',
		'htaccess',
		'htpasswd',
	);

	// Merge so defaults can never be removed via the filter.
	return array_unique(
		array_merge( $defaults, apply_filters( 'axellcore_blocked_exts', array() ) )
	);
}

/**
 * Central map of extra MIME types allowed for upload.
 *
 * Applies the `axellcore_allowed_mimes` filter so themes and other plugins can
 * add entries without touching core plugin code. Extensions listed in
 * `axellcore_blocked_exts()` are stripped after the filter runs — they can
 * never be unlocked from outside this plugin.
 *
 * Default entries:
 *   - .skp  — SketchUp model        (application/vnd.sketchup.skp)
 *   - .dwg  — AutoCAD drawing       (image/vnd.dwg)
 *   - .rfa  — Autodesk Revit family (application/x-ole-storage)
 *
 * @return array<string,string> Extension => MIME type, with blocked extensions removed.
 */
function axellcore_allowed_mimes(): array {
	$mimes   = apply_filters(
		'axellcore_allowed_mimes',
		array(
			'skp' => 'application/vnd.sketchup.skp',
			'dwg' => 'image/vnd.dwg',
			'rfa' => 'application/x-ole-storage',
		)
	);
	$blocked = axellcore_blocked_exts();

	// Strip any dangerous extension added by external filters.
	return array_diff_key( $mimes, array_flip( $blocked ) );
}

/**
 * Whether the current user may upload CAD/3D model files.
 *
 * Defaults to `edit_others_posts` (Editor+). Filterable via
 * `axellcore_upload_cad_capability` to loosen or tighten the requirement.
 *
 * Returns true in WP-CLI and cron contexts (no logged-in user) so that
 * programmatic imports are never blocked.
 *
 * @return bool
 */
function axellcore_current_user_can_upload_cad(): bool {
	$capability = apply_filters( 'axellcore_upload_cad_capability', 'edit_others_posts' );

	// Non-HTTP contexts (WP-CLI, cron) have no current user — allow unconditionally.
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return true;
	}

	return current_user_can( $capability );
}

/**
 * Map of MIME type => plugin icon URL for CAD formats.
 *
 * Used by `wp_prepare_attachment_for_js` to replace WordPress's generic
 * default.svg with a format-specific icon in the media library.
 *
 * @return array<string,string> MIME type => absolute URL to SVG icon.
 */
function axellcore_icon_urls(): array {
	$base = AXELLCORE_URL . 'assets/images/';
	return apply_filters(
		'axellcore_icon_urls',
		array(
			'image/vnd.dwg'                => $base . 'dwg.svg',
			'application/vnd.sketchup.skp' => $base . 'skp.svg',
			'application/x-ole-storage'    => $base . 'rfa.svg',
		)
	);
}

/**
 * Hooks into `wp_prepare_attachment_for_js` to inject format-specific icons
 * for CAD attachments in the media library.
 *
 * Covers both cases:
 *   - Non-image types (.skp, .rfa): WordPress uses `icon` for the thumbnail.
 *   - Image-typed MIMEs (.dwg as image/vnd.dwg): WordPress ignores `icon` and
 *     renders the file URL directly as <img>. For those, `sizes` and `url` are
 *     also overridden so the media library displays the SVG icon instead of
 *     trying to render the binary file as an image.
 */
add_filter(
	'wp_prepare_attachment_for_js',
	function ( array $response ): array {
		$icons = axellcore_icon_urls();
		$mime  = $response['mime'] ?? '';

		if ( ! isset( $icons[ $mime ] ) ) {
			return $response;
		}

		$icon_url = $icons[ $mime ];

		// Always set icon (used by non-image attachments).
		$response['icon'] = $icon_url;

		// For image/* MIMEs WordPress renders the file itself as thumbnail.
		// Override type so the media library treats it as a generic file
		// (showing the icon) rather than trying to render the binary as an image.
		if ( str_starts_with( $mime, 'image/' ) ) {
			$response['type']  = 'application';
			$response['url']   = $icon_url;
			$response['sizes'] = array(
				'full' => array( 'url' => $icon_url ),
			);
		}

		return $response;
	}
);

/**
 * Exclude CAD formats that carry an image/* MIME from image-only media contexts.
 *
 * WordPress uses the image/* prefix to populate image pickers (featured image,
 * <img> block, etc.). Formats like .dwg are registered as image/vnd.dwg by the
 * IANA but are not renderable images — they should never appear in those pickers.
 *
 * Hooks into `ajax_query_attachments_args` and checks the `post_mime_type`
 * constraint passed by the media modal. When the caller requests only images,
 * a `post_mime_type` exclusion list is built from the image/* entries in
 * `axellcore_allowed_mimes()`.
 */
add_filter(
	'ajax_query_attachments_args',
	function ( array $query ): array {
		$requested_mime = $query['post_mime_type'] ?? '';

		// Only act when the modal is filtering for images.
		if ( 'image' !== $requested_mime && ! str_starts_with( (string) $requested_mime, 'image/' ) ) {
			return $query;
		}

		// Collect the image/* MIMEs we registered that are not real images.
		$cad_image_mimes = array_values(
			array_filter(
				axellcore_allowed_mimes(),
				fn( string $mime ) => str_starts_with( $mime, 'image/' )
			)
		);

		if ( empty( $cad_image_mimes ) ) {
			return $query;
		}

		// WordPress WP_Query accepts post_mime_type as an array of MIME strings.
		// Build a positive list: all registered image/* MIMEs minus our CAD ones.
		$all_image_mimes = array_values(
			array_filter(
				get_allowed_mime_types(),
				fn( string $mime ) => str_starts_with( $mime, 'image/' )
			)
		);

		$allowed = array_values(
			array_diff( $all_image_mimes, $cad_image_mimes )
		);

		if ( ! empty( $allowed ) ) {
			$query['post_mime_type'] = $allowed;
		}

		return $query;
	}
);

/**
 * Enqueue admin CSS to fix icon alignment for CAD attachment types.
 *
 * WordPress applies translate(-50%, -70%) only to img.icon (application/* types).
 * For image/* types (e.g. image/vnd.dwg) it uses translate(-50%, -50%), which
 * misaligns our SVG icon. This rule forces the correct transform for .dwg
 * regardless of the type- class on the attachment element.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ) {
		if ( 'upload.php' !== $hook && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$css = '.wp-core-ui .attachment .thumbnail .centered img[src$="/dwg.svg"],
			.wp-core-ui .attachment .thumbnail .centered img[src$="/skp.svg"],
			.wp-core-ui .attachment .thumbnail .centered img[src$="/rfa.svg"] { transform: translate(-50%, -70%); }';
		wp_add_inline_style( 'media-views', $css );
	}
);

/**
 * Hooks into `upload_mimes` to merge CAD types into WordPress's upload allowlist.
 *
 * No-ops for users who lack CAD upload capability (Editor+ by default).
 * The merged entries come from `axellcore_allowed_mimes()`, which already has
 * dangerous extensions stripped.
 */
add_filter(
	'upload_mimes',
	function ( array $mimes ): array {
		if ( ! axellcore_current_user_can_upload_cad() ) {
			return $mimes;
		}
		return array_merge( $mimes, axellcore_allowed_mimes() );
	}
);

/**
 * Hooks into `wp_check_filetype_and_ext` to approve CAD file uploads.
 *
 * WordPress validates uploads by matching the file's binary signature against
 * its extension via PHP's finfo. CAD formats have no recognised signature in
 * core, so the check fails even when the extension and MIME type are correct.
 *
 * Approves the upload only when ALL conditions are met:
 *   1. WordPress could not resolve the type on its own (`$data['ext']` is empty).
 *   2. The current user has CAD upload capability.
 *   3. The file's declared extension is in `axellcore_allowed_mimes()`.
 *   4. The MIME detected by PHP finfo matches the expected MIME, or is the
 *      generic `application/octet-stream` fallback (sent by some OS/browsers
 *      for unknown types), or could not be determined (`false`).
 *
 * If none of these conditions are met the upload is left unresolved and
 * WordPress blocks it — no silent pass-through.
 *
 * @param array<string,string|bool> $data      Checked data (ext, type, proper_filename).
 * @param string                    $file      Absolute path to the uploaded temp file.
 * @param string                    $filename  Original filename with extension.
 * @param array<string,string>|null $mimes     Allowed MIME types passed by WordPress.
 * @param string|false              $real_mime MIME type detected by PHP finfo, or false.
 * @return array<string,string|bool>
 */
add_filter(
	'wp_check_filetype_and_ext',
	function ( array $data, string $file, string $filename, ?array $mimes, $real_mime ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! empty( $data['ext'] ) ) {
			return $data;
		}

		if ( ! axellcore_current_user_can_upload_cad() ) {
			return $data;
		}

		$ext     = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$allowed = axellcore_allowed_mimes();

		if ( ! isset( $allowed[ $ext ] ) ) {
			return $data;
		}

		$expected_mime = $allowed[ $ext ];

		// Accept when the detected MIME matches, or when it is a generic binary
		// fallback (some OS/browsers send application/octet-stream for unknown types).
		$accepted = array( false, 'application/octet-stream', $expected_mime );

		if ( ! in_array( $real_mime, $accepted, true ) ) {
			return $data; // MIME mismatch — leave unresolved, WordPress blocks.
		}

		$data['ext']  = $ext;
		$data['type'] = $expected_mime;

		return $data;
	},
	10,
	5
);
