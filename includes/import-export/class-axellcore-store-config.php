<?php
/**
 * Per post type configuration and column schema for the store CSV import/export.
 *
 * @package Axellcore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Describes one exportable post type (resellers or service centers).
 */
class Axellcore_Store_Config {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public string $post_type;

	/**
	 * Admin label (plural).
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Taxonomy slug per location level.
	 *
	 * @var array{country:string,state:string,city:string}
	 */
	public array $taxonomies;

	/**
	 * Post meta key per simple field.
	 *
	 * @var array<string,string>
	 */
	public array $meta_keys;

	/**
	 * Constructor.
	 *
	 * @param string                                         $post_type  Post type slug.
	 * @param string                                         $label      Admin label.
	 * @param array{country:string,state:string,city:string} $taxonomies Taxonomies by level.
	 * @param array<string,string>                           $meta_keys  Meta keys.
	 */
	public function __construct( string $post_type, string $label, array $taxonomies, array $meta_keys ) {
		$this->post_type  = $post_type;
		$this->label      = $label;
		$this->taxonomies = $taxonomies;
		$this->meta_keys  = $meta_keys;
	}

	/**
	 * Configurations for the supported post types.
	 *
	 * @return array<string,Axellcore_Store_Config>
	 */
	public static function all(): array {
		$meta = array(
			'address' => 'endereco',
			'phone_1' => 'telefone-1',
			'phone_2' => 'telefone-2',
			'website' => 'site',
			'email'   => 'e-mail',
		);

		$configs = array(
			'revendas'    => new self(
				'revendas',
				__( 'Resellers', 'axellcore' ),
				array(
					'country' => 'paises',
					'state'   => 'estados',
					'city'    => 'cidades',
				),
				$meta
			),
			'assistencia' => new self(
				'assistencia',
				__( 'Service centers', 'axellcore' ),
				array(
					'country' => 'paises_assistencias',
					'state'   => 'estados_assistencias',
					'city'    => 'cidades_assistencias',
				),
				$meta
			),
		);

		/**
		 * Filters the post types that get a CSV import/export screen.
		 *
		 * @param array<string,Axellcore_Store_Config> $configs Configs keyed by post type.
		 */
		return apply_filters( 'axellcore_store_csv_configs', $configs );
	}

	/**
	 * Column definitions: id => label and header aliases used for auto-mapping.
	 *
	 * Order here is the export order.
	 *
	 * @return array<string,array{label:string,aliases:list<string>}>
	 */
	public static function columns(): array {
		return array(
			'id'       => array(
				'label'   => __( 'ID', 'axellcore' ),
				'aliases' => array( 'id', 'postid' ),
			),
			'title'    => array(
				'label'   => __( 'Name', 'axellcore' ),
				'aliases' => array( 'nome', 'name', 'titulo', 'title' ),
			),
			'location' => array(
				'label'   => __( 'Location', 'axellcore' ),
				'aliases' => array( 'localizacao', 'location' ),
			),
			'country'  => array(
				'label'   => __( 'Country', 'axellcore' ),
				'aliases' => array( 'pais', 'country' ),
			),
			'state'    => array(
				'label'   => __( 'State', 'axellcore' ),
				'aliases' => array( 'estado', 'state', 'uf' ),
			),
			'city'     => array(
				'label'   => __( 'City', 'axellcore' ),
				'aliases' => array( 'cidade', 'city' ),
			),
			'address'  => array(
				'label'   => __( 'Address', 'axellcore' ),
				'aliases' => array( 'endereco', 'address' ),
			),
			'phone'    => array(
				'label'   => __( 'Phone', 'axellcore' ),
				'aliases' => array( 'telefone', 'telefones', 'phone', 'phones' ),
			),
			'phone_1'  => array(
				'label'   => __( 'Phone 1', 'axellcore' ),
				'aliases' => array( 'telefone1', 'phone1' ),
			),
			'phone_2'  => array(
				'label'   => __( 'Phone 2', 'axellcore' ),
				'aliases' => array( 'telefone2', 'phone2' ),
			),
			'website'  => array(
				'label'   => __( 'Website', 'axellcore' ),
				'aliases' => array( 'site', 'website', 'url' ),
			),
			'email'    => array(
				'label'   => __( 'Email', 'axellcore' ),
				'aliases' => array( 'email', 'e-mail' ),
			),
		);
	}

	/**
	 * Default export columns for the chosen location/phone layouts.
	 *
	 * @param bool $split_location Use Country/State/City instead of Location.
	 * @param bool $split_phone    Use Phone 1/Phone 2 instead of Phone.
	 * @return list<string>
	 */
	public static function default_columns( bool $split_location = false, bool $split_phone = false ): array {
		$columns   = array( 'id', 'title' );
		$columns   = array_merge( $columns, $split_location ? array( 'country', 'state', 'city' ) : array( 'location' ) );
		$columns[] = 'address';
		$columns   = array_merge( $columns, $split_phone ? array( 'phone_1', 'phone_2' ) : array( 'phone' ) );

		return array_merge( $columns, array( 'website', 'email' ) );
	}

	/**
	 * Guess the column id for a raw CSV header.
	 *
	 * @param string $header Header text.
	 * @return string Column id or empty string.
	 */
	public static function match_header( string $header ): string {
		$needle = Axellcore_Fields::normalize_header( $header );

		foreach ( self::columns() as $id => $column ) {
			$candidates = array_merge( $column['aliases'], array( $column['label'] ) );
			foreach ( $candidates as $candidate ) {
				if ( Axellcore_Fields::normalize_header( $candidate ) === $needle ) {
					return $id;
				}
			}
		}

		return '';
	}
}
