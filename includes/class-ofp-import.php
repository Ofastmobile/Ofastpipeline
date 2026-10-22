<?php
/**
 * Class OFP_Import
 *
 * Brings a client's existing lists into the platform. Three kinds of import:
 *
 *  leads      People who showed interest. Skips anyone already a lead (same phone
 *             or email). Never starts the automatic follow ups or calls unless the
 *             client ticks the box. Marked with the source "import: <batch name>".
 *  customers  People who already bought. Saved as leads marked converted, so they
 *             appear in the Customers list.
 *  sales      Past sales and payments. Creates the purchase and the payment record
 *             with the REAL past date. Sends no email, SMS or call, uses no credit.
 *
 * Files: CSV, TSV, TXT and Excel (.xlsx). The first row should be the column names.
 * Nothing is saved until the client confirms the preview.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Import {

    const MAX_ROWS  = 5000;
    const MAX_BYTES = 5242880; // 5 MB
    const TTL       = 3600;
    const GATEWAY   = 'import';

    // ─────────────────────────────────────────────────────────────────────────
    // WHAT EACH IMPORT UNDERSTANDS
    // ─────────────────────────────────────────────────────────────────────────

    public static function types(): array {
        return [
            'leads'     => [ 'label' => 'Leads',      'help' => 'People who showed interest but have not bought yet.' ],
            'customers' => [ 'label' => 'Customers',  'help' => 'People who already bought or rent from you.' ],
            'sales'     => [ 'label' => 'Past sales', 'help' => 'What each customer bought, what they paid and when.' ],
        ];
    }

    /** @return array<string,array{label:string,required:bool,names:string[]}> */
    public static function fields( string $type ): array {
        $name  = [ 'label' => 'Name',  'required' => false, 'names' => [ 'name', 'fullname', 'customername', 'clientname', 'contact', 'contactname', 'customer', 'buyer', 'buyername', 'lead' ] ];
        $phone = [ 'label' => 'Phone', 'required' => false, 'names' => [ 'phone', 'phonenumber', 'mobile', 'mobilenumber', 'telephone', 'tel', 'whatsapp', 'gsm', 'contactnumber', 'phoneno' ] ];
        $email = [ 'label' => 'Email', 'required' => false, 'names' => [ 'email', 'emailaddress', 'mail', 'emailid' ] ];
        $notes = [ 'label' => 'Notes', 'required' => false, 'names' => [ 'notes', 'note', 'comment', 'comments', 'remarks', 'remark', 'message' ] ];

        switch ( $type ) {
            case 'sales':
                $name['required'] = true;
                return [
                    'name'     => $name,
                    'phone'    => $phone,
                    'email'    => $email,
                    'property' => [ 'label' => 'Property', 'required' => true, 'names' => [ 'property', 'propertyname', 'propertytitle', 'listing', 'house', 'apartment', 'plot', 'project', 'unit', 'item', 'purchase' ] ],
                    'total'    => [ 'label' => 'Total price', 'required' => true, 'names' => [ 'total', 'totalprice', 'price', 'saleprice', 'contractsum', 'propertyprice', 'cost', 'amount', 'value' ] ],
                    'paid'     => [ 'label' => 'Amount paid', 'required' => false, 'names' => [ 'paid', 'amountpaid', 'payment', 'deposit', 'paidsofar', 'received', 'amountreceived' ] ],
                    'date'     => [ 'label' => 'Date', 'required' => false, 'names' => [ 'date', 'paymentdate', 'purchasedate', 'datepaid', 'transactiondate', 'dateofpurchase', 'saledate' ] ],
                    'receipt'  => [ 'label' => 'Receipt number', 'required' => false, 'names' => [ 'receipt', 'receiptno', 'receiptnumber', 'receiptnum', 'reference', 'ref', 'refno', 'transactionref', 'invoice', 'invoiceno' ] ],
                    'method'   => [ 'label' => 'Payment method', 'required' => false, 'names' => [ 'method', 'paymentmethod', 'mode', 'paymentmode', 'paidvia' ] ],
                    'notes'    => $notes,
                ];
            case 'customers':
                return [
                    'name' => $name, 'phone' => $phone, 'email' => $email,
                    'date' => [ 'label' => 'Date they became a customer', 'required' => false, 'names' => [ 'date', 'datejoined', 'customersince', 'dateadded', 'createdat', 'created', 'registered' ] ],
                    'notes' => $notes,
                ];
            default:
                return [
                    'name' => $name, 'phone' => $phone, 'email' => $email,
                    'property' => [ 'label' => 'Property they asked about', 'required' => false, 'names' => [ 'property', 'propertyname', 'listing', 'house', 'apartment', 'plot', 'project', 'interest', 'interestedin' ] ],
                    'status'   => [ 'label' => 'Status', 'required' => false, 'names' => [ 'status', 'stage', 'leadstatus' ] ],
                    'date'     => [ 'label' => 'Date added', 'required' => false, 'names' => [ 'date', 'dateadded', 'createdat', 'created', 'addedon', 'registered', 'datecreated' ] ],
                    'notes'    => $notes,
                ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // READING FILES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int,array<int,string>>|WP_Error  Every non empty row, first row included.
     */
    public static function read_file( string $path, string $original_name ) {
        if ( ! is_readable( $path ) ) {
            return new WP_Error( 'import_unreadable', 'The file could not be read.' );
        }
        if ( filesize( $path ) > self::MAX_BYTES ) {
            return new WP_Error( 'import_too_big', 'The file is bigger than 5 MB. Split it into smaller files.' );
        }

        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

        if ( 'xlsx' === $ext ) {
            return self::read_xlsx( $path );
        }
        if ( in_array( $ext, [ 'xls', 'xlsm', 'ods', 'numbers' ], true ) ) {
            return new WP_Error( 'import_format', 'Please save the file as .xlsx or .csv in Excel (File, Save As) and upload that.' );
        }
        if ( ! in_array( $ext, [ 'csv', 'tsv', 'txt', '' ], true ) ) {
            return new WP_Error( 'import_format', 'Upload a .csv, .tsv, .txt or .xlsx file.' );
        }

        $text = (string) file_get_contents( $path );
        return self::read_delimited( $text );
    }

    /** @return array<int,array<int,string>>|WP_Error */
    public static function read_delimited( string $text ) {
        // Byte order mark and encoding. Excel on Windows often saves CSV as Windows-1252.
        if ( strncmp( $text, "\xEF\xBB\xBF", 3 ) === 0 ) {
            $text = substr( $text, 3 );
        }
        if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
            $text = function_exists( 'iconv' ) ? (string) iconv( 'Windows-1252', 'UTF-8//IGNORE', $text ) : utf8_encode( $text );
        }
        $text = str_replace( [ "\r\n", "\r" ], "\n", $text );
        if ( trim( $text ) === '' ) {
            return new WP_Error( 'import_empty', 'The file is empty.' );
        }

        // Pick the separator that appears most in the first filled line.
        $first = '';
        foreach ( explode( "\n", $text ) as $line ) {
            if ( trim( $line ) !== '' ) { $first = $line; break; }
        }
        $best = ',';
        $max  = -1;
        foreach ( [ ',', ';', "\t", '|' ] as $d ) {
            $c = substr_count( $first, $d );
            if ( $c > $max ) { $max = $c; $best = $d; }
        }

        $h = fopen( 'php://temp', 'r+' );
        fwrite( $h, $text );
        rewind( $h );

        $rows = [];
        while ( ( $r = fgetcsv( $h, 0, $best, '"', '' ) ) !== false ) {
            $r = array_map( static fn( $v ) => trim( (string) $v ), $r );
            if ( count( array_filter( $r, static fn( $v ) => $v !== '' ) ) === 0 ) {
                continue;
            }
            $rows[] = $r;
            if ( count( $rows ) > self::MAX_ROWS + 1 ) {
                break;
            }
        }
        fclose( $h );

        return self::check_size( $rows );
    }

    /** @return array<int,array<int,string>>|WP_Error */
    private static function read_xlsx( string $path ) {
        if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'import_no_zip', 'This server cannot open Excel files. Save the file as .csv and upload that.' );
        }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'import_bad_xlsx', 'The Excel file could not be opened.' );
        }

        // First worksheet.
        $sheet_name = '';
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $n = $zip->getNameIndex( $i );
            if ( preg_match( '#^xl/worksheets/sheet\d+\.xml$#', $n ) && ( $sheet_name === '' || strnatcmp( $n, $sheet_name ) < 0 ) ) {
                $sheet_name = $n;
            }
        }
        if ( $sheet_name === '' ) {
            $zip->close();
            return new WP_Error( 'import_bad_xlsx', 'No sheet was found in the Excel file.' );
        }
        $sheet  = (string) $zip->getFromName( $sheet_name );
        $shared = (string) $zip->getFromName( 'xl/sharedStrings.xml' );
        $zip->close();

        return self::check_size( self::read_xlsx_xml( $sheet, $shared ) );
    }

    /**
     * Turns the XML of one Excel sheet into rows. Public so it can be tested without a zip file.
     *
     * @return array<int,array<int,string>>
     */
    public static function read_xlsx_xml( string $sheet_xml, string $shared_xml = '' ): array {
        $strings = [];
        if ( $shared_xml !== '' ) {
            $sx = @simplexml_load_string( $shared_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
            if ( $sx ) {
                foreach ( $sx->si as $si ) {
                    $text = '';
                    if ( isset( $si->t ) ) {
                        $text = (string) $si->t;
                    } elseif ( isset( $si->r ) ) {
                        foreach ( $si->r as $run ) { $text .= (string) $run->t; }
                    }
                    $strings[] = $text;
                }
            }
        }

        $sx = @simplexml_load_string( $sheet_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        if ( ! $sx || ! isset( $sx->sheetData ) ) {
            return [];
        }

        $rows = [];
        foreach ( $sx->sheetData->row as $row ) {
            $cells = [];
            $max   = -1;
            foreach ( $row->c as $c ) {
                $ref = (string) $c['r'];
                $col = self::column_index( $ref );
                $t   = (string) $c['t'];
                if ( 's' === $t ) {
                    $val = $strings[ (int) $c->v ] ?? '';
                } elseif ( 'inlineStr' === $t ) {
                    $val = isset( $c->is->t ) ? (string) $c->is->t : '';
                } else {
                    $val = isset( $c->v ) ? (string) $c->v : '';
                }
                $cells[ $col ] = trim( $val );
                $max = max( $max, $col );
            }
            if ( $max < 0 ) {
                continue;
            }
            $line = [];
            for ( $i = 0; $i <= $max; $i++ ) {
                $line[] = $cells[ $i ] ?? '';
            }
            if ( count( array_filter( $line, static fn( $v ) => $v !== '' ) ) === 0 ) {
                continue;
            }
            $rows[] = $line;
            if ( count( $rows ) > self::MAX_ROWS + 1 ) {
                break;
            }
        }
        return $rows;
    }

    /** "C5" becomes 2. */
    private static function column_index( string $cell_ref ): int {
        $letters = strtoupper( preg_replace( '/[^A-Za-z]/', '', $cell_ref ) );
        $n = 0;
        for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
            $n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
        }
        return max( 0, $n - 1 );
    }

    /** @return array<int,array<int,string>>|WP_Error */
    private static function check_size( array $rows ) {
        if ( count( $rows ) < 2 ) {
            return new WP_Error( 'import_empty', 'The file needs a first row with column names and at least one row of data.' );
        }
        if ( count( $rows ) - 1 > self::MAX_ROWS ) {
            return new WP_Error( 'import_rows', 'The file has more than ' . number_format( self::MAX_ROWS ) . ' rows. Split it into smaller files.' );
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GUESSING THE COLUMNS
    // ─────────────────────────────────────────────────────────────────────────

    private static function squash( string $header ): string {
        return preg_replace( '/[^a-z0-9]/', '', strtolower( $header ) );
    }

    /**
     * @param string[] $headers
     * @return array<string,int> field => column index, or -1 when no column fits
     */
    public static function guess_mapping( string $type, array $headers ): array {
        $fields = self::fields( $type );
        $map    = array_fill_keys( array_keys( $fields ), -1 );
        $taken  = [];
        $squash = array_map( [ __CLASS__, 'squash' ], $headers );

        // Pass 1: the header is exactly one of the known names.
        foreach ( $fields as $key => $def ) {
            foreach ( $squash as $i => $h ) {
                if ( isset( $taken[ $i ] ) || $h === '' ) continue;
                if ( in_array( $h, $def['names'], true ) ) {
                    $map[ $key ] = $i;
                    $taken[ $i ] = true;
                    break;
                }
            }
        }
        // Pass 2: the header contains a known name (but never guess Name this way,
        // because "Property name" and "Sender name" would match).
        foreach ( $fields as $key => $def ) {
            if ( $map[ $key ] !== -1 || 'name' === $key ) continue;
            foreach ( $squash as $i => $h ) {
                if ( isset( $taken[ $i ] ) || $h === '' ) continue;
                foreach ( $def['names'] as $n ) {
                    if ( strlen( $n ) >= 4 && strpos( $h, $n ) !== false ) {
                        $map[ $key ] = $i;
                        $taken[ $i ] = true;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CLEANING VALUES
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{phone:string,ok:bool} */
    public static function clean_phone( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return [ 'phone' => '', 'ok' => true ];
        }
        // Excel turns long numbers into 8.03E+09.
        if ( preg_match( '/^\d(\.\d+)?E\+?\d+$/i', $raw ) ) {
            $raw = sprintf( '%.0f', (float) $raw );
        }
        $has_plus = strpos( $raw, '+' ) === 0;
        $digits   = preg_replace( '/\D+/', '', $raw );
        $len      = strlen( $digits );

        if ( strpos( $digits, '234' ) === 0 && 13 === $len ) {
            return [ 'phone' => '0' . substr( $digits, 3 ), 'ok' => true ];
        }
        if ( 10 === $len && in_array( $digits[0], [ '7', '8', '9' ], true ) ) {
            return [ 'phone' => '0' . $digits, 'ok' => true ];
        }
        if ( 11 === $len && '0' === $digits[0] ) {
            return [ 'phone' => $digits, 'ok' => true ];
        }
        if ( $has_plus && $len >= 8 && $len <= 15 ) {
            return [ 'phone' => '+' . $digits, 'ok' => true ];
        }
        if ( $len >= 8 && $len <= 15 && '0' !== $digits[0] ) {
            return [ 'phone' => $digits, 'ok' => true ];
        }
        return [ 'phone' => '', 'ok' => false ];
    }

    public static function parse_money( string $raw ): ?float {
        $s = preg_replace( '/(?i)ngn|naira|[₦#N]\b|₦/u', '', trim( $raw ) );
        $s = preg_replace( '/[^0-9.,\-]/', '', (string) $s );
        if ( $s === '' || $s === '-' ) {
            return null;
        }
        if ( strpos( $s, ',' ) !== false && strpos( $s, '.' ) !== false ) {
            // The later one is the decimal point.
            $s = strrpos( $s, ',' ) > strrpos( $s, '.' )
                ? str_replace( [ '.', ',' ], [ '', '.' ], $s )
                : str_replace( ',', '', $s );
        } elseif ( strpos( $s, ',' ) !== false ) {
            $s = preg_match( '/,\d{3}(,|$)/', $s ) ? str_replace( ',', '', $s ) : str_replace( ',', '.', $s );
        }
        if ( ! is_numeric( $s ) ) {
            return null;
        }
        $v = (float) $s;
        return $v < 0 ? null : round( $v, 2 );
    }

    /** @return string|null MySQL date time in site time, or null when it cannot be read. */
    public static function parse_date( string $raw ): ?string {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return null;
        }
        // Excel stores dates as a number of days since 1900.
        if ( preg_match( '/^\d{5}(\.\d+)?$/', $raw ) && (float) $raw > 20000 && (float) $raw < 80000 ) {
            return gmdate( 'Y-m-d H:i:s', (int) round( ( (float) $raw - 25569 ) * 86400 ) );
        }
        $formats = [
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y/m/d',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y',
            'j M Y', 'j F Y', 'j-M-Y', 'j-M-y', 'M j, Y', 'F j, Y', 'M j Y', 'F j Y', 'jS F Y', 'jS M Y',
        ];
        foreach ( $formats as $f ) {
            $d = DateTime::createFromFormat( '!' . $f, $raw );
            $e = DateTime::getLastErrors();
            // A two digit year read by a four digit format gives year 24, not 2024. Skip those.
            if ( $d && (int) $d->format( 'Y' ) < 1900 ) {
                continue;
            }
            if ( $d && ( ! $e || ( 0 === $e['warning_count'] && 0 === $e['error_count'] ) ) ) {
                if ( strpos( $f, 'H' ) === false ) {
                    $d->setTime( 9, 0, 0 );
                }
                return $d->format( 'Y-m-d H:i:s' );
            }
        }
        return null;
    }

    private static function clean_status( string $raw ): string {
        $s = strtolower( trim( $raw ) );
        return in_array( $s, [ 'new', 'contacted', 'interested', 'converted', 'dead' ], true ) ? $s : 'new';
    }

    private static function norm_title( string $title ): string {
        return preg_replace( '/\s+/', ' ', strtolower( trim( $title ) ) );
    }

    /** @return array{by_title:array<string,array>,list:array} the client's own properties. */
    public static function property_index( int $client_id ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, wp_post_id, title FROM {$wpdb->prefix}ofp_properties WHERE client_id = %d ORDER BY title ASC LIMIT 2000",
            $client_id
        ) );
        $by = [];
        foreach ( $rows as $r ) {
            $by[ self::norm_title( (string) $r->title ) ] = [ 'id' => (int) $r->id, 'post_id' => (int) $r->wp_post_id, 'title' => (string) $r->title ];
        }
        return [ 'by_title' => $by, 'list' => $rows ];
    }

    private static function cell( array $row, array $map, string $field ): string {
        $i = $map[ $field ] ?? -1;
        return ( $i >= 0 && isset( $row[ $i ] ) ) ? trim( (string) $row[ $i ] ) : '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THE PREVIEW (nothing is saved here)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Checks every row the way the real import will, and counts the outcome.
     *
     * @return array{ok:int,duplicate:int,invalid:int,problems:array}
     */
    public static function preview( int $client_id, string $type, array $rows, array $map, array $opts = [] ): array {
        $report = self::run( $client_id, $type, $rows, $map, $opts + [ 'dry_run' => true ] );
        return [
            'ok'        => $report['imported'],
            'duplicate' => $report['duplicates'],
            'invalid'   => $report['invalid'],
            'problems'  => $report['problems'],
        ];
    }

    /**
     * @param array $opts batch, status, followups, dry_run, fully_paid, fallback_property, make_converted
     * @return array{imported:int,duplicates:int,invalid:int,problems:array,batch:string,purchases:int}
     */
    public static function run( int $client_id, string $type, array $rows, array $map, array $opts = [] ): array {
        if ( 'sales' === $type ) {
            return self::run_sales( $client_id, $rows, $map, $opts );
        }
        return self::run_people( $client_id, $type, $rows, $map, $opts );
    }

    private static function new_report( string $batch = '' ): array {
        return [ 'imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'problems' => [], 'batch' => $batch, 'purchases' => 0 ];
    }

    private static function problem( array &$report, int $line, string $why ): void {
        $report['invalid']++;
        if ( count( $report['problems'] ) < 100 ) {
            $report['problems'][] = [ 'line' => $line, 'why' => $why ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEADS AND CUSTOMERS
    // ─────────────────────────────────────────────────────────────────────────

    private static function run_people( int $client_id, string $type, array $rows, array $map, array $opts ): array {
        global $wpdb;
        $dry       = ! empty( $opts['dry_run'] );
        $batch     = trim( (string) ( $opts['batch'] ?? '' ) );
        $batch     = $batch !== '' ? $batch : 'Import ' . current_time( 'Y-m-d' );
        $source    = mb_substr( 'import: ' . $batch, 0, 150 );
        $report    = self::new_report( $source );
        $existing  = OFP_Contacts::lead_keys( $client_id );
        $seen      = [ 'phones' => [], 'emails' => [] ];
        $props     = self::property_index( $client_id );
        $is_cust   = ( 'customers' === $type );
        $default   = $is_cust ? 'converted' : self::clean_status( (string) ( $opts['status'] ?? 'new' ) );

        foreach ( $rows as $n => $row ) {
            $line  = $n + 2; // row 1 is the header
            $name  = self::cell( $row, $map, 'name' );
            $ph    = self::clean_phone( self::cell( $row, $map, 'phone' ) );
            $email = OFP_Contacts::email_key( self::cell( $row, $map, 'email' ) );
            $raw_e = self::cell( $row, $map, 'email' );

            if ( count( array_filter( $row, static fn( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) {
                continue;
            }
            if ( ! $ph['ok'] ) {
                self::problem( $report, $line, 'The phone number looks wrong: ' . self::cell( $row, $map, 'phone' ) );
                continue;
            }
            if ( $raw_e !== '' && $email === '' ) {
                self::problem( $report, $line, 'The email looks wrong: ' . $raw_e );
                continue;
            }
            if ( $ph['phone'] === '' && $email === '' ) {
                self::problem( $report, $line, 'Needs a phone number or an email.' );
                continue;
            }

            $pk = OFP_Contacts::phone_key( $ph['phone'] );
            if ( ( $pk !== '' && ( isset( $existing['phones'][ $pk ] ) || isset( $seen['phones'][ $pk ] ) ) )
              || ( $email !== '' && ( isset( $existing['emails'][ $email ] ) || isset( $seen['emails'][ $email ] ) ) ) ) {
                $report['duplicates']++;
                continue;
            }
            if ( $pk !== '' )   $seen['phones'][ $pk ]    = true;
            if ( $email !== '' ) $seen['emails'][ $email ] = true;

            $date = self::parse_date( self::cell( $row, $map, 'date' ) );
            if ( self::cell( $row, $map, 'date' ) !== '' && $date === null ) {
                self::problem( $report, $line, 'The date could not be read: ' . self::cell( $row, $map, 'date' ) . ' (use for example 25/03/2024)' );
                continue;
            }

            $status = $default;
            if ( ! $is_cust && self::cell( $row, $map, 'status' ) !== '' ) {
                $status = self::clean_status( self::cell( $row, $map, 'status' ) );
            }

            $post_id = null;
            if ( ! $is_cust ) {
                $title = self::norm_title( self::cell( $row, $map, 'property' ) );
                if ( $title !== '' && isset( $props['by_title'][ $title ] ) && $props['by_title'][ $title ]['post_id'] ) {
                    $post_id = $props['by_title'][ $title ]['post_id'];
                }
            }

            if ( $dry ) {
                $report['imported']++;
                continue;
            }

            $lead_id = OFP_Lead::create(
                $client_id,
                $name,
                $ph['phone'],
                $email !== '' ? $email : null,
                $source,
                $post_id,
                self::cell( $row, $map, 'notes' ) ?: null
            );
            if ( ! $lead_id ) {
                self::problem( $report, $line, 'The database refused this row.' );
                continue;
            }

            $update = [ 'status' => $status ];
            if ( $date ) {
                $update['created_at'] = $date;
            }
            if ( 'converted' === $status ) {
                $update['converted_at'] = $date ?: current_time( 'mysql' );
            }
            $wpdb->update( $wpdb->prefix . 'ofp_leads', $update, [ 'id' => $lead_id ] );

            // Automatic follow ups only when the client asked for them, and only for new leads.
            if ( ! empty( $opts['followups'] ) && 'new' === $status && $ph['phone'] !== '' && class_exists( 'OFP_Queue' ) ) {
                OFP_Queue::populate_triggers( $client_id, $lead_id );
            }

            $report['imported']++;
        }

        if ( ! $dry && $report['imported'] > 0 && class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( $is_cust ? 'Customers imported' : 'Leads imported', $client_id, [
                'message' => sprintf( '%d %s imported from a file (%s). %d already existed and %d had problems.', $report['imported'], $is_cust ? 'customers' : 'leads', $source, $report['duplicates'], $report['invalid'] ),
            ] );
        }
        return $report;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAST SALES
    // ─────────────────────────────────────────────────────────────────────────

    private static function payment_method( string $raw ): string {
        $s = strtolower( trim( $raw ) );
        if ( $s === '' ) return 'manual';
        if ( strpos( $s, 'cash' ) !== false ) return 'cash';
        if ( strpos( $s, 'pos' ) !== false ) return 'pos';
        if ( strpos( $s, 'deposit' ) !== false ) return 'bank_deposit';
        if ( strpos( $s, 'transfer' ) !== false || strpos( $s, 'bank' ) !== false ) return 'bank_transfer';
        return 'manual';
    }

    private static function sale_reference( int $client_id, string $receipt, string $person, int $property_id, string $date, float $total, float $paid ): string {
        if ( $receipt !== '' ) {
            return 'c' . $client_id . '-' . mb_substr( preg_replace( '/[^A-Za-z0-9\-_\/\.]/', '', $receipt ), 0, 100 );
        }
        return 'c' . $client_id . '-imp-' . substr( sha1( implode( '|', [ $client_id, $person, $property_id, $date, $total, $paid ] ) ), 0, 24 );
    }

    private static function run_sales( int $client_id, array $rows, array $map, array $opts ): array {
        global $wpdb;
        $p          = $wpdb->prefix;
        $dry        = ! empty( $opts['dry_run'] );
        $fully_paid = ! empty( $opts['fully_paid'] );
        $fallback   = (int) ( $opts['fallback_property'] ?? 0 );
        $report     = self::new_report( 'Past sales' );
        $props      = self::property_index( $client_id );

        $fallback_row = null;
        if ( $fallback ) {
            foreach ( $props['list'] as $r ) {
                if ( (int) $r->id === $fallback ) { $fallback_row = $r; break; }
            }
        }

        // 1. Check every row and group payments that belong to the same sale.
        $groups = [];
        foreach ( $rows as $n => $row ) {
            $line = $n + 2;
            if ( count( array_filter( $row, static fn( $v ) => trim( (string) $v ) !== '' ) ) === 0 ) {
                continue;
            }

            $name  = self::cell( $row, $map, 'name' );
            $ph    = self::clean_phone( self::cell( $row, $map, 'phone' ) );
            $raw_e = self::cell( $row, $map, 'email' );
            $email = OFP_Contacts::email_key( $raw_e );
            $title = self::norm_title( self::cell( $row, $map, 'property' ) );
            $total = self::parse_money( self::cell( $row, $map, 'total' ) );
            $paid_raw = self::cell( $row, $map, 'paid' );
            $paid  = $fully_paid ? $total : self::parse_money( $paid_raw );
            $date_raw = self::cell( $row, $map, 'date' );
            $date  = self::parse_date( $date_raw );

            if ( $name === '' )                               { self::problem( $report, $line, 'The customer name is missing.' ); continue; }
            if ( ! $ph['ok'] )                                { self::problem( $report, $line, 'The phone number looks wrong: ' . self::cell( $row, $map, 'phone' ) ); continue; }
            if ( $raw_e !== '' && $email === '' )             { self::problem( $report, $line, 'The email looks wrong: ' . $raw_e ); continue; }
            if ( $ph['phone'] === '' && $email === '' )      { self::problem( $report, $line, 'Needs a phone number or an email.' ); continue; }
            if ( $total === null || $total <= 0 )             { self::problem( $report, $line, 'The total price is missing or not a number.' ); continue; }
            if ( $paid === null )                             { self::problem( $report, $line, 'The amount paid is missing. Fill it in, or tick "all of these sales are fully paid".' ); continue; }
            if ( $paid > $total + 0.005 )                     { self::problem( $report, $line, 'The amount paid is more than the total price.' ); continue; }
            if ( $date_raw !== '' && $date === null )         { self::problem( $report, $line, 'The date could not be read: ' . $date_raw . ' (use for example 25/03/2024)' ); continue; }
            if ( $date !== null && strtotime( $date ) > time() + 86400 ) { self::problem( $report, $line, 'The date is in the future.' ); continue; }

            $prop = $props['by_title'][ $title ] ?? null;
            if ( ! $prop && $fallback_row ) {
                $prop = [ 'id' => (int) $fallback_row->id, 'post_id' => (int) $fallback_row->wp_post_id, 'title' => (string) $fallback_row->title ];
            }
            if ( ! $prop ) {
                self::problem( $report, $line, 'No property of yours is called "' . self::cell( $row, $map, 'property' ) . '". Add that property first, or pick a property to use for rows that do not match.' );
                continue;
            }

            $date    = $date ?: current_time( 'mysql' );
            $person  = OFP_Contacts::person_key( $ph['phone'], $email );
            $gkey    = $person . '|' . $prop['id'] . '|' . number_format( $total, 2, '.', '' );
            $groups[ $gkey ][] = [
                'line' => $line, 'name' => $name, 'phone' => $ph['phone'], 'email' => $email,
                'property' => $prop, 'total' => $total, 'paid' => $paid, 'date' => $date,
                'receipt' => self::cell( $row, $map, 'receipt' ),
                'method'  => self::payment_method( self::cell( $row, $map, 'method' ) ),
                'notes'   => self::cell( $row, $map, 'notes' ),
                'person'  => $person,
            ];
        }

        // 2. One purchase per group, one payment per row.
        foreach ( $groups as $items ) {
            $first = $items[0];
            $total = $first['total'];

            // Rows that were already imported are skipped. If some payments of this sale are
            // already saved, the new ones join that same sale instead of creating a second copy.
            $anchor     = null;
            $candidates = [];
            foreach ( $items as $it ) {
                $ref   = self::sale_reference( $client_id, $it['receipt'], $it['person'], $it['property']['id'], $it['date'], $it['total'], $it['paid'] );
                $found = $wpdb->get_row( $wpdb->prepare(
                    "SELECT pu.id AS purchase_id, pu.amount_paid FROM {$p}ofp_property_payments py
                     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                     WHERE py.gateway = %s AND py.gateway_reference = %s AND pu.client_id = %d LIMIT 1",
                    self::GATEWAY, $ref, $client_id
                ) );
                if ( $found ) {
                    $report['duplicates']++;
                    $anchor = $anchor ?: $found;
                    continue;
                }
                $it['ref']    = $ref;
                $candidates[] = $it;
            }

            $fresh = [];
            $sum   = $anchor ? (float) $anchor->amount_paid : 0.0;
            foreach ( $candidates as $it ) {
                if ( $sum + $it['paid'] > $total + 0.005 ) {
                    self::problem( $report, $it['line'], 'The payments for this sale add up to more than the total price.' );
                    continue;
                }
                $sum    += $it['paid'];
                $fresh[] = $it;
            }
            if ( empty( $fresh ) ) {
                continue;
            }

            if ( $dry ) {
                $report['imported'] += count( $fresh );
                if ( ! $anchor ) {
                    $report['purchases']++;
                }
                continue;
            }

            if ( $anchor ) {
                $purchase_id = (int) $anchor->purchase_id;
                $balance     = max( 0.0, round( $total - $sum, 2 ) );
                $wpdb->update( "{$p}ofp_property_purchases", [
                    'amount_paid' => $sum,
                    'balance'     => $balance,
                    'status'      => $balance <= 0.00001 ? 'completed' : 'active',
                    'updated_at'  => current_time( 'mysql' ),
                ], [ 'id' => $purchase_id ] );
                self::save_payments( $report, $wpdb, $p, $purchase_id, $fresh );
                OFP_Contacts::mark_converted( $client_id, $first['phone'], $first['email'] );
                continue;
            }

            $dates   = array_column( $fresh, 'date' );
            $started = min( $dates );
            $balance = max( 0.0, round( $total - $sum, 2 ) );
            $lead_id = null;
            $keys    = OFP_Contacts::lead_keys( $client_id );
            $pk      = OFP_Contacts::phone_key( $first['phone'] );
            if ( $pk !== '' && isset( $keys['phones'][ $pk ] ) ) {
                $lead_id = $keys['phones'][ $pk ];
            } elseif ( $first['email'] !== '' && isset( $keys['emails'][ $first['email'] ] ) ) {
                $lead_id = $keys['emails'][ $first['email'] ];
            }

            $ok = $wpdb->insert( "{$p}ofp_property_purchases", [
                'property_id'        => $first['property']['id'],
                'client_id'          => $client_id,
                'lead_id'            => $lead_id,
                'buyer_name'         => mb_substr( $first['name'], 0, 150 ),
                'buyer_phone'        => $first['phone'],
                'buyer_email'        => $first['email'] !== '' ? $first['email'] : null,
                'total_price'        => $total,
                'amount_paid'        => $sum,
                'balance'            => $balance,
                'payment_owner_type' => 'client',
                'payment_owner_id'   => $client_id,
                'payment_method'     => 'manual',
                'status'             => $balance <= 0.00001 ? 'completed' : 'active',
                'created_at'         => $started,
                'updated_at'         => current_time( 'mysql' ),
            ] );
            $purchase_id = $ok ? (int) $wpdb->insert_id : 0;
            if ( ! $purchase_id ) {
                foreach ( $fresh as $it ) {
                    self::problem( $report, $it['line'], 'The database refused this sale.' );
                }
                continue;
            }
            $report['purchases']++;

            self::save_payments( $report, $wpdb, $p, $purchase_id, $fresh );

            OFP_Contacts::mark_converted( $client_id, $first['phone'], $first['email'] );
        }

        if ( ! $dry && $report['imported'] > 0 && class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Past sales imported', $client_id, [
                'message' => sprintf( '%d past payment(s) in %d sale(s) were imported from a file. %d already existed and %d had problems.', $report['imported'], $report['purchases'], $report['duplicates'], $report['invalid'] ),
            ] );
        }
        return $report;
    }

    /** Writes the payment rows of one sale. */
    private static function save_payments( array &$report, $wpdb, string $p, int $purchase_id, array $fresh ): void {
        foreach ( $fresh as $it ) {
            if ( $it['paid'] <= 0 ) {
                $report['imported']++; // a sale with nothing paid yet still counts as imported
                continue;
            }
            $paid_ok = $wpdb->insert( "{$p}ofp_property_payments", [
                'purchase_id'       => $purchase_id,
                'payment_method'    => $it['method'],
                'gateway'           => self::GATEWAY,
                'gateway_reference' => $it['ref'],
                'amount'            => $it['paid'],
                'status'            => 'successful',
                'payer_name'        => mb_substr( $it['name'], 0, 150 ),
                'payer_reference'   => $it['receipt'] !== '' ? mb_substr( $it['receipt'], 0, 150 ) : null,
                'note'              => trim( 'Imported past record. ' . $it['notes'] ),
                'verified_at'       => $it['date'],
                'created_at'        => $it['date'],
                'updated_at'        => current_time( 'mysql' ),
            ] );
            if ( $paid_ok ) {
                $report['imported']++;
            } else {
                self::problem( $report, $it['line'], 'The payment could not be saved.' );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECEIPTS FOR IMPORTED PAYMENTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Attaches the company's own receipt (PDF, JPG or PNG, up to 5 MB) to one payment.
     * Stored in the private receipts folder and shown through the secure receipt viewer.
     *
     * @param array $file One entry of $_FILES.
     * @return true|WP_Error
     */
    public static function attach_receipt( int $client_id, int $payment_id, array $file ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT py.id, py.receipt_path FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             WHERE py.id = %d AND pu.client_id = %d LIMIT 1",
            $payment_id,
            $client_id
        ) );
        if ( ! $row ) {
            return new WP_Error( 'receipt_not_found', 'That payment was not found.' );
        }
        if ( ! class_exists( 'OFP_Property_Manual_Payment' ) ) {
            return new WP_Error( 'receipt_unavailable', 'Receipt storage is not available.' );
        }

        $stored = OFP_Property_Manual_Payment::store_receipt( $file );
        if ( is_wp_error( $stored ) ) {
            return $stored;
        }

        $old = (string) $row->receipt_path;
        $wpdb->update( "{$p}ofp_property_payments", [
            'receipt_path' => $stored['path'],
            'receipt_mime' => $stored['mime'],
            'receipt_size' => $stored['size'],
            'updated_at'   => current_time( 'mysql' ),
        ], [ 'id' => $payment_id ] );

        // Remove the file this replaces, but only when it is one of our private uploads.
        if ( $old !== '' && strpos( $old, 'ofp-private-receipts' ) !== false ) {
            OFP_Property_Manual_Payment::delete_receipt( $old );
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HOLDING A FILE BETWEEN THE PREVIEW AND THE CONFIRM STEP
    // ─────────────────────────────────────────────────────────────────────────

    private static function stash_dir( int $client_id ): string {
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'ofp-imports/' . $client_id;
    }

    /** Saves the parsed file privately for an hour. Returns a token. */
    public static function stash( int $client_id, string $type, string $filename, array $rows ): string {
        $dir = self::stash_dir( $client_id );
        wp_mkdir_p( $dir );
        $root = dirname( $dir );
        if ( ! file_exists( $root . '/.htaccess' ) ) {
            @file_put_contents( $root . '/.htaccess', "# Deny all direct web access\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" );
            @file_put_contents( $root . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n" );
        }
        // Clear out old files.
        foreach ( (array) glob( $dir . '/*.json' ) as $old ) {
            if ( is_file( $old ) && filemtime( $old ) < time() - self::TTL ) {
                @unlink( $old );
            }
        }
        $token = wp_generate_password( 24, false, false );
        file_put_contents( $dir . '/' . $token . '.json', wp_json_encode( [
            'type' => $type, 'filename' => $filename, 'rows' => $rows, 'created' => time(),
        ] ) );
        return $token;
    }

    /** @return array{type:string,filename:string,rows:array}|null */
    public static function load_stash( int $client_id, string $token ): ?array {
        if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', $token ) ) {
            return null;
        }
        $file = self::stash_dir( $client_id ) . '/' . $token . '.json';
        if ( ! is_file( $file ) || filemtime( $file ) < time() - self::TTL ) {
            return null;
        }
        $data = json_decode( (string) file_get_contents( $file ), true );
        return ( is_array( $data ) && isset( $data['rows'], $data['type'] ) ) ? $data : null;
    }

    public static function drop_stash( int $client_id, string $token ): void {
        if ( preg_match( '/^[A-Za-z0-9]{24}$/', $token ) ) {
            @unlink( self::stash_dir( $client_id ) . '/' . $token . '.json' );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A TEMPLATE THE CLIENT CAN DOWNLOAD
    // ─────────────────────────────────────────────────────────────────────────

    public static function sample_csv( string $type ): string {
        $file_path = OFP_PATH . 'assets/templates/template-' . $type . '.csv';
        if ( file_exists( $file_path ) ) {
            return (string) file_get_contents( $file_path );
        }

        switch ( $type ) {
            case 'sales':
                return "Name,Phone,Email,Property,Total price,Amount paid,Date,Receipt number,Payment method,Notes\n"
                     . "Ade Okafor,08031234567,ade.okafor@example.com,Palm Court Duplex,45000000,15000000,25/03/2024,REC-0012,Bank transfer,Initial deposit 30%\n"
                     . "Ade Okafor,08031234567,ade.okafor@example.com,Palm Court Duplex,45000000,10000000,25/06/2024,REC-0044,Bank transfer,Second milestone installment\n"
                     . "Tolu Bello,08099887766,tolu.bello@example.com,Lakeview 3 Bedroom,32000000,32000000,12/01/2024,REC-0003,Bank transfer,Outright purchase paid in full\n";
            case 'customers':
                return "Name,Phone,Email,Date,Notes\nAde Okafor,08031234567,ade.okafor@example.com,25/03/2024,Bought a 4-bedroom duplex\nTolu Bello,08099887766,tolu.bello@example.com,12/01/2024,Tenant at Lakeview\n";
            default:
                return "Name,Phone,Email,Property,Status,Date,Notes\nChidi Eze,08055512345,chidi.eze@example.com,Lakeview 3 Bedroom,new,02/10/2026,Asked about payment plan\nAmina Yusuf,07012345678,,Palm Court Duplex,contacted,05/10/2026,Inspection scheduled\n";
        }
    }
}
