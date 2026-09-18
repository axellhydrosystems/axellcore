<?php
/**
 * Admin screens, AJAX handlers and file storage for the store CSV import/export.
 *
 * @package Axellcore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Import and Export screens under each supported post type.
 */
class Axellcore_Admin {

	const CAPABILITY = 'manage_options';
	const NONCE      = 'axellcore-import-export';
	const BATCH_SIZE = 30;
	const EXPORT_PER = 100;

	/**
	 * Hook everything.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 20 );
		add_action( 'admin_head', array( __CLASS__, 'hide_submenus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_axellcore_upload_csv', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_axellcore_download_export', array( __CLASS__, 'handle_download' ) );
		add_action( 'wp_ajax_axellcore_import_batch', array( __CLASS__, 'ajax_import_batch' ) );
		add_action( 'wp_ajax_axellcore_export_batch', array( __CLASS__, 'ajax_export_batch' ) );
	}

	/**
	 * Add the submenus.
	 */
	public static function register_menus(): void {
		foreach ( Axellcore_Store_Config::all() as $config ) {
			if ( ! post_type_exists( $config->post_type ) ) {
				continue;
			}

			$parent = 'edit.php?post_type=' . $config->post_type;

			add_submenu_page(
				$parent,
				/* translators: %s: post type label (lower case on the page title). */
				sprintf( __( 'Import %s', 'axellcore' ), $config->label ),
				__( 'Import', 'axellcore' ),
				self::CAPABILITY,
				'axellcore-import-' . $config->post_type,
				array( __CLASS__, 'render_import' )
			);
			add_submenu_page(
				$parent,
				/* translators: %s: post type label (lower case on the page title). */
				sprintf( __( 'Export %s', 'axellcore' ), $config->label ),
				__( 'Export', 'axellcore' ),
				self::CAPABILITY,
				'axellcore-export-' . $config->post_type,
				array( __CLASS__, 'render_export' )
			);
		}
	}

	/**
	 * Hide our pages from the submenu while keeping them reachable.
	 *
	 * Removing the entries any earlier would also revoke access to the pages, so this
	 * runs in admin_head, after WordPress has checked access and before the menu renders.
	 */
	public static function hide_submenus(): void {
		foreach ( Axellcore_Store_Config::all() as $config ) {
			$parent = 'edit.php?post_type=' . $config->post_type;
			remove_submenu_page( $parent, 'axellcore-import-' . $config->post_type );
			remove_submenu_page( $parent, 'axellcore-export-' . $config->post_type );
		}
	}

	/**
	 * Enqueue assets on our screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'edit.php' === $hook_suffix ) {
			self::enqueue_list_links();
			return;
		}

		if ( ! str_contains( $hook_suffix, '_page_axellcore-import-' ) && ! str_contains( $hook_suffix, '_page_axellcore-export-' ) ) {
			return;
		}

		wp_enqueue_style( 'axellcore-import-export', AXELLCORE_URL . 'assets/css/admin/import-export.css', array(), AXELLCORE_VERSION );
		wp_enqueue_script( 'axellcore-import-export', AXELLCORE_URL . 'assets/js/admin/import-export.js', array(), AXELLCORE_VERSION, true );
		wp_localize_script(
			'axellcore-import-export',
			'axellcoreIE',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'complete'     => __( 'Import complete!', 'axellcore' ),
					/* translators: %s: number of records. */
					'created'      => array( __( '%s record imported', 'axellcore' ), __( '%s records imported', 'axellcore' ) ),
					/* translators: %s: number of records. */
					'updated'      => array( __( '%s record updated', 'axellcore' ), __( '%s records updated', 'axellcore' ) ),
					/* translators: %s: number of records. */
					'skipped'      => array( __( '%s record was skipped', 'axellcore' ), __( '%s records were skipped', 'axellcore' ) ),
					/* translators: %s: number of records. */
					'failed'       => array( __( 'Failed to import %s record', 'axellcore' ), __( 'Failed to import %s records', 'axellcore' ) ),
					'viewLog'      => __( 'View import log', 'axellcore' ),
					/* translators: %s: file name. */
					'fileUploaded' => __( 'File uploaded: %s', 'axellcore' ),
					'row'          => __( 'Row', 'axellcore' ),
					'reason'       => __( 'Reason for failure', 'axellcore' ),
					'error'        => __( 'An error occurred. Please try again.', 'axellcore' ),
				),
			)
		);
	}

	/**
	 * Add "Import" and "Export" next to the "Add New" button of the list screen.
	 */
	private static function enqueue_list_links(): void {
		$screen  = get_current_screen();
		$configs = Axellcore_Store_Config::all();

		if ( ! $screen || ! isset( $configs[ $screen->post_type ] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$post_type = $screen->post_type;
		$base      = admin_url( 'edit.php?post_type=' . $post_type . '&page=' );

		wp_enqueue_script( 'axellcore-list-links', AXELLCORE_URL . 'assets/js/admin/list-links.js', array(), AXELLCORE_VERSION, true );
		wp_localize_script(
			'axellcore-list-links',
			'axellcoreLinks',
			array(
				array(
					'label' => __( 'Import', 'axellcore' ),
					'url'   => $base . 'axellcore-import-' . $post_type,
				),
				array(
					'label' => __( 'Export', 'axellcore' ),
					'url'   => $base . 'axellcore-export-' . $post_type,
				),
			)
		);
	}

	/**
	 * Resolve the config for the current screen, or die.
	 *
	 * @return Axellcore_Store_Config
	 */
	private static function current_config(): Axellcore_Store_Config {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$configs   = Axellcore_Store_Config::all();

		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $configs[ $post_type ] ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'axellcore' ), '', array( 'response' => 403 ) );
		}

		return $configs[ $post_type ];
	}

	/**
	 * Directory for temporary files (created and protected on demand).
	 *
	 * @return string Absolute path with trailing slash.
	 */
	private static function storage_dir(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'axellcore-import/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Remove leftovers older than a day.
		foreach ( (array) glob( $dir . '*.csv' ) as $old ) {
			if ( is_file( $old ) && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}

		return $dir;
	}

	/**
	 * Path for a token, or empty string when the token is malformed.
	 *
	 * @param string $token 32 hex characters.
	 * @return string
	 */
	private static function path_for( string $token ): string {
		return preg_match( '/^[a-f0-9]{32}$/', $token ) ? self::storage_dir() . $token . '.csv' : '';
	}

	/**
	 * Steps of the import wizard, as shown in the progress list.
	 *
	 * @return array<int,string>
	 */
	private static function import_steps(): array {
		return array(
			__( 'Upload CSV file', 'axellcore' ),
			__( 'Column mapping', 'axellcore' ),
			__( 'Import', 'axellcore' ),
			__( 'Done!', 'axellcore' ),
		);
	}

	/**
	 * Common page header with the step list.
	 *
	 * @param string            $title   Page title.
	 * @param array<int,string> $steps   Step labels.
	 * @param int               $current Current step (1-based), 0 for none.
	 */
	private static function open_page( string $title, array $steps, int $current ): void {
		echo '<div class="wrap axc-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1><hr class="wp-header-end">';
		if ( ! $steps ) {
			return;
		}
		echo '<ol class="axc-steps" aria-label="' . esc_attr__( 'Steps', 'axellcore' ) . '">';
		foreach ( $steps as $index => $label ) {
			$number = $index + 1;
			$class  = $number === $current ? ' is-current' : ( $number < $current ? ' is-done' : '' );
			printf(
				'<li class="axc-step%1$s"%2$s><span class="axc-step__number">%3$d</span>%4$s</li>',
				esc_attr( $class ),
				$number === $current ? ' aria-current="step"' : '',
				(int) $number,
				esc_html( $label )
			);
		}
		echo '</ol>';
	}

	/**
	 * Close the page wrapper.
	 */
	private static function close_page(): void {
		echo '</div>';
	}

	/**
	 * Encodings offered in the import form (real text encodings only).
	 *
	 * @return string[]
	 */
	private static function encoding_choices(): array {
		$skip = array( 'pass', 'auto', 'wchar', 'byte2be', 'byte2le', 'byte4be', 'byte4le', 'base64', 'uuencode', 'html-entities', 'html', 'quoted-printable', '7bit', '8bit' );
		$list = array_filter(
			mb_list_encodings(),
			static fn( string $encoding ): bool => ! in_array( strtolower( $encoding ), $skip, true )
		);

		sort( $list, SORT_NATURAL | SORT_FLAG_CASE );

		return array_values( $list );
	}

	/**
	 * Validate an encoding name; anything unknown means auto-detect.
	 *
	 * @param string $value Requested encoding.
	 * @return string
	 */
	private static function clean_encoding( string $value ): string {
		foreach ( self::encoding_choices() as $choice ) {
			if ( 0 === strcasecmp( $choice, $value ) ) {
				return $choice;
			}
		}

		return 'auto';
	}

	/**
	 * Import screen: upload form, or mapping + progress when a file is present.
	 */
	public static function render_import(): void {
		$config = self::current_config();
		$steps  = self::import_steps();
		$lower  = mb_strtolower( $config->label );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified below when a file token is present.
		$token = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';
		$error = isset( $_GET['axc_error'] ) ? sanitize_text_field( wp_unslash( $_GET['axc_error'] ) ) : '';
		// phpcs:enable

		if ( '' !== $token ) {
			check_admin_referer( 'axellcore-import-' . $token );
			self::render_mapping( $config, $token, $steps );
			return;
		}

		/* translators: %s: post type label (lower case on the page title). */
		self::open_page( sprintf( __( 'Import %s', 'axellcore' ), $lower ), $steps, 1 );

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form id="axc-upload" class="axc-card" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="axellcore_upload_csv">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( $config->post_type ) . '">';
		wp_nonce_field( 'axellcore-upload-' . $config->post_type );

		/* translators: %s: post type label in lower case. */
		echo '<h2 class="axc-card__title">' . esc_html( sprintf( __( 'Import %s from a CSV file', 'axellcore' ), $lower ) ) . '</h2>';
		/* translators: %s: post type label in lower case. */
		echo '<p class="axc-help">' . esc_html( sprintf( __( 'This tool allows you to import (or merge) %s data to your site from a CSV or TXT file.', 'axellcore' ), $lower ) ) . '</p>';

		echo '<div class="axc-row"><label for="axc-file">' . esc_html__( 'Choose a CSV file from your computer:', 'axellcore' ) . '</label><div>';
		echo '<input type="file" id="axc-file" name="import" size="25" accept=".csv,.txt,text/csv">';
		/* translators: %s: maximum upload size, e.g. "8 MB". */
		echo '<p class="axc-help">' . esc_html( sprintf( __( 'Maximum size: %s', 'axellcore' ), size_format( wp_max_upload_size() ) ) ) . '</p>';
		echo '</div></div>';

		/* translators: %s: post type label in lower case. */
		echo '<div class="axc-row"><label for="axc-update">' . esc_html( sprintf( __( 'Update existing %s', 'axellcore' ), $lower ) ) . '</label><div>';
		echo '<label><input type="checkbox" id="axc-update" name="update_existing" value="1"> ';
		/* translators: %s: post type label in lower case. */
		echo esc_html( sprintf( __( 'Records of %s with an existing ID will be updated. IDs that do not exist will be skipped, and rows without an ID create new records.', 'axellcore' ), $lower ) ) . '</label>';
		echo '</div></div>';

		echo '<div class="axc-advanced" id="axc-advanced" hidden>';
		echo '<div class="axc-row"><label for="axc-file-url">' . esc_html__( 'Alternatively, enter the path to a CSV file on your server:', 'axellcore' ) . '</label><div><span class="axc-path"><code>' . esc_html( ABSPATH ) . '</code><input type="text" id="axc-file-url" name="file_url"></span></div></div>';
		echo '<div class="axc-row"><label for="axc-delimiter">' . esc_html__( 'CSV delimiter', 'axellcore' ) . '</label><div><input type="text" id="axc-delimiter" name="delimiter" placeholder="," size="2" maxlength="3"></div></div>';
		echo '<div class="axc-row"><label for="axc-map-preferences">' . esc_html__( 'Use previous column mapping preferences?', 'axellcore' ) . '</label><div><input type="checkbox" id="axc-map-preferences" name="map_preferences" value="1"></div></div>';
		echo '<div class="axc-row"><label for="axc-encoding">' . esc_html__( 'Character encoding of the file', 'axellcore' ) . '</label><div><select id="axc-encoding" name="encoding"><option value="" selected>' . esc_html__( 'Autodetect', 'axellcore' ) . '</option>';
		foreach ( self::encoding_choices() as $encoding ) {
			echo '<option>' . esc_html( $encoding ) . '</option>';
		}
		echo '</select></div></div>';
		echo '</div>';

		echo '<div class="axc-actions axc-actions--split">';
		echo '<button type="button" class="axc-summary-toggle" aria-expanded="false" aria-controls="axc-advanced"><span class="axc-chevron" aria-hidden="true"></span><span class="axc-summary-toggle__show">' . esc_html__( 'Show advanced options', 'axellcore' ) . '</span><span class="axc-summary-toggle__hide">' . esc_html__( 'Hide advanced options', 'axellcore' ) . '</span></button>';
		echo '<button type="submit" class="button button-primary" disabled>' . esc_html__( 'Continue', 'axellcore' ) . '</button>';
		echo '</div>';
		echo '</form>';

		self::close_page();
	}

	/**
	 * Store the uploaded file and redirect to the mapping step.
	 */
	public static function handle_upload(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right below with the post-type specific action.
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$configs   = Axellcore_Store_Config::all();

		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $configs[ $post_type ] ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'axellcore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axellcore-upload-' . $post_type );

		$back = admin_url( 'edit.php?post_type=' . $post_type . '&page=axellcore-import-' . $post_type );

		$fail = static function ( string $message ) use ( $back ): void {
			wp_safe_redirect( add_query_arg( 'axc_error', $message, $back ) );
			exit;
		};

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- File array is validated below.
		$upload = isset( $_FILES['import'] ) && is_array( $_FILES['import'] ) ? $_FILES['import'] : array();
		// phpcs:enable
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$file_url = isset( $_POST['file_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['file_url'] ) ) ) : '';

		if ( ! empty( $upload['tmp_name'] ) ) {
			if ( UPLOAD_ERR_OK !== (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
				$fail( __( 'The file upload failed.', 'axellcore' ) );
			}
			$source    = (string) $upload['tmp_name'];
			$file_name = sanitize_file_name( (string) $upload['name'] );
			$is_upload = true;
		} elseif ( '' !== $file_url ) {
			// Server path, relative to the WordPress root. Only files inside the uploads folder are allowed.
			$real    = realpath( ABSPATH . ltrim( $file_url, '/\\' ) );
			$uploads = wp_upload_dir();
			$base    = realpath( $uploads['basedir'] );

			if ( false === $real || false === $base || ! str_starts_with( $real, trailingslashit( $base ) ) || ! is_file( $real ) || ! is_readable( $real ) ) {
				$fail( __( 'The file path provided is invalid. Use a file inside the uploads folder.', 'axellcore' ) );
			}
			$source    = (string) $real;
			$file_name = sanitize_file_name( basename( $real ) );
			$is_upload = false;
		} else {
			$fail( __( 'The file upload failed.', 'axellcore' ) );
		}

		$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			$fail( __( 'Invalid file type. The importer supports CSV and TXT file formats.', 'axellcore' ) );
		}

		$sample = (string) file_get_contents( $source, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $sample || str_contains( $sample, "\0" ) || str_contains( $sample, '<?php' ) ) {
			$fail( __( 'The file is empty or is not a valid text CSV.', 'axellcore' ) );
		}

		$token = bin2hex( random_bytes( 16 ) );
		$path  = self::path_for( $token );

		// Uploads are moved; server files are copied so the original is never touched or deleted.
		$stored = $is_upload ? move_uploaded_file( $source, $path ) : copy( $source, $path ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
		if ( ! $stored ) {
			$fail( __( 'The uploaded file could not be saved.', 'axellcore' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$encoding = self::clean_encoding( isset( $_POST['encoding'] ) ? sanitize_text_field( wp_unslash( $_POST['encoding'] ) ) : '' );
		$chosen   = isset( $_POST['delimiter'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) ) : '';
		$update   = ! empty( $_POST['update_existing'] );
		$prefs    = ! empty( $_POST['map_preferences'] );
		// phpcs:enable

		// An empty delimiter means auto-detect from the header line.
		if ( '' === $chosen ) {
			$first_line = strtok( Axellcore_Fields::strip_bom( $sample ), "\r\n" );
			$chosen     = Axellcore_Fields::detect_delimiter( false === $first_line ? '' : $first_line );
		}
		$delimiter = Axellcore_Exporter::clean_delimiter( $chosen );

		$url = add_query_arg(
			array(
				'file'            => $token,
				'delimiter'       => "\t" === $delimiter ? 'tab' : $delimiter,
				'encoding'        => $encoding,
				'update_existing' => $update ? 1 : 0,
				'map_preferences' => $prefs ? 1 : 0,
				'file_name'       => $file_name,
			),
			$back
		);

		wp_safe_redirect( add_query_arg( '_wpnonce', wp_create_nonce( 'axellcore-import-' . $token ), $url ) );
		exit;
	}

	/**
	 * Saved column mapping preferences for the current user.
	 *
	 * @param Axellcore_Store_Config $config Post type configuration.
	 * @return array<string,string> Normalised header => column id.
	 */
	private static function saved_mapping( Axellcore_Store_Config $config ): array {
		$saved = get_user_meta( get_current_user_id(), 'axellcore_map_' . $config->post_type, true );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Mapping step and progress UI.
	 *
	 * @param Axellcore_Store_Config $config Post type configuration.
	 * @param string                 $token  File token.
	 * @param array<int,string>      $steps  Step labels.
	 */
	private static function render_mapping( Axellcore_Store_Config $config, string $token, array $steps ): void {
		$path  = self::path_for( $token );
		$lower = mb_strtolower( $config->label );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified in render_import().
		$delimiter = isset( $_GET['delimiter'] ) ? sanitize_text_field( wp_unslash( $_GET['delimiter'] ) ) : ',';
		$encoding  = self::clean_encoding( isset( $_GET['encoding'] ) ? sanitize_text_field( wp_unslash( $_GET['encoding'] ) ) : '' );
		$update    = ! empty( $_GET['update_existing'] );
		$prefs     = ! empty( $_GET['map_preferences'] );
		$file_name = isset( $_GET['file_name'] ) ? sanitize_file_name( wp_unslash( $_GET['file_name'] ) ) : '';
		// phpcs:enable

		$back = admin_url( 'edit.php?post_type=' . $config->post_type . '&page=axellcore-import-' . $config->post_type );

		if ( '' === $path || ! is_file( $path ) ) {
			wp_safe_redirect( add_query_arg( 'axc_error', __( 'The uploaded file has expired. Please upload it again.', 'axellcore' ), $back ) );
			exit;
		}

		$importer = new Axellcore_Importer(
			$config,
			array(
				'file'      => $path,
				'delimiter' => $delimiter,
				'encoding'  => $encoding,
			)
		);
		$preview  = $importer->preview();

		/* translators: %s: post type label (lower case on the page title). */
		self::open_page( sprintf( __( 'Import %s', 'axellcore' ), $lower ), $steps, 2 );

		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $preview->get_error_message() ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Back', 'axellcore' ) . '</a></p>';
			self::close_page();
			return;
		}

		if ( $preview['invalid_utf8'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Invalid characters were found. The file encoding may be wrong; go back and choose another encoding.', 'axellcore' ) . '</p></div>';
		}

		$attributes = array(
			'data-post-type'       => $config->post_type,
			'data-file'            => $token,
			'data-delimiter'       => $delimiter,
			'data-encoding'        => $encoding,
			'data-update-existing' => $update ? '1' : '0',
			'data-file-name'       => $file_name,
			'data-list'            => admin_url( 'edit.php?post_type=' . $config->post_type ),
		);
		$html       = '';
		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		echo '<div id="axc-import"' . $html . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped above.

		echo '<form id="axc-import-form" class="axc-card">';
		/* translators: %s: post type label in lower case. */
		echo '<h2 class="axc-card__title">' . esc_html( sprintf( __( 'Map CSV fields to %s', 'axellcore' ), $lower ) ) . '</h2>';
		/* translators: %s: post type label in lower case. */
		echo '<p class="axc-help">' . esc_html( sprintf( __( 'Select fields from your CSV file to map against %s fields, or to ignore during import.', 'axellcore' ), $lower ) ) . '</p>';

		$columns = Axellcore_Store_Config::columns();
		$saved   = $prefs ? self::saved_mapping( $config ) : array();

		echo '<p class="axc-note" id="axc-priority-note" hidden>' . esc_html__( 'You mapped both the combined and the separate version of the same data. The separate columns (Country, State, City, Phone 1, Phone 2) take priority when they have a value; when they are empty, the combined column is used. Differences are shown in the log.', 'axellcore' ) . '</p>';
		echo '<table class="widefat axc-table"><thead><tr><th>' . esc_html__( 'Column name', 'axellcore' ) . '</th><th>' . esc_html__( 'Map to field', 'axellcore' ) . '</th></tr></thead><tbody>';

		foreach ( $preview['headers'] as $index => $header ) {
			$key    = Axellcore_Fields::normalize_header( $header );
			$guess  = isset( $saved[ $key ] ) && isset( $columns[ $saved[ $key ] ] ) ? $saved[ $key ] : Axellcore_Store_Config::match_header( $header );
			$sample = $preview['samples'][0][ $index ] ?? '';

			echo '<tr><td class="axc-table__name">' . esc_html( $header );
			if ( '' !== $sample ) {
				echo '<span class="description">' . esc_html__( 'Sample:', 'axellcore' ) . ' <code>' . esc_html( $sample ) . '</code></span>';
			}
			echo '</td><td>';
			printf( '<input type="hidden" name="map_from[%d]" value="%s">', (int) $index, esc_attr( $header ) );
			printf( '<select name="mapping[%d]" aria-label="%s">', (int) $index, esc_attr( $header ) );
			echo '<option value="">' . esc_html__( 'Do not import', 'axellcore' ) . '</option><option value="" disabled>--------------</option>';
			foreach ( $columns as $id => $column ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $id ), selected( $guess, $id, false ), esc_html( $column['label'] ) );
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="axc-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Run the importer', 'axellcore' ) . '</button></div>';
		echo '</form>';

		echo '<div id="axc-progress" class="axc-card" hidden>';
		echo '<h2 class="axc-card__title">' . esc_html__( 'Importing', 'axellcore' ) . '</h2>';
		/* translators: %s: post type label in lower case. */
		echo '<p class="axc-help">' . esc_html( sprintf( __( 'Your %s data is now being imported...', 'axellcore' ), $lower ) ) . '</p>';
		echo '<div class="axc-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>';
		echo '</div>';

		echo '<div id="axc-done" class="axc-card axc-done" hidden><p id="axc-done-text" aria-live="polite"></p><div id="axc-log" hidden></div>';
		echo '<div class="axc-actions"><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=' . $config->post_type ) ) . '">' . esc_html( $config->label ) . '</a></div></div>';

		echo '</div>';

		self::close_page();
	}

	/**
	 * Export screen.
	 */
	public static function render_export(): void {
		$config = self::current_config();
		$lower  = mb_strtolower( $config->label );

		/* translators: %s: post type label (lower case on the page title). */
		self::open_page( sprintf( __( 'Export %s', 'axellcore' ), $lower ), array(), 0 );

		echo '<form id="axc-export" class="axc-card" data-post-type="' . esc_attr( $config->post_type ) . '">';
		/* translators: %s: post type label in lower case. */
		echo '<h2 class="axc-card__title">' . esc_html( sprintf( __( 'Export %s to a CSV file', 'axellcore' ), $lower ) ) . '</h2>';
		/* translators: %s: post type label in lower case. */
		echo '<p class="axc-help">' . esc_html( sprintf( __( 'This tool allows you to generate and download a CSV file containing a list of all %s.', 'axellcore' ), $lower ) ) . '</p>';

		echo '<div id="axc-export-fields">';

		// Columns: nothing pre-selected; an empty choice exports the default set.
		$options = array();
		foreach ( Axellcore_Store_Config::columns() as $id => $column ) {
			$options[ $id ] = array(
				'label'    => $column['label'],
				'selected' => false,
			);
		}
		self::select_row( 'axc-columns', 'columns[]', __( 'Which columns should be exported?', 'axellcore' ), __( 'Export all default columns', 'axellcore' ), $options );

		// Location filters: one multi-select per level, listing the existing terms.
		$filters = array(
			'country' => array(
				'name'        => 'countries[]',
				/* translators: %s: post type label in lower case. */
				'label'       => sprintf( __( 'Which countries of %s should be exported?', 'axellcore' ), $lower ),
				'placeholder' => __( 'Export all countries', 'axellcore' ),
			),
			'state'   => array(
				'name'        => 'states[]',
				/* translators: %s: post type label in lower case. */
				'label'       => sprintf( __( 'Which states of %s should be exported?', 'axellcore' ), $lower ),
				'placeholder' => __( 'Export all states', 'axellcore' ),
			),
			'city'    => array(
				'name'        => 'cities[]',
				/* translators: %s: post type label in lower case. */
				'label'       => sprintf( __( 'Which cities of %s should be exported?', 'axellcore' ), $lower ),
				'placeholder' => __( 'Export all cities', 'axellcore' ),
			),
		);
		foreach ( $filters as $level => $filter ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $config->taxonomies[ $level ],
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);
			$items = array();
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$items[ $term->term_id ] = array(
					'label'    => $term->name,
					'selected' => false,
				);
			}
			self::select_row( 'axc-filter-' . $level, $filter['name'], $filter['label'], $filter['placeholder'], $items );
		}

		echo '</div>';

		echo '<div id="axc-export-progress" hidden><div class="axc-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div><p id="axc-export-status" aria-live="polite"></p></div>';
		echo '<div class="axc-actions"><button type="submit" class="button button-primary">' . esc_html__( 'Generate CSV', 'axellcore' ) . '</button></div>';
		echo '</form>';

		self::close_page();
	}

	/**
	 * Print one "label + multi-select" row.
	 *
	 * @param string                                              $id          Select id.
	 * @param string                                              $name        Field name.
	 * @param string                                              $label       Row label.
	 * @param string                                              $placeholder Text shown when nothing is selected.
	 * @param array<int|string,array{label:string,selected:bool}> $options     Options by value.
	 */
	private static function select_row( string $id, string $name, string $label, string $placeholder, array $options ): void {
		echo '<div class="axc-row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div>';
		printf( '<select id="%1$s" name="%2$s" multiple class="axc-columns" data-placeholder="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $placeholder ) );
		foreach ( $options as $value => $option ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $value ), $option['selected'] ? ' selected' : '', esc_html( $option['label'] ) );
		}
		echo '</select></div></div>';
	}

	/**
	 * Common AJAX guard: nonce, capability and post type.
	 *
	 * @return Axellcore_Store_Config
	 */
	private static function ajax_config(): Axellcore_Store_Config {
		check_ajax_referer( self::NONCE, 'security' );

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$configs   = Axellcore_Store_Config::all();

		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $configs[ $post_type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'axellcore' ) ), 403 );
		}

		return $configs[ $post_type ];
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- ajax_config() calls check_ajax_referer().
	/**
	 * AJAX: import the next batch.
	 */
	public static function ajax_import_batch(): void {
		$config = self::ajax_config();

		$token = isset( $_POST['file'] ) ? sanitize_key( wp_unslash( $_POST['file'] ) ) : '';
		$path  = self::path_for( $token );

		if ( '' === $path || ! is_file( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'The uploaded file has expired. Please upload it again.', 'axellcore' ) ) );
		}

		$mapping = array();
		if ( isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ) {
			foreach ( wp_unslash( $_POST['mapping'] ) as $index => $column ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is checked against the known column list in the importer.
				$mapping[ absint( $index ) ] = sanitize_key( (string) $column );
			}
		}

		$importer = new Axellcore_Importer(
			$config,
			array(
				'file'            => $path,
				'delimiter'       => isset( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : ',',
				'encoding'        => self::clean_encoding( isset( $_POST['encoding'] ) ? sanitize_text_field( wp_unslash( $_POST['encoding'] ) ) : '' ),
				'mapping'         => $mapping,
				'update_existing' => ! empty( $_POST['update_existing'] ),
				'create_terms'    => true,
				'value_separator' => ',',
				'position'        => isset( $_POST['position'] ) ? absint( $_POST['position'] ) : 0,
				'row'             => isset( $_POST['row'] ) ? absint( $_POST['row'] ) : 0,
				/**
				 * Filters how many CSV rows are imported per AJAX request.
				 *
				 * @param int $size Batch size.
				 */
				'lines'           => (int) apply_filters( 'axellcore_import_batch_size', self::BATCH_SIZE ),
			)
		);

		if ( 0 === ( isset( $_POST['position'] ) ? absint( $_POST['position'] ) : 0 ) ) {
			self::save_mapping( $config, $mapping );
		}

		$result = $importer->import_batch();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( 100 === $result['percent'] ) {
			wp_delete_file( $path );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: export the next page and, when done, return the download URL.
	 */
	public static function ajax_export_batch(): void {
		$config = self::ajax_config();

		$page  = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';

		if ( 1 === $page ) {
			$token = bin2hex( random_bytes( 16 ) );
		}
		$path = self::path_for( $token );
		if ( '' === $path || ( $page > 1 && ! is_file( $path ) ) ) {
			wp_send_json_error( array( 'message' => __( 'The export has expired. Please start again.', 'axellcore' ) ) );
		}

		$columns = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['columns'] ) ) : array();

		$exporter = new Axellcore_Exporter(
			$config,
			array(
				'columns'         => $columns,
				'delimiter'       => ',',
				'value_separator' => ',',
				'published_only'  => false,
				'bom'             => true,
				'terms'           => array(
					'country' => self::posted_ids( 'countries' ),
					'state'   => self::posted_ids( 'states' ),
					'city'    => self::posted_ids( 'cities' ),
				),
			)
		);

		$result = $exporter->export_page( $path, $page, self::EXPORT_PER );

		$result['token'] = $token;
		$result['page']  = $page;

		if ( 100 === $result['percent'] || $result['done'] >= $result['total'] ) {
			$result['percent'] = 100;
			$result['url']     = add_query_arg(
				array(
					'action'    => 'axellcore_download_export',
					'post_type' => $config->post_type,
					'token'     => $token,
					'_wpnonce'  => wp_create_nonce( 'axellcore-download-' . $token ),
				),
				admin_url( 'admin-post.php' )
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Remember the column mapping (by header name) for the next import.
	 *
	 * @param Axellcore_Store_Config $config  Post type configuration.
	 * @param array<int,string>      $mapping Column id by CSV column index.
	 */
	private static function save_mapping( Axellcore_Store_Config $config, array $mapping ): void {
		$headers = isset( $_POST['map_from'] ) && is_array( $_POST['map_from'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['map_from'] ) ) : array();
		$known   = Axellcore_Store_Config::columns();
		$saved   = array();

		foreach ( $mapping as $index => $column ) {
			if ( isset( $known[ $column ], $headers[ $index ] ) ) {
				$saved[ Axellcore_Fields::normalize_header( $headers[ $index ] ) ] = $column;
			}
		}

		update_user_meta( get_current_user_id(), 'axellcore_map_' . $config->post_type, $saved );
	}

	/**
	 * Read a posted list of IDs (nonce already verified by ajax_config()).
	 *
	 * @param string $key POST key.
	 * @return int[]
	 */
	private static function posted_ids( string $key ): array {
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'absint', wp_unslash( $_POST[ $key ] ) ) : array();
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce is verified with check_admin_referer() below.
	/**
	 * Stream the finished export and delete the temporary file.
	 */
	public static function handle_download(): void {
		$token     = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$configs   = Axellcore_Store_Config::all();

		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $configs[ $post_type ] ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'axellcore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axellcore-download-' . $token );

		$path = self::path_for( $token );
		if ( '' === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The export file is no longer available.', 'axellcore' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $post_type . '-' . gmdate( 'Y-m-d' ) . '.csv' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $path );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
