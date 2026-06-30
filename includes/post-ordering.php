<?php
/**
 * Drag-and-drop ordering for registered post types.
 *
 * Based on the term-ordering implementation from WooCommerce, adapted from
 * the ninodem theme's post-ordering.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the list of post types with drag-and-drop ordering enabled.
 *
 * Reads from the `axellcore_post_types_ordering` option (comma-separated) and
 * passes the result through the `axellcore_post_types_ordering` filter so that
 * code can register post types programmatically without touching the DB.
 *
 * @return list<string>
 */
function axell_post_types_ordering(): array {
	$option     = get_option( 'axellcore_post_types_ordering', '' );
	$post_types = (array) explode( ',', $option );
	$post_types = apply_filters( 'axellcore_post_types_ordering', $post_types );
	$post_types = array_filter( $post_types );
	$post_types = array_unique( $post_types );
	return array_values( $post_types );
}

foreach ( axell_post_types_ordering() as $post_type ) {
	add_filter( "views_edit-{$post_type}", 'axell_post_ordering_views' );
}

/**
 * Append a "Sorting" entry to the post-status filter bar (subsubsub).
 *
 * When no explicit `orderby` is set the list is in drag-and-drop sorting mode.
 * In that state "Sorting" is marked current and "All" is unmarked so only one
 * entry appears active at a time.
 *
 * @param array<string,string> $views
 * @return array<string,string>
 */
function axell_post_ordering_views( array $views ): array {
	$screen    = get_current_screen();
	$post_type = $screen ? $screen->post_type : '';

	if ( ! $post_type ) {
		return $views;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$orderby    = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
	$is_sorting = str_starts_with( $orderby, 'menu_order' );

	// Remove "current" from "All" while in sorting mode so only "Sorting" is highlighted.
	if ( $is_sorting && isset( $views['all'] ) ) {
		$views['all'] = str_replace(
			array( ' class="current"', ' aria-current="page"' ),
			'',
			$views['all']
		);
	}

	$args        = $post_type !== 'post' ? array( 'post_type' => $post_type ) : array();
	$sorting_url = add_query_arg( array_merge( $args, array( 'orderby' => 'menu_order title', 'order' => 'ASC' ) ), admin_url( 'edit.php' ) );

	$views['axell_sorting'] = sprintf(
		'<a href="%s"%s>%s</a>',
		esc_url( $sorting_url ),
		$is_sorting ? ' class="current" aria-current="page"' : '',
		esc_html__( 'Sorting', 'axellcore' )
	);

	return $views;
}

/**
 * Enqueue the sortable JS on post-type list screens with default ordering.
 *
 * @param string $hook Current admin page hook.
 */
function axell_admin_post_ordering_script( string $hook ): void {
	if ( 'edit.php' !== $hook ) {
		return;
	}

	$post_types = axell_post_types_ordering();
	$post_type  = isset( $_GET['post_type'] ) ? wp_unslash( $_GET['post_type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( empty( $post_type ) || ! in_array( $post_type, $post_types, true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$orderby = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
	if ( ! str_starts_with( $orderby, 'menu_order' ) ) {
		return;
	}

	wp_enqueue_script(
		'axellcore-post-ordering',
		AXELLCORE_URL . 'assets/js/admin/post-ordering.js',
		array( 'jquery-ui-sortable' ),
		AXELLCORE_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'axell_admin_post_ordering_script' );

/**
 * Enqueue the handle-column CSS on post-type list screens.
 */
function axell_admin_post_ordering_styles(): void {
	$screen    = get_current_screen();
	$screen_id = $screen ? $screen->id : '';

	wp_register_style(
		'axellcore-post-ordering',
		AXELLCORE_URL . 'assets/css/admin/post-ordering.css',
		array(),
		AXELLCORE_VERSION
	);

	$screen_ids = array_map(
		fn( string $pt ) => 'edit-' . $pt,
		axell_post_types_ordering()
	);

	if ( in_array( $screen_id, $screen_ids, true ) ) {
		wp_enqueue_style( 'axellcore-post-ordering' );
	}
}
add_action( 'admin_enqueue_scripts', 'axell_admin_post_ordering_styles' );

/**
 * AJAX handler: reorder the dragged post relative to its new neighbour.
 */
function axell_post_ordering(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( ! current_user_can( 'edit_posts' ) || empty( $_POST['id'] ) ) {
		wp_die( -1 );
	}

	$id      = absint( $_POST['id'] );
	$next_id = isset( $_POST['nextid'] ) && absint( $_POST['nextid'] ) ? absint( $_POST['nextid'] ) : null;
	$post    = get_post( $id );

	if ( ! $post ) {
		wp_die( 0 );
	}

	axell_reorder_posts( $post, $next_id );
	// phpcs:enable
}
add_action( 'wp_ajax_axell_post_ordering', 'axell_post_ordering' );

/**
 * Move a post before the given sibling, updating `menu_order` for all siblings.
 *
 * Bug fix vs. reference: `$post_in_level` is initialised to false before the
 * loop and set to true when the dragged post is found among its siblings, so the
 * final "last position" branch only fires when the post actually belongs to this
 * level.
 *
 * @param \WP_Post   $the_post The post being moved.
 * @param int|null   $next_id  ID of the post that should follow it, or null if last.
 * @param int        $index    Running menu_order counter (used in recursion).
 * @param \WP_Post[] $posts    Sibling posts (defaults to all posts of this type).
 * @return int Updated index.
 */
function axell_reorder_posts( \WP_Post $the_post, ?int $next_id, int $index = 0, ?array $posts = null ): int {
	$post_type = $the_post->post_type;

	if ( null === $posts ) {
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'posts_per_page' => -1,
			)
		);
	}

	if ( empty( $posts ) ) {
		return $index;
	}

	$id            = (int) $the_post->ID;
	$post_in_level = false;

	foreach ( $posts as $post ) {
		$post_id = (int) $post->ID;

		if ( $post_id === $id ) {
			$post_in_level = true;
			continue;
		}

		if ( null !== $next_id && $post_id === $next_id ) {
			$index++;
			$index = axell_set_post_order( $post_type, $id, $index, true );
		}

		$index++;
		$index = axell_set_post_order( $post_type, $post_id, $index );
	}

	if ( $post_in_level && null === $next_id ) {
		$index = axell_set_post_order( $post_type, $id, $index + 1, true );
	}

	return $index;
}

/**
 * Persist the `menu_order` for a single post and bust its cache.
 *
 * @param string $post_type Post type (used for cache clearing).
 * @param int    $post_id   Post ID.
 * @param int    $index     New menu_order value.
 * @param bool   $recursive Unused; preserved for API parity with the reference.
 * @return int The index passed in, unchanged.
 */
function axell_set_post_order( string $post_type, int $post_id, int $index, bool $recursive = false ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	wp_update_post(
		array(
			'ID'         => $post_id,
			'menu_order' => $index,
		)
	);

	clean_post_cache( $post_id, $post_type );

	return $index;
}

/**
 * Default ordering by `menu_order ASC` for ordering-enabled post types.
 *
 * Only applies when no explicit `orderby` is set, so manual column sorting
 * still works.
 *
 * @param \WP_Query $query
 */
function axell_get_posts_ordering( \WP_Query $query ): void {
	$post_types = axell_post_types_ordering();
	if ( ! in_array( $query->get( 'post_type' ), $post_types, true ) ) {
		return;
	}
	if ( ! $query->get( 'orderby' ) ) {
		$query->set( 'orderby', 'menu_order' );
		$query->set( 'order', 'ASC' );
	}
}
add_action( 'pre_get_posts', 'axell_get_posts_ordering' );
