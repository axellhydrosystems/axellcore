<?php
/**
 * DB update infrastructure and data migrations.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ordered map of db versions → migration callbacks.
 *
 * Each key is the db_version that will be recorded after all its callbacks
 * run successfully. Add new entries here; never remove existing ones.
 *
 * @var array<string, list<string>> $_axellcore_db_updates
 */
$GLOBALS['_axellcore_db_updates'] = array(
	'0.2.9'  => array(
		'axell_update_029_rfa_mime_type',
		'axell_update_029_db_version',
	),
	'0.2.10' => array(
		'axell_update_0210_rfa_mime_type',
		'axell_update_0210_db_version',
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
 * Trigger pending db updates on every request after a plugin update.
 * Runs on `plugins_loaded` so all WordPress functions are available.
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

	foreach ( $ids as $id ) {
		$meta = get_post_meta( (int) $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && isset( $meta['mime-type'] ) ) {
			$meta['mime-type'] = 'application/x-ole-storage';
			update_post_meta( (int) $id, '_wp_attachment_metadata', $meta );
		}
	}

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

// ── Migration: 0.2.10 ────────────────────────────────────────────────────────

/**
 * Fix .rfa attachments stored with MIME type application/octet-stream.
 *
 * Migration 0.2.9 failed in production because it matched against post_title,
 * but WordPress strips the file extension when saving the attachment title.
 * The reliable source of truth is the _wp_attached_file postmeta value.
 *
 * This migration re-runs the same fix — updating post_mime_type to
 * application/x-ole-storage — using a JOIN on wp_postmeta instead.
 *
 * @return void
 */
function axell_update_0210_rfa_mime_type(): void {
	global $wpdb;

	// Join on _wp_attached_file to match the extension reliably.
	// WordPress strips the extension from post_title — this is the correct check.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			         ON pm.post_id = p.ID
			        AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type      = 'attachment'
			   AND p.post_mime_type = %s
			   AND pm.meta_value    LIKE %s",
			'application/octet-stream',
			'%.rfa'
		)
	);

	if ( empty( $ids ) ) {
		return;
	}

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

	foreach ( $ids as $id ) {
		$meta = get_post_meta( (int) $id, '_wp_attachment_metadata', true );
		if ( is_array( $meta ) && isset( $meta['mime-type'] ) ) {
			$meta['mime-type'] = 'application/x-ole-storage';
			update_post_meta( (int) $id, '_wp_attachment_metadata', $meta );
		}
	}

	array_map( 'clean_attachment_cache', array_map( 'intval', $ids ) );
}

/**
 * Bump db_version to 0.2.10.
 *
 * Always the last callback in the 0.2.10 group so the version is only
 * recorded after all preceding migrations completed successfully.
 *
 * @return void
 */
function axell_update_0210_db_version(): void {
	axellcore_update_db_version( '0.2.10' );
}
