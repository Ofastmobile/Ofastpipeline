<?php
/**
 * Template: /leads
 * Client's leads list with filtering and status management.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

nocache_headers();

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Auth::has_permission( 'view_leads' ) ) {
    wp_die( 'You do not have permission to view leads.', 'Access Denied', [ 'response' => 403 ] );
}

// Handle status update
$message = isset( $_GET['note_saved'] ) ? 'note' : '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_leads_nonce'] ) ) {
    if ( ! OFP_Auth::has_permission( 'edit_leads' ) ) {
        wp_die( 'You do not have permission to edit leads.', 'Access Denied', [ 'response' => 403 ] );
    }

    if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_leads_nonce'] ) ), 'ofp_leads_' . $client->id ) ) {
        $lead_id    = (int) ( $_POST['lead_id'] ?? 0 );
        $new_status = sanitize_text_field( wp_unslash( $_POST['new_status'] ?? '' ) );
        $allowed    = [ 'new', 'contacted', 'interested', 'converted', 'dead' ];

        // Add a note to a lead.
        if ( isset( $_POST['lead_note'] ) ) {
            global $wpdb;
            $note_text = trim( (string) wp_unslash( $_POST['lead_note'] ) );
            $note_text = function_exists( 'mb_substr' ) ? mb_substr( $note_text, 0, 1000 ) : substr( $note_text, 0, 1000 );
            $note_owns = $lead_id ? $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ofp_leads WHERE id = %d AND client_id = %d LIMIT 1",
                $lead_id, $client->id
            ) ) : 0;
            if ( $note_owns && $note_text !== '' ) {
                OFP_Lead::add_note( $lead_id, $note_text );
                wp_safe_redirect( home_url( '/leads?note_saved=1' ) );
                exit;
            }
        }

        if ( $lead_id && in_array( $new_status, $allowed, true ) ) {
            // Verify this lead belongs to this client.
            global $wpdb;
            $owns = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ofp_leads WHERE id = %d AND client_id = %d LIMIT 1",
                    $lead_id, $client->id
                )
            );
            if ( $owns ) {
                OFP_Lead::update_status( $lead_id, $new_status );
                if ( $new_status === 'converted' ) {
                    OFP_Queue::cancel_for_lead( $lead_id );
                }
                $message = 'success';

                if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) === 'xmlhttprequest' ) {
                    $badges = [
                        'new'        => '<span class="ofp-badge ofp-badge-blue">New</span>',
                        'contacted'  => '<span class="ofp-badge ofp-badge-yellow">Contacted</span>',
                        'interested' => '<span class="ofp-badge ofp-badge-orange">Interested</span>',
                        'converted'  => '<span class="ofp-badge ofp-badge-green">✅ Converted</span>',
                        'dead'       => '<span class="ofp-badge ofp-badge-grey">Dead</span>',
                    ];
                    wp_send_json_success( [
                        'message'    => 'Lead status updated to ' . ucfirst( $new_status ),
                        'new_status' => $new_status,
                        'lead_id'    => $lead_id,
                        'badge_html' => $badges[ $new_status ] ?? esc_html( $new_status ),
                    ] );
                }
            }
        }
    }
}

// Pagination and filter
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$filter_q      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
$filter_batch  = sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) );
$per_page      = 20;
$current_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

global $wpdb;
$p = $wpdb->prefix;
[ $where, $args ] = OFP_Contacts::lead_filter( (int) $client->id, $filter_status, $filter_q, $filter_batch );
$batches          = OFP_Contacts::batches( (int) $client->id );
$filter_args      = array_filter( [ 'status' => $filter_status, 'q' => $filter_q, 'batch' => $filter_batch ], static fn( $v ) => $v !== '' );

// "Message selected": remember the chosen leads for an hour, then open Send Broadcast with them.
$bulk_error = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_bulk_nonce'] ) ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ofp_bulk_nonce'] ) ), 'ofp_bulk_' . (int) $client->id ) ) {
        $bulk_error = 'Security check failed. Please refresh and try again.';
    } elseif ( ! OFP_Auth::has_permission( 'send_messages' ) ) {
        $bulk_error = 'You do not have permission to send messages.';
    } else {
        if ( ! empty( $_POST['all_matching'] ) ) {
            $picked = OFP_Contacts::lead_ids_matching( (int) $client->id, $filter_status, $filter_q, $filter_batch, OFP_Client_Portal::BROADCAST_MAX );
        } else {
            $picked = OFP_Contacts::own_lead_ids( (int) $client->id, (array) ( $_POST['lead_ids'] ?? [] ) );
        }
        if ( empty( $picked ) ) {
            $bulk_error = 'Tick at least one lead first.';
        } else {
            $sel = wp_generate_password( 20, false, false );
            set_transient( 'ofp_sel_' . (int) $client->id . '_' . $sel, array_slice( $picked, 0, OFP_Client_Portal::BROADCAST_MAX ), HOUR_IN_SECONDS );
            wp_safe_redirect( add_query_arg( [ 'tab' => 'send', 'aud' => 'leads_selected', 'sel' => $sel ], home_url( '/message-templates' ) ) );
            exit;
        }
    }
}

$total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ofp_leads l WHERE {$where}", ...$args )
);

$leads = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT l.* FROM {$p}ofp_leads l
         WHERE {$where}
         ORDER BY l.created_at DESC
         LIMIT %d OFFSET %d",
        ...array_merge( $args, [ $per_page, ( $current_page - 1 ) * $per_page ] )
    )
);

$total_pages = ceil( $total / $per_page );

$stats = OFP_Lead::get_stats( $client->id );

// Per-status counts for filter pill badges
$status_counts_raw = $wpdb->get_results( $wpdb->prepare(
    "SELECT status, COUNT(*) as cnt FROM {$p}ofp_leads WHERE client_id = %d GROUP BY status",
    (int) $client->id
) );
$status_counts = [];
$total_all_leads = 0;
if ( is_array( $status_counts_raw ) ) {
    foreach ( $status_counts_raw as $scr ) {
        $status_counts[ $scr->status ] = (int) $scr->cnt;
        $total_all_leads += (int) $scr->cnt;
    }
}

$status_badges = [
    'new'        => '<span class="ofp-badge ofp-badge-blue">New</span>',
    'contacted'  => '<span class="ofp-badge ofp-badge-yellow">Contacted</span>',
    'interested' => '<span class="ofp-badge ofp-badge-orange">Interested</span>',
    'converted'  => '<span class="ofp-badge ofp-badge-green">✅ Converted</span>',
    'dead'       => '<span class="ofp-badge ofp-badge-grey">Dead</span>',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'My Prospects', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <?php if ( $message === 'note' ) : ?>
        <div class="ofp-alert ofp-alert-success">✅ Note saved.</div>
    <?php endif; ?>
    <?php if ( $message === 'success' ) : ?>
        <div class="ofp-alert ofp-alert-success">✅ Prospect status updated.</div>
    <?php endif; ?>

    <!-- Leads KPI Summary Grid -->
    <div class="ofp-zentra-kpi-grid" style="margin-bottom:24px;">
        <div class="ofp-kpi-card ofp-kpi-card-hero">
            <div class="ofp-kpi-title">New Prospects</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $stats['today'] ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-hero">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $stats['today'], $stats['yesterday'] ) ); ?> vs yesterday</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Total Prospects</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $stats['this_month'] ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-blue">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $stats['this_month'], $stats['last_month'] ) ); ?> this month</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Interested</div>
            <div class="ofp-kpi-value"><?php echo esc_html( $stats['interested'] ?: 0 ); ?></div>
            <div class="ofp-kpi-badge badge-amber">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $stats['interested'], $stats['interested_last'] ) ); ?> active interest</span>
            </div>
        </div>

        <div class="ofp-kpi-card">
            <div class="ofp-kpi-title">Conv. Rate</div>
            <div class="ofp-kpi-value">
                <?php 
                    $rate = $stats['this_month'] > 0 ? round( ( $stats['converted_month'] / $stats['this_month'] ) * 100 ) : 0;
                    $last_rate = $stats['last_month'] > 0 ? round( ( $stats['converted_last'] / $stats['last_month'] ) * 100 ) : 0;
                    echo esc_html( $rate ) . '%';
                ?>
            </div>
            <div class="ofp-kpi-badge badge-green">
                <span><?php echo strip_tags( OFP_Lead::get_growth_html( $rate, $last_rate ) ); ?> conversion rate</span>
            </div>
        </div>
    </div>

    <!-- Dashboard Layout: Top Charts -->
    <div class="ofp-leads-top-charts-grid">
        
        <!-- Main Line Chart -->
        <div class="ofp-card ofp-leads-chart-card" style="display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div>
                    <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Prospect Generation</h3>
                    <p style="margin: 0; font-size: 13px; color: var(--text-muted); margin-top: 4px;">Acquisition over the last 7 days</p>
                </div>
                <div style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); font-size: 12px; font-weight: 600; color: var(--text-muted);">
                    This Week ⌵
                </div>
            </div>
            
            <div style="display: flex; gap: 32px; margin-bottom: 24px;">
                <div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">This Week</div>
                    <div style="font-size: 20px; font-weight: 700; color: var(--accent-blue);">
                        <span style="font-size: 14px; color: var(--accent-blue); opacity: 0.7;">●</span> <?php echo esc_html( $total ); ?> Prospects
                    </div>
                </div>
                <div>
                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Previous Week</div>
                    <div style="font-size: 20px; font-weight: 700; color: var(--text-muted);">
                        <span style="font-size: 14px; color: var(--text-muted); opacity: 0.5;">●</span> <?php echo esc_html( max( 0, $total - 12 ) ); ?> Prospects
                    </div>
                </div>
            </div>

            <div style="position: relative; height: 220px; width: 100%; margin-top: auto;">
                <canvas id="leadsLineChart"></canvas>
            </div>
        </div>

        <!-- Conversion Goal Donut Chart -->
        <div class="ofp-card ofp-leads-chart-card" style="display: flex; flex-direction: column;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Conversion Goal</h3>
                <div style="padding: 4px 8px; border-radius: 4px; background: rgba(16, 185, 129, 0.1); color: var(--accent-green); font-size: 12px; font-weight: 700;">
                    + 14%
                </div>
            </div>

            <div style="position: relative; height: 200px; width: 100%; display: flex; align-items: center; justify-content: center; margin-top: auto; margin-bottom: auto;">
                <canvas id="conversionDonutChart"></canvas>
                <div style="position: absolute; text-align: center; pointer-events: none;">
                    <?php 
                        $rate = $total > 0 ? round(($stats['converted'] / $total) * 100) : 0;
                    ?>
                    <div style="font-size: 32px; font-weight: 700; color: var(--accent-blue);"><?php echo $rate; ?>%</div>
                    <div style="font-size: 12px; color: var(--text-muted);">Converted</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── SEARCH BAR & FILTER PILLS: Modernized Card with SVG search & count pills ── -->
    <div class="ofp-leads-filter-card">
        <form method="get" action="<?php echo esc_url( home_url( '/leads' ) ); ?>" class="ofp-leads-search-row">
            <?php if ( $filter_status !== '' ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $filter_status ); ?>">
            <?php endif; ?>

            <div class="ofp-leads-search-box">
                <svg class="ofp-leads-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <input class="ofp-leads-search-input" type="text" name="q" value="<?php echo esc_attr( $filter_q ); ?>" placeholder="Search prospects by name, phone or email...">
            </div>

            <select name="batch" class="ofp-leads-select">
                <option value="">All Sources</option>
                <?php foreach ( $batches as $b ) : ?>
                    <option value="<?php echo esc_attr( $b ); ?>" <?php selected( $filter_batch, $b ); ?>><?php echo esc_html( preg_replace( '/^import: /', 'Import: ', $b ) ); ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="ofp-btn ofp-btn-primary" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;">
                <span>Filter</span>
            </button>

            <?php if ( ! empty( $filter_args ) && ( $filter_q !== '' || $filter_batch !== '' ) ) : ?>
                <a href="<?php echo esc_url( add_query_arg( array_filter( [ 'status' => $filter_status ] ), home_url( '/leads' ) ) ); ?>" class="ofp-btn" style="padding:9px 14px;">Clear</a>
            <?php endif; ?>
        </form>

        <div class="ofp-leads-filter-pills">
            <?php
            $filters = [
                ''           => [ 'label' => 'All Prospects', 'count' => $total_all_leads ],
                'new'        => [ 'label' => 'New',           'count' => $status_counts['new'] ?? 0 ],
                'contacted'  => [ 'label' => 'Contacted',     'count' => $status_counts['contacted'] ?? 0 ],
                'interested' => [ 'label' => 'Interested',    'count' => $status_counts['interested'] ?? 0 ],
                'converted'  => [ 'label' => 'Converted',     'count' => $status_counts['converted'] ?? 0 ],
                'dead'       => [ 'label' => 'Dead',          'count' => $status_counts['dead'] ?? 0 ],
            ];
            foreach ( $filters as $val => $f_info ) :
                $active = $filter_status === $val;
                $url = add_query_arg( array_merge( array_diff_key( $filter_args, [ 'status' => 1 ] ), $val !== '' ? [ 'status' => $val ] : [] ), home_url( '/leads' ) );
            ?>
                <a class="ofp-leads-pill <?php echo $active ? 'active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" data-status="<?php echo esc_attr( $val ); ?>">
                    <span><?php echo esc_html( $f_info['label'] ); ?></span>
                    <span class="ofp-leads-pill-count"><?php echo (int) $f_info['count']; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ( $bulk_error ) : ?>
        <div style="padding:12px 16px;border-radius:8px;margin-bottom:14px;background:rgba(239,68,68,.12);border-left:4px solid #ef4444;"><?php echo esc_html( $bulk_error ); ?></div>
    <?php endif; ?>

    <div class="ofp-card" id="ofp-leads-results-card" style="padding: 0; overflow: hidden; transition: opacity 0.2s ease;">
        <div style="padding: 24px; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 600;">Recent Prospects</h3>
            <a href="<?php echo esc_url( home_url( '/import' ) ); ?>" style="font-size: 13px; color: var(--accent-blue); text-decoration: none; font-weight: 500;">Import</a>
            <span style="opacity:.4;">&nbsp;|&nbsp;</span>
            <a href="<?php echo esc_url( home_url( '/customers' ) ); ?>" style="font-size: 13px; color: var(--accent-blue); text-decoration: none; font-weight: 500;">Customers</a>
        </div>
        
        <?php if ( empty( $leads ) ) : ?>
            <div class="ofp-table-wrap ofp-table-responsive" style="margin-top: 0;">
                <table class="ofp-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>IVR</th>
                            <th>Date</th>
                            <th>Status Action</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="ofp-leads-tbody">
                        <tr>
                            <td colspan="8" style="text-align:center; padding: 48px;">
                                <div class="ofp-empty" style="padding:0;">
                                    <div class="ofp-empty-icon" style="font-size:24px;margin-bottom:12px;">📭</div>
                                    <h3 style="font-size:16px;font-weight:600;margin:0 0 4px;color:var(--text-main);">No prospects found</h3>
                                    <p style="margin:0;color:var(--text-muted);font-size:13px;">Prospects matching this filter will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div class="ofp-table-wrap ofp-table-responsive" style="margin-top: 0;">
                <table class="ofp-table">
                    <thead>
                        <tr>
                            <th style="width:34px;"><input type="checkbox" id="ofp-sel-all" aria-label="Select all leads on this page"></th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>IVR</th>
                            <th>Date</th>
                            <th>Status Action</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody id="ofp-leads-tbody">
                        <?php foreach ( $leads as $lead ) : 
                            $lead_notes  = $lead->notes ?? ( $lead->message ?? '' );
                            $lead_search = strtolower( ( $lead->name ?? '' ) . ' ' . ( $lead->phone ?? '' ) . ' ' . ( $lead->email ?? '' ) . ' ' . $lead_notes );
                        ?>
                            <tr class="ofp-lead-row" data-status="<?php echo esc_attr( $lead->status ); ?>" data-search="<?php echo esc_attr( $lead_search ); ?>">
                                <td><input type="checkbox" class="ofp-sel" name="lead_ids[]" value="<?php echo (int) $lead->id; ?>" form="ofp-bulk-form" aria-label="Select <?php echo esc_attr( $lead->name ?: $lead->phone ); ?>"></td>
                                <td><?php echo esc_html( $lead->name ?: '—' ); ?></td>
                                <td><strong><?php echo esc_html( $lead->phone ); ?></strong></td>
                                <td><?php echo esc_html( $lead->email ?: '—' ); ?></td>
                                <td class="ofp-lead-status-cell"><?php echo $status_badges[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                                <td style="color: var(--text-muted);"><?php echo $lead->ivr_response ? esc_html( 'Pressed ' . $lead->ivr_response ) : '—'; ?></td>
                                <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);">
                                    <?php echo esc_html( gmdate( 'M j, Y', strtotime( $lead->created_at ) ) ); ?>
                                </td>
                                <td>
                                    <?php if ( $lead->status !== 'converted' ) : ?>
                                        <form method="POST" action="" style="display:inline;">
                                             <?php wp_nonce_field( 'ofp_leads_' . $client->id, 'ofp_leads_nonce' ); ?>
                                            <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                                            <select name="new_status" onchange="this.form.submit()" style="font-size:12px;padding:6px 12px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-body);color:var(--text-main);outline:none;cursor:pointer;">
                                                <?php foreach ( array_keys( $status_badges ) as $s ) : ?>
                                                    <option value="<?php echo esc_attr( $s ); ?>"
                                                        <?php selected( $lead->status, $s ); ?>>
                                                        <?php echo esc_html( ucfirst( $s ) ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php else : ?>
                                        <span style="font-size:12px;color:#9ca3af;">Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="ofp-btn ofp-btn-sm ofp-btn-ghost" onclick="openMessageModal(<?php echo esc_attr($lead->id); ?>, '<?php echo esc_js($lead->name ?? ''); ?>', '<?php echo esc_js($lead->phone ?? ''); ?>', '<?php echo esc_js($lead->email ?? ''); ?>')" title="Send Message">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 16px; height: 16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                    </button>
                                    <details style="margin-top:6px; text-align:left; min-width:180px;">
                                        <summary style="cursor:pointer; font-size:12px; color:var(--text-muted);">Notes</summary>
                                        <?php if ( ! empty( $lead->notes ) ) : ?>
                                            <div style="white-space:pre-wrap; font-size:12px; margin:6px 0; max-height:120px; overflow:auto;"><?php echo esc_html( $lead->notes ); ?></div>
                                        <?php endif; ?>
                                        <?php if ( OFP_Auth::has_permission( 'edit_leads' ) ) : ?>
                                            <form method="POST" action="">
                                                <?php wp_nonce_field( 'ofp_leads_' . $client->id, 'ofp_leads_nonce', false ); ?>
                                                <input type="hidden" name="lead_id" value="<?php echo (int) $lead->id; ?>">
                                                <textarea name="lead_note" rows="2" maxlength="1000" placeholder="Add a note" style="width:100%; font-size:12px; margin-top:4px;"></textarea>
                                                <button type="submit" class="ofp-btn ofp-btn-sm" style="margin-top:4px;">Save note</button>
                                            </form>
                                        <?php endif; ?>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" id="ofp-bulk-form" action="<?php echo esc_url( add_query_arg( $filter_args, home_url( '/leads' ) ) ); ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px 16px;border-top:1px solid var(--border-color,rgba(128,128,128,.2));">
                <?php wp_nonce_field( 'ofp_bulk_' . (int) $client->id, 'ofp_bulk_nonce' ); ?>
                <button type="submit" class="ofp-btn ofp-btn-primary" id="ofp-msg-selected" disabled>Message selected (<span id="ofp-sel-count">0</span>)</button>
                <button type="submit" name="all_matching" value="1" class="ofp-btn">Message all <?php echo esc_html( number_format( $total ) ); ?> in this filter</button>
                <span style="font-size:12px;opacity:.7;">Opens Send Broadcast with these people chosen. Up to <?php echo (int) OFP_Client_Portal::BROADCAST_MAX; ?> at a time.</span>
            </form>
            <script>
            (function () {
                var all = document.getElementById('ofp-sel-all'), btn = document.getElementById('ofp-msg-selected'), out = document.getElementById('ofp-sel-count');
                function boxes() { return document.querySelectorAll('.ofp-sel'); }
                function refresh() { var n = 0; boxes().forEach(function (b) { if (b.checked) n++; }); out.textContent = n; btn.disabled = n === 0; }
                if (all) all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); refresh(); });
                document.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('ofp-sel')) refresh(); });
                refresh();
            })();
            </script>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="ofp-pagination" style="padding:16px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, [ 'paged' => $i ] ), home_url( '/leads' ) ) ); ?>"
                           class="ofp-page-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo esc_html( $i ); ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</div>
</main>
</div><!-- .ofp-shell -->

<!-- Send Message Modal -->
<div class="ofp-modal-backdrop" id="message-modal" style="display:none;">
    <div class="ofp-modal" style="max-width:500px;">
        <div class="ofp-modal-header">
            <h3>Send Message to <span id="modal-lead-name" style="color:var(--accent-blue);"></span></h3>
            <button type="button" class="ofp-modal-close" onclick="closeMessageModal()">&times;</button>
        </div>
        <form id="send-message-form">
            <div class="ofp-modal-body">
                <input type="hidden" name="lead_id" id="msg-lead-id" value="">
                
                <div class="ofp-field" style="margin-bottom:16px;">
                    <label>Channel <span class="required">*</span></label>
                    <select name="channel" id="msg-channel" required>
                        <option value="sms">SMS</option>
                        <option value="email">Email</option>
                    </select>
                </div>

                <div class="ofp-field" style="margin-bottom:16px;">
                    <label>Template (Optional)</label>
                    <select name="template_id" id="msg-template">
                        <option value="">-- No Template --</option>
                        <!-- Templates will be populated via JS -->
                    </select>
                </div>

                <div class="ofp-field" id="msg-subject-wrap" style="margin-bottom:16px; display:none;">
                    <label>Subject <span class="required">*</span></label>
                    <input type="text" name="subject" id="msg-subject" placeholder="Email subject line">
                </div>

                <div class="ofp-field" style="margin-bottom:16px;">
                    <label>Message Body <span class="required">*</span></label>
                    <textarea name="body" id="msg-body" rows="6" required placeholder="Write your message..."></textarea>
                </div>
            </div>
            <div class="ofp-modal-footer">
                <button type="button" class="ofp-btn ofp-btn-ghost" onclick="closeMessageModal()">Cancel</button>
                <button type="submit" class="ofp-btn ofp-btn-primary" id="msg-submit-btn">Send Message</button>
            </div>
        </form>
    </div>
</div>

<?php wp_footer(); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Shared styling vars
    const gridColor = document.documentElement.getAttribute('data-theme') === 'light' ? '#e2e8f0' : 'rgba(255, 255, 255, 0.05)';
    const textColor = document.documentElement.getAttribute('data-theme') === 'light' ? '#64748b' : '#94a3b8';

    // Lead Generation Line Chart (real data via AJAX, last 7 days)
    const lineCtx = document.getElementById('leadsLineChart');
    if (lineCtx && typeof ofpClientData !== 'undefined') {
        const gradient = lineCtx.getContext('2d').createLinearGradient(0, 0, 0, 220);
        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.3)');
        gradient.addColorStop(1, 'rgba(59, 130, 246, 0.0)');

        const lineBody = new URLSearchParams();
        lineBody.append('action', 'ofp_dashboard_chart_data');
        lineBody.append('nonce', ofpClientData.nonce);

        fetch(ofpClientData.ajaxurl, { method: 'POST', credentials: 'same-origin', body: lineBody })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res.success) return;
                const daily = res.data.daily || [];

                new Chart(lineCtx, {
                    type: 'line',
                    data: {
                        labels: daily.map(function(d) { return d.label; }),
                        datasets: [{
                            label: 'Leads',
                            data: daily.map(function(d) { return d.count; }),
                            borderColor: '#3b82f6',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#3b82f6',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#1e2638',
                                titleColor: '#f8fafc',
                                bodyColor: '#cbd5e1',
                                borderColor: 'rgba(255,255,255,0.1)',
                                borderWidth: 1,
                                padding: 10,
                                displayColors: false
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false, drawBorder: false },
                                ticks: { color: textColor, font: { family: "'Inter', sans-serif", size: 12 } }
                            },
                            y: {
                                border: { display: false },
                                grid: { color: gridColor },
                                ticks: { color: textColor, font: { family: "'Inter', sans-serif", size: 12 }, padding: 10, precision: 0 }
                            }
                        }
                    }
                });
            })
            .catch(function(err) { console.error('OFP leads chart error:', err); });
    }

    // Conversion Donut Chart
    const donutCtx = document.getElementById('conversionDonutChart');
    if (donutCtx) {
        const rate = <?php echo isset($rate) ? $rate : 72; ?>;
        new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: ['Converted', 'Pending'],
                datasets: [{
                    data: [rate, 100 - rate],
                    backgroundColor: ['#3b82f6', gridColor],
                    borderWidth: 0,
                    hoverOffset: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '80%',
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false } // We use the center text instead
                }
            }
        });
    }
});

// Manual Messaging Logic
let clientTemplates = [];

// Fetch templates once on load
fetch(ofpClientData.ajaxurl + '?action=ofp_fetch_templates&nonce=' + ofpClientData.nonce)
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            clientTemplates = res.data;
        }
    });

window.openMessageModal = function(leadId, name, phone, email) {
    document.getElementById('msg-lead-id').value = leadId;
    document.getElementById('modal-lead-name').textContent = name || 'Lead';
    document.getElementById('msg-channel').value = 'sms';
    document.getElementById('msg-subject').value = '';
    document.getElementById('msg-body').value = '';
    toggleMessageChannel();
    populateTemplates();
    document.getElementById('message-modal').style.display = 'flex';
};

window.closeMessageModal = function() {
    document.getElementById('message-modal').style.display = 'none';
};

function toggleMessageChannel() {
    const channel = document.getElementById('msg-channel').value;
    const subjWrap = document.getElementById('msg-subject-wrap');
    const subj = document.getElementById('msg-subject');
    
    if (channel === 'email') {
        subjWrap.style.display = 'block';
        subj.setAttribute('required', 'required');
    } else {
        subjWrap.style.display = 'none';
        subj.removeAttribute('required');
    }
    populateTemplates();
}

document.getElementById('msg-channel').addEventListener('change', toggleMessageChannel);

function populateTemplates() {
    const channel = document.getElementById('msg-channel').value;
    const select = document.getElementById('msg-template');
    
    select.innerHTML = '<option value="">-- No Template --</option>';
    
    clientTemplates.filter(t => t.type === channel).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        select.appendChild(opt);
    });
}

document.getElementById('msg-template').addEventListener('change', function() {
    const tplId = this.value;
    if (!tplId) {
        document.getElementById('msg-subject').value = '';
        document.getElementById('msg-body').value = '';
        return;
    }
    
    const tpl = clientTemplates.find(t => t.id == tplId);
    if (tpl) {
        if (tpl.type === 'email') {
            document.getElementById('msg-subject').value = tpl.subject || '';
        }
        document.getElementById('msg-body').value = tpl.body || '';
    }
});

document.getElementById('send-message-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('msg-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    const formData = new FormData(this);
    formData.append('action', 'ofp_send_manual_message');
    formData.append('nonce', ofpClientData.nonce);

    fetch(ofpClientData.ajaxurl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (window.OFP && typeof window.OFP.toast === 'function') {
                    window.OFP.toast('Message sent successfully!', 'success');
                } else {
                    alert('Message sent successfully!');
                }
                closeMessageModal();
            } else {
                if (window.OFP && typeof window.OFP.toast === 'function') {
                    window.OFP.toast(res.data || 'Failed to send message.', 'error');
                } else {
                    alert(res.data || 'Failed to send message.');
                }
            }
        })
        .catch(() => {
            if (window.OFP && typeof window.OFP.toast === 'function') {
                window.OFP.toast('Network error. Please try again.', 'error');
            } else {
                alert('Network error. Please try again.');
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Send Message';
        });
});

// AJAX Tab Switch & Live Filter for Leads
(function() {
    var searchInput = document.querySelector('.ofp-leads-search-input');
    var resultsCard = document.getElementById('ofp-leads-results-card');

    function bindCheckboxEvents() {
        var all = document.getElementById('ofp-sel-all');
        var btn = document.getElementById('ofp-msg-selected');
        var out = document.getElementById('ofp-sel-count');
        if (!out || !btn) return;

        function boxes() { return document.querySelectorAll('.ofp-sel'); }
        function refresh() {
            var n = 0;
            boxes().forEach(function(b) { if (b.checked) n++; });
            out.textContent = n;
            btn.disabled = n === 0;
        }

        if (all) {
            all.addEventListener('change', function() {
                boxes().forEach(function(b) { b.checked = all.checked; });
                refresh();
            });
        }
        refresh();
    }

    function doClientFilter(query) {
        var val = (query || '').trim().toLowerCase();
        var rows = document.querySelectorAll('.ofp-lead-row');
        rows.forEach(function(r) {
            var blob = r.getAttribute('data-search') || '';
            if (!val || blob.indexOf(val) !== -1) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            doClientFilter(this.value);
        });
    }

    function loadLeadsAjax(url, push) {
        if (!resultsCard) return;
        resultsCard.style.opacity = '0.5';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var newCard = doc.getElementById('ofp-leads-results-card');
                if (newCard) {
                    resultsCard.innerHTML = newCard.innerHTML;
                    resultsCard.style.opacity = '1';
                }

                // Update filter pills active state
                var newPills = doc.querySelectorAll('.ofp-leads-pill');
                if (newPills.length) {
                    var currentPills = document.querySelectorAll('.ofp-leads-pill');
                    currentPills.forEach(function(p, idx) {
                        if (newPills[idx]) {
                            p.className = newPills[idx].className;
                            var countEl = p.querySelector('.ofp-leads-pill-count');
                            var newCountEl = newPills[idx].querySelector('.ofp-leads-pill-count');
                            if (countEl && newCountEl) countEl.textContent = newCountEl.textContent;
                        }
                    });
                }

                if (push) {
                    window.history.pushState({ url: url }, '', url);
                }

                bindCheckboxEvents();
            })
            .catch(function(err) {
                console.error('AJAX lead tab load error:', err);
                resultsCard.style.opacity = '1';
                window.location.href = url;
            });
    }

    document.addEventListener('click', function(e) {
        var pill = e.target.closest('.ofp-leads-pill');
        if (pill) {
            e.preventDefault();
            var url = pill.getAttribute('href');
            document.querySelectorAll('.ofp-leads-pill').forEach(function(p) { p.classList.remove('active'); });
            pill.classList.add('active');
            loadLeadsAjax(url, true);
            return;
        }

        var pageBtn = e.target.closest('.ofp-page-btn');
        if (pageBtn) {
            e.preventDefault();
            var pageUrl = pageBtn.getAttribute('href');
            loadLeadsAjax(pageUrl, true);
            return;
        }
    });

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.url) {
            loadLeadsAjax(e.state.url, false);
        } else {
            loadLeadsAjax(window.location.href, false);
        }
    });

    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            window.location.reload();
        }
    });
})();
</script>
</body>
</html>
