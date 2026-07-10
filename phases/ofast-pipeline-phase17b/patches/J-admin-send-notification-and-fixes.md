# PATCH J — Admin Send Notification + nav link fix

---

## J1. Add "Send Notification" sub-menu page in wp-admin

In your existing `admin_menu` registration in
`admin/class-ofp-admin-menu.php`, add this alongside the existing
sub-menu pages:

```php
add_submenu_page(
    'ofast-pipeline',
    'Send Notification',
    'Send Notification',
    'manage_options',
    'ofp-send-notification',
    [ $this, 'render_send_notification' ]
);
```

Then add this render method to the class:

```php
/**
 * Admin page: Send a manual notification to one client or all clients.
 */
public function render_send_notification(): void {
    $sent    = false;
    $error   = '';
    $clients = OFP_Client::get_all(); // adjust to whatever method
                                       // your codebase uses to fetch
                                       // all client rows

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset( $_POST['ofp_send_notification'] ) &&
        check_admin_referer( 'ofp_send_notification_action', 'ofp_send_notif_nonce' )
    ) {
        $recipient = sanitize_text_field( $_POST['recipient'] ?? '' );
        $title     = sanitize_text_field( $_POST['notif_title'] ?? '' );
        $message   = sanitize_textarea_field( $_POST['notif_message'] ?? '' );

        if ( empty( $title ) || empty( $message ) ) {
            $error = 'Please fill in both a title and a message.';
        } else {
            if ( $recipient === 'all' ) {
                foreach ( $clients as $client ) {
                    OFP_Notification::create(
                        $client->id,
                        'admin_message',
                        $title,
                        $message
                    );
                }
                $sent = true;
            } elseif ( is_numeric( $recipient ) && (int) $recipient > 0 ) {
                OFP_Notification::create(
                    (int) $recipient,
                    'admin_message',
                    $title,
                    $message
                );
                $sent = true;
            } else {
                $error = 'Please choose a recipient.';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Send Notification</h1>

        <?php if ( $sent ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>Notification sent successfully.</p>
            </div>
        <?php endif; ?>

        <?php if ( $error ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( $error ); ?></p>
            </div>
        <?php endif; ?>

        <form method="POST" style="max-width:600px;">
            <?php wp_nonce_field( 'ofp_send_notification_action', 'ofp_send_notif_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="recipient">Send To</label></th>
                    <td>
                        <select name="recipient" id="recipient" required style="width:300px;">
                            <option value="">— Choose recipient —</option>
                            <option value="all">All Clients</option>
                            <optgroup label="One Client">
                                <?php foreach ( $clients as $client ) : ?>
                                    <option value="<?php echo esc_attr( $client->id ); ?>">
                                        <?php echo esc_html( $client->business_name . ' (' . $client->owner_name . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="notif_title">Title</label></th>
                    <td>
                        <input type="text" name="notif_title" id="notif_title"
                               style="width:300px;" required
                               placeholder="Short subject, e.g. System maintenance tonight">
                    </td>
                </tr>
                <tr>
                    <th><label for="notif_message">Message</label></th>
                    <td>
                        <textarea name="notif_message" id="notif_message"
                                  rows="5" style="width:300px;" required
                                  placeholder="Full message the client will see in their bell and/or email"></textarea>
                        <p class="description">
                            Delivered via bell, email, or both — based on
                            each client's own notification preference.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="ofp_send_notification" value="1"
                        class="button button-primary">
                    Send Notification
                </button>
            </p>
        </form>
    </div>
    <?php
}
```

---

## J2. Fix: add the notification settings link to your nav

In Phase 17, Patch I2 gave you the bell icon HTML to add to your
shared nav. In that same nav block, also add this link so clients
can actually find their notification settings:

```php
<a href="<?php echo esc_url( home_url( '/notification-settings' ) ); ?>">
    Notification Settings
</a>
```

If your nav already has a "Settings" section or a profile dropdown,
this fits naturally there. If not, add it anywhere alongside the
existing Credits, Properties, and Account links.

---

## J3. Fix: admin Funding Requests page — handle crm_plan and listing_plan

In Phase 17's Patch I4 (`render_funding_requests()`), the Approve
action called `OFP_Credit::topup()` directly — which only works for
SMS and Voice credit. Now that the funding form accepts CRM plan and
Listing plan payments too, the approve handler needs to route
correctly depending on `$request->channel`.

Find this block inside `render_funding_requests()`:

```php
// Credit the client's balance.
OFP_Credit::topup(
    $request->client_id,
    $request->channel,
    $request->amount,
    'manual_funding_' . $request_id
);
```

Replace it with:

```php
// Route approval based on what the payment was for.
if ( in_array( $request->channel, [ 'sms', 'voice' ], true ) ) {
    // SMS or Voice credit top-up.
    OFP_Credit::topup(
        $request->client_id,
        $request->channel,
        $request->amount,
        'manual_funding_' . $request_id
    );
} elseif ( $request->channel === 'crm_plan' ) {
    // CRM plan payment — activate or extend the subscription.
    OFP_Subscription::activate_from_manual_payment(
        $request->client_id,
        'crm',
        $request->amount
    );
} elseif ( $request->channel === 'listing_plan' ) {
    // Listing plan payment — activate or extend the listing subscription.
    OFP_Subscription::activate_from_manual_payment(
        $request->client_id,
        'listing',
        $request->amount
    );
}
```

This requires a new method on `OFP_Subscription` — see Patch K.

---

## Why J3 needs a new method rather than reusing create()

`OFP_Subscription::create()` from Phase 11 creates a NEW subscription
row and sets it to 'pending'. What happens when admin approves a
manual plan payment is different: there's already a pending
subscription row (created when the client picked their plan), and we
want to flip it to 'paid' and set the period_end date, not create a
duplicate row. That's what `activate_from_manual_payment()` in
Patch K handles.
