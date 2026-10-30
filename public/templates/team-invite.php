<?php
/**
 * Template: /team-invite
 *
 * Page for team members to accept an invitation and set their password.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$error   = '';
$success = false;

$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

if ( empty( $token ) ) {
    wp_safe_redirect( home_url( '/login' ) );
    exit;
}

global $wpdb;
$team_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE invite_token = %s AND status = 'pending' LIMIT 1", $token ) );

if ( ! $team_member ) {
    $error = 'This invitation link is invalid or has already been used.';
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && $team_member ) {
    $password = (string) wp_unslash( $_POST['password'] ?? '' );
    
    if ( empty( $password ) || strlen( $password ) < 8 ) {
        $error = 'Please enter a password of at least 8 characters.';
    } else {
        $result = OFP_Team_Member::accept_invite( $token, $password );
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $success = true;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Accept Invitation', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-team-invite-body">
    <div class="card">
        <h1><?php echo esc_html( OFP_Brand::welcome_title() ); ?></h1>
        
        <?php if ( $success ) : ?>
            <div class="alert alert-success">Your password has been set. You can now log in.</div>
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="btn">Go to Login</a>
        <?php elseif ( $error && ! $team_member ) : ?>
            <div class="alert alert-error"><?php echo esc_html( $error ); ?></div>
            <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="btn" style="background:#cbd5e1;color:#1e293b;">Go to Login</a>
        <?php else : ?>
            <p>You have been invited to join <strong><?php echo esc_html( OFP_Client::get( $team_member->client_id )->business_name ); ?></strong>. Please set a password to activate your account.</p>
            
            <?php if ( $error ) : ?>
                <div class="alert alert-error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Set Password</label>
                    <input type="password" name="password" required minlength="6" placeholder="Enter at least 6 characters">
                </div>
                <button type="submit" class="btn">Activate Account</button>
            </form>
        <?php endif; ?>
    </div>
<?php wp_footer(); ?>
</body>
</html>
