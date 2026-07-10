<?php
/**
 * Template: /account — client's virtual account page.
 *
 * Shows:
 * 1. Their virtual bank account details (to pay subscription/top-up into)
 * 2. Your company bank account details (alternative manual transfer)
 * 3. Manual funding request form (client pastes their transaction ref)
 *
 * Route: logged-in clients only. Add 'account' to your portal routes
 * the same way 'credits' and 'properties' are registered (Patch I).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();

$error   = '';
$success = '';

/* -----------------------------------------------------------
 * Handle manual funding form submission
 * --------------------------------------------------------- */
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_submit_funding'] ) ) {

	if ( ! wp_verify_nonce( $_POST['ofp_funding_nonce'] ?? '', 'ofp_manual_funding_action' ) ) {
		$error = 'Security check failed — please try again.';
	} else {

		OFP_Security::check_rate_limit( $_SERVER['REMOTE_ADDR'] ?? '', 'manual_funding', 5, 600 );

		$amount          = (float) ( $_POST['amount'] ?? 0 );
		$channel         = sanitize_text_field( $_POST['channel'] ?? '' );
		$bank_name       = sanitize_text_field( $_POST['bank_name'] ?? '' );
		$account_name    = sanitize_text_field( $_POST['account_name'] ?? '' );
		$transaction_ref = sanitize_text_field( $_POST['transaction_ref'] ?? '' );
		$note            = sanitize_textarea_field( $_POST['note'] ?? '' );

		if ( $amount <= 0 ) {
			$error = 'Please enter the amount you sent.';
		} elseif ( ! in_array( $channel, [ 'sms', 'voice' ], true ) ) {
			$error = 'Please choose SMS or Voice credit.';
		} elseif ( empty( $bank_name ) || empty( $account_name ) || empty( $transaction_ref ) ) {
			$error = 'Please fill in all required fields.';
		} else {
			global $wpdb;
			$wpdb->insert( $wpdb->prefix . 'ofp_funding_requests', [
				'client_id'       => $client->id,
				'amount'          => $amount,
				'channel'         => $channel,
				'bank_name'       => $bank_name,
				'account_name'    => $account_name,
				'transaction_ref' => $transaction_ref,
				'note'            => $note,
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql' ),
			] );

			// Notify the admin by email.
			OFP_Mailer::send_transactional(
				get_option( 'admin_email' ),
				'Admin',
				'New Manual Funding Request — ' . $client->business_name,
				"Client: {$client->business_name} ({$client->owner_name})\n" .
				"Amount: NGN " . number_format( $amount, 2 ) . "\n" .
				"Channel: " . ucfirst( $channel ) . " credit\n" .
				"Bank: {$bank_name}\n" .
				"Account Name: {$account_name}\n" .
				"Transaction Ref: {$transaction_ref}\n" .
				( $note ? "Note: {$note}" : '' )
			);

			// Notify the client via bell + email based on their preference.
			OFP_Notification::create(
				$client->id,
				'manual_funding_received',
				'Funding request received',
				'We received your manual funding request of NGN ' . number_format( $amount, 2 ) . '. We will review and credit your account within 24 hours.'
			);

			$success = 'Your funding request has been submitted. We will review and credit your account within 24 hours.';
		}
	}
}

// Company bank account details — stored in wp_options, set in Settings.
$company_bank_name    = get_option( 'ofp_company_bank_name', '' );
$company_account_no   = get_option( 'ofp_company_account_no', '' );
$company_account_name = get_option( 'ofp_company_account_name', '' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Account — OFast Pipeline</title>
	<?php wp_head(); ?>
</head>
<body>
<div class="ofp-dashboard-wrapper">
	<h1>Account &amp; Funding</h1>

	<?php if ( $error ) : ?>
		<div class="ofp-notice ofp-notice-error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<?php if ( $success ) : ?>
		<div class="ofp-notice ofp-notice-success"><?php echo esc_html( $success ); ?></div>
	<?php endif; ?>

	<!-- Virtual account (auto-pay) -->
	<?php if ( ! empty( $client->virtual_account_number ) ) : ?>
	<div class="ofp-card">
		<h2>Your Virtual Account</h2>
		<p class="ofp-muted">
			Transfer your subscription or top-up amount to this account.
			Your balance updates automatically once payment is confirmed.
		</p>
		<table class="ofp-account-details">
			<tr>
				<th>Bank</th>
				<td><?php echo esc_html( $client->virtual_bank_name ); ?></td>
			</tr>
			<tr>
				<th>Account Number</th>
				<td>
					<strong><?php echo esc_html( $client->virtual_account_number ); ?></strong>
					<button class="ofp-copy-btn" data-copy="<?php echo esc_attr( $client->virtual_account_number ); ?>">
						Copy
					</button>
				</td>
			</tr>
			<tr>
				<th>Account Name</th>
				<td><?php echo esc_html( $client->virtual_account_name ?? $client->business_name ); ?></td>
			</tr>
		</table>
		<p class="ofp-muted" style="font-size:12px;">
			This account is unique to you. Payments made here are matched
			to your account automatically — no need to notify us.
		</p>
	</div>
	<?php endif; ?>

	<!-- Company account (manual transfer) -->
	<?php if ( $company_bank_name && $company_account_no ) : ?>
	<div class="ofp-card">
		<h2>Pay to Our Company Account</h2>
		<p class="ofp-muted">
			If you prefer to transfer directly to our account, use the
			details below — then fill the form underneath to let us know.
		</p>
		<table class="ofp-account-details">
			<tr>
				<th>Bank</th>
				<td><?php echo esc_html( $company_bank_name ); ?></td>
			</tr>
			<tr>
				<th>Account Number</th>
				<td>
					<strong><?php echo esc_html( $company_account_no ); ?></strong>
					<button class="ofp-copy-btn" data-copy="<?php echo esc_attr( $company_account_no ); ?>">
						Copy
					</button>
				</td>
			</tr>
			<tr>
				<th>Account Name</th>
				<td><?php echo esc_html( $company_account_name ); ?></td>
			</tr>
		</table>
	</div>
	<?php endif; ?>

	<!-- Manual funding form -->
	<div class="ofp-card">
		<h2>I Have Already Transferred</h2>
		<p class="ofp-muted">
			Transferred to our account above? Fill this in and we will
			credit your account within 24 hours after confirming.
		</p>

		<form method="POST" class="ofp-funding-form">
			<?php wp_nonce_field( 'ofp_manual_funding_action', 'ofp_funding_nonce' ); ?>

			<label>
				Credit Type
				<select name="channel" required>
					<option value="sms">SMS Credit</option>
					<option value="voice">Voice Credit</option>
				</select>
			</label>

			<label>
				Amount You Sent (NGN)
				<input type="number" step="0.01" min="1" name="amount" required>
			</label>

			<label>
				Your Bank Name
				<input type="text" name="bank_name" required placeholder="e.g. GTBank">
			</label>

			<label>
				Your Account Name
				<input type="text" name="account_name" required placeholder="Name on your bank account">
			</label>

			<label>
				Transaction Reference
				<input type="text" name="transaction_ref" required
					   placeholder="The reference number from your bank app or receipt">
			</label>

			<label>
				Additional Note <span class="ofp-optional">(optional)</span>
				<textarea name="note" rows="3" placeholder="Anything else we should know"></textarea>
			</label>

			<button type="submit" name="ofp_submit_funding" value="1" class="ofp-btn ofp-btn-primary">
				Submit Funding Request
			</button>
		</form>
	</div>
</div>

<script>
// Copy to clipboard buttons
document.querySelectorAll( '.ofp-copy-btn' ).forEach( function( btn ) {
	btn.addEventListener( 'click', function() {
		navigator.clipboard.writeText( btn.dataset.copy ).then( function() {
			btn.textContent = 'Copied!';
			setTimeout( function() { btn.textContent = 'Copy'; }, 2000 );
		} );
	} );
} );
</script>

<?php wp_footer(); ?>
</body>
</html>
