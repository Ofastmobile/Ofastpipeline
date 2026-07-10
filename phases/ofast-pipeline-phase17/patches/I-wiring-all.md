# PATCH I — All the wiring for Phase 17

---

## I1. Register 4 new portal routes

Same place you registered 'account', 'credits', 'properties' etc.
Add these four:

```php
// Phase 17 routes — all logged-in-required
'account',
'notifications',
'notification-settings',
```

And their template mappings:
```php
case 'account':
    require OFP_PLUGIN_DIR . 'public/templates/account.php';
    break;
case 'notifications':
    require OFP_PLUGIN_DIR . 'public/templates/notifications.php';
    break;
case 'notification-settings':
    require OFP_PLUGIN_DIR . 'public/templates/notification-settings.php';
    break;
```

Also add these two to `OFP_Host_Router::APP_ROUTES` so links on
`app.crmdomain.com` stay on the right subdomain:

```php
const APP_ROUTES = [
    'login', 'signup', 'dashboard', 'credits', 'properties',
    'forgot-password', 'reset-password',
    'account',           // Phase 17
    'notifications',     // Phase 17
    'notification-settings', // Phase 17
];
```

---

## I2. Bell icon HTML — add to your shared dashboard nav/header

This goes wherever your existing nav already has links to Dashboard,
Credits, etc. The bell shows a red badge with the unread count, and
links to the full notifications page.

```php
<?php
$ofp_unread = OFP_Notification::unread_count( $client->id );
?>
<a href="<?php echo esc_url( home_url( '/notifications' ) ); ?>"
   class="ofp-bell-icon" title="Notifications">
    🔔
    <?php if ( $ofp_unread > 0 ) : ?>
        <span class="ofp-bell-badge"><?php echo esc_html( $ofp_unread ); ?></span>
    <?php endif; ?>
</a>
```

Also add an "Account" link in the same nav:

```php
<a href="<?php echo esc_url( home_url( '/account' ) ); ?>">Account</a>
```

And under Settings or wherever profile links live, add:

```php
<a href="<?php echo esc_url( home_url( '/notification-settings' ) ); ?>">
    Notification Settings
</a>
```

---

## I3. Company bank account details — add to Settings page

Add this small block inside `admin/views/settings.php`, alongside
the existing Plans & Pricing and Listing Plans sections:

```php
<h2>Company Bank Account</h2>
<p class="description">
    Shown to clients on their Account page as an alternative
    transfer option for manual funding.
</p>

<form method="post" action="">
    <?php wp_nonce_field( 'ofp_save_company_bank_action', 'ofp_company_bank_nonce' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th>Bank Name</th>
            <td><input type="text" name="company_bank_name"
                       value="<?php echo esc_attr( get_option( 'ofp_company_bank_name' ) ); ?>"
                       style="width:300px;"></td>
        </tr>
        <tr>
            <th>Account Number</th>
            <td><input type="text" name="company_account_no"
                       value="<?php echo esc_attr( get_option( 'ofp_company_account_no' ) ); ?>"
                       style="width:300px;"></td>
        </tr>
        <tr>
            <th>Account Name</th>
            <td><input type="text" name="company_account_name"
                       value="<?php echo esc_attr( get_option( 'ofp_company_account_name' ) ); ?>"
                       style="width:300px;"></td>
        </tr>
    </table>
    <p class="submit">
        <button type="submit" name="ofp_save_company_bank" value="1" class="button button-primary">
            Save Bank Details
        </button>
    </p>
</form>
```

And in `admin/class-ofp-admin-menu.php`, add the save handler
(same pattern as `handle_save_plan_pricing()`):

```php
public function handle_save_company_bank(): void {
    if ( empty( $_POST['ofp_save_company_bank'] ) ) return;
    if ( OFP_Auth::current_admin_role() !== 'super_admin' ) {
        wp_die( 'Access denied.' );
    }
    check_admin_referer( 'ofp_save_company_bank_action', 'ofp_company_bank_nonce' );
    update_option( 'ofp_company_bank_name',    sanitize_text_field( $_POST['company_bank_name'] ?? '' ) );
    update_option( 'ofp_company_account_no',   sanitize_text_field( $_POST['company_account_no'] ?? '' ) );
    update_option( 'ofp_company_account_name', sanitize_text_field( $_POST['company_account_name'] ?? '' ) );
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-success is-dismissible"><p>Company bank details saved.</p></div>';
    } );
}
```

And wire it in `__construct()`:
```php
add_action( 'admin_init', [ $this, 'handle_save_company_bank' ] );
```

---

## I4. Admin funding requests page

Add a new sub-menu page under OFast Pipeline in wp-admin to review
pending manual funding requests. Add this to your existing
`admin_menu` registration:

```php
add_submenu_page(
    'ofast-pipeline',
    'Funding Requests',
    'Funding Requests',
    'manage_options',
    'ofp-funding-requests',
    [ $this, 'render_funding_requests' ]
);
```

And the render method:

```php
public function render_funding_requests(): void {
    global $wpdb;

    // Handle approve action.
    if (
        isset( $_GET['action'], $_GET['request_id'] ) &&
        $_GET['action'] === 'approve' &&
        wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ofp_approve_funding' )
    ) {
        $request_id = (int) $_GET['request_id'];
        $request = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_funding_requests WHERE id = %d",
            $request_id
        ) );

        if ( $request && $request->status === 'pending' ) {
            // Credit the client's balance.
            OFP_Credit::topup(
                $request->client_id,
                $request->channel,
                $request->amount,
                'manual_funding_' . $request_id
            );

            // Mark as approved.
            $wpdb->update(
                $wpdb->prefix . 'ofp_funding_requests',
                [
                    'status'      => 'approved',
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $request_id ]
            );

            // Notify the client.
            OFP_Notification::create(
                $request->client_id,
                'manual_funding_approved',
                'Funding approved',
                'Your manual funding of NGN ' . number_format( $request->amount, 2 ) .
                ' has been approved and credited to your ' . ucfirst( $request->channel ) . ' balance.'
            );

            echo '<div class="notice notice-success"><p>Funding request approved and client credited.</p></div>';
        }
    }

    // Handle reject action.
    if (
        isset( $_GET['action'], $_GET['request_id'] ) &&
        $_GET['action'] === 'reject' &&
        wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'ofp_reject_funding' )
    ) {
        $request_id = (int) $_GET['request_id'];
        $request = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_funding_requests WHERE id = %d",
            $request_id
        ) );

        if ( $request && $request->status === 'pending' ) {
            $wpdb->update(
                $wpdb->prefix . 'ofp_funding_requests',
                [
                    'status'      => 'rejected',
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $request_id ]
            );

            OFP_Notification::create(
                $request->client_id,
                'manual_funding_rejected',
                'Funding request rejected',
                'Your manual funding request of NGN ' . number_format( $request->amount, 2 ) .
                ' could not be verified. Please contact us if you believe this is a mistake.'
            );

            echo '<div class="notice notice-error"><p>Funding request rejected.</p></div>';
        }
    }

    // Fetch all pending requests first, then others.
    $requests = $wpdb->get_results( "
        SELECT r.*, c.business_name, c.owner_name, c.email
        FROM {$wpdb->prefix}ofp_funding_requests r
        JOIN {$wpdb->prefix}ofp_clients c ON c.id = r.client_id
        ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC
    " );
    ?>
    <div class="wrap">
        <h1>Funding Requests</h1>
        <?php if ( empty( $requests ) ) : ?>
            <p>No funding requests yet.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Amount</th>
                    <th>Channel</th>
                    <th>Bank</th>
                    <th>Ref</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $requests as $req ) : ?>
                <tr>
                    <td>
                        <?php echo esc_html( $req->business_name ); ?><br>
                        <small><?php echo esc_html( $req->owner_name ); ?></small>
                    </td>
                    <td>NGN <?php echo esc_html( number_format( $req->amount, 2 ) ); ?></td>
                    <td><?php echo esc_html( ucfirst( $req->channel ) ); ?></td>
                    <td>
                        <?php echo esc_html( $req->bank_name ); ?><br>
                        <small><?php echo esc_html( $req->account_name ); ?></small>
                    </td>
                    <td><?php echo esc_html( $req->transaction_ref ); ?></td>
                    <td><?php echo esc_html( date( 'd M Y', strtotime( $req->created_at ) ) ); ?></td>
                    <td>
                        <span class="ofp-status-badge ofp-status-<?php echo esc_attr( $req->status ); ?>">
                            <?php echo esc_html( ucfirst( $req->status ) ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( $req->status === 'pending' ) : ?>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                add_query_arg( [ 'action' => 'approve', 'request_id' => $req->id ] ),
                                'ofp_approve_funding'
                            ) ); ?>" class="button button-primary button-small">Approve</a>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                add_query_arg( [ 'action' => 'reject', 'request_id' => $req->id ] ),
                                'ofp_reject_funding'
                            ) ); ?>" class="button button-small"
                               onclick="return confirm('Reject this request?')">Reject</a>
                        <?php else : ?>
                            <span class="ofp-muted">Done</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
```
