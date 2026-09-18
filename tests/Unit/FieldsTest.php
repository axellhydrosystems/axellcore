<?php
/**
 * Tests for Axellcore_Fields.
 *
 * @package AxellCore
 */

declare( strict_types=1 );

namespace AxellCore\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/import-export/class-axellcore-fields.php';

/**
 * @covers \Axellcore_Fields
 */
final class FieldsTest extends TestCase {

	/**
	 * @dataProvider locations
	 * @param string $input    Cell.
	 * @param array  $expected Slots.
	 */
	public function test_parse_location( string $input, array $expected ): void {
		$this->assertSame( $expected, \Axellcore_Fields::parse_location( $input ) );
	}

	/**
	 * @return array<string,array>
	 */
	public static function locations(): array {
		return array(
			'full'          => array( 'Brasil > Santa Catarina > Porto União', array( 'country' => 'Brasil', 'state' => 'Santa Catarina', 'city' => 'Porto União' ) ),
			'no state'      => array( 'Brasil >> Canoas', array( 'country' => 'Brasil', 'state' => '', 'city' => 'Canoas' ) ),
			'no country'    => array( '> RS > Canoas', array( 'country' => '', 'state' => 'RS', 'city' => 'Canoas' ) ),
			'city only'     => array( '>> Canoas', array( 'country' => '', 'state' => '', 'city' => 'Canoas' ) ),
			'no city'       => array( 'Brasil > RS >', array( 'country' => 'Brasil', 'state' => 'RS', 'city' => '' ) ),
			'country only'  => array( 'Brasil >', array( 'country' => 'Brasil', 'state' => '', 'city' => '' ) ),
			'bare country'  => array( 'Brasil', array( 'country' => 'Brasil', 'state' => '', 'city' => '' ) ),
			'empty'         => array( '', array( 'country' => '', 'state' => '', 'city' => '' ) ),
		);
	}

	public function test_format_location_output(): void {
		$this->assertSame( 'Brasil > RS > Canoas', \Axellcore_Fields::format_location( 'Brasil', 'RS', 'Canoas' ) );
		$this->assertSame( 'Brasil >> Canoas', \Axellcore_Fields::format_location( 'Brasil', '', 'Canoas' ) );
		$this->assertSame( '> RS > Canoas', \Axellcore_Fields::format_location( '', 'RS', 'Canoas' ) );
		$this->assertSame( '>> Canoas', \Axellcore_Fields::format_location( '', '', 'Canoas' ) );
		$this->assertSame( 'Brasil > RS >', \Axellcore_Fields::format_location( 'Brasil', 'RS', '' ) );
		$this->assertSame( 'Brasil >>', \Axellcore_Fields::format_location( 'Brasil', '', '' ) );
		$this->assertSame( '', \Axellcore_Fields::format_location( '', '', '' ) );
	}

	public function test_parse_location_accepts_both_empty_level_styles(): void {
		$expected = array( 'country' => 'Brasil', 'state' => '', 'city' => 'Canoas' );
		$this->assertSame( $expected, \Axellcore_Fields::parse_location( 'Brasil >> Canoas' ) );
		$this->assertSame( $expected, \Axellcore_Fields::parse_location( 'Brasil >  > Canoas' ) );
		$this->assertSame( $expected, \Axellcore_Fields::parse_location( 'Brasil > > Canoas' ) );
	}

	public function test_format_location_round_trips(): void {
		foreach ( array( 'Brasil > RS > Canoas', 'Brasil >  > Canoas', ' > RS > Canoas', '  >  > Canoas', 'Brasil >  > ' ) as $cell ) {
			$parts = \Axellcore_Fields::parse_location( $cell );
			$this->assertSame(
				\Axellcore_Fields::parse_location( $cell ),
				\Axellcore_Fields::parse_location( \Axellcore_Fields::format_location( $parts['country'], $parts['state'], $parts['city'] ) )
			);
		}
		$this->assertSame( '', \Axellcore_Fields::format_location( '', '', '' ) );
	}

	public function test_split_and_join_values(): void {
		$this->assertSame( array( '51 1111-1111', '51 2222-2222' ), \Axellcore_Fields::split_values( '51 1111-1111, 51 2222-2222' ) );
		$this->assertSame( array( 'a, b', 'c' ), \Axellcore_Fields::split_values( 'a\\, b, c' ) );
		$this->assertSame( array( 'a', 'b' ), \Axellcore_Fields::split_values( 'a | b', '|' ) );
		$this->assertSame( '1, 2', \Axellcore_Fields::join_values( array( '1', '', '2' ) ) );
		$this->assertSame( 'a\\, b', \Axellcore_Fields::join_values( array( 'a, b' ) ) );
	}

	public function test_formula_escape_round_trip(): void {
		foreach ( array( '=SUM(A1)', '+1', '-1', '@x' ) as $value ) {
			$this->assertSame( "'" . $value, \Axellcore_Fields::escape_cell( $value ) );
			$this->assertSame( $value, \Axellcore_Fields::unescape_cell( \Axellcore_Fields::escape_cell( $value ) ) );
		}
		$this->assertSame( 'Rua A', \Axellcore_Fields::escape_cell( 'Rua A' ) );
	}

	public function test_csv_line_quotes_only_when_needed(): void {
		// Plain values and values with inner spaces stay unquoted.
		$this->assertSame( "58,Lady Decor Pisos,Brasil > RS > Canoas\n", \Axellcore_Fields::csv_line( array( '58', 'Lady Decor Pisos', 'Brasil > RS > Canoas' ) ) );
		// Empty cells stay empty.
		$this->assertSame( "a,,b\n", \Axellcore_Fields::csv_line( array( 'a', '', 'b' ) ) );
		// Delimiter, quote, line break and edge whitespace force quotes.
		$this->assertSame( "\"R. Gen. Borman, 289\",x\n", \Axellcore_Fields::csv_line( array( 'R. Gen. Borman, 289', 'x' ) ) );
		$this->assertSame( "\"diz \"\"oi\"\"\"\n", \Axellcore_Fields::csv_line( array( 'diz "oi"' ) ) );
		$this->assertSame( "\"a\nb\"\n", \Axellcore_Fields::csv_line( array( "a\nb" ) ) );
		$this->assertSame( "\" x\",\"y \"\n", \Axellcore_Fields::csv_line( array( ' x', 'y ' ) ) );
		// A semicolon is only special when it is the delimiter.
		$this->assertSame( "a;b,c\n", \Axellcore_Fields::csv_line( array( 'a;b', 'c' ) ) );
		$this->assertSame( "\"a;b\";c\n", \Axellcore_Fields::csv_line( array( 'a;b', 'c' ), ';' ) );
	}

	public function test_csv_line_round_trips_through_a_csv_reader(): void {
		$cells = array( '58', 'Loja "Nova", Centro', "linha1\nlinha2", ' com espaço ', '', "'=HYPERLINK(\"x\")", 'São Paulo' );
		$line  = \Axellcore_Fields::csv_line( $cells );
		$this->assertSame( $cells, str_getcsv( $line, ',', '"', '' ) );
	}

	public function test_encoding_and_bom(): void {
		$this->assertSame( 'abc', \Axellcore_Fields::strip_bom( "\xEF\xBB\xBFabc" ) );
		$this->assertSame( 'São', \Axellcore_Fields::to_utf8( "S\xE3o", 'Windows-1252' ) );
		$this->assertSame( 'São', \Axellcore_Fields::to_utf8( "S\xE3o", 'auto' ) );
		$this->assertSame( 'São', \Axellcore_Fields::to_utf8( 'São', 'auto' ) );
	}

	public function test_detect_delimiter(): void {
		$this->assertSame( ';', \Axellcore_Fields::detect_delimiter( 'ID;Nome;Localização' ) );
		$this->assertSame( ',', \Axellcore_Fields::detect_delimiter( 'ID,Nome' ) );
		$this->assertSame( "\t", \Axellcore_Fields::detect_delimiter( "ID\tNome\tSite" ) );
	}

	public function test_normalize_header(): void {
		$this->assertSame( 'localizacao', \Axellcore_Fields::normalize_header( "\xEF\xBB\xBFLocalização" ) );
		$this->assertSame( 'email', \Axellcore_Fields::normalize_header( 'E-mail' ) );
	}
}
