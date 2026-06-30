<?php
/**
 * CAD media: MIME types, upload validation, and media library integration.
 */

defined( 'ABSPATH' ) || exit;

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
 * CAD-specific MIME types managed by this plugin.
 *
 * This is the canonical source of CAD formats. It powers:
 *   - The "CAD" filter group in the media library dropdown.
 *   - The capability check for CAD uploads.
 *   - The icon map for the media library.
 *
 * Filterable via `axellcore_allowed_cad_mimes` to add or override entries.
 * Extensions listed in `axellcore_blocked_exts()` are stripped after the
 * filter runs — they can never be unlocked from outside this plugin.
 *
 * Default entries:
 *   - .skp  — SketchUp model        (application/vnd.sketchup.skp)
 *   - .dwg  — AutoCAD drawing       (image/vnd.dwg)
 *   - .rfa  — Autodesk Revit family (application/x-ole-storage)
 *
 * @return array<string,string> Extension => MIME type, with blocked extensions removed.
 */
function axellcore_allowed_cad_mimes(): array {
	$mimes = apply_filters(
		'axellcore_allowed_cad_mimes',
		array(
			'skp' => 'application/vnd.sketchup.skp',
			'dwg' => 'image/vnd.dwg',
			'rfa' => 'application/x-ole-storage',
		)
	);

	return array_diff_key( $mimes, array_flip( axellcore_blocked_exts() ) );
}

/**
 * Full map of extra MIME types allowed for upload.
 *
 * Merges `axellcore_allowed_cad_mimes()` with additional types and passes the
 * result through the `axellcore_allowed_mimes` filter. CAD types are always
 * included — they cannot be removed through this filter.
 *
 * Extensions listed in `axellcore_blocked_exts()` are stripped after the
 * filter runs — they can never be unlocked from outside this plugin.
 *
 * @return array<string,string> Extension => MIME type, with blocked extensions removed.
 */
function axellcore_allowed_mimes(): array {
	$mimes = apply_filters(
		'axellcore_allowed_mimes',
		array_merge(
			axellcore_allowed_cad_mimes(),
			array()
		)
	);

	return array_diff_key( $mimes, array_flip( axellcore_blocked_exts() ) );
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

		if ( 'image' !== $requested_mime && ! str_starts_with( (string) $requested_mime, 'image/' ) ) {
			return $query;
		}

		$cad_image_mimes = array_values(
			array_filter(
				axellcore_allowed_cad_mimes(),
				fn( string $mime ) => str_starts_with( $mime, 'image/' )
			)
		);

		if ( empty( $cad_image_mimes ) ) {
			return $query;
		}

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
 * Add a "CAD" group to the media library attachment-filters dropdown.
 *
 * WordPress builds the <select> from the `post_mime_types` global filter.
 * Each entry is [ singular_label, plural_label, edit_label ]; the key is the
 * MIME type (or a comma-separated list) used as the <option value>.
 */
add_filter(
	'post_mime_types',
	function ( array $post_mime_types ): array {
		$mimes    = array_values( axellcore_allowed_cad_mimes() );
		$mime_key = implode( ',', $mimes );

		$post_mime_types[ $mime_key ] = array(
			__( 'CAD Files', 'axellcore' ),
			__( 'Manage CAD Files', 'axellcore' ),
			/* translators: %s: number of CAD files */
			_n_noop( 'CAD File <span class="count">(%s)</span>', 'CAD Files <span class="count">(%s)</span>', 'axellcore' ),
		);

		return $post_mime_types;
	}
);

/**
 * Hooks into `upload_mimes` to merge CAD types into WordPress's upload allowlist.
 *
 * CAD types require the additional CAD upload capability (Editor+ by default).
 * The merged entries come from `axellcore_allowed_cad_mimes()`, which already
 * has dangerous extensions stripped.
 */
add_filter(
	'upload_mimes',
	function ( array $mimes ): array {
		if ( axellcore_current_user_can_upload_cad() ) {
			$mimes = array_merge( $mimes, axellcore_allowed_cad_mimes() );
		}

		return $mimes;
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
			return $data;
		}

		$data['ext']  = $ext;
		$data['type'] = $expected_mime;

		return $data;
	},
	10,
	5
);
