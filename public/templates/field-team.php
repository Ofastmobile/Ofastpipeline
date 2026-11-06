<?php
/**
 * Template: /field-team — review queue, field agents and areas (platform account only).
 *
 * Who may open it: the platform owner, Managers, and team members given "Approve listings".
 * The Agents and Areas tabs (invite, ID checks, areas) are for the owner and Managers.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
if ( ! OFP_Field_Agent::can_approve() ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}
$client     = OFP_Auth::current_client();
$user       = OFP_Field_Agent::user();
$can_manage = OFP_Field_Agent::can_manage();

$tab = sanitize_key( $_GET['tab'] ?? 'queue' );
if ( ! in_array( $tab, [ 'queue', 'agents', 'areas' ], true ) || ( 'queue' !== $tab && ! $can_manage ) ) {
    $tab = 'queue';
}

$flash_key = 'ofp_fat_flash_' . ( ! empty( $user->is_team_member ) ? 't' : 'c' ) . (int) $user->id;
$flash     = get_transient( $flash_key );
if ( $flash ) { delete_transient( $flash_key ); }

// ── Actions (every one checks the nonce and the permission again) ──────
if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
    $result = [ false, 'Security check failed. Please try again.' ];
    $back   = $tab;

    if ( wp_verify_nonce( $_POST['ofp_fat_nonce'] ?? '', 'ofp_fat_action' ) ) {
        $act  = sanitize_key( $_POST['fat_action'] ?? '' );
        $in   = wp_unslash( $_POST );
        $aid  = (int) ( $in['agent_id'] ?? 0 );
        $valid_agent = in_array( $aid, array_map( static function ( $a ) { return (int) $a->id; }, OFP_Field_Agent::agents() ), true );
        $result = [ false, 'Not allowed.' ];

        if ( 'decide' === $act ) {
            $back   = 'queue';
            $result = OFP_Field_Agent::decide( (int) ( $in['report_id'] ?? 0 ), sanitize_key( $in['decision'] ?? '' ), $in );
        } elseif ( $can_manage ) {
            if ( 'invite_agent' === $act ) {
                $back   = 'agents';
                $result = OFP_Field_Agent::invite_agent( sanitize_text_field( $in['name'] ?? '' ), (string) ( $in['email'] ?? '' ), (string) ( $in['phone'] ?? '' ) );
            } elseif ( 'kyc' === $act && $valid_agent ) {
                $back   = 'agents';
                $result = OFP_Field_Agent::kyc_decide( $aid, sanitize_key( $in['kyc_action'] ?? '' ), (string) ( $in['note'] ?? '' ) );
            } elseif ( 'set_areas' === $act && $valid_agent ) {
                $back   = 'agents';
                OFP_Field_Agent::set_agent_areas( $aid, (array) ( $in['areas'] ?? [] ) );
                $result = [ true, 'Areas updated.' ];
            } elseif ( 'remove_agent' === $act && $valid_agent ) {
                $back   = 'agents';
                $result = OFP_Field_Agent::remove_agent( $aid );
            } elseif ( 'add_area' === $act ) {
                $back   = 'areas';
                $result = OFP_Field_Agent::add_area( (string) ( $in['state'] ?? '' ), (string) ( $in['area'] ?? '' ) );
            } elseif ( 'toggle_area' === $act ) {
                $back   = 'areas';
                OFP_Field_Agent::toggle_area( (int) ( $in['area_id'] ?? 0 ) );
                $result = [ true, 'Area updated.' ];
            }
        }
    }
    set_transient( $flash_key, [ 'ok' => (bool) $result[0], 'msg' => (string) $result[1] ], 60 );
    wp_safe_redirect( home_url( '/field-team?tab=' . $back ) );
    exit;
}

$pending_n = OFP_Field_Agent::pending_count();
$pill = [
    'incomplete' => [ 'Profile not done', '#475569', '#e2e8f0' ],
    'submitted'  => [ 'Waiting for ID review', '#b45309', '#fef3c7' ],
    'approved'   => [ 'Approved', '#047857', '#d1fae5' ],
    'rejected'   => [ 'Needs fixing', '#b91c1c', '#fee2e2' ],
    'suspended'  => [ 'Suspended', '#475569', '#e2e8f0' ],
];
$nonce = wp_nonce_field( 'ofp_fat_action', 'ofp_fat_nonce', true, false );

/** WhatsApp link from a Nigerian number (0803… becomes 234803…). */
$wa = static function ( string $phone ): string {
    $d = preg_replace( '/\D+/', '', $phone );
    if ( 0 === strpos( $d, '0' ) ) { $d = '234' . substr( $d, 1 ); }
    return 'https://wa.me/' . $d;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Field Agents', $client ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">
<?php
$ofp_banner_btn = [ 'label' => 'Review Queue', 'url' => home_url( '/field-team' ) ];
include OFP_PATH . 'public/templates/partials/nav.php';
?>

<div class="ofp-container">
<div style="padding-bottom:60px;">

    <?php if ( $flash ) : ?>
        <div class="ofp-alert <?php echo $flash['ok'] ? 'ofp-alert-success' : 'ofp-alert-error'; ?>" style="margin-bottom:16px;border-radius:12px;padding:14px 18px;font-size:14px;"><?php echo esc_html( $flash['msg'] ); ?></div>
    <?php endif; ?>

    <div class="ft-tabs">
        <a class="ft-tab <?php echo 'queue' === $tab ? 'on' : ''; ?>" href="<?php echo esc_url( home_url( '/field-team?tab=queue' ) ); ?>">Review queue<?php echo $pending_n ? ' (' . (int) $pending_n . ')' : ''; ?></a>
        <?php if ( $can_manage ) : ?>
            <a class="ft-tab <?php echo 'agents' === $tab ? 'on' : ''; ?>" href="<?php echo esc_url( home_url( '/field-team?tab=agents' ) ); ?>">Agents</a>
            <a class="ft-tab <?php echo 'areas' === $tab ? 'on' : ''; ?>" href="<?php echo esc_url( home_url( '/field-team?tab=areas' ) ); ?>">Areas</a>
        <?php endif; ?>
    </div>

<?php /* ════════ REVIEW QUEUE ════════ */ ?>
<?php if ( 'queue' === $tab ) :
    $queue = OFP_Field_Agent::queue( 'submitted', 20 );
    if ( empty( $queue ) ) : ?>
        <div class="ft-card"><h3>All clear</h3><p class="ft-muted">Nothing is waiting for review.</p></div>
    <?php endif;
    foreach ( $queue as $r ) :
        $pid   = (int) $r->property_id;
        $type  = (string) get_post_meta( $pid, 'ofp_listing_type', true );
        $is_rent = in_array( $type, OFP_Property_Package::RENT_TYPES, true );
        $risky = OFP_Property_CPT::is_sale_like( $type ) || 'commercial' === $type;
        $flags = (array) json_decode( (string) $r->flags, true );
        $docs  = (array) json_decode( (string) $r->doc_files, true );
        $gal   = (array) json_decode( (string) get_post_meta( $pid, 'ofp_gallery_ids', true ), true );
        $ids   = array_filter( array_merge( [ get_post_thumbnail_id( $pid ) ], $gal ) );
        ?>
        <div class="ft-card">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div>
                    <h3><?php echo esc_html( get_the_title( $pid ) ); ?></h3>
                    <p class="ft-muted"><?php echo esc_html( ucfirst( $type ) ); ?> · <?php echo esc_html( OFP_Field_Agent::area_label( (int) $r->area_id ) ); ?> · <?php echo esc_html( get_post_meta( $pid, 'ofp_location_text', true ) ); ?></p>
                </div>
                <div style="text-align:right;"><div style="font-weight:800;font-size:18px;color:var(--text-main,#0f172a);">₦<?php echo esc_html( number_format( (float) get_post_meta( $pid, 'ofp_price', true ) ) ); ?></div>
                    <div class="ft-muted" style="margin:0;">by <?php echo esc_html( $r->agent_name ?: 'agent' ); ?> · <?php echo esc_html( human_time_diff( strtotime( $r->created_at ) ) ); ?> ago</div></div>
            </div>

            <?php foreach ( $flags as $f ) : ?><div class="ft-flag">⚠ <?php echo esc_html( $f ); ?></div><?php endforeach; ?>
            <?php if ( $risky ) : ?><div class="ft-flag" style="background:#fffbeb;color:#92400e;">Higher-risk listing: only the owner or a Manager can approve it.</div><?php endif; ?>

            <?php if ( $ids ) : ?><div class="ft-photos"><?php foreach ( array_slice( $ids, 0, 10 ) as $att ) : ?><a href="<?php echo esc_url( wp_get_attachment_url( (int) $att ) ); ?>" target="_blank" rel="noopener"><?php echo wp_get_attachment_image( (int) $att, 'thumbnail', false, [ 'loading' => 'lazy' ] ); ?></a><?php endforeach; ?></div><?php endif; ?>

            <div class="ft-grid">
                <div>
                    <div class="ft-kv"><b>Owner / caretaker:</b> <?php echo esc_html( $r->landlord_name ); ?></div>
                    <div class="ft-kv"><b>Phone:</b> <?php echo esc_html( $r->landlord_phone ); ?>
                        · <a href="tel:<?php echo esc_attr( $r->landlord_phone ); ?>">Call</a>
                        · <a href="<?php echo esc_url( $wa( $r->landlord_phone ) ); ?>" target="_blank" rel="noopener">WhatsApp</a></div>
                </div>
                <div>
                    <div class="ft-kv"><b>Visited:</b> <?php echo esc_html( mysql2date( 'M j, Y', $r->visited_at ) ); ?></div>
                    <div class="ft-kv"><b>Location:</b> <?php echo ( $r->lat && $r->lng ) ? '<a href="' . esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $r->lat . ',' . $r->lng ) ) . '" target="_blank" rel="noopener">Open on map</a>' : '<span style="color:#b91c1c;">not captured</span>'; ?></div>
                </div>
            </div>
            <div class="ft-checks" style="margin-top:8px;"><?php foreach ( OFP_Field_Agent::CHECKLIST as $text ) : ?><span>✓ <?php echo esc_html( $text ); ?></span><?php endforeach; ?></div>
            <?php if ( $r->notes ) : ?><div class="ft-kv" style="margin-top:8px;"><b>Agent's notes:</b> <?php echo esc_html( $r->notes ); ?></div><?php endif; ?>
            <?php if ( OFP_Field_Agent::is_video_id( (string) $r->video_id ) ) : ?>
                <div class="ft-kv" style="margin-top:8px;"><b>Video:</b>
                    <a href="<?php echo esc_url( OFP_Field_Agent::youtube_url( $r->video_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r->video_title ?: 'Watch on YouTube' ); ?></a>
                    <?php if ( $r->video_author ) : ?> · <?php echo esc_html( $r->video_author ); ?><?php endif; ?>
                    <?php if ( 'verified' !== $r->video_state ) : ?><span style="color:#b45309;font-weight:700;"> · could not be checked automatically</span><?php endif; ?>
                </div>
                <a href="<?php echo esc_url( OFP_Field_Agent::youtube_url( $r->video_id ) ); ?>" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $r->video_id . '/mqdefault.jpg' ); ?>" alt="Video preview" loading="lazy" referrerpolicy="no-referrer" style="width:200px;border-radius:8px;margin-top:6px;"></a>
            <?php endif; ?>
            <?php if ( $docs ) : ?><div class="ft-kv"><b>Documents:</b> <?php foreach ( $docs as $i => $d ) : ?><a href="<?php echo esc_url( OFP_Field_Agent::file_url( (string) $d ) ); ?>" target="_blank" rel="noopener">Document <?php echo (int) $i + 1; ?></a> <?php endforeach; ?></div><?php endif; ?>

            <form method="post" class="ft-form" style="margin-top:12px;border-top:1px solid var(--border-color,#e2e8f0);padding-top:6px;">
                <?php echo $nonce; ?>
                <input type="hidden" name="fat_action" value="decide">
                <input type="hidden" name="report_id" value="<?php echo (int) $r->id; ?>">
                <input type="hidden" name="total_package" value="">
                <label>Price (₦): you can correct it</label>
                <input type="number" name="price" min="1" step="1" value="<?php echo esc_attr( (float) get_post_meta( $pid, 'ofp_price', true ) ); ?>">
                <?php if ( $is_rent ) { OFP_Property_Package::render_fields( $pid, 'input[name="price"]', 'input[name="total_package"]' ); } ?>
                <?php if ( OFP_Field_Agent::is_video_id( (string) $r->video_id ) ) : ?>
                    <label class="ft-chk"><input type="checkbox" name="video_checked" value="1"> I watched the video and it shows this property.</label>
                    <label class="ft-chk"><input type="checkbox" name="remove_video" value="1"> Remove the video (publish without it).</label>
                <?php endif; ?>
                <label class="ft-chk"><input type="checkbox" name="landlord_confirmed" value="1"> I spoke to the owner on <?php echo esc_html( $r->landlord_phone ); ?> and they agreed to list this property.</label>
                <label>Note to the agent (needed to send back or reject)</label>
                <textarea name="note" rows="2"></textarea>
                <div class="ft-actions">
                    <?php if ( $risky && ! $can_manage ) : ?>
                        <span class="ft-muted" style="margin:0;align-self:center;">Ask the owner or a Manager to approve this one.</span>
                    <?php else : ?>
                        <button type="submit" name="decision" value="approve" class="ofp-btn ft-btn-ok">Approve and publish</button>
                    <?php endif; ?>
                    <button type="submit" name="decision" value="changes" class="ofp-btn ft-btn-warn">Send back</button>
                    <button type="submit" name="decision" value="reject" class="ofp-btn ft-btn-bad" onclick="return confirm('Reject this submission?');">Reject</button>
                </div>
            </form>
        </div>
    <?php endforeach;

    // Recently decided
    global $wpdb;
    $recent = (array) $wpdb->get_results( "SELECT r.*, m.name AS agent_name FROM {$wpdb->prefix}ofp_fa_reports r LEFT JOIN {$wpdb->prefix}ofp_team_members m ON m.id = r.agent_id WHERE r.status <> 'submitted' ORDER BY r.reviewed_at DESC, r.id DESC LIMIT 10" );
    if ( $recent ) : ?>
        <div class="ft-card"><h3>Recently decided</h3>
            <?php foreach ( $recent as $r ) : ?>
                <div class="ft-kv" style="padding:6px 0;border-top:1px solid var(--border-color,#e2e8f0);">
                    <?php echo esc_html( get_the_title( (int) $r->property_id ) ); ?> · <?php echo esc_html( $r->agent_name ?: 'agent' ); ?>
                    · <strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $r->status ) ) ); ?></strong>
                    <?php if ( $r->reviewed_by ) : ?> by <?php echo esc_html( $r->reviewed_by ); ?><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php /* ════════ AGENTS ════════ */ ?>
<?php elseif ( 'agents' === $tab ) :
    $agents    = OFP_Field_Agent::agents();
    $all_areas = OFP_Field_Agent::areas( true ); ?>
    <div class="ft-card">
        <h3>Invite a field agent</h3>
        <p class="ft-muted">They get an email and SMS to set a password. Then they complete their profile and wait for your approval.</p>
        <form method="post" class="ft-form">
            <?php echo $nonce; ?><input type="hidden" name="fat_action" value="invite_agent">
            <div class="ft-grid">
                <div><label>Full name</label><input type="text" name="name" required></div>
                <div><label>Email</label><input type="email" name="email" required></div>
                <div><label>Phone</label><input type="tel" name="phone" required></div>
            </div>
            <div class="ft-actions"><button type="submit" class="ofp-btn ofp-btn-primary">Send invitation</button></div>
        </form>
    </div>

    <?php if ( empty( $agents ) ) : ?><div class="ft-card"><p class="ft-muted" style="margin:0;">No field agents yet.</p></div><?php endif; ?>

    <?php foreach ( $agents as $a ) :
        $lab   = $pill[ $a->kyc_status ] ?? $pill['incomplete'];
        $st    = OFP_Field_Agent::agent_stats( (int) $a->id );
        $mine  = OFP_Field_Agent::agent_area_ids( (int) $a->id );
        $names = array_map( static function ( $x ) { return $x->area . ', ' . $x->state; }, OFP_Field_Agent::agent_areas( (int) $a->id ) ); ?>
        <div class="ft-card">
            <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                <div><h3><?php echo esc_html( $a->name ); ?></h3>
                    <p class="ft-muted" style="margin:0;"><?php echo esc_html( $a->email ); ?> · <?php echo esc_html( $a->phone ); ?></p></div>
                <div><span class="ft-pill" style="color:<?php echo esc_attr( $lab[1] ); ?>;background:<?php echo esc_attr( $lab[2] ); ?>;"><?php echo esc_html( 'pending' === $a->invite_status ? 'Invite not accepted' : $lab[0] ); ?></span></div>
            </div>
            <div class="ft-kv" style="margin-top:8px;"><b>Submitted:</b> <?php echo (int) array_sum( $st ); ?> · <b>Live:</b> <?php echo (int) $st['approved']; ?> · <b>Waiting:</b> <?php echo (int) $st['submitted']; ?></div>
            <div class="ft-kv"><b>Areas:</b> <?php echo $names ? esc_html( implode( ' · ', $names ) ) : '<span style="color:#b91c1c;">none assigned</span>'; ?></div>

            <?php if ( 'incomplete' !== $a->kyc_status && 'pending' !== $a->invite_status ) : ?>
                <div class="ft-kv"><b>Address:</b> <?php echo esc_html( $a->address ); ?></div>
                <div class="ft-kv"><b>ID:</b> <?php echo esc_html( $a->id_type ); ?> ending <?php echo esc_html( $a->id_last4 ); ?>
                    · <a href="<?php echo esc_url( OFP_Field_Agent::file_url( (string) $a->id_file ) ); ?>" target="_blank" rel="noopener">View ID photo</a>
                    · <a href="<?php echo esc_url( OFP_Field_Agent::file_url( (string) $a->selfie_file ) ); ?>" target="_blank" rel="noopener">View selfie</a></div>
            <?php endif; ?>

            <?php if ( 'submitted' === $a->kyc_status ) : ?>
                <form method="post" class="ft-form" style="border-top:1px solid var(--border-color,#e2e8f0);margin-top:10px;">
                    <?php echo $nonce; ?><input type="hidden" name="fat_action" value="kyc"><input type="hidden" name="agent_id" value="<?php echo (int) $a->id; ?>">
                    <label>Note (needed if you ask them to fix something)</label><textarea name="note" rows="2"></textarea>
                    <div class="ft-actions">
                        <button class="ofp-btn ft-btn-ok" name="kyc_action" value="approve">Approve agent</button>
                        <button class="ofp-btn ft-btn-bad" name="kyc_action" value="reject">Ask them to fix</button>
                    </div>
                </form>
            <?php endif; ?>

            <details class="ft-details">
                <summary>Assign areas</summary>
                <form method="post" class="ft-form">
                    <?php echo $nonce; ?><input type="hidden" name="fat_action" value="set_areas"><input type="hidden" name="agent_id" value="<?php echo (int) $a->id; ?>">
                    <?php if ( empty( $all_areas ) ) : ?><p class="ft-muted">Add areas on the Areas tab first.</p><?php endif; ?>
                    <div class="ft-grid"><?php foreach ( $all_areas as $ar ) : ?>
                        <label class="ft-chk"><input type="checkbox" name="areas[]" value="<?php echo (int) $ar->id; ?>" <?php checked( in_array( (int) $ar->id, $mine, true ) ); ?>> <?php echo esc_html( $ar->area . ', ' . $ar->state ); ?></label>
                    <?php endforeach; ?></div>
                    <div class="ft-actions"><button class="ofp-btn ofp-btn-primary">Save areas</button></div>
                </form>
            </details>

            <div class="ft-actions" style="margin-top:6px;">
                <?php if ( 'approved' === $a->kyc_status ) : ?>
                    <form method="post"><?php echo $nonce; ?><input type="hidden" name="fat_action" value="kyc"><input type="hidden" name="agent_id" value="<?php echo (int) $a->id; ?>"><button class="ofp-btn ofp-btn-secondary" name="kyc_action" value="suspend" onclick="return confirm('Suspend this agent?');">Suspend</button></form>
                <?php elseif ( 'suspended' === $a->kyc_status ) : ?>
                    <form method="post"><?php echo $nonce; ?><input type="hidden" name="fat_action" value="kyc"><input type="hidden" name="agent_id" value="<?php echo (int) $a->id; ?>"><button class="ofp-btn ofp-btn-secondary" name="kyc_action" value="reactivate">Reactivate</button></form>
                <?php endif; ?>
                <form method="post"><?php echo $nonce; ?><input type="hidden" name="fat_action" value="remove_agent"><input type="hidden" name="agent_id" value="<?php echo (int) $a->id; ?>"><button class="ofp-btn ft-btn-bad" onclick="return confirm('Remove this agent? Their submitted properties stay on the platform.');">Remove</button></form>
            </div>
        </div>
    <?php endforeach; ?>

<?php /* ════════ AREAS ════════ */ ?>
<?php else :
    $areas = OFP_Field_Agent::areas( false ); ?>
    <div class="ft-card">
        <h3>Add an area</h3>
        <p class="ft-muted">Agents can only work in areas assigned to them. Use one area per neighbourhood or LGA, for example "Yaba" in Lagos.</p>
        <form method="post" class="ft-form">
            <?php echo $nonce; ?><input type="hidden" name="fat_action" value="add_area">
            <div class="ft-grid">
                <div><label>State</label><select name="state" required><option value="">Choose…</option><?php foreach ( OFP_Field_Agent::STATES as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option><?php endforeach; ?></select></div>
                <div><label>Area</label><input type="text" name="area" required maxlength="120"></div>
            </div>
            <div class="ft-actions"><button class="ofp-btn ofp-btn-primary">Add area</button></div>
        </form>
    </div>
    <div class="ft-card">
        <h3>All areas</h3>
        <?php if ( empty( $areas ) ) : ?><p class="ft-muted" style="margin:0;">No areas yet.</p><?php endif; ?>
        <?php foreach ( $areas as $ar ) : ?>
            <form method="post" style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid var(--border-color,#e2e8f0);">
                <?php echo $nonce; ?><input type="hidden" name="fat_action" value="toggle_area"><input type="hidden" name="area_id" value="<?php echo (int) $ar->id; ?>">
                <span class="ft-kv" style="<?php echo $ar->active ? '' : 'opacity:.5;'; ?>"><?php echo esc_html( $ar->area . ', ' . $ar->state ); ?></span>
                <button class="ofp-btn ofp-btn-secondary" style="padding:6px 12px;"><?php echo $ar->active ? 'Turn off' : 'Turn on'; ?></button>
            </form>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</div>
</div>
</main>
</div><!-- .ofp-shell -->
<?php wp_footer(); ?>
</body>
</html>
