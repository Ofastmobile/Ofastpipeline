<?php
/**
 * Template: /import
 *
 * Bring in existing lists: leads, customers, or past sales with their payments.
 * Steps: 1 upload a file, 2 match the columns, 3 check (nothing is saved yet),
 * 4 import. See OFP_Import for the rules.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

if ( ! OFP_Auth::has_permission( 'edit_leads' ) ) {
    wp_die( 'You do not have permission to import data.', 'Access Denied', [ 'response' => 403 ] );
}

$client_id = (int) $client->id;
$page_url  = home_url( '/import' );
$types     = OFP_Import::types();

$type = isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : 'leads';
if ( ! isset( $types[ $type ] ) ) {
    $type = 'leads';
}

// ── Download a template / sample file ─────────────────────────────────────────
if ( ! empty( $_GET['sample'] ) || ! empty( $_GET['template'] ) ) {
    $sample = sanitize_key( wp_unslash( $_GET['sample'] ?? $_GET['template'] ) );
    if ( isset( $types[ $sample ] ) ) {
        $file_path = OFP_PATH . 'assets/templates/template-' . $sample . '.csv';
        if ( file_exists( $file_path ) ) {
            $content = (string) file_get_contents( $file_path );
        } else {
            $content = OFP_Import::sample_csv( $sample );
        }
        $payload = "\xEF\xBB\xBF" . ltrim( $content, "\xEF\xBB\xBF" );

        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
        nocache_headers();
        if ( function_exists( 'header_remove' ) ) {
            header_remove( 'Pragma' );
            header_remove( 'Cache-Control' );
            header_remove( 'Expires' );
        }
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="template-' . $sample . '.csv"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . strlen( $payload ) );
        header( 'Cache-Control: public, must-revalidate, max-age=0' );
        header( 'Pragma: public' );
        header( 'Expires: 0' );
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }
}

$error   = '';
$token   = isset( $_REQUEST['t'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['t'] ) ) : '';
$nonce_a = 'ofp_import_' . $client_id;
$posted  = ( $_SERVER['REQUEST_METHOD'] === 'POST' );

// ── Step 1: a file was uploaded ──────────────────────────────────────────────
if ( $posted && isset( $_POST['ofp_import_upload'] ) ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $nonce_a ) ) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } elseif ( empty( $_FILES['import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
        $error = 'Choose a file to upload.';
    } else {
        $rows = OFP_Import::read_file( $_FILES['import_file']['tmp_name'], sanitize_file_name( $_FILES['import_file']['name'] ) );
        if ( is_wp_error( $rows ) ) {
            $error = $rows->get_error_message();
        } else {
            $new = OFP_Import::stash( $client_id, $type, sanitize_file_name( $_FILES['import_file']['name'] ), $rows );
            wp_safe_redirect( add_query_arg( [ 'type' => $type, 't' => $new ], $page_url ) );
            exit;
        }
    }
}

// ── Steps 2 to 4: a file is waiting ──────────────────────────────────────────
$stash   = $token !== '' ? OFP_Import::load_stash( $client_id, $token ) : null;
if ( $token !== '' && ! $stash && ! $error ) {
    $error = 'That upload has expired. Please upload the file again.';
    $token = '';
}
if ( $stash ) {
    $type = $stash['type'];
}

$headers = $rows_data = $map = $opts = [];
$fields  = OFP_Import::fields( $type );
$preview = $report = null;

if ( $stash ) {
    $all_rows  = $stash['rows'];
    $headers   = array_map( 'strval', array_shift( $all_rows ) );
    $rows_data = $all_rows;
    $map       = OFP_Import::guess_mapping( $type, $headers );

    // The client's own column choices (after pressing Check or Import).
    if ( $posted && isset( $_POST['do'] ) ) {
        foreach ( $fields as $key => $def ) {
            $v = isset( $_POST['map'][ $key ] ) ? (int) $_POST['map'][ $key ] : -1;
            $map[ $key ] = ( $v >= 0 && $v < count( $headers ) ) ? $v : -1;
        }
    }
    $opts = [
        'batch'             => sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ),
        'status'            => sanitize_key( $_POST['status'] ?? 'new' ),
        'followups'         => ! empty( $_POST['followups'] ),
        'fully_paid'        => ! empty( $_POST['fully_paid'] ),
        'fallback_property' => absint( $_POST['fallback_property'] ?? 0 ),
    ];

    if ( $posted && isset( $_POST['do'] ) ) {
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $nonce_a ) ) {
            $error = 'Security check failed. Please refresh the page and try again.';
        } else {
            $missing = [];
            foreach ( $fields as $key => $def ) {
                if ( $def['required'] && $map[ $key ] < 0 && ! ( 'paid' === $key ) ) {
                    $missing[] = $def['label'];
                }
            }
            if ( 'sales' === $type && $map['paid'] < 0 && empty( $opts['fully_paid'] ) ) {
                $missing[] = 'Amount paid (or tick "all of these sales are fully paid")';
            }
            if ( $map['phone'] < 0 && $map['email'] < 0 ) {
                $missing[] = 'Phone or Email';
            }

            if ( ! empty( $missing ) ) {
                $error = 'Please match these columns: ' . implode( ', ', $missing ) . '.';
            } elseif ( 'import' === $_POST['do'] ) {
                $report = OFP_Import::run( $client_id, $type, $rows_data, $map, $opts );
                OFP_Import::drop_stash( $client_id, $token );
                $stash = null;
            } else {
                $preview = OFP_Import::preview( $client_id, $type, $rows_data, $map, $opts );
            }
        }
    }
}

$properties = ( 'sales' === $type ) ? OFP_Import::property_index( $client_id )['list'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Import', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
    <style>
        .ofp-im-tabs{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:22px;}
        .ofp-im-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:9px;background:transparent;border:1px solid var(--border-color,rgba(255,255,255,.08));text-decoration:none;color:var(--text-muted,#94a3b8);font-size:13px;font-weight:500;transition:all .2s;}
        .ofp-im-tab:hover{color:var(--text-main,#f8fafc);border-color:var(--border-highlight,rgba(255,255,255,.18));background:rgba(255,255,255,.02);}
        .ofp-im-tab.active{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.4);color:#60a5fa;font-weight:600;}
        .ofp-im-note{padding:14px 18px;border-radius:10px;margin-bottom:18px;font-size:14px;}
        .ofp-im-note.bad{background:rgba(239,68,68,.12);border-left:4px solid #ef4444;color:#fca5a5;}
        .ofp-im-note.ok{background:rgba(16,185,129,.12);border-left:4px solid #10b981;color:#6ee7b7;}
        .ofp-im-note.warn{background:rgba(245,158,11,.14);border-left:4px solid #f59e0b;color:#fcd34d;}
        .ofp-im-grid{display:grid;grid-template-columns:minmax(160px,240px) 1fr;gap:12px 18px;align-items:center;}
        .ofp-im-table{width:100%;border-collapse:collapse;font-size:13px;}
        .ofp-im-table th,.ofp-im-table td{padding:10px 12px;border-bottom:1px solid var(--border-color,rgba(128,128,128,.18));text-align:left;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis;}
        .ofp-im-table tbody tr:hover{background:var(--bg-card-hover,rgba(255,255,255,.02));}
        .ofp-im-sub{font-size:12px;opacity:.75;}
        .ofp-im-stat{display:inline-block;margin-right:24px;padding:12px 18px;border-radius:10px;background:var(--bg-body,#131722);border:1px solid var(--border-color,rgba(255,255,255,.06));}
        .ofp-im-stat b{font-size:24px;display:block;color:var(--brand-color,#3b82f6);}
        @media (max-width:640px){.ofp-im-grid{grid-template-columns:1fr}}

        /* Light Back Button */
        .ofp-btn-back-light{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:9px;background:rgba(255,255,255,.08);border:1px solid var(--border-color,rgba(255,255,255,.12));color:var(--text-main,#f8fafc);font-size:13px;font-weight:600;text-decoration:none;transition:all .2s;}
        .ofp-btn-back-light:hover{background:rgba(255,255,255,.15);color:#ffffff;border-color:rgba(255,255,255,.24);}
        [data-theme="light"] .ofp-btn-back-light,html.light .ofp-btn-back-light{background:#f1f5f9;border-color:#e2e8f0;color:#334155;}
        [data-theme="light"] .ofp-btn-back-light:hover,html.light .ofp-btn-back-light:hover{background:#e2e8f0;color:#0f172a;}

        /* Light CSV Template Download Button */
        .ofp-btn-tpl-light{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:9px;background:#ffffff;color:#0f172a !important;border:1px solid #e2e8f0;font-size:13px;font-weight:600;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,.15);transition:all .2s;}
        .ofp-btn-tpl-light:hover{background:#f1f5f9;color:#000000 !important;box-shadow:0 4px 12px rgba(0,0,0,.2);transform:translateY(-1px);}
        .ofp-btn-tpl-light svg{color:#0f172a;}
        [data-theme="light"] .ofp-btn-tpl-light,html.light .ofp-btn-tpl-light{background:#ffffff;color:#0f172a !important;border:1px solid #cbd5e1;box-shadow:0 1px 3px rgba(0,0,0,.08);}
        [data-theme="light"] .ofp-btn-tpl-light:hover,html.light .ofp-btn-tpl-light:hover{background:#f8fafc;border-color:#94a3b8;}

        /* Modern dropzone */
        .ofp-dropzone{border:2px dashed var(--border-color,rgba(255,255,255,.15));border-radius:12px;padding:32px 20px;text-align:center;background:var(--bg-body,#131722);cursor:pointer;transition:all .2s;position:relative;margin-bottom:18px;}
        .ofp-dropzone:hover,.ofp-dropzone.dragover{border-color:var(--brand-color,#3b82f6);background:rgba(59,130,246,.04);}
        .ofp-dropzone-icon{width:44px;height:44px;border-radius:50%;background:rgba(59,130,246,.12);color:var(--brand-color,#3b82f6);display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;}
        .ofp-dropzone-title{font-size:15px;font-weight:600;color:var(--text-main);margin-bottom:4px;}
        .ofp-dropzone-sub{font-size:12px;color:var(--text-muted);}
        .ofp-dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
        .ofp-badge-pill{display:inline-block;padding:2px 8px;border-radius:6px;background:rgba(255,255,255,.06);font-size:11px;font-weight:600;color:var(--text-muted);margin:0 2px;}

        /* Template banner */
        .ofp-tpl-banner{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 18px;border-radius:12px;background:rgba(59,130,246,.06);border:1px solid rgba(59,130,246,.18);margin-bottom:20px;}
        .ofp-guide-card{background:var(--bg-body,#131722);border:1px solid var(--border-color,rgba(255,255,255,.06));border-radius:12px;padding:18px;margin-top:22px;}
        .ofp-guide-list{list-style:none;padding:0;margin:12px 0 0;display:flex;flex-direction:column;gap:10px;font-size:13px;color:var(--text-muted);}
        .ofp-guide-list li{display:flex;align-items:flex-start;gap:10px;line-height:1.5;}
        .ofp-guide-bullet{color:#10b981;flex-shrink:0;margin-top:2px;}
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

<div class="ofp-container">

    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;align-items:center;margin-bottom:20px;">
        <div>
            <a class="ofp-btn-back-light" href="<?php echo esc_url( home_url( '/customers' ) ); ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" /></svg>
                <span>Back to Customers</span>
            </a>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="ofp-btn" href="<?php echo esc_url( home_url( '/leads' ) ); ?>" style="background:transparent;border:1px solid var(--border-color);color:var(--text-muted);">Leads</a>
            <a class="ofp-btn" href="<?php echo esc_url( home_url( '/customers' ) ); ?>" style="background:transparent;border:1px solid var(--border-color);color:var(--text-muted);">Customers</a>
        </div>
    </div>

    <?php if ( ! $stash && ! $report ) : ?>
        <div class="ofp-im-tabs">
            <?php foreach ( $types as $slug => $info ) : ?>
                <a class="ofp-im-tab <?php echo $type === $slug ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'type', $slug, $page_url ) ); ?>">
                    <?php if ( 'leads' === $slug ) : ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    <?php elseif ( 'customers' === $slug ) : ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                    <?php else : ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6H2.25m0 0H3m0 0h1.5m16.5 0H21m0 0h-.75A.75.75 0 0019.5 6v-.75M21 6h-1.5m-15 0h15m-15 0v13.5m15-13.5v13.5m0 0h1.5M3 19.5h1.5m0 0h15" /></svg>
                    <?php endif; ?>
                    <span><?php echo esc_html( $info['label'] ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ( $error ) : ?>
        <div class="ofp-im-note bad"><?php echo esc_html( $error ); ?></div>
    <?php endif; ?>

    <?php /* ───────────── FINAL REPORT ───────────── */ ?>
    <?php if ( $report ) : ?>
        <div class="ofp-card" style="padding:26px;border-radius:14px;">
            <h3 style="margin-top:0;font-size:18px;">Import Finished</h3>
            <div style="margin:20px 0;display:flex;gap:14px;flex-wrap:wrap;">
                <div class="ofp-im-stat"><b><?php echo esc_html( number_format( $report['imported'] ) ); ?></b>imported</div>
                <?php if ( 'sales' === $type ) : ?><div class="ofp-im-stat"><b><?php echo esc_html( number_format( $report['purchases'] ) ); ?></b>new sales</div><?php endif; ?>
                <div class="ofp-im-stat"><b><?php echo esc_html( number_format( $report['duplicates'] ) ); ?></b>already existed</div>
                <div class="ofp-im-stat"><b><?php echo esc_html( number_format( $report['invalid'] ) ); ?></b>had problems</div>
            </div>
            <?php if ( ! empty( $report['problems'] ) ) : ?>
                <p><strong>Rows that were not imported</strong> (row 1 is column names):</p>
                <ul style="margin:0 0 16px 18px;line-height:1.7;">
                    <?php foreach ( $report['problems'] as $pr ) : ?>
                        <li>Line <?php echo (int) $pr['line']; ?>: <?php echo esc_html( $pr['why'] ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="ofp-im-sub">Fix those rows in your file and import it again. Rows that already went in are skipped, so nothing is duplicated.</p>
            <?php endif; ?>
            <?php if ( 'sales' === $type && $report['imported'] > 0 ) : ?>
                <div class="ofp-im-note ok">No emails, SMS or calls were sent to these customers, and no credit was used. Open a customer to view the system receipt or attach your own receipt file to each payment.</div>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
                <?php if ( 'leads' === $type ) : ?>
                    <a class="ofp-btn ofp-btn-primary" href="<?php echo esc_url( home_url( '/leads' ) ); ?>">See my leads</a>
                <?php else : ?>
                    <a class="ofp-btn ofp-btn-primary" href="<?php echo esc_url( home_url( '/customers' ) ); ?>">See my customers</a>
                <?php endif; ?>
                <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( 'type', $type, $page_url ) ); ?>">Import another file</a>
            </div>
        </div>

    <?php /* ───────────── STEP 1: UPLOAD ───────────── */ ?>
    <?php elseif ( ! $stash ) : ?>
        <div class="ofp-card" style="padding:26px;border-radius:14px;">
            <!-- Pre-formatted CSV Template Banner -->
            <div class="ofp-tpl-banner">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;border-radius:8px;background:rgba(59,130,246,.15);color:#3b82f6;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:20px;height:20px;"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:14px;color:var(--text-main);">Need the template format?</div>
                        <div style="font-size:12px;color:var(--text-muted);">Download our pre-structured <?php echo esc_html( $types[ $type ]['label'] ); ?> CSV template pre-loaded with realistic examples.</div>
                    </div>
                </div>
                <a class="ofp-btn-tpl-light" href="<?php echo esc_url( OFP_URL . 'assets/templates/template-' . $type . '.csv' ); ?>" download="template-<?php echo esc_attr( $type ); ?>.csv" title="Download sample CSV template for <?php echo esc_attr( $types[ $type ]['label'] ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <span>Download CSV Template</span>
                </a>
            </div>

            <form id="ofp-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'type', $type, $page_url ) ); ?>">
                <?php wp_nonce_field( $nonce_a ); ?>
                <input type="hidden" name="ofp_import_upload" value="1">
                <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
                
                <div class="ofp-dropzone" id="ofp-dropzone-box">
                    <div class="ofp-dropzone-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:24px;height:24px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" /></svg>
                    </div>
                    <div class="ofp-dropzone-title" id="ofp-dropzone-text">Click to choose or drag & drop your spreadsheet here</div>
                    <div class="ofp-dropzone-sub">
                        Supported files: <span class="ofp-badge-pill">.CSV</span> <span class="ofp-badge-pill">.XLSX</span> <span class="ofp-badge-pill">.TSV</span> &middot; Up to 5 MB (max <?php echo esc_html( number_format( OFP_Import::MAX_ROWS ) ); ?> rows)
                    </div>
                    <input type="file" id="import_file" name="import_file" accept=".csv,.tsv,.txt,.xlsx" required onchange="handleFileSelected(this)">
                </div>

                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="ofp-btn ofp-btn-primary" style="padding:10px 22px;font-size:14px;font-weight:600;">Upload and Continue</button>
                </div>
            </form>

            <script>
                function handleFileSelected(input) {
                    if (input.files && input.files[0]) {
                        var file = input.files[0];
                        var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                        var textElem = document.getElementById('ofp-dropzone-text');
                        if (textElem) {
                            textElem.innerHTML = 'Selected: <strong>' + file.name + '</strong> (' + sizeMb + ' MB)';
                            textElem.style.color = '#10b981';
                        }
                    }
                }
            </script>

            <div class="ofp-guide-card">
                <div style="font-weight:700;font-size:13px;color:var(--text-main);display:flex;align-items:center;gap:6px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;color:#3b82f6;"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                    <span>Import Requirements & Instructions</span>
                </div>
                <ul class="ofp-guide-list">
                    <?php if ( 'leads' === $type ) : ?>
                        <li><span class="ofp-guide-bullet">✔</span><span>Each row requires either a <strong>phone number</strong> or an <strong>email address</strong>. Existing leads are automatically recognized and skipped.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>Imported prospects will not receive automatic SMS or calls unless you opt in during column matching.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>You can message newly imported batches anytime from the Leads page or Broadcast Sender.</span></li>
                    <?php elseif ( 'customers' === $type ) : ?>
                        <li><span class="ofp-guide-bullet">✔</span><span>Each row requires a phone number or email. Contacts are saved as converted clients and appear in the Customer Directory.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>To include properties bought and payments made, import under <strong>Past Sales</strong> instead.</span></li>
                    <?php else : ?>
                        <li><span class="ofp-guide-bullet">✔</span><span>Each row represents one payment installment. Rows matching the same customer, property, and total price are automatically consolidated into <strong>one sale</strong>.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>Properties are matched by name. Please ensure your properties are created on the platform prior to importing.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>Format dates as <code>DD/MM/YYYY</code> (e.g. 25/03/2024). Past recorded transaction dates are fully preserved.</span></li>
                        <li><span class="ofp-guide-bullet">✔</span><span>Zero SMS or call credits are deducted. Company receipts can be attached to each payment after import.</span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

    <?php /* ───────────── STEPS 2 TO 4: MATCH, CHECK, IMPORT ───────────── */ ?>
    <?php else : ?>
        <div class="ofp-card" style="padding:24px;margin-bottom:16px;">
            <h3 style="margin-top:0;"><?php echo esc_html( $types[ $type ]['label'] ); ?>: <?php echo esc_html( $stash['filename'] ); ?></h3>
            <p class="ofp-subtitle"><?php echo esc_html( number_format( count( $rows_data ) ) ); ?> rows found. This is how the first rows look:</p>
            <div style="overflow-x:auto;">
                <table class="ofp-im-table">
                    <thead><tr><?php foreach ( $headers as $h ) : ?><th><?php echo esc_html( $h ); ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ( array_slice( $rows_data, 0, 5 ) as $r ) : ?>
                        <tr><?php foreach ( range( 0, count( $headers ) - 1 ) as $i ) : ?><td><?php echo esc_html( $r[ $i ] ?? '' ); ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ( $preview ) : ?>
            <div class="ofp-im-note <?php echo $preview['ok'] > 0 ? 'ok' : 'warn'; ?>">
                <strong>Check result (nothing has been saved yet)</strong><br>
                <span class="ofp-im-stat"><b><?php echo esc_html( number_format( $preview['ok'] ) ); ?></b>ready to import</span>
                <span class="ofp-im-stat"><b><?php echo esc_html( number_format( $preview['duplicate'] ) ); ?></b>already exist, will be skipped</span>
                <span class="ofp-im-stat"><b><?php echo esc_html( number_format( $preview['invalid'] ) ); ?></b>have problems, will be skipped</span>
                <?php if ( ! empty( $preview['problems'] ) ) : ?>
                    <ul style="margin:10px 0 0 18px;">
                        <?php foreach ( array_slice( $preview['problems'], 0, 15 ) as $pr ) : ?>
                            <li>Line <?php echo (int) $pr['line']; ?>: <?php echo esc_html( $pr['why'] ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ( count( $preview['problems'] ) > 15 ) : ?><div class="ofp-im-sub">and <?php echo (int) ( count( $preview['problems'] ) - 15 ); ?> more. They are all listed after the import.</div><?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( add_query_arg( [ 'type' => $type, 't' => $token ], $page_url ) ); ?>" class="ofp-card" style="padding:24px;">
            <?php wp_nonce_field( $nonce_a ); ?>
            <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
            <input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">

            <h4 style="margin-top:0;">Match your columns</h4>
            <div class="ofp-im-grid">
                <?php foreach ( $fields as $key => $def ) : ?>
                    <label for="map_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def['label'] ); ?><?php echo $def['required'] ? ' *' : ''; ?></label>
                    <select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
                        <option value="-1">Not in my file</option>
                        <?php foreach ( $headers as $i => $h ) : ?>
                            <option value="<?php echo (int) $i; ?>" <?php selected( $map[ $key ], $i ); ?>><?php echo esc_html( ( $h !== '' ? $h : 'Column ' . ( $i + 1 ) ) . ( isset( $rows_data[0][ $i ] ) && $rows_data[0][ $i ] !== '' ? '   (e.g. ' . mb_substr( $rows_data[0][ $i ], 0, 24 ) . ')' : '' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
            </div>

            <h4 style="margin:24px 0 10px;">Options</h4>
            <div class="ofp-im-grid">
                <?php if ( 'leads' === $type || 'customers' === $type ) : ?>
                    <label for="batch">Batch name</label>
                    <div>
                        <input type="text" id="batch" name="batch" value="<?php echo esc_attr( $opts['batch'] ?: 'Import ' . wp_date( 'j M Y' ) ); ?>" maxlength="100">
                        <div class="ofp-im-sub">Use this name later to message exactly this group in Send Broadcast.</div>
                    </div>
                <?php endif; ?>
                <?php if ( 'leads' === $type ) : ?>
                    <label for="status">Status for rows with none</label>
                    <select name="status" id="status">
                        <?php foreach ( [ 'new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'dead' => 'Dead' ] as $v => $l ) : ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $opts['status'] ?: 'new', $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Automatic follow ups</label>
                    <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="followups" value="1" <?php checked( ! empty( $opts['followups'] ) ); ?>>
                        <span>Start my follow up messages for new leads in this file. <span class="ofp-im-sub">This can send SMS and use credit. Leave it off for old lists.</span></span></label>
                <?php endif; ?>
                <?php if ( 'sales' === $type ) : ?>
                    <label>Amounts paid</label>
                    <label style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="fully_paid" value="1" <?php checked( ! empty( $opts['fully_paid'] ) ); ?>>
                        <span>All of these sales are fully paid. <span class="ofp-im-sub">Ignores the Amount paid column.</span></span></label>
                    <label for="fallback_property">If a property is not found</label>
                    <div>
                        <select name="fallback_property" id="fallback_property">
                            <option value="0">Skip that row and tell me</option>
                            <?php foreach ( $properties as $pr ) : ?>
                                <option value="<?php echo (int) $pr->id; ?>" <?php selected( (int) $opts['fallback_property'], (int) $pr->id ); ?>>Use: <?php echo esc_html( $pr->title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="ofp-im-sub">Imported sales count against a property's available units, so only pick one if that is right.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div style="margin-top:24px;display:flex;gap:10px;flex-wrap:wrap;">
                <button type="submit" name="do" value="preview" class="ofp-btn <?php echo $preview ? '' : 'ofp-btn-primary'; ?>">Check my file</button>
                <?php if ( $preview && $preview['ok'] > 0 ) : ?>
                    <button type="submit" name="do" value="import" class="ofp-btn ofp-btn-primary">Import <?php echo esc_html( number_format( $preview['ok'] ) ); ?> row<?php echo 1 === $preview['ok'] ? '' : 's'; ?></button>
                <?php endif; ?>
                <a class="ofp-btn" href="<?php echo esc_url( add_query_arg( 'type', $type, $page_url ) ); ?>">Start again</a>
            </div>
        </form>
    <?php endif; ?>
</div>
</div><!-- .ofp-content-area -->
</main>
</div><!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
