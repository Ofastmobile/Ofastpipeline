<?php
/**
 * Template: /field — the Field Agent's own dashboard.
 *
 * Phone-first. A Field Agent sees only this page. They can:
 *  - complete their profile (address, ID, selfie, agreement) and wait for approval
 *  - submit a property they visited (it goes to review as "pending")
 *  - see the status of every submission and fix ones that were sent back
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$agent = OFP_Field_Agent::agent();
if ( ! $agent ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}
$client  = OFP_Auth::current_client();
$agent_id = (int) $agent->id;
$profile = OFP_Field_Agent::profile( $agent_id );

$error   = '';
$success = '';
$done    = sanitize_key( $_GET['done'] ?? '' );
if ( 'submitted' === $done ) { $success = 'Submitted for review. We will email you when it has been checked.'; }
if ( 'profile' === $done )   { $success = 'Thank you. Your profile is with our team for review.'; }

$is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
$act     = $is_post ? sanitize_key( $_POST['fa_action'] ?? '' ) : '';

if ( $is_post ) {
    if ( ! wp_verify_nonce( $_POST['ofp_fa_nonce'] ?? '', 'ofp_fa_action' ) ) {
        $error = 'Security check failed. Please try again.';
    } elseif ( 'save_profile' === $act ) {
        [ $ok, $msg ] = OFP_Field_Agent::save_profile( $agent, wp_unslash( $_POST ), $_FILES );
        if ( $ok ) { wp_safe_redirect( home_url( '/field?done=profile' ) ); exit; }
        $error = $msg;
    } elseif ( 'submit_property' === $act ) {
        $res = OFP_Field_Agent::submit_property( $agent, wp_unslash( $_POST ), $_FILES, (int) ( $_POST['edit_id'] ?? 0 ) );
        if ( $res['ok'] ) { wp_safe_redirect( home_url( '/field?done=submitted' ) ); exit; }
        $error = $res['message'];
    }
}

$status  = $profile->kyc_status;
$areas   = OFP_Field_Agent::agent_areas( $agent_id );
$reports = OFP_Field_Agent::agent_reports( $agent_id );
$stats   = OFP_Field_Agent::agent_stats( $agent_id );
$editing = (int) ( $_GET['edit'] ?? 0 );

// Values to show in the form: what was just posted, or the sent-back submission being fixed.
$old = [];
if ( $is_post && 'submit_property' === $act ) {
    $old = wp_unslash( $_POST );
} elseif ( $editing ) {
    foreach ( $reports as $r ) {
        if ( (int) $r->property_id === $editing && 'changes_requested' === $r->status ) {
            $old = [
                'listing_type' => get_post_meta( $editing, 'ofp_listing_type', true ),
                'title' => get_the_title( $editing ), 'description' => get_post_field( 'post_content', $editing ),
                'price' => get_post_meta( $editing, 'ofp_price', true ), 'price_period' => get_post_meta( $editing, 'ofp_price_period', true ),
                'property_type' => get_post_meta( $editing, 'ofp_property_type', true ),
                'bedrooms' => get_post_meta( $editing, 'ofp_bedrooms', true ), 'bathrooms' => get_post_meta( $editing, 'ofp_bathrooms', true ),
                'location_text' => get_post_meta( $editing, 'ofp_location_text', true ), 'area_id' => $r->area_id,
                'landlord_name' => $r->landlord_name, 'landlord_phone' => $r->landlord_phone,
                'visited_at' => substr( (string) $r->visited_at, 0, 10 ), 'notes' => $r->notes,
                'video_url' => get_post_meta( $editing, 'ofp_video_url', true ),
            ];
            break;
        }
    }
    if ( empty( $old ) ) { $editing = 0; }
}
$v  = static function ( string $k, $d = '' ) use ( $old ) { return esc_attr( $old[ $k ] ?? $d ); };
$is = static function ( string $k, string $val, string $d = '' ) use ( $old ) { return selected( (string) ( $old[ $k ] ?? $d ), $val, false ); };

$show_form = 'approved' === $status && ( ! empty( $_GET['new'] ) || $editing || ( $is_post && 'submit_property' === $act && $error ) );

$labels = [
    'submitted'         => [ 'Waiting for review', '#b45309', '#fef3c7' ],
    'approved'          => [ 'Live', '#047857', '#d1fae5' ],
    'changes_requested' => [ 'Needs changes', '#b91c1c', '#fee2e2' ],
    'rejected'          => [ 'Not accepted', '#475569', '#e2e8f0' ],
];
$types = [ 'apartment' => 'Apartment / Flat', 'house' => 'House', 'duplex' => 'Duplex', 'bungalow' => 'Bungalow', 'terrace' => 'Terrace', 'studio' => 'Studio / Room', 'shop' => 'Shop', 'office' => 'Office', 'warehouse' => 'Warehouse' ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Field Work', $client ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">
<?php
$ofp_banner_btn = [ 'label' => 'Submit a Property', 'url' => home_url( '/field?new=1' ) ];
include OFP_PATH . 'public/templates/partials/nav.php';
?>

<div class="ofp-container">
<div class="fa-wrap">

    <?php if ( $success ) : ?><div class="ofp-alert ofp-alert-success" style="margin-bottom:16px;border-radius:12px;padding:14px 18px;font-size:14px;"><?php echo esc_html( $success ); ?></div><?php endif; ?>
    <?php if ( $error ) : ?><div class="ofp-alert ofp-alert-error" style="margin-bottom:16px;border-radius:12px;padding:14px 18px;font-size:14px;"><?php echo esc_html( $error ); ?></div><?php endif; ?>

<?php if ( 'suspended' === $status ) : ?>
    <div class="fa-card"><h2>Account suspended</h2><p class="fa-muted">Your field agent account is suspended. Please contact the team.</p></div>

<?php elseif ( 'submitted' === $status ) : ?>
    <div class="fa-card"><h2>Your profile is being reviewed</h2><p class="fa-muted">We will email you as soon as you are approved. Then you can start submitting properties.</p></div>

<?php elseif ( in_array( $status, [ 'incomplete', 'rejected' ], true ) ) : ?>
    <div class="fa-card">
        <h2>Welcome, <?php echo esc_html( $agent->name ); ?></h2>
        <p class="fa-muted">Before you start, we need to know who you are. This takes about two minutes.</p>
        <?php if ( 'rejected' === $status && $profile->kyc_note ) : ?><div class="fa-note"><strong>Please fix:</strong> <?php echo esc_html( $profile->kyc_note ); ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="fa-form">
            <?php wp_nonce_field( 'ofp_fa_action', 'ofp_fa_nonce' ); ?>
            <input type="hidden" name="fa_action" value="save_profile">
            <label>Home address</label>
            <textarea name="address" rows="2" required><?php echo esc_textarea( $_POST['address'] ?? $profile->address ?? '' ); ?></textarea>
            <div class="fa-row">
                <div><label>ID type</label>
                    <select name="id_type" required>
                        <?php foreach ( [ 'NIN', "Voter's card", "Driver's licence", 'International passport' ] as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $_POST['id_type'] ?? $profile->id_type, $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div><label>ID number</label><input type="text" name="id_number" required autocomplete="off" placeholder="Only the last 4 are kept"></div>
            </div>
            <label>Photo of your ID</label><input type="file" name="id_photo" accept="image/*,.pdf" required>
            <label>A clear selfie of you</label><input type="file" name="selfie" accept="image/*" capture="user" required>
            <p class="fa-muted" style="margin-top:14px;"><strong>Our rules</strong></p>
            <ul style="margin:0 0 8px 18px;padding:0;font-size:13px;color:var(--text-main,#0f172a);">
                <?php foreach ( OFP_Field_Agent::AGREEMENT as $rule ) : ?><li style="margin:4px 0;"><?php echo esc_html( $rule ); ?></li><?php endforeach; ?>
            </ul>
            <label class="fa-check"><input type="checkbox" name="agree" value="1" required> I have read and agree to these rules.</label>
            <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%;margin-top:14px;padding:14px;">Send for approval</button>
        </form>
    </div>

<?php elseif ( $show_form ) : ?>
    <div class="fa-card">
        <h2><?php echo $editing ? 'Fix and resubmit' : 'Submit a property'; ?></h2>
        <p class="fa-muted">Only submit a property you have visited. A reviewer checks it before it goes live.</p>
        <?php if ( empty( $areas ) ) : ?>
            <div class="fa-note">No area is assigned to you yet. Please ask the team to assign one.</div>
        <?php else : ?>
        <form method="post" enctype="multipart/form-data" class="fa-form" id="fa-property-form">
            <?php wp_nonce_field( 'ofp_fa_action', 'ofp_fa_nonce' ); ?>
            <input type="hidden" name="fa_action" value="submit_property">
            <input type="hidden" name="edit_id" value="<?php echo (int) $editing; ?>">
            <input type="hidden" name="lat" id="fa-lat" value="<?php echo $v( 'lat' ); ?>">
            <input type="hidden" name="lng" id="fa-lng" value="<?php echo $v( 'lng' ); ?>">

            <label>What is it?</label>
            <select name="listing_type" id="fa-type" required <?php echo $editing ? 'disabled' : ''; ?>>
                <?php foreach ( [ 'rent' => 'For rent', 'sale' => 'For sale', 'shortlet' => 'Short-let', 'commercial' => 'Commercial', 'land' => 'Land' ] as $k => $lbl ) : ?>
                    <option value="<?php echo esc_attr( $k ); ?>" <?php echo $is( 'listing_type', $k, 'rent' ); ?>><?php echo esc_html( $lbl ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ( $editing ) : ?><input type="hidden" name="listing_type" value="<?php echo $v( 'listing_type' ); ?>"><?php endif; ?>

            <label>Title</label><input type="text" name="title" required maxlength="140" value="<?php echo $v( 'title' ); ?>" placeholder="e.g. 3 bedroom flat, Yaba">
            <label>Area</label>
            <select name="area_id" required>
                <?php foreach ( $areas as $a ) : ?><option value="<?php echo (int) $a->id; ?>" <?php echo $is( 'area_id', (string) $a->id ); ?>><?php echo esc_html( $a->area . ', ' . $a->state ); ?></option><?php endforeach; ?>
            </select>
            <label>Street address or landmark</label><input type="text" name="location_text" required value="<?php echo $v( 'location_text' ); ?>">

            <div class="fa-row">
                <div><label>Price (₦)</label><input type="number" name="price" required min="1" step="1" inputmode="numeric" value="<?php echo $v( 'price' ); ?>"></div>
                <div id="fa-period-wrap"><label>Per</label>
                    <select name="price_period">
                        <?php foreach ( [ 'year' => 'Year', 'month' => 'Month', '2years' => '2 years', 'night' => 'Night', 'week' => 'Week' ] as $k => $lbl ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php echo $is( 'price_period', $k, 'year' ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select></div>
            </div>

            <div id="fa-rooms">
                <label>Type of property</label>
                <select name="property_type">
                    <?php foreach ( $types as $k => $lbl ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php echo $is( 'property_type', $k, 'apartment' ); ?>><?php echo esc_html( $lbl ); ?></option><?php endforeach; ?>
                </select>
                <div class="fa-row">
                    <div><label>Bedrooms</label><input type="number" name="bedrooms" min="0" max="50" inputmode="numeric" value="<?php echo $v( 'bedrooms', '0' ); ?>"></div>
                    <div><label>Bathrooms</label><input type="number" name="bathrooms" min="0" max="50" inputmode="numeric" value="<?php echo $v( 'bathrooms', '0' ); ?>"></div>
                </div>
            </div>

            <label>Description</label><textarea name="description" rows="4"><?php echo esc_textarea( $old['description'] ?? '' ); ?></textarea>

            <label>Photos (at least 3<?php echo $editing ? ', add more if needed' : ''; ?>)</label>
            <input type="file" name="photos[]" accept="image/*" multiple <?php echo $editing ? '' : 'required'; ?>>

            <div id="fa-docs" class="fa-hide">
                <label>Title documents (photo or PDF scan)</label>
                <input type="file" name="docs[]" accept="image/*,.pdf" multiple>
                <p class="fa-muted">Required for sale and land listings.</p>
            </div>

            <label>YouTube video link (optional)</label>
            <input type="url" name="video_url" maxlength="300" inputmode="url" autocomplete="off" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo $v( 'video_url' ); ?>">
            <p class="fa-muted">Upload your walkthrough to YouTube as <strong>Public</strong> or <strong>Unlisted</strong>, then paste the link. Only YouTube links are accepted, and a reviewer watches it before it goes live.</p>

            <p class="fa-muted" style="margin-top:18px;"><strong>Owner or caretaker</strong> (only our team sees this)</p>
            <div class="fa-row">
                <div><label>Name</label><input type="text" name="landlord_name" required value="<?php echo $v( 'landlord_name' ); ?>"></div>
                <div><label>Phone</label><input type="tel" name="landlord_phone" required inputmode="tel" value="<?php echo $v( 'landlord_phone' ); ?>"></div>
            </div>
            <label>Date you visited</label><input type="date" name="visited_at" required max="<?php echo esc_attr( gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) ); ?>" value="<?php echo $v( 'visited_at', gmdate( 'Y-m-d' ) ); ?>">
            <label>Notes for the reviewer (optional)</label><textarea name="notes" rows="2"><?php echo esc_textarea( $old['notes'] ?? '' ); ?></textarea>

            <p class="fa-muted" style="margin-top:18px;"><strong>Visit checklist</strong>. Tick every box.</p>
            <?php foreach ( OFP_Field_Agent::CHECKLIST as $k => $text ) : ?>
                <label class="fa-check"><input type="checkbox" name="check[<?php echo esc_attr( $k ); ?>]" value="1" required <?php checked( ! empty( $old['check'][ $k ] ) ); ?>> <?php echo esc_html( $text ); ?></label>
            <?php endforeach; ?>
            <div class="fa-gps" id="fa-gps">Capturing your location… (allow it so reviewers can see you were there)</div>

            <button type="submit" class="ofp-btn ofp-btn-primary" style="width:100%;margin-top:14px;padding:14px;">Submit for review</button>
            <a href="<?php echo esc_url( home_url( '/field' ) ); ?>" class="ofp-btn ofp-btn-secondary" style="display:block;text-align:center;margin-top:8px;padding:12px;">Cancel</a>
        </form>
        <?php endif; ?>
    </div>

<?php else : ?>
    <div class="fa-stats">
        <div class="fa-stat"><b><?php echo (int) $stats['submitted']; ?></b><span>Waiting</span></div>
        <div class="fa-stat"><b style="color:#047857;"><?php echo (int) $stats['approved']; ?></b><span>Live</span></div>
        <div class="fa-stat"><b style="color:#b91c1c;"><?php echo (int) $stats['changes_requested']; ?></b><span>Need changes</span></div>
        <div class="fa-stat"><b><?php echo count( $areas ); ?></b><span>My areas</span></div>
    </div>

    <div class="fa-card">
        <h2>Submit a property</h2>
        <p class="fa-muted"><?php echo $areas ? 'Visited a property? Add it for review.' : 'No area is assigned to you yet. Please ask the team to assign one.'; ?></p>
        <?php if ( $areas ) : ?><a class="ofp-btn ofp-btn-primary" style="display:block;text-align:center;padding:14px;" href="<?php echo esc_url( home_url( '/field?new=1' ) ); ?>">+ Submit a property</a><?php endif; ?>
        <?php if ( $areas ) : ?><p class="fa-muted" style="margin-top:12px;">My areas: <?php echo esc_html( implode( ' · ', array_map( static function ( $a ) { return $a->area . ', ' . $a->state; }, $areas ) ) ); ?></p><?php endif; ?>
    </div>

    <div class="fa-card">
        <h2>My submissions</h2>
        <?php if ( empty( $reports ) ) : ?>
            <p class="fa-muted">Nothing yet. Your first submission will show here.</p>
        <?php else : foreach ( $reports as $r ) :
            $lab = $labels[ $r->status ] ?? [ $r->status, '#475569', '#e2e8f0' ];
            $thumb = get_the_post_thumbnail_url( (int) $r->property_id, 'thumbnail' ); ?>
            <div class="fa-item">
                <div class="fa-thumb" style="<?php echo $thumb ? 'background-image:url(' . esc_url( $thumb ) . ')' : ''; ?>"></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:var(--text-main,#0f172a);"><?php echo esc_html( get_the_title( (int) $r->property_id ) ); ?></div>
                    <div class="fa-muted" style="margin:2px 0 6px;"><?php echo esc_html( OFP_Field_Agent::area_label( (int) $r->area_id ) ); ?> · ₦<?php echo esc_html( number_format( (float) get_post_meta( (int) $r->property_id, 'ofp_price', true ) ) ); ?></div>
                    <span class="fa-pill" style="color:<?php echo esc_attr( $lab[1] ); ?>;background:<?php echo esc_attr( $lab[2] ); ?>;"><?php echo esc_html( $lab[0] ); ?></span>
                    <?php if ( $r->review_note && in_array( $r->status, [ 'changes_requested', 'rejected' ], true ) ) : ?><div class="fa-note"><?php echo esc_html( $r->review_note ); ?></div><?php endif; ?>
                    <?php if ( 'changes_requested' === $r->status ) : ?><a class="ofp-btn ofp-btn-secondary" style="display:inline-block;margin-top:8px;padding:8px 14px;" href="<?php echo esc_url( home_url( '/field?edit=' . (int) $r->property_id ) ); ?>">Fix and resubmit</a><?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
<?php endif; ?>

</div>
</div>
</main>
</div><!-- .ofp-shell -->
<?php wp_footer(); ?>
</body>
</html>
