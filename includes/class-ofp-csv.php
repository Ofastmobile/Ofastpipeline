<?php
/**
 * OFP_CSV
 *
 * Generates monthly pipeline reports as CSV files across all operational pillars:
 *  1. leads_{month}_{year}.csv             — all leads captured that month
 *  2. sales_installments_{month}_{year}.csv — property sales deals & installment progress
 *  3. rent_roll_{month}_{year}.csv         — tenant directory, rent rates, lease status
 *  4. inspections_{month}_{year}.csv       — inspection tour bookings & attendance
 *  5. transactions_{month}_{year}.csv      — unified financial receipts across all channels
 *  6. communications_{month}_{year}.csv    — all SMS, Voice, and Email interactions
 *
 * FILES ARE STORED IN:
 *  wp-content/uploads/ofp-archives/{client_id}/
 *  Protected from direct browser access via .htaccess.
 *
 * ACCESS CONTROL:
 *  Each archive gets a 72-hour tokenised download URL.
 *  The token is stored in ofp_archives and validated before serving the file.
 *  The client template /reports handles the actual file serving.
 *
 * CALLED BY:
 *  - OFP_Cron_Handler::monthly_archive() — automatically on 1st of each month
 *  - Admin reports page — manually for any client/month combination
 *  - /reports client portal chart AJAX and archive downloads
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_CSV {

    /**
     * Generate comprehensive monthly CSV reports for a client.
     *
     * Creates the archive directory, writes the 6 CSV report files,
     * stores the archive record with a 72-hour download token, and emails
     * the client an executive summary with the download link.
     *
     * @param  int    $client_id  Client ID.
     * @param  int    $month      Month number (1–12).
     * @param  int    $year       Four-digit year.
     * @return array|false        Array of generated files and download token, or false on failure.
     */
    public static function generate_monthly_report(
        int $client_id,
        int $month,
        int $year
    ): array|false {
        global $wpdb;
        $p = $wpdb->prefix;

        $client = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}ofp_clients WHERE id = %d LIMIT 1",
                $client_id
            )
        );

        if ( ! $client ) {
            error_log( "[OFP_CSV] Client {$client_id} not found." );
            return false;
        }

        // ── Create archive directory ──────────────────────────────────────────
        $upload_dir  = wp_upload_dir();
        $archive_dir = trailingslashit( $upload_dir['basedir'] ) . 'ofp-archives/' . $client_id . '/';

        if ( ! file_exists( $archive_dir ) ) {
            wp_mkdir_p( $archive_dir );
            file_put_contents( $archive_dir . '.htaccess', "deny from all\n" );
            file_put_contents( $archive_dir . 'index.php', "<?php // Silence is golden.\n" );
        }

        $month_padded = str_pad( (string) $month, 2, '0', STR_PAD_LEFT );
        $period_label = $month_padded . '_' . $year;

        // ── Helper to open CSV with UTF-8 BOM ──────────────────────────────────
        $open_csv = function( string $filename ) use ( $archive_dir ) {
            $filepath = $archive_dir . $filename;
            $fh = fopen( $filepath, 'w' );
            if ( $fh ) {
                fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel
            }
            return [ $fh, $filepath ];
        };

        // ── 1. Leads CSV ─────────────────────────────────────────────────────
        [ $leads_fh, $leads_file ] = $open_csv( "leads_{$period_label}.csv" );
        if ( ! $leads_fh ) {
            error_log( "[OFP_CSV] Could not open leads file: {$leads_file}" );
            return false;
        }

        fputcsv( $leads_fh, [
            'Name', 'Phone', 'Email', 'Source',
            'Date Submitted', 'Status', 'IVR Response',
            'Converted At', 'Notes',
        ] );

        $leads = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT name, phone, email, source, created_at, status,
                        ivr_response, converted_at, notes
                 FROM {$p}ofp_leads
                 WHERE client_id   = %d
                   AND MONTH(created_at) = %d
                   AND YEAR(created_at)  = %d
                 ORDER BY created_at ASC",
                $client_id, $month, $year
            )
        );

        foreach ( $leads as $lead ) {
            fputcsv( $leads_fh, [
                $lead->name         ?? '',
                $lead->phone        ?? '',
                $lead->email        ?? '',
                $lead->source       ?? '',
                $lead->created_at   ?? '',
                $lead->status       ?? '',
                $lead->ivr_response ?? '',
                $lead->converted_at ?? '',
                $lead->notes        ?? '',
            ] );
        }
        fclose( $leads_fh );

        // ── 2. Property Sales & Installments CSV ──────────────────────────────
        [ $sales_fh, $sales_file ] = $open_csv( "sales_installments_{$period_label}.csv" );
        if ( $sales_fh ) {
            fputcsv( $sales_fh, [
                'Buyer Name', 'Buyer Phone', 'Buyer Email', 'Property Title',
                'Plan Type', 'Total Price (NGN)', 'Initial Deposit (NGN)',
                'Amount Paid (NGN)', 'Balance Due (NGN)', 'Installment Count',
                'Installments Paid', 'Next Due Date', 'Status', 'Date Created',
            ] );

            $sales = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pu.*, pr.title AS property_title
                     FROM {$p}ofp_property_purchases pu
                     LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                     WHERE pu.client_id = %d
                       AND ( ( MONTH(pu.created_at) = %d AND YEAR(pu.created_at) = %d )
                             OR pu.status = 'active' )
                     ORDER BY pu.created_at ASC",
                    $client_id, $month, $year
                )
            );

            foreach ( $sales as $sale ) {
                $paid_ins = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_installments WHERE purchase_id = %d AND status = 'paid'",
                    $sale->id
                ) );
                $next_due = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT MIN(due_date) FROM {$p}ofp_property_installments WHERE purchase_id = %d AND status != 'paid'",
                    $sale->id
                ) );

                fputcsv( $sales_fh, [
                    $sale->buyer_name ?? '',
                    $sale->buyer_phone ?? '',
                    $sale->buyer_email ?? '',
                    $sale->property_title ?? 'Property #' . $sale->property_id,
                    $sale->frequency ? ucfirst( $sale->frequency ) : 'Outright',
                    number_format( (float) ( $sale->total_price ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $sale->initial_payment ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $sale->amount_paid ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $sale->balance ?? 0 ), 2, '.', '' ),
                    (int) ( $sale->installment_count ?? 0 ),
                    $paid_ins,
                    $next_due ?: 'N/A',
                    ucfirst( $sale->status ?? 'active' ),
                    $sale->created_at ?? '',
                ] );
            }
            fclose( $sales_fh );
        }

        // ── 3. Rent Roll & Tenant Directory CSV ───────────────────────────────
        [ $rent_fh, $rent_file ] = $open_csv( "rent_roll_{$period_label}.csv" );
        if ( $rent_fh ) {
            fputcsv( $rent_fh, [
                'Tenant Name', 'Tenant Phone', 'Tenant Email', 'Property Title',
                'Billing Period', 'Cycle #', 'Rent Rate (NGN)', 'Service Charge (NGN)',
                'Caution Deposit (NGN)', 'Legal Fee (NGN)', 'Agency Fee (NGN)',
                'Total Package (NGN)', 'Amount Paid (NGN)', 'Balance Due (NGN)',
                'Lease Status', 'Start Date', 'End Date',
            ] );

            $leases = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.*, t.full_name AS tenant_name, t.phone AS tenant_phone, t.email AS tenant_email,
                            pr.title AS property_title
                     FROM {$p}ofp_property_leases l
                     JOIN {$p}ofp_property_tenants t ON t.id = l.tenant_id
                     LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
                     WHERE l.client_id = %d
                       AND ( ( MONTH(l.created_at) = %d AND YEAR(l.created_at) = %d )
                             OR l.status IN ('active', 'pending_offer') )
                     ORDER BY l.created_at ASC",
                    $client_id, $month, $year
                )
            );

            foreach ( $leases as $lease ) {
                fputcsv( $rent_fh, [
                    $lease->tenant_name ?? '',
                    $lease->tenant_phone ?? '',
                    $lease->tenant_email ?? '',
                    $lease->property_title ?? 'Property #' . $lease->property_id,
                    ucfirst( $lease->rent_period ?? 'yearly' ),
                    (int) ( $lease->cycle_number ?? 1 ),
                    number_format( (float) ( $lease->rent_amount ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->service_charge ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->caution_fee ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->legal_fee ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->agency_fee ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->total_initial_package ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->amount_paid ?? 0 ), 2, '.', '' ),
                    number_format( (float) ( $lease->balance ?? 0 ), 2, '.', '' ),
                    ucfirst( str_replace( '_', ' ', $lease->status ?? '' ) ),
                    $lease->start_date ?? '',
                    $lease->end_date ?? '',
                ] );
            }
            fclose( $rent_fh );
        }

        // ── 4. Property Inspections CSV ───────────────────────────────────────
        [ $insp_fh, $insp_file ] = $open_csv( "inspections_{$period_label}.csv" );
        if ( $insp_fh ) {
            fputcsv( $insp_fh, [
                'Prospect Name', 'Prospect Phone', 'Prospect Email', 'Property Title',
                'Tour Type', 'Preferred Date', 'Preferred Time', 'Status',
                'Prospect Notes', 'Booked At',
            ] );

            $inspections = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT inq.*, l.name AS lead_name, l.phone AS lead_phone, l.email AS lead_email,
                            pr.title AS property_title
                     FROM {$p}ofp_property_inquiries inq
                     LEFT JOIN {$p}ofp_leads l ON l.id = inq.lead_id
                     LEFT JOIN {$p}ofp_properties pr ON pr.id = inq.property_id
                     WHERE inq.client_id = %d
                       AND MONTH(inq.created_at) = %d
                       AND YEAR(inq.created_at)  = %d
                     ORDER BY inq.created_at ASC",
                    $client_id, $month, $year
                )
            );

            foreach ( $inspections as $inq ) {
                fputcsv( $insp_fh, [
                    $inq->lead_name ?? '',
                    $inq->lead_phone ?? '',
                    $inq->lead_email ?? '',
                    $inq->property_title ?? 'General Inquiry',
                    ( $inq->tour_type ?? '' ) === 'whatsapp_video' ? 'WhatsApp Video Tour' : 'Physical Tour',
                    $inq->preferred_viewing_date ?? '',
                    $inq->preferred_viewing_time ?? '',
                    ucfirst( $inq->status ?? 'pending' ),
                    $inq->message ?? '',
                    $inq->created_at ?? '',
                ] );
            }
            fclose( $insp_fh );
        }

        // ── 5. Unified Financial Transactions CSV ────────────────────────────
        [ $trx_fh, $trx_file ] = $open_csv( "transactions_{$period_label}.csv" );
        if ( $trx_fh ) {
            fputcsv( $trx_fh, [
                'Transaction ID', 'Category', 'Customer / Payer', 'Property / Item Reference',
                'Amount (NGN)', 'Payment Method', 'Gateway Reference', 'Status', 'Date',
            ] );

            // Gather property payments
            $prop_payments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT py.id, 'Property Purchase' AS category, py.payer_name,
                            pr.title AS item_ref, py.amount, py.payment_method,
                            py.gateway_reference, py.status, py.created_at
                     FROM {$p}ofp_property_payments py
                     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                     LEFT JOIN {$p}ofp_properties pr ON pr.id = pu.property_id
                     WHERE pu.client_id = %d
                       AND MONTH(py.created_at) = %d
                       AND YEAR(py.created_at)  = %d",
                    $client_id, $month, $year
                )
            );

            // Gather rent payments
            $rent_payments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT py.id, 'Rent & Tenancy' AS category, py.payer_name,
                            pr.title AS item_ref, py.amount, py.payment_method,
                            py.gateway_reference, py.status, py.created_at
                     FROM {$p}ofp_property_lease_payments py
                     INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
                     LEFT JOIN {$p}ofp_properties pr ON pr.id = l.property_id
                     WHERE l.client_id = %d
                       AND MONTH(py.created_at) = %d
                       AND YEAR(py.created_at)  = %d",
                    $client_id, $month, $year
                )
            );

            // Gather wallet deposits
            $wallet_deposits = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, 'Credit Wallet Top-up' AS category, '' AS payer_name,
                            CONCAT(UPPER(channel), ' Wallet') AS item_ref, amount,
                            'Online Gateway' AS payment_method, reference AS gateway_reference,
                            'successful' AS status, created_at
                     FROM {$p}ofp_credit_transactions
                     WHERE client_id = %d
                       AND type = 'deposit'
                       AND MONTH(created_at) = %d
                       AND YEAR(created_at)  = %d",
                    $client_id, $month, $year
                )
            );

            $all_trxs = array_merge( $prop_payments, $rent_payments, $wallet_deposits );
            usort( $all_trxs, function( $a, $b ) {
                return strcmp( $a->created_at, $b->created_at );
            } );

            foreach ( $all_trxs as $trx ) {
                fputcsv( $trx_fh, [
                    $trx->id ?? '',
                    $trx->category ?? '',
                    $trx->payer_name ?? 'N/A',
                    $trx->item_ref ?? '',
                    number_format( (float) ( $trx->amount ?? 0 ), 2, '.', '' ),
                    ucfirst( $trx->payment_method ?? 'Gateway' ),
                    $trx->gateway_reference ?? '',
                    ucfirst( str_replace( '_', ' ', $trx->status ?? 'successful' ) ),
                    $trx->created_at ?? '',
                ] );
            }
            fclose( $trx_fh );
        }

        // ── 6. Communications Log CSV ─────────────────────────────────────────
        [ $comms_fh, $comms_file ] = $open_csv( "communications_{$period_label}.csv" );
        if ( $comms_fh ) {
            fputcsv( $comms_fh, [
                'Recipient / Lead', 'Phone / Email', 'Channel', 'Message Content',
                'Status', 'Provider', 'Reference', 'Cost (NGN)', 'Sent At',
            ] );

            $comms = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.recipient, c.type, c.message, c.status, c.provider,
                            c.provider_ref, c.cost, c.sent_at,
                            COALESCE(l.name, '') AS lead_name, COALESCE(l.phone, '') AS lead_phone
                     FROM {$p}ofp_communications_log c
                     LEFT JOIN {$p}ofp_leads l ON l.id = c.lead_id
                     WHERE c.client_id      = %d
                       AND MONTH(c.sent_at) = %d
                       AND YEAR(c.sent_at)  = %d
                     ORDER BY c.sent_at ASC",
                    $client_id, $month, $year
                )
            );

            foreach ( $comms as $comm ) {
                fputcsv( $comms_fh, [
                    $comm->lead_name ?: ( $comm->recipient ?: 'Direct' ),
                    $comm->lead_phone ?: $comm->recipient,
                    strtoupper( $comm->type ?? 'SMS' ),
                    $comm->message ?? '',
                    ucfirst( $comm->status ?? 'sent' ),
                    $comm->provider ?? '',
                    $comm->provider_ref ?? '',
                    number_format( (float) ( $comm->cost ?? 0 ), 2, '.', '' ),
                    $comm->sent_at ?? '',
                ] );
            }
            fclose( $comms_fh );
        }

        // ── Record archive + generate download token ───────────────────────────
        $token        = bin2hex( random_bytes( 32 ) );
        $token_expiry = gmdate( 'Y-m-d H:i:s', strtotime( '+72 hours' ) );

        $files_list = [
            "leads_{$period_label}.csv",
            "sales_installments_{$period_label}.csv",
            "rent_roll_{$period_label}.csv",
            "inspections_{period_label}.csv",
            "transactions_{period_label}.csv",
            "communications_{period_label}.csv",
        ];
        $file_path_str = implode( '|', $files_list );

        $total_size = 0;
        foreach ( [ $leads_file, $sales_file, $rent_file, $insp_file, $trx_file, $comms_file ] as $f ) {
            if ( $f && file_exists( $f ) ) {
                $total_size += filesize( $f );
            }
        }

        // Delete any existing archive for this client + period to avoid duplicates.
        $wpdb->delete(
            $p . 'ofp_archives',
            [ 'client_id' => $client_id, 'period' => $period_label ]
        );

        $wpdb->insert(
            $p . 'ofp_archives',
            [
                'client_id'      => $client_id,
                'period'         => $period_label,
                'file_path'      => $file_path_str,
                'file_size'      => $total_size,
                'download_token' => $token,
                'token_expires'  => $token_expiry,
                'created_at'     => current_time( 'mysql' ),
            ]
        );

        // ── Email client executive report + download link ─────────────────────
        $month_name   = gmdate( 'F', mktime( 0, 0, 0, $month, 1 ) );
        $period_human = $month_name . ' ' . $year;
        $download_url = add_query_arg( 'token', $token, home_url( '/reports' ) );
        $summary      = self::get_monthly_summary( $client_id, $month, $year );

        if ( class_exists( 'OFP_Mailer' ) && method_exists( 'OFP_Mailer', 'send_monthly_report' ) ) {
            OFP_Mailer::send_monthly_report( $client, $period_human, $download_url, $summary );
        }

        return [
            'leads_file' => $leads_file,
            'sales_file' => $sales_file,
            'rent_file'  => $rent_file,
            'token'      => $token,
        ];
    }

    /**
     * Regenerate download token for an existing archive.
     *
     * @param  int $archive_id
     * @return string|false
     */
    public static function refresh_token( int $archive_id ): string|false {
        global $wpdb;
        $archive = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_archives WHERE id = %d LIMIT 1",
                $archive_id
            )
        );
        if ( ! $archive ) return false;

        $token  = bin2hex( random_bytes( 32 ) );
        $expiry = gmdate( 'Y-m-d H:i:s', strtotime( '+72 hours' ) );

        $wpdb->update(
            $wpdb->prefix . 'ofp_archives',
            [ 'download_token' => $token, 'token_expires' => $expiry ],
            [ 'id' => $archive_id ]
        );

        return $token;
    }

    /**
     * Get monthly summary stats across all operational modules.
     *
     * @param  int $client_id  Client ID.
     * @param  int $month      Month number (1-12).
     * @param  int $year       Year.
     * @return array
     */
    public static function get_monthly_summary(
        int $client_id,
        int $month,
        int $year
    ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $leads_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_leads
             WHERE client_id = %d AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            $client_id, $month, $year
        ) );

        $leads_converted = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_leads
             WHERE client_id = %d AND status = 'converted'
               AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            $client_id, $month, $year
        ) );

        $sales_vol = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_price), 0) FROM {$p}ofp_property_purchases
             WHERE client_id = %d AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            $client_id, $month, $year
        ) );

        $sales_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(py.amount), 0)
             FROM {$p}ofp_property_payments py
             INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
             WHERE pu.client_id = %d AND py.status IN ('successful', 'verified')
               AND MONTH(py.created_at) = %d AND YEAR(py.created_at) = %d",
            $client_id, $month, $year
        ) );

        $rent_collected = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(py.amount), 0)
             FROM {$p}ofp_property_lease_payments py
             INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
             WHERE l.client_id = %d AND py.status IN ('successful', 'verified', 'approved')
               AND MONTH(py.created_at) = %d AND YEAR(py.created_at) = %d",
            $client_id, $month, $year
        ) );

        $active_leases = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_leases
             WHERE client_id = %d AND status = 'active'",
            $client_id
        ) );

        $inspections_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_inquiries
             WHERE client_id = %d AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            $client_id, $month, $year
        ) );

        $inspections_done = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_property_inquiries
             WHERE client_id = %d AND status = 'completed'
               AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            $client_id, $month, $year
        ) );

        $comms_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}ofp_communications_log
             WHERE client_id = %d AND MONTH(sent_at) = %d AND YEAR(sent_at) = %d",
            $client_id, $month, $year
        ) );

        $credit_cost = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(cost), 0) FROM {$p}ofp_communications_log
             WHERE client_id = %d AND MONTH(sent_at) = %d AND YEAR(sent_at) = %d",
            $client_id, $month, $year
        ) );

        return [
            'leads'                 => $leads_total,
            'converted'             => $leads_converted,
            'conv_rate'             => $leads_total > 0 ? round( ( $leads_converted / $leads_total ) * 100 ) : 0,
            'sales_volume'          => $sales_vol,
            'sales_collected'       => $sales_paid,
            'rent_collected'        => $rent_collected,
            'active_leases'         => $active_leases,
            'inspections_total'     => $inspections_total,
            'inspections_completed' => $inspections_done,
            'comms_count'           => $comms_count,
            'credit_cost'           => $credit_cost,
            'total_inflow'          => $sales_paid + $rent_collected,
        ];
    }

    /**
     * Get 12-month series data for Chart.js and KPI grid for any selected year & metric.
     *
     * @param  int    $client_id
     * @param  string $metric     leads | sales | rent | inspections | comms_spend | transactions
     * @param  int    $year
     * @return array
     */
    public static function get_yearly_chart_data( int $client_id, string $metric, int $year ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $labels = [ 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' ];
        $series1 = array_fill( 0, 12, 0 );
        $series2 = array_fill( 0, 12, 0 );

        $series1_label = '';
        $series2_label = '';
        $format        = 'count';
        $kpis          = [];

        switch ( $metric ) {

            // ── 1. Property Sales & Installments ──
            case 'sales':
                $format        = 'currency';
                $series1_label = 'Sales Volume (NGN)';
                $series2_label = 'Payments Collected (NGN)';

                $vol_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(created_at) AS m, COALESCE(SUM(total_price), 0) AS val
                     FROM {$p}ofp_property_purchases
                     WHERE client_id = %d AND YEAR(created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $vol_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) $series1[ $m_idx ] = (float) $r->val;
                }

                $paid_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
                     FROM {$p}ofp_property_payments py
                     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                     WHERE pu.client_id = %d AND py.status IN ('successful', 'verified')
                       AND YEAR(py.created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $paid_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series2[ $m_idx ] ) ) $series2[ $m_idx ] = (float) $r->val;
                }

                $total_vol  = array_sum( $series1 );
                $total_paid = array_sum( $series2 );
                $deal_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_purchases WHERE client_id = %d AND YEAR(created_at) = %d",
                    $client_id, $year
                ) );
                $rec_rate = $total_vol > 0 ? round( ( $total_paid / $total_vol ) * 100 ) : 0;

                $kpis = [
                    [ 'title' => 'Sales Volume', 'value' => '₦' . number_format( $total_vol ), 'badge' => $year . ' Closed Deals', 'color' => 'hero' ],
                    [ 'title' => 'Collected Revenue', 'value' => '₦' . number_format( $total_paid ), 'badge' => 'Verified Inflow', 'color' => 'green' ],
                    [ 'title' => 'Total Deals', 'value' => (string) $deal_count, 'badge' => 'Purchases Signed', 'color' => 'blue' ],
                    [ 'title' => 'Recovery Rate', 'value' => $rec_rate . '%', 'badge' => 'Paid vs Volume', 'color' => 'purple' ],
                ];
                break;

            // ── 2. Rent & Tenant Collections ──
            case 'rent':
                $format        = 'currency';
                $series1_label = 'Rent Collected (NGN)';
                $series2_label = 'Outstanding Rent Due (NGN)';

                $rent_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
                     FROM {$p}ofp_property_lease_payments py
                     INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
                     WHERE l.client_id = %d AND py.status IN ('successful', 'verified', 'approved')
                       AND YEAR(py.created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $rent_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) $series1[ $m_idx ] = (float) $r->val;
                }

                $due_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(created_at) AS m, COALESCE(SUM(balance), 0) AS val
                     FROM {$p}ofp_property_leases
                     WHERE client_id = %d AND YEAR(created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $due_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series2[ $m_idx ] ) ) $series2[ $m_idx ] = (float) $r->val;
                }

                $total_rent_paid = array_sum( $series1 );
                $total_rent_due  = array_sum( $series2 );
                $active_leases   = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_leases WHERE client_id = %d AND status = 'active'",
                    $client_id
                ) );
                $total_tenants   = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_tenants WHERE client_id = %d",
                    $client_id
                ) );

                $kpis = [
                    [ 'title' => 'Rent Collected', 'value' => '₦' . number_format( $total_rent_paid ), 'badge' => $year . ' Rent Inflow', 'color' => 'hero' ],
                    [ 'title' => 'Outstanding Due', 'value' => '₦' . number_format( $total_rent_due ), 'badge' => 'Pending Balance', 'color' => 'orange' ],
                    [ 'title' => 'Active Leases', 'value' => (string) $active_leases, 'badge' => 'Current Signed Leases', 'color' => 'green' ],
                    [ 'title' => 'Total Tenants', 'value' => (string) $total_tenants, 'badge' => 'Registered Tenants', 'color' => 'blue' ],
                ];
                break;

            // ── 3. Inspections & Tours ──
            case 'inspections':
                $format        = 'count';
                $series1_label = 'Tour Bookings Logged';
                $series2_label = 'Completed Tours';

                $insp_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(created_at) AS m, COUNT(*) AS val
                     FROM {$p}ofp_property_inquiries
                     WHERE client_id = %d AND YEAR(created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $insp_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) $series1[ $m_idx ] = (int) $r->val;
                }

                $done_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(created_at) AS m, COUNT(*) AS val
                     FROM {$p}ofp_property_inquiries
                     WHERE client_id = %d AND status = 'completed' AND YEAR(created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $done_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series2[ $m_idx ] ) ) $series2[ $m_idx ] = (int) $r->val;
                }

                $total_booked = array_sum( $series1 );
                $total_done   = array_sum( $series2 );
                $pending_insp = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_property_inquiries WHERE client_id = %d AND status = 'pending'",
                    $client_id
                ) );
                $comp_rate = $total_booked > 0 ? round( ( $total_done / $total_booked ) * 100 ) : 0;

                $kpis = [
                    [ 'title' => 'Inspections Booked', 'value' => (string) $total_booked, 'badge' => $year . ' Total Tours', 'color' => 'hero' ],
                    [ 'title' => 'Completed Tours', 'value' => (string) $total_done, 'badge' => 'Conducted Visits', 'color' => 'green' ],
                    [ 'title' => 'Pending Schedule', 'value' => (string) $pending_insp, 'badge' => 'Awaiting Tour', 'color' => 'orange' ],
                    [ 'title' => 'Completion Rate', 'value' => $comp_rate . '%', 'badge' => 'Conducted vs Booked', 'color' => 'purple' ],
                ];
                break;

            // ── 4. Messaging & Credit Spend ──
            case 'comms_spend':
                $format        = 'mixed';
                $series1_label = 'Messages Sent (Count)';
                $series2_label = 'Credit Spend (NGN)';

                $comm_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(sent_at) AS m, COUNT(*) AS cnt, COALESCE(SUM(cost), 0) AS spend
                     FROM {$p}ofp_communications_log
                     WHERE client_id = %d AND YEAR(sent_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $comm_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) {
                        $series1[ $m_idx ] = (int) $r->cnt;
                        $series2[ $m_idx ] = (float) $r->spend;
                    }
                }

                $total_msgs  = array_sum( $series1 );
                $total_spend = array_sum( $series2 );

                $credits = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$p}ofp_credits WHERE client_id = %d LIMIT 1",
                    $client_id
                ) );
                $sms_rem   = (float) ( $credits->sms_remaining ?? 0 );
                $voice_rem = (float) ( $credits->voice_remaining ?? 0 );

                $kpis = [
                    [ 'title' => 'Messages Sent', 'value' => number_format( $total_msgs ), 'badge' => $year . ' SMS/Calls/Emails', 'color' => 'hero' ],
                    [ 'title' => 'Credit Spend', 'value' => '₦' . number_format( $total_spend, 2 ), 'badge' => $year . ' Total Cost', 'color' => 'blue' ],
                    [ 'title' => 'SMS Balance', 'value' => number_format( $sms_rem, 1 ), 'badge' => 'Credits Left', 'color' => 'green' ],
                    [ 'title' => 'Voice Balance', 'value' => number_format( $voice_rem, 1 ), 'badge' => 'Minutes Left', 'color' => 'purple' ],
                ];
                break;

            // ── 5. All Financial Transactions ──
            case 'transactions':
                $format        = 'currency';
                $series1_label = 'Property Inflow (NGN)';
                $series2_label = 'Rent Inflow (NGN)';

                $p_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
                     FROM {$p}ofp_property_payments py
                     INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id
                     WHERE pu.client_id = %d AND py.status IN ('successful', 'verified')
                       AND YEAR(py.created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $p_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) $series1[ $m_idx ] = (float) $r->val;
                }

                $r_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(py.created_at) AS m, COALESCE(SUM(py.amount), 0) AS val
                     FROM {$p}ofp_property_lease_payments py
                     INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id
                     WHERE l.client_id = %d AND py.status IN ('successful', 'verified', 'approved')
                       AND YEAR(py.created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $r_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series2[ $m_idx ] ) ) $series2[ $m_idx ] = (float) $r->val;
                }

                $prop_inflow = array_sum( $series1 );
                $rent_inflow = array_sum( $series2 );
                $total_inflow = $prop_inflow + $rent_inflow;
                $trx_count   = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT (
                        (SELECT COUNT(*) FROM {$p}ofp_property_payments py INNER JOIN {$p}ofp_property_purchases pu ON pu.id = py.purchase_id WHERE pu.client_id = %d AND YEAR(py.created_at) = %d) +
                        (SELECT COUNT(*) FROM {$p}ofp_property_lease_payments py INNER JOIN {$p}ofp_property_leases l ON l.id = py.lease_id WHERE l.client_id = %d AND YEAR(py.created_at) = %d)
                    )",
                    $client_id, $year, $client_id, $year
                ) );

                $kpis = [
                    [ 'title' => 'Gross Financial Inflow', 'value' => '₦' . number_format( $total_inflow ), 'badge' => $year . ' Total Collected', 'color' => 'hero' ],
                    [ 'title' => 'Property Sales Paid', 'value' => '₦' . number_format( $prop_inflow ), 'badge' => 'Purchases Inflow', 'color' => 'green' ],
                    [ 'title' => 'Rent Collections', 'value' => '₦' . number_format( $rent_inflow ), 'badge' => 'Tenancy Inflow', 'color' => 'blue' ],
                    [ 'title' => 'Transaction Count', 'value' => number_format( $trx_count ), 'badge' => 'Processed Payments', 'color' => 'purple' ],
                ];
                break;

            // ── Default: Leads & Conversions ──
            case 'leads':
            default:
                $format        = 'count';
                $series1_label = 'Leads Generated';
                $series2_label = 'Deals Converted';

                $lead_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(created_at) AS m, COUNT(*) AS val
                     FROM {$p}ofp_leads
                     WHERE client_id = %d AND YEAR(created_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $lead_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series1[ $m_idx ] ) ) $series1[ $m_idx ] = (int) $r->val;
                }

                $conv_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT MONTH(converted_at) AS m, COUNT(*) AS val
                     FROM {$p}ofp_leads
                     WHERE client_id = %d AND status = 'converted' AND YEAR(converted_at) = %d
                     GROUP BY m",
                    $client_id, $year
                ) );
                foreach ( $conv_rows as $r ) {
                    $m_idx = (int) $r->m - 1;
                    if ( isset( $series2[ $m_idx ] ) ) $series2[ $m_idx ] = (int) $r->val;
                }

                $total_leads = array_sum( $series1 );
                $total_conv  = array_sum( $series2 );
                $active_leads = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}ofp_leads WHERE client_id = %d AND status NOT IN ('converted', 'lost', 'dead')",
                    $client_id
                ) );
                $conv_rate = $total_leads > 0 ? round( ( $total_conv / $total_leads ) * 100 ) : 0;

                $kpis = [
                    [ 'title' => 'Leads Generated', 'value' => (string) $total_leads, 'badge' => $year . ' Total Prospects', 'color' => 'hero' ],
                    [ 'title' => 'Deals Converted', 'value' => (string) $total_conv, 'badge' => 'Won Opportunities', 'color' => 'green' ],
                    [ 'title' => 'Active Pipeline', 'value' => (string) $active_leads, 'badge' => 'In-Progress Leads', 'color' => 'blue' ],
                    [ 'title' => 'Conversion Rate', 'value' => $conv_rate . '%', 'badge' => 'Leads to Deals', 'color' => 'purple' ],
                ];
                break;
        }

        return [
            'labels'  => $labels,
            'format'  => $format,
            'metric'  => $metric,
            'year'    => $year,
            'series1' => [
                'label' => $series1_label,
                'data'  => $series1,
            ],
            'series2' => [
                'label' => $series2_label,
                'data'  => $series2,
            ],
            'kpis' => $kpis,
        ];
    }
}
