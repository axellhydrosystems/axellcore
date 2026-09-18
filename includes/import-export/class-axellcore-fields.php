<?php
/**
 * Pure helpers to parse and format CSV cell values (no WordPress state).
 *
 * @package Axellcore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cell-level parsing and formatting for the store CSV import/export.
 */
class Axellcore_Fields {

	/**
	 * Location levels in the order used by the "Location" column.
	 *
	 * @var list<string>
	 */
	const LOCATION_LEVELS = array( 'country', 'state', 'city' );

	/**
	 * Split a "Country > State > City" string into its three slots.
	 *
	 * Empty levels are allowed and keep their position: "Brasil >> Canoas" has no
	 * state, "> RS > Canoas" has no country and "Brasil >" has no state or city.
	 *
	 * @param string $value Raw cell value.
	 * @return array{country:string,state:string,city:string}
	 */
	public static function parse_location( string $value ): array {
		$parts = array_map( 'trim', explode( '>', $value, 3 ) );
		$parts = array_pad( $parts, 3, '' );

		return array(
			'country' => $parts[0],
			'state'   => $parts[1],
			'city'    => $parts[2],
		);
	}

	/**
	 * Build the "Country > State > City" string.
	 *
	 * Empty levels keep their position and are written without spaces, so a missing
	 * state reads "Brasil >> Canoas" and a missing country "> RS > Canoas".
	 *
	 * @param string $country Country name.
	 * @param string $state   State name.
	 * @param string $city    City name.
	 * @return string
	 */
	public static function format_location( string $country, string $state, string $city ): string {
		if ( '' === $country && '' === $state && '' === $city ) {
			return '';
		}

		$levels = array_map(
			static fn( string $name ): string => '' === $name ? '' : ' ' . $name . ' ',
			array( $country, $state, $city )
		);

		return trim( implode( '>', $levels ) );
	}

	/**
	 * Split a multi-value cell. A backslash before the separator escapes it.
	 *
	 * @param string $value     Raw cell value.
	 * @param string $separator Single-character separator.
	 * @return list<string>
	 */
	public static function split_values( string $value, string $separator = ',' ): array {
		$marker = "\x1F";
		$value  = str_replace( '\\' . $separator, $marker, $value );
		$parts  = array();

		foreach ( explode( $separator, $value ) as $part ) {
			$part = trim( str_replace( $marker, $separator, $part ) );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * Join values into one cell, escaping the separator inside each value.
	 *
	 * @param string[] $values    Values to join.
	 * @param string   $separator Single-character separator.
	 * @return string
	 */
	public static function join_values( array $values, string $separator = ',' ): string {
		$out = array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$out[] = str_replace( $separator, '\\' . $separator, $value );
			}
		}

		return implode( $separator . ' ', $out );
	}

	/**
	 * Build one CSV line, quoting a cell only when it needs it.
	 *
	 * A cell is quoted when it contains the delimiter, a double quote or a line break,
	 * or when it has leading or trailing whitespace that a reader could trim. Quotes
	 * inside a quoted cell are doubled (RFC 4180). PHP's fputcsv() also quotes every
	 * cell that merely contains a space, which is why it is not used here.
	 *
	 * @param string[] $cells     Cell values.
	 * @param string   $delimiter Single-character delimiter.
	 * @return string Line ending in "\n".
	 */
	public static function csv_line( array $cells, string $delimiter = ',' ): string {
		$out = array();

		foreach ( $cells as $cell ) {
			$cell = (string) $cell;

			$needs_quotes = '' !== $cell && (
				str_contains( $cell, $delimiter )
				|| str_contains( $cell, '"' )
				|| str_contains( $cell, "\n" )
				|| str_contains( $cell, "\r" )
				|| trim( $cell ) !== $cell
			);

			$out[] = $needs_quotes ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}

		return implode( $delimiter, $out ) . "\n";
	}

	/**
	 * Neutralise spreadsheet formulas by prefixing a single quote.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function escape_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Undo escape_cell() when reading a file produced by the exporter.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function unescape_cell( string $value ): string {
		if ( in_array( substr( $value, 0, 2 ), array( "'=", "'+", "'-", "'@" ), true ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	/**
	 * Strip a UTF-8 byte order mark.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	public static function strip_bom( string $value ): string {
		return str_starts_with( $value, "\xEF\xBB\xBF" ) ? substr( $value, 3 ) : $value;
	}

	/**
	 * Convert text to UTF-8 from the given encoding.
	 *
	 * @param string $value    Raw text.
	 * @param string $encoding Source encoding, or "auto".
	 * @return string
	 */
	public static function to_utf8( string $value, string $encoding ): string {
		if ( 'auto' === $encoding ) {
			$encoding = mb_check_encoding( $value, 'UTF-8' ) ? 'UTF-8' : 'Windows-1252';
		}

		if ( 'UTF-8' === $encoding ) {
			return $value;
		}

		return mb_convert_encoding( $value, 'UTF-8', $encoding );
	}

	/**
	 * Guess the CSV delimiter from the header line.
	 *
	 * @param string $header_line First line of the file.
	 * @return string
	 */
	public static function detect_delimiter( string $header_line ): string {
		$best  = ',';
		$count = 0;

		foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
			$found = substr_count( $header_line, $candidate );
			if ( $found > $count ) {
				$best  = $candidate;
				$count = $found;
			}
		}

		return $best;
	}

	/**
	 * Normalise a column header for matching: lowercase, no accents, no punctuation.
	 *
	 * @param string $header Header text.
	 * @return string
	 */
	public static function normalize_header( string $header ): string {
		$header = mb_strtolower( trim( self::strip_bom( $header ) ), 'UTF-8' );
		$header = strtr(
			$header,
			array(
				'á' => 'a',
				'à' => 'a',
				'â' => 'a',
				'ã' => 'a',
				'é' => 'e',
				'ê' => 'e',
				'í' => 'i',
				'ó' => 'o',
				'ô' => 'o',
				'õ' => 'o',
				'ú' => 'u',
				'ç' => 'c',
			)
		);

		return (string) preg_replace( '/[^a-z0-9]+/', '', $header );
	}
}
