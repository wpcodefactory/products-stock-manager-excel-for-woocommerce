<?php
/**
 * Stock Manager engine: reads .xlsx, .xls and .csv files into rows of cells.
 *
 * .xlsx is streamed (ZipArchive + XMLReader), so 10,000 rows fit in a small memory limit. .csv uses fgetcsv with the
 * separator guessed from the first line. .xls (and .xlsx when the server lacks ZipArchive or XMLReader) goes through
 * the bundled PhpSpreadsheet.
 *
 * @package products-stock-manager-with-excel
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPF_SM_Reader' ) ) :

	/**
	 * File reader.
	 */
	class WPF_SM_Reader {

		/**
		 * Extensions we read.
		 *
		 * @return array
		 */
		public static function extensions() {
			return array( 'xlsx', 'xls', 'csv', 'txt' );
		}

		/**
		 * Kind of file from its first bytes: xlsx (zip), xls (OLE), html, csv or empty.
		 *
		 * @param string $path File.
		 * @return string
		 */
		public static function sniff( $path ) {
			$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				return 'empty';
			}
			$head = (string) fread( $fh, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( '' === trim( $head ) ) {
				return 'empty';
			}
			if ( 0 === strpos( $head, "PK\x03\x04" ) ) {
				return 'xlsx';
			}
			if ( 0 === strpos( $head, "\xD0\xCF\x11\xE0" ) ) {
				return 'xls';
			}
			$lower = strtolower( ltrim( $head, "\xEF\xBB\xBF \t\r\n" ) );
			if ( 0 === strpos( $lower, '<!doctype html' ) || 0 === strpos( $lower, '<html' ) || false !== strpos( $lower, '<head' ) ) {
				return 'html';
			}
			if ( preg_match( '/[\x00-\x08\x0E-\x1F]/', str_replace( "\x00", '', $head ) ) && false === strpos( $head, "\x00" ) ) {
				return 'binary';
			}
			return 'csv';
		}

		/**
		 * Read a file. Returns array( 'rows' => array of arrays of strings, 'sheet' => name ) or WP_Error.
		 * Empty trailing cells are trimmed; rows keep their original line number as key (1-based).
		 *
		 * @param string $path File on disk.
		 * @param string $name Original file name (for messages).
		 * @return array|WP_Error
		 */
		public static function read( $path, $name = '' ) {
			$kind = self::sniff( $path );
			switch ( $kind ) {
				case 'empty':
					return new WP_Error( 'wpf_sm_empty', __( 'The file is empty.', 'products-stock-manager-with-excel' ) );
				case 'html':
					return new WP_Error( 'wpf_sm_html', __( 'This is a web page, not a spreadsheet. If it is a Google Sheets link, share the sheet with "Anyone with the link" or publish it, then try again.', 'products-stock-manager-with-excel' ) );
				case 'binary':
					return new WP_Error( 'wpf_sm_binary', __( 'This file is not a spreadsheet we can read. Save it as .xlsx, .xls or .csv and try again.', 'products-stock-manager-with-excel' ) );
				case 'xlsx':
					$rows = ( class_exists( 'ZipArchive' ) && class_exists( 'XMLReader' ) ) ? self::read_xlsx( $path ) : self::read_spreadsheet( $path );
					break;
				case 'xls':
					$rows = self::read_spreadsheet( $path );
					break;
				default:
					$rows = self::read_csv( $path );
			}
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$rows = array_filter(
				$rows,
				function ( $r ) {
					return '' !== implode( '', array_map( 'trim', array_map( 'strval', $r ) ) );
				}
			);
			if ( ! $rows ) {
				return new WP_Error( 'wpf_sm_no_rows', __( 'The file has no rows.', 'products-stock-manager-with-excel' ) );
			}
			return array( 'rows' => $rows, 'kind' => $kind );
		}

		/**
		 * Streamed .xlsx: first worksheet, shared and inline strings, numbers as Excel shows them unformatted.
		 *
		 * @param string $path File.
		 * @return array|WP_Error
		 */
		protected static function read_xlsx( $path ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new WP_Error( 'wpf_sm_zip', __( 'The .xlsx file could not be opened. It may be damaged or cut off. Save it again in Excel and try again.', 'products-stock-manager-with-excel' ) );
			}
			$sheet = self::first_sheet_path( $zip );
			$xml   = ( $sheet && false !== $zip->locateName( $sheet ) ) ? $zip->getFromName( $sheet ) : false;
			$sst   = $zip->getFromName( 'xl/sharedStrings.xml' );
			$zip->close();
			if ( false === $xml || '' === $xml ) {
				return new WP_Error( 'wpf_sm_zip', __( 'The .xlsx file has no worksheet we can read. Save it again in Excel and try again.', 'products-stock-manager-with-excel' ) );
			}
			$strings = array();
			if ( $sst ) {
				$x = new XMLReader();
				$x->XML( $sst, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
				while ( @$x->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					while ( XMLReader::ELEMENT === $x->nodeType && 'si' === $x->localName ) {
						$node      = $x->expand();
						$strings[] = $node ? self::node_text( $node ) : '';
						if ( ! $x->next() ) {
							break 2;
						}
					}
				}
				$x->close();
				unset( $sst );
			}
			$rows = array();
			$line = 0;
			$x    = new XMLReader();
			$x->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
			unset( $xml );
			while ( @$x->read() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				while ( XMLReader::ELEMENT === $x->nodeType && 'row' === $x->localName ) {
					$r    = $x->getAttribute( 'r' );
					$line = $r ? (int) $r : $line + 1;
					$node = $x->expand();
					$row  = array();
					if ( $node ) {
						foreach ( $node->childNodes as $c ) {
							if ( XML_ELEMENT_NODE !== $c->nodeType || 'c' !== $c->localName ) {
								continue;
							}
							$ref  = (string) $c->getAttribute( 'r' );
							$type = (string) $c->getAttribute( 't' );
							$col  = '' !== $ref ? WPF_SM_Fields::index( preg_replace( '/\d+/', '', $ref ) ) : count( $row );
							$val  = '';
							if ( 'inlineStr' === $type ) {
								$val = self::node_text( $c );
							} else {
								foreach ( $c->childNodes as $v ) {
									if ( XML_ELEMENT_NODE === $v->nodeType && 'v' === $v->localName ) {
										$val = $v->textContent;
									}
								}
								if ( 's' === $type ) {
									$val = isset( $strings[ (int) $val ] ) ? $strings[ (int) $val ] : '';
								} elseif ( 'b' === $type ) {
									$val = $val ? 'TRUE' : 'FALSE';
								} elseif ( ( '' === $type || 'n' === $type ) && '' !== $val ) {
									$val = self::number_text( $val );
								}
							}
							if ( $col >= 0 && $col < 500 ) {
								for ( $k = count( $row ); $k < $col; $k++ ) {
									$row[ $k ] = '';
								}
								$row[ $col ] = (string) $val;
							}
						}
					}
					$rows[ $line ] = $row;
					if ( ! $x->next() ) {
						break 2;
					}
				}
			}
			$x->close();
			if ( ! $rows ) {
				return new WP_Error( 'wpf_sm_zip', __( 'The .xlsx file could not be read. It may be damaged or cut off. Save it again in Excel and try again.', 'products-stock-manager-with-excel' ) );
			}
			return self::trim_rows( $rows );
		}

		/**
		 * Text of an <si>, <is> or inline cell node: text runs joined, phonetic runs left out.
		 *
		 * @param DOMNode $node Node.
		 * @return string
		 */
		protected static function node_text( $node ) {
			$out = '';
			foreach ( $node->getElementsByTagName( 't' ) as $t ) {
				$p = $t->parentNode;
				if ( $p && 'rPh' === $p->localName ) {
					continue;
				}
				$out .= $t->textContent;
			}
			return $out;
		}

	/**
		 * Path of the first worksheet in workbook order.
		 *
		 * @param ZipArchive $zip Open archive.
		 * @return string
		 */
		protected static function first_sheet_path( $zip ) {
			$wb   = $zip->getFromName( 'xl/workbook.xml' );
			$rels = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
			if ( $wb && $rels && preg_match( '#<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"#', $wb, $m ) ) {
				if ( preg_match( '#<Relationship\b[^>]*\bId="' . preg_quote( $m[1], '#' ) . '"[^>]*\bTarget="([^"]+)"#', $rels, $t )
					|| preg_match( '#<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="' . preg_quote( $m[1], '#' ) . '"#', $rels, $t ) ) {
					$target = ltrim( $t[1], '/' );
					return 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
				}
			}
			return 'xl/worksheets/sheet1.xml';
		}

		/**
		 * Excel stores 19.9 as 19.899999999999999: show what Excel shows (15 significant digits).
		 *
		 * @param string $v Raw number.
		 * @return string
		 */
		protected static function number_text( $v ) {
			if ( ! is_numeric( $v ) ) {
				return $v;
			}
			$f = (float) $v;
			if ( floor( $f ) === $f && abs( $f ) < 1e15 ) {
				return (string) (int) $f;
			}
			$s = rtrim( rtrim( sprintf( '%.15G', $f ), '0' ), '.' );
			return false !== stripos( $s, 'E' ) ? (string) $f : $s;
		}

		/**
		 * PhpSpreadsheet for .xls (and .xlsx without ZipArchive). Reads values, not formats.
		 *
		 * @param string $path File.
		 * @return array|WP_Error
		 */
		protected static function read_spreadsheet( $path ) {
			if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
				$autoload = dirname( __DIR__, 2 ) . '/Classes/autoload.php';
				if ( file_exists( $autoload ) ) {
					require_once $autoload;
				}
			}
			if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
				return new WP_Error( 'wpf_sm_lib', __( 'This server cannot read this kind of file. Save it as .csv and try again.', 'products-stock-manager-with-excel' ) );
			}
			try {
				$type   = \PhpOffice\PhpSpreadsheet\IOFactory::identify( $path, array( 'Xls', 'Xlsx' ) );
				$reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader( $type );
				$reader->setReadDataOnly( true );
				$book = $reader->load( $path );
				$rows = array();
				foreach ( $book->getActiveSheet()->toArray( '', true, false, false ) as $i => $r ) {
					$rows[ $i + 1 ] = array_map(
						function ( $c ) {
							return is_float( $c ) ? self::number_text( (string) $c ) : (string) $c;
						},
						$r
					);
				}
				$book->disconnectWorksheets();
				return self::trim_rows( $rows );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'wpf_sm_read', __( 'The file could not be read. It may be damaged, protected by a password or in a format we do not read. Save it as .xlsx or .csv and try again.', 'products-stock-manager-with-excel' ) );
			}
		}

		/**
		 * CSV: UTF-8 (with or without BOM) or Windows-1252, separator , ; tab or | guessed from the first line.
		 *
		 * @param string $path File.
		 * @return array|WP_Error
		 */
		protected static function read_csv( $path ) {
			$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				return new WP_Error( 'wpf_sm_read', __( 'The file could not be opened.', 'products-stock-manager-with-excel' ) );
			}
			$first = (string) fgets( $fh );
			$bom   = 0 === strpos( $first, "\xEF\xBB\xBF" );
			if ( 0 === strpos( $first, "\xFF\xFE" ) || 0 === strpos( $first, "\xFE\xFF" ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error( 'wpf_sm_read', __( 'This .csv file is saved as UTF-16. Save it as "CSV UTF-8" and try again.', 'products-stock-manager-with-excel' ) );
			}
			$sep   = ',';
			$best  = 0;
			foreach ( array( ',', ';', "\t", '|' ) as $s ) {
				$n = substr_count( $first, $s );
				if ( $n > $best ) {
					$best = $n;
					$sep  = $s;
				}
			}
			rewind( $fh );
			if ( $bom ) {
				fread( $fh, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			}
			$rows = array();
			$line = 0;
			while ( false !== ( $r = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$line;
				if ( null === $r || array( null ) === $r ) {
					continue;
				}
				$rows[ $line ] = array_map(
					function ( $c ) {
						$c = (string) $c;
						if ( '' !== $c && ! seems_utf8( $c ) && function_exists( 'mb_convert_encoding' ) ) {
							$c = mb_convert_encoding( $c, 'UTF-8', 'Windows-1252' );
						}
						return $c;
					},
					$r
				);
				if ( $line > 200000 ) {
					break;
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::trim_rows( $rows );
		}

		/**
		 * Trim every cell and drop empty cells at the end of each row.
		 *
		 * @param array $rows Rows.
		 * @return array
		 */
		protected static function trim_rows( $rows ) {
			foreach ( $rows as $k => $r ) {
				$r = array_map(
					function ( $c ) {
						return trim( (string) $c, " \t\n\r\0\x0B\xC2\xA0" );
					},
					(array) $r
				);
				while ( $r && '' === end( $r ) ) {
					array_pop( $r );
				}
				$rows[ $k ] = $r;
			}
			return $rows;
		}
	}

endif;
