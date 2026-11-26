<?php
/**
 * Stock Manager engine: small streaming .xlsx writer (one sheet, frozen header, column widths, filter, row colours)
 * and the private folder for temporary files.
 *
 * Rows are appended to a temporary file across several requests, so a 10,000 row export never needs the whole sheet in
 * memory. Needs ZipArchive; without it the export falls back to .csv.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Xlsx' ) ) :

	/**
	 * Writer.
	 */
	class WPF_SM_Xlsx {

		const STYLE_NONE   = 0;
		const STYLE_HEADER = 1;
		const STYLE_LOW    = 2;
		const STYLE_OUT    = 3;

		/**
		 * Private folder in uploads (no listing, no direct access on Apache, random file names everywhere).
		 *
		 * @return string|WP_Error
		 */
		public static function dir() {
			$up = wp_upload_dir( null, false );
			if ( ! empty( $up['error'] ) ) {
				return new WP_Error( 'wpf_sm_dir', $up['error'] );
			}
			$dir = trailingslashit( $up['basedir'] ) . 'wpfactory-stock-manager';
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return new WP_Error( 'wpf_sm_dir', __( 'The uploads folder is not writable.', 'products-stock-manager-with-excel' ) );
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			return $dir;
		}

		/**
		 * Path for a token and extension, or WP_Error.
		 *
		 * @param string $token Token (letters and digits).
		 * @param string $ext   Extension.
		 * @return string|WP_Error
		 */
		public static function path( $token, $ext ) {
			$dir = self::dir();
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			return $dir . '/' . preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) ) . '.' . preg_replace( '/[^a-z0-9]/', '', $ext );
		}

		/**
		 * Remove temporary files older than a day.
		 */
		public static function cleanup() {
			$dir = self::dir();
			if ( is_wp_error( $dir ) ) {
				return;
			}
			foreach ( (array) glob( $dir . '/*.{rows,json,xlsx,csv,tmp}', GLOB_BRACE ) as $f ) {
				if ( $f && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
					wp_delete_file( $f );
				}
			}
		}

		/**
		 * Start a sheet: stores the header and column types.
		 *
		 * @param string $token  Token.
		 * @param array  $header Header labels.
		 * @param array  $types  's' (text) or 'n' (number) per column.
		 * @return true|WP_Error
		 */
		public static function begin( $token, $header, $types ) {
			$rows = self::path( $token, 'rows' );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			file_put_contents( $rows, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$state = array(
				'header' => array_values( $header ),
				'types'  => array_values( $types ),
				'widths' => array_map(
					function ( $h ) {
						return min( 60, max( 8, mb_strlen( (string) $h ) + 2 ) );
					},
					array_values( $header )
				),
				'count'  => 0,
			);
			file_put_contents( self::path( $token, 'json' ), wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return true;
		}

		/**
		 * Append rows. Each row: array( 'cells' => array, 'style' => int ).
		 *
		 * @param string $token Token.
		 * @param array  $rows  Rows.
		 */
		public static function add( $token, $rows ) {
			$state = json_decode( (string) file_get_contents( self::path( $token, 'json' ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! $state ) {
				return;
			}
			$xml = '';
			foreach ( $rows as $row ) {
				$r = $state['count'] + 2;
				$xml .= self::row_xml( $r, $row['cells'], $state['types'], isset( $row['style'] ) ? (int) $row['style'] : 0 );
				foreach ( array_values( $row['cells'] ) as $i => $c ) {
					if ( isset( $state['widths'][ $i ] ) ) {
						$state['widths'][ $i ] = min( 60, max( $state['widths'][ $i ], mb_strlen( (string) $c ) + 2 ) );
					}
				}
				++$state['count'];
			}
			file_put_contents( self::path( $token, 'rows' ), $xml, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( self::path( $token, 'json' ), wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		/**
		 * Rows written so far.
		 *
		 * @param string $token Token.
		 * @return int
		 */
		public static function count( $token ) {
			$state = json_decode( (string) @file_get_contents( self::path( $token, 'json' ) ), true ); // phpcs:ignore
			return $state ? (int) $state['count'] : 0;
		}

		/**
		 * One <row>.
		 *
		 * @param int   $r     Row number.
		 * @param array $cells Cells.
		 * @param array $types Types.
		 * @param int   $style Style.
		 * @return string
		 */
		protected static function row_xml( $r, $cells, $types, $style ) {
			$x = '<row r="' . $r . '">';
			foreach ( array_values( $cells ) as $i => $v ) {
				$ref = WPF_SM_Fields::letter( $i ) . $r;
				$s   = $style ? ' s="' . $style . '"' : '';
				$v   = (string) $v;
				if ( '' === $v ) {
					$x .= $style ? '<c r="' . $ref . '"' . $s . '/>' : '';
					continue;
				}
				$type = isset( $types[ $i ] ) ? $types[ $i ] : 's';
				if ( 'n' === $type && is_numeric( $v ) && ! preg_match( '/^0\d/', $v ) ) {
					$x .= '<c r="' . $ref . '"' . $s . '><v>' . ( 0 + $v ) . '</v></c>';
				} else {
					$x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc( $v ) . '</t></is></c>';
				}
			}
			return $x . '</row>';
		}

		/**
		 * XML text escape, invalid characters removed.
		 *
		 * @param string $v Text.
		 * @return string
		 */
		protected static function esc( $v ) {
			$v = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $v );
			return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}

		/**
		 * Build the .xlsx (or .csv without ZipArchive). Returns the file path.
		 *
		 * @param string $token Token.
		 * @return string|WP_Error
		 */
		public static function finish( $token ) {
			$state = json_decode( (string) file_get_contents( self::path( $token, 'json' ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! $state ) {
				return new WP_Error( 'wpf_sm_export', __( 'The export expired. Start it again.', 'products-stock-manager-with-excel' ) );
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				return self::finish_csv( $token, $state );
			}
			$cols = '<cols>';
			foreach ( $state['widths'] as $i => $w ) {
				$cols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
			}
			$cols  .= '</cols>';
			$last   = WPF_SM_Fields::letter( max( 0, count( $state['header'] ) - 1 ) );
			$sheet  = self::path( $token, 'tmp' );
			$fh     = fopen( $sheet, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fh, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $fh, $cols . '<sheetData>' . self::row_xml( 1, $state['header'], array(), self::STYLE_HEADER ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$in = fopen( self::path( $token, 'rows' ), 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			stream_copy_to_stream( $in, $fh );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fwrite( $fh, '</sheetData><autoFilter ref="A1:' . $last . ( $state['count'] + 1 ) . '"/></worksheet>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$out = self::path( $token, 'xlsx' );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'wpf_sm_export', __( 'The .xlsx file could not be created.', 'products-stock-manager-with-excel' ) );
			}
			$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' );
			$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
			$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Products!$A$1:$' . $last . '$' . ( $state['count'] + 1 ) . '</definedName></definedNames></workbook>' );
			$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
			$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7F4EA"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF1C7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFDDCD8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
			$zip->addFile( $sheet, 'xl/worksheets/sheet1.xml' );
			$zip->close();
			wp_delete_file( $sheet );
			wp_delete_file( self::path( $token, 'rows' ) );
			return $out;
		}

		/**
		 * CSV fallback (UTF-8 with BOM so Excel opens accents right).
		 *
		 * @param string $token Token.
		 * @param array  $state State.
		 * @return string|WP_Error
		 */
		protected static function finish_csv( $token, $state ) {
			$out = self::path( $token, 'csv' );
			$fh  = fopen( $out, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fputcsv( $fh, $state['header'], ',', '"', '\\' );
			$xml = (string) file_get_contents( self::path( $token, 'rows' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match_all( '#<row\b[^>]*>(.*?)</row>#s', $xml, $rows );
			foreach ( $rows[1] as $row ) {
				$cells = array();
				preg_match_all( '#<c r="([A-Z]+)\d+"[^>]*?(?:/>|>(?:<v>(.*?)</v>|<is><t[^>]*>(.*?)</t></is>)</c>)#s', $row, $m, PREG_SET_ORDER );
				foreach ( $m as $c ) {
					$cells[ WPF_SM_Fields::index( $c[1] ) ] = html_entity_decode( isset( $c[3] ) && '' !== $c[3] ? $c[3] : ( isset( $c[2] ) ? $c[2] : '' ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
				}
				$line = array();
				for ( $i = 0; $i < count( $state['header'] ); $i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
					$line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
				}
				fputcsv( $fh, $line, ',', '"', '\\' );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_delete_file( self::path( $token, 'rows' ) );
			return $out;
		}
	}

endif;
