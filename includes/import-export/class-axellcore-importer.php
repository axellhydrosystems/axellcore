<?php
/**
 * Batch CSV importer for a store post type.
 *
 * @package Axellcore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a CSV file in batches and creates or updates posts, matching only by ID.
 */
class Axellcore_Importer {

	/**
	 * Post type configuration.
	 *
	 * @var Axellcore_Store_Config
	 */
	private Axellcore_Store_Config $config;

	/**
	 * Import options.
	 *
	 * @var array{file:string,delimiter:string,encoding:string,mapping:array<int,string>,update_existing:bool,create_terms:bool,value_separator:string,position:int,row:int,lines:int}
	 */
	private array $params;

	/**
	 * Constructor.
	 *
	 * @param Axellcore_Store_Config $config Post type configuration.
	 * @param array<string,mixed>    $params Options (see property docblock).
	 */
	public function __construct( Axellcore_Store_Config $config, array $params ) {
		$known   = array_keys( Axellcore_Store_Config::columns() );
		$mapping = array();

		foreach ( (array) ( $params['mapping'] ?? array() ) as $index => $column ) {
			if ( in_array( $column, $known, true ) ) {
				$mapping[ (int) $index ] = (string) $column;
			}
		}

		$this->config = $config;
		$this->params = array(
			'file'            => (string) $params['file'],
			'delimiter'       => Axellcore_Exporter::clean_delimiter( (string) ( $params['delimiter'] ?? ',' ) ),
			'encoding'        => (string) ( $params['encoding'] ?? 'auto' ),
			'mapping'         => $mapping,
			'update_existing' => ! empty( $params['update_existing'] ),
			'create_terms'    => ! empty( $params['create_terms'] ),
			'value_separator' => '|' === ( $params['value_separator'] ?? ',' ) ? '|' : ',',
			'position'        => max( 0, (int) ( $params['position'] ?? 0 ) ),
			'row'             => max( 0, (int) ( $params['row'] ?? 0 ) ),
			'lines'           => max( 1, (int) ( $params['lines'] ?? 30 ) ),
		);
	}

	/**
	 * Read the header row and (up to) a few sample rows for the mapping step.
	 *
	 * @param int $samples Number of sample rows.
	 * @return array{headers:list<string>,samples:list<list<string>>,invalid_utf8:bool}|WP_Error
	 */
	public function preview( int $samples = 3 ) {
		$handle = $this->open();
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$headers = $this->read_row( $handle, true );
		if ( null === $headers ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'axellcore_empty_file', __( 'The file is empty or uses a different encoding. Try another file or another encoding.', 'axellcore' ) );
		}

		$rows = array();
		while ( $samples > 0 ) {
			$row = $this->read_row( $handle );
			if ( null === $row ) {
				break;
			}
			$rows[] = $row;
			--$samples;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$flat = implode( '', $headers ) . implode( '', array_merge( ...( $rows ? $rows : array( array() ) ) ) );

		return array(
			'headers'      => $headers,
			'samples'      => $rows,
			'invalid_utf8' => str_contains( $flat, "\xEF\xBF\xBD" ),
		);
	}

	/**
	 * Import the next batch of rows.
	 *
	 * @return array<string,mixed>|WP_Error Counters, log and the next file position.
	 */
	public function import_batch() {
		$handle = $this->open();
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$size   = (int) filesize( $this->params['file'] );
		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'log'     => array(),
		);

		if ( 0 === $this->params['position'] ) {
			$this->read_row( $handle, true );
		} else {
			fseek( $handle, $this->params['position'] );
		}

		$start = time();
		$row   = $this->params['row'];
		$count = 0;

		while ( $count < $this->params['lines'] && ( time() - $start ) < 20 ) {
			$cells = $this->read_row( $handle );
			if ( null === $cells ) {
				break;
			}

			++$count;
			++$row;

			$outcome = $this->process_row( $cells );
			++$result[ $outcome['status'] ];

			$imported = 'created' === $outcome['status'] || 'updated' === $outcome['status'];
			$notes    = array_filter( array_merge( $imported ? array() : array( $outcome['message'] ), $outcome['warnings'] ) );

			if ( $notes ) {
				$result['log'][] = array(
					'row'     => $row + 1, // Header is row 1.
					'status'  => $imported ? 'warning' : $outcome['status'],
					'message' => implode( ' ', $notes ),
				);
			}
		}

		$position = (int) ftell( $handle );
		$finished = feof( $handle ) || $position >= $size;
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$result['position'] = $position;
		$result['row']      = $row;
		$result['percent']  = $finished || ! $size ? 100 : (int) floor( $position / $size * 100 );

		return $result;
	}

	/**
	 * Open the file for reading.
	 *
	 * @return resource|WP_Error
	 */
	private function open() {
		$file = $this->params['file'];

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'axellcore_unreadable', __( 'The uploaded file could not be read.', 'axellcore' ) );
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'axellcore_unreadable', __( 'The uploaded file could not be opened.', 'axellcore' ) );
		}

		return $handle;
	}

	/**
	 * Read one CSV row converted to UTF-8, or null at the end of the file.
	 *
	 * Blank lines are skipped.
	 *
	 * @param resource $handle    File handle.
	 * @param bool     $is_header Strip the BOM from the first cell.
	 * @return string[]|null
	 */
	private function read_row( $handle, bool $is_header = false ): ?array {
		do {
			$cells = fgetcsv( $handle, 0, $this->params['delimiter'], '"', '' );
			if ( false === $cells ) {
				return null;
			}
		} while ( array( null ) === $cells );

		$cells = array_map(
			fn( ?string $cell ): string => Axellcore_Fields::to_utf8( (string) $cell, $this->params['encoding'] ),
			$cells
		);

		if ( $is_header && isset( $cells[0] ) ) {
			$cells[0] = Axellcore_Fields::strip_bom( $cells[0] );
		}

		return $cells;
	}

	/**
	 * Create or update one post from a CSV row.
	 *
	 * @param string[] $cells Row cells.
	 * @return array{status:string,message:string,warnings:string[]}
	 */
	private function process_row( array $cells ): array {
		$values = array();
		foreach ( $this->params['mapping'] as $index => $column ) {
			$values[ $column ] = Axellcore_Fields::unescape_cell( trim( $cells[ $index ] ?? '' ) );
		}

		$post_type = $this->config->post_type;
		$post_id   = isset( $values['id'] ) ? absint( $values['id'] ) : 0;

		if ( $post_id ) {
			$existing = get_post( $post_id );

			if ( ! $existing ) {
				/* translators: %d: post ID from the CSV. */
				return $this->result( 'skipped', sprintf( __( 'No record with ID %d.', 'axellcore' ), $post_id ) );
			}
			if ( $existing->post_type !== $post_type ) {
				/* translators: %d: post ID from the CSV. */
				return $this->result( 'skipped', sprintf( __( 'ID %d belongs to a different content type.', 'axellcore' ), $post_id ) );
			}
			if ( ! $this->params['update_existing'] ) {
				/* translators: %d: post ID from the CSV. */
				return $this->result( 'skipped', sprintf( __( 'ID %d already exists and updating is disabled.', 'axellcore' ), $post_id ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				/* translators: %d: post ID from the CSV. */
				return $this->result( 'failed', sprintf( __( 'You do not have permission to edit ID %d.', 'axellcore' ), $post_id ) );
			}
		} else {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
				return $this->result( 'failed', __( 'You do not have permission to create records.', 'axellcore' ) );
			}
			if ( '' === ( $values['title'] ?? '' ) ) {
				return $this->result( 'failed', __( 'The name is required to create a record.', 'axellcore' ) );
			}
		}

		$fields = $this->sanitize( $values );
		if ( is_wp_error( $fields ) ) {
			return $this->result( 'failed', $fields->get_error_message() );
		}

		$term_ids = $this->resolve_terms( $fields['location'] );
		if ( is_wp_error( $term_ids ) ) {
			return $this->result( 'failed', $term_ids->get_error_message() );
		}

		if ( $post_id ) {
			if ( isset( $fields['title'] ) && '' !== $fields['title'] ) {
				$saved = wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $fields['title'],
					),
					true
				);
			} else {
				$saved = $post_id;
			}
		} else {
			$saved = wp_insert_post(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_title'  => $fields['title'],
				),
				true
			);
		}

		if ( is_wp_error( $saved ) ) {
			return $this->result( 'failed', $saved->get_error_message() );
		}

		$saved = (int) $saved;

		foreach ( $fields['meta'] as $key => $value ) {
			update_post_meta( $saved, $this->config->meta_keys[ $key ], $value );
		}

		foreach ( $term_ids as $level => $ids ) {
			wp_set_object_terms( $saved, $ids, $this->config->taxonomies[ $level ] );
		}

		return $this->result( $post_id ? 'updated' : 'created', '', $fields['warnings'] );
	}

	/**
	 * Sanitize the mapped values and split combined columns.
	 *
	 * Only fields whose column is mapped are returned, so unmapped data is left untouched.
	 *
	 * @param array<string,string> $values Raw values by column id.
	 * @return array{title?:string,meta:array<string,string>,location:array<string,string>,warnings:string[]}|WP_Error
	 */
	private function sanitize( array $values ) {
		$out = array(
			'meta'     => array(),
			'location' => array(),
			'warnings' => array(),
		);

		if ( isset( $values['title'] ) ) {
			$out['title'] = sanitize_text_field( $values['title'] );
		}

		if ( isset( $values['address'] ) ) {
			$out['meta']['address'] = sanitize_text_field( $values['address'] );
		}

		// Phones: the combined column first; Phone 1/2 win only when they have a value.
		$from_combined = false;
		if ( isset( $values['phone'] ) ) {
			$phones = Axellcore_Fields::split_values( $values['phone'], $this->params['value_separator'] );
			if ( count( $phones ) > 2 ) {
				return new WP_Error( 'axellcore_phones', __( 'There are more than two phone numbers in the Phone column.', 'axellcore' ) );
			}
			$out['meta']['phone_1'] = sanitize_text_field( $phones[0] ?? '' );
			$out['meta']['phone_2'] = sanitize_text_field( $phones[1] ?? '' );
			$from_combined          = true;
		}
		$columns = Axellcore_Store_Config::columns();
		foreach ( array( 'phone_1', 'phone_2' ) as $key ) {
			if ( ! isset( $values[ $key ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $values[ $key ] );
			if ( $from_combined && '' === $value ) {
				continue;
			}
			if ( $from_combined && '' !== $out['meta'][ $key ] && $out['meta'][ $key ] !== $value ) {
				$out['warnings'][] = sprintf(
					/* translators: 1: column name, 2: value in that column, 3: value in the Phone column. */
					__( '%1$s in the CSV (%2$s) differs from the Phone column (%3$s); the %1$s column was used.', 'axellcore' ),
					$columns[ $key ]['label'],
					$value,
					$out['meta'][ $key ]
				);
			}
			$out['meta'][ $key ] = $value;
		}

		if ( isset( $values['website'] ) ) {
			$url = '' === $values['website'] ? '' : esc_url_raw( $values['website'] );
			if ( '' !== $values['website'] && '' === $url ) {
				return new WP_Error( 'axellcore_url', __( 'Website has an invalid URL.', 'axellcore' ) );
			}
			$out['meta']['website'] = $url;
		}

		if ( isset( $values['email'] ) ) {
			$email = sanitize_email( $values['email'] );
			if ( '' !== $values['email'] && ! is_email( $email ) ) {
				return new WP_Error( 'axellcore_email', __( 'Invalid email address.', 'axellcore' ) );
			}
			$out['meta']['email'] = $email;
		}

		// Location: the combined column first; Country/State/City win only when they have a value.
		$has_combined = isset( $values['location'] );
		if ( $has_combined ) {
			$out['location'] = array_map( 'sanitize_text_field', Axellcore_Fields::parse_location( $values['location'] ) );
		}
		foreach ( Axellcore_Fields::LOCATION_LEVELS as $level ) {
			if ( ! isset( $values[ $level ] ) ) {
				continue;
			}
			$value = sanitize_text_field( $values[ $level ] );
			if ( $has_combined && '' === $value ) {
				continue;
			}
			if ( $has_combined && '' !== $out['location'][ $level ] && 0 !== strcasecmp( $out['location'][ $level ], $value ) ) {
				$out['warnings'][] = sprintf(
					/* translators: 1: column name, 2: value in that column, 3: value in the Location column. */
					__( '%1$s in the CSV (%2$s) differs from the Location column (%3$s); the %1$s column was used.', 'axellcore' ),
					$columns[ $level ]['label'],
					$value,
					$out['location'][ $level ]
				);
			}
			$out['location'][ $level ] = $value;
		}

		return $out;
	}

	/**
	 * Turn location names into term IDs, reusing existing terms and creating only when allowed.
	 *
	 * An empty name clears that level. Levels that are not mapped are not touched.
	 *
	 * @param array<string,string> $location Names by level.
	 * @return array<string,list<int>>|WP_Error
	 */
	private function resolve_terms( array $location ) {
		$ids = array();

		foreach ( $location as $level => $name ) {
			if ( '' === $name ) {
				$ids[ $level ] = array();
				continue;
			}

			$taxonomy = $this->config->taxonomies[ $level ];
			$term     = term_exists( $name, $taxonomy );

			if ( ! $term ) {
				$taxonomy_object = get_taxonomy( $taxonomy );
				if ( ! $this->params['create_terms'] || ! $taxonomy_object || ! current_user_can( $taxonomy_object->cap->manage_terms ) ) {
					/* translators: %s: location name from the CSV. */
					return new WP_Error( 'axellcore_term_missing', sprintf( __( 'The location "%s" does not exist and cannot be created.', 'axellcore' ), $name ) );
				}

				$term = wp_insert_term( $name, $taxonomy );
				if ( is_wp_error( $term ) ) {
					return $term;
				}
			}

			$ids[ $level ] = array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) );
		}

		return $ids;
	}

	/**
	 * Build a row result.
	 *
	 * @param string   $status   created, updated, skipped or failed.
	 * @param string   $message  Reason.
	 * @param string[] $warnings Notes about a row that was still imported.
	 * @return array{status:string,message:string,warnings:string[]}
	 */
	private function result( string $status, string $message, array $warnings = array() ): array {
		return array(
			'status'   => $status,
			'message'  => $message,
			'warnings' => $warnings,
		);
	}
}
