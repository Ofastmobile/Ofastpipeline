<?php
/**
 * Template: /account — client's Account & Funding page.
 *
 * Phase 17b fix: expanded manual funding form now covers all four
 * payment types — SMS credit, Voice credit, CRM plan renewal, and
 * Listing plan payment — not just SMS/Voice.
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
		$payment_for     = sanitize_text_field( $_POST['payment_for'] ?? '' );
		$bank_name       = sanitize_text_field( $_POST['bank_name'] ?? '' );
		$account_name    = sanitize_text_field( $_POST['account_name'] ?? '' );
		$transaction_ref = sanitize_text_field( $_POST['transaction_ref'] ?? '' );
		$note            = sanitize_textarea_field( $_POST['note'] ?? '' );

		// Valid payment_for values:
		// sms, voice, crm_plan, listing_plan
		$valid_payment_types = [ 'sms', 'voice', 'crm_plan', 'listing_plan' ];

		if ( $amount <= 0 ) {
			$error = 'Please enter the amount you sent.';
		} elseif ( ! in_array( $payment_for, $valid_payment_types, true ) ) {
			$error = 'Please choose what this payment is for.';
		} elseif ( empty( $bank_name ) || empty( $account_name ) || empty( $transaction_ref ) ) {
			$error = 'Please fill in all required fields.';
		} else {

			// Human-readable label for notifications and emails
			$payment_labels = [
				'sms'          => 'SMS Credit',
				'voice'        => 'Voice Credit',
				'crm_plan'     => 'CRM Plan (' . ucfirst( $client->plan ?? 'N/A' ) . ')',
				'listing_plan' => 'Listing Plan (' . ucfirst( OFP_Subscription::get_active_listing_plan( $client->id ) ?? 'N/A' ) . ')',
			];
			$payment_label = $payment_labels[ $payment_for ];

			global $wpdb;
			$wpdb->insert( $wpdb->prefix . 'ofp_funding_requests', [
				'client_id'       => $client->id,
				'amount'          => $amount,
				'channel'         => $payment_for,
				'bank_name'       => $bank_name,
				'account_name'    => $account_name,
				'transaction_ref' => $transaction_ref,
				'note'            => $note,
				'status'          => 'pending',
				'created_at'      => current_time( 'mysql' ),
			] );

			// Email admin.
			OFP_Mailer::send_transactional(
				get_option( 'admin_email' ),
				'Admin',
				'New Manual Funding Request — ' . $client->business_name,
				"Client: {$client->business_name} ({$client->owner_name})\n" .
				"Payment for: {$payment_label}\n" .
				"Amount: NGN " . number_format( $amount, 2 ) . "\n" .
				"Bank: {$bank_name}\n" .
				"Account Name: {$account_name}\n" .
				"Transaction Ref: {$transaction_ref}\n" .
				( $note ? "Note: {$note}" : '' )
			);

			// Notify client via bell + email per their preference.
			OFP_Notification::create(
				$client->id,
				'manual_funding_received',
				'Funding request received',
				'We received your payment request of NGN ' . number_format( $amount, 2 ) .
				' for ' . $payment_label . '. We will review and credit your account within 24 hours.'
			);

			$success = 'Your funding request has been submitted. We will review and update your account within 24 hours.';
		}
	}
}

// Company bank details from Settings.
$company_bank_name    = get_option( 'ofp_company_bank_name', '' );
$company_account_no   = get_option( 'ofp_company_account_no', '' );
$company_account_name = get_option( 'ofp_company_account_name', '' );

// Current plans for display context.
$crm_plan     = $client->plan ?? null;
$listing_plan = OFP_Subscription::get_active_listing_plan( $client->id );
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

	<!-- Virtual account -->
	<?php if ( ! empty( $client->virtual_account_number ) ) : ?>
	<div class="ofp-card">
		<h2>Your Virtual Account</h2>
		<p class="ofp-muted">
			Transfer any payment to this account and it will be matched
			to you automatically. No form needed.
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
					<button class="ofp-copy-btn" data-copy="<?php echo esc_attr( $client->virtual_account_number ); ?>">Copy</button>
				</td>
			</tr>
			<tr>
				<th>Account Name</th>
				<td><?php echo esc_html( $client->virtual_account_name ?? $client->business_name ); ?></td>
			</tr>
		</table>
		<p class="ofp-muted" style="font-size:12px;">
			Payments here are matched automatically — no need to fill
			the form below for virtual account transfers.
		</p>
	</div>
	<?php endif; ?>

	<!-- Company bank account -->
	<?php if ( $company_bank_name && $company_account_no ) : ?>
	<div class="ofp-card">
		<h2>Pay to Our Company Account</h2>
		<p class="ofp-muted">
			Prefer to transfer directly? Use the details below, then
			fill in the form underneath so we know to expect it.
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
					<button class="ofp-copy-btn" data-copy="<?php echo esc_attr( $company_account_no ); ?>">Copy</button>
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
			Transferred to our company account above? Fill this in so
			we can verify and update your account.
		</p>

		<form method="POST" class="ofp-funding-form">
			<?php wp_nonce_field( 'ofp_manual_funding_action', 'ofp_funding_nonce' ); ?>

			<label>
				This Payment Is For
				<select name="payment_for" required>
					<option value="">— Choose one —</option>

					<optgroup label="Credit Top-Up">
						<option value="sms">SMS Credit</option>
						<option value="voice">Voice Credit</option>
					</optgroup>

					<?php if ( $crm_plan ) : ?>
					<optgroup label="CRM Subscription">
						<option value="crm_plan">
							CRM Plan — <?php echo esc_html( ucfirst( $crm_plan ) ); ?>
							(NGN <?php echo esc_html( number_format( OFP_Subscription::get_plan_price( $crm_plan ), 2 ) ); ?>/month)
						</option>
					</optgroup>
					<?php endif; ?>

					<?php if ( $listing_plan ) : ?>
					<optgroup label="Listing Subscription">
						<option value="listing_plan">
							Listing Plan — <?php echo esc_html( ucfirst( $listing_plan ) ); ?>
							(NGN <?php echo esc_html( number_format( OFP_Property::get_plan_price( $listing_plan ), 2 ) ); ?>/month)
						</option>
					</optgroup>
					<?php endif; ?>

					<?php if ( ! $crm_plan && ! $listing_plan ) : ?>
					<optgroup label="New Subscription">
						<option value="crm_plan">CRM Plan Payment</option>
						<option value="listing_plan">Listing Plan Payment</option>
					</optgroup>
					<?php endif; ?>
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
				<input type="text" name="account_name" required
					   placeholder="Name on your bank account">
			</label>

			<label>
				Transaction Reference
				<input type="text" name="transaction_ref" required
					   placeholder="Reference number from your bank app or receipt">
			</label>

			<label>
				Additional Note <span class="ofp-optional">(optional)</span>
				<textarea name="note" rows="3"
						  placeholder="Anything else we should know"></textarea>
			</label>

			<button type="submit" name="ofp_submit_funding" value="1"
					class="ofp-btn ofp-btn-primary">
				Submit Funding Request
			</button>
		</form>
	</div>
</div>

<script>
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
