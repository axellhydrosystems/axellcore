<?php
/**
 * Batch CSV exporter for a store post type.
 *
 * @package Axellcore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes one page of posts at a time to a temporary CSV file.
 */
class Axellcore_Exporter {

	/**
	 * Post type configuration.
	 *
	 * @var Axellcore_Store_Config
	 */
	private Axellcore_Store_Config $config;

	/**
	 * Column ids to export.
	 *
	 * @var list<string>
	 */
	private array $columns;

	/**
	 * CSV delimiter.
	 *
	 * @var string
	 */
	private string $delimiter;

	/**
	 * Separator between multiple values in one cell.
	 *
	 * @var string
	 */
	private string $value_separator;

	/**
	 * Only published posts.
	 *
	 * @var bool
	 */
	private bool $published_only;

	/**
	 * Term IDs to filter by, per location level (empty = no filter).
	 *
	 * @var array<string,int[]>
	 */
	private array $terms;

	/**
	 * Prefix the file with a UTF-8 BOM (helps Excel).
	 *
	 * @var bool
	 */
	private bool $bom;

	/**
	 * Constructor.
	 *
	 * @param Axellcore_Store_Config $config Post type configuration.
	 * @param array<string,mixed>    $args   Options: columns, delimiter, value_separator, published_only, bom, terms.
	 */
	public function __construct( Axellcore_Store_Config $config, array $args ) {
		$known   = array_keys( Axellcore_Store_Config::columns() );
		$columns = array_values( array_intersect( $known, (array) ( $args['columns'] ?? array() ) ) );

		$this->config          = $config;
		$this->columns         = $columns ? $columns : Axellcore_Store_Config::default_columns();
		$this->delimiter       = self::clean_delimiter( (string) ( $args['delimiter'] ?? ',' ) );
		$this->value_separator = '|' === ( $args['value_separator'] ?? ',' ) ? '|' : ',';
		$this->published_only  = ! empty( $args['published_only'] );
		$this->bom             = ! isset( $args['bom'] ) || (bool) $args['bom'];
		$this->terms           = array();

		foreach ( Axellcore_Fields::LOCATION_LEVELS as $level ) {
			$ids = array_filter( array_map( 'absint', (array) ( $args['terms'][ $level ] ?? array() ) ) );
			if ( $ids ) {
				$this->terms[ $level ] = array_values( $ids );
			}
		}
	}

	/**
	 * Restrict a delimiter to the supported set.
	 *
	 * @param string $delimiter Requested delimiter.
	 * @return string
	 */
	public static function clean_delimiter( string $delimiter ): string {
		if ( 'tab' === $delimiter || "\t" === $delimiter ) {
			return "\t";
		}

		return in_array( $delimiter, array( ',', ';', '|' ), true ) ? $delimiter : ',';
	}

	/**
	 * Export one page of posts and append it to the file.
	 *
	 * @param string $file     Absolute path of the temporary file.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Posts per page.
	 * @return array{total:int,done:int,percent:int}
	 */
	public function export_page( string $file, int $page, int $per_page ): array {
		$tax_query = array();
		foreach ( $this->terms as $level => $ids ) {
			$tax_query[] = array(
				'taxonomy' => $this->config->taxonomies[ $level ],
				'field'    => 'term_id',
				'terms'    => $ids,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => $this->config->post_type,
				'post_status'            => $this->published_only ? 'publish' : array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Only when the user filters by location.
				'no_found_rows'          => false,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
			)
		);

		$handle = fopen( $file, 1 === $page ? 'w' : 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array(
				'total'   => 0,
				'done'    => 0,
				'percent' => 100,
			);
		}

		if ( 1 === $page ) {
			if ( $this->bom ) {
				fwrite( $handle, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
			$this->put_row( $handle, $this->header_row() );
		}

		foreach ( $query->posts as $post ) {
			$this->put_row( $handle, $this->row_for( $post ) );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$total = (int) $query->found_posts;
		$done  = min( $total, $page * $per_page );

		return array(
			'total'   => $total,
			'done'    => $done,
			'percent' => $total ? (int) floor( $done / $total * 100 ) : 100,
		);
	}

	/**
	 * Header labels for the chosen columns.
	 *
	 * @return list<string>
	 */
	private function header_row(): array {
		$all = Axellcore_Store_Config::columns();

		return array_map(
			static fn( string $id ): string => $all[ $id ]['label'],
			$this->columns
		);
	}

	/**
	 * Build the CSV cells for one post.
	 *
	 * @param WP_Post $post Post.
	 * @return list<string>
	 */
	private function row_for( WP_Post $post ): array {
		$location = array();
		foreach ( Axellcore_Fields::LOCATION_LEVELS as $level ) {
			$names              = wp_get_object_terms( $post->ID, $this->config->taxonomies[ $level ], array( 'fields' => 'names' ) );
			$location[ $level ] = is_wp_error( $names ) || empty( $names ) ? '' : (string) $names[0];
		}

		$meta = array();
		foreach ( $this->config->meta_keys as $key => $meta_key ) {
			$meta[ $key ] = (string) get_post_meta( $post->ID, $meta_key, true );
		}

		$values = array(
			'id'       => (string) $post->ID,
			'title'    => $post->post_title,
			'location' => Axellcore_Fields::format_location( $location['country'], $location['state'], $location['city'] ),
			'country'  => $location['country'],
			'state'    => $location['state'],
			'city'     => $location['city'],
			'address'  => $meta['address'],
			'phone'    => Axellcore_Fields::join_values( array( $meta['phone_1'], $meta['phone_2'] ), $this->value_separator ),
			'phone_1'  => $meta['phone_1'],
			'phone_2'  => $meta['phone_2'],
			'website'  => $meta['website'],
			'email'    => $meta['email'],
		);

		$row = array();
		foreach ( $this->columns as $id ) {
			$row[] = Axellcore_Fields::escape_cell( html_entity_decode( $values[ $id ], ENT_QUOTES, 'UTF-8' ) );
		}

		return $row;
	}

	/**
	 * Write one CSV row.
	 *
	 * @param resource $handle File handle.
	 * @param string[] $row    Cells.
	 */
	private function put_row( $handle, array $row ): void {
		fwrite( $handle, Axellcore_Fields::csv_line( $row, $this->delimiter ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
}
