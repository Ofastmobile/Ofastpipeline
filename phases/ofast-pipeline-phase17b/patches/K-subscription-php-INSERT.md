# PATCH K — includes/class-ofp-subscription.php

**Type:** INSERT (1 new method)

---

## Add this method to the class

```php
/**
 * Activates or extends a subscription when admin approves a manual
 * plan payment (Phase 17b). Different from create() which makes a
 * new pending row — this finds the most recent pending or expired
 * row for that client and type, sets it to 'paid', and pushes the
 * period_end date 30 days forward from today.
 *
 * If no existing row is found (e.g. client somehow never got a
 * subscription row created), it creates one and immediately activates
 * it, so admin approval always results in an active subscription
 * regardless of what state the data was in before.
 *
 * @param int    $client_id
 * @param string $type      'crm' or 'listing'
 * @param float  $amount_paid  the amount the client actually paid
 *                             (stored for reference, not used for
 *                             plan-tier lookup — admin already
 *                             verified this is the right amount)
 */
public static function activate_from_manual_payment(
    int $client_id,
    string $type,
    float $amount_paid
): void {
    global $wpdb;

    $existing = $wpdb->get_row( $wpdb->prepare( "
        SELECT * FROM {$wpdb->prefix}ofp_subscriptions
        WHERE client_id = %d AND type = %s
        ORDER BY created_at DESC LIMIT 1
    ", $client_id, $type ) );

    $period_end = date( 'Y-m-d', strtotime( '+30 days' ) );

    if ( $existing ) {
        $wpdb->update(
            $wpdb->prefix . 'ofp_subscriptions',
            [
                'status'         => 'paid',
                'payment_method' => 'manual',
                'amount'         => $amount_paid,
                'period_end'     => $period_end,
            ],
            [ 'id' => $existing->id ]
        );
    } else {
        // No row at all — create and immediately activate.
        $client = OFP_Client::get( $client_id );
        $plan   = ( $type === 'crm' ) ? ( $client->plan ?? 'starter' ) : null;

        $wpdb->insert( $wpdb->prefix . 'ofp_subscriptions', [
            'client_id'      => $client_id,
            'type'           => $type,
            'plan'           => $plan,
            'amount'         => $amount_paid,
            'payment_method' => 'manual',
            'status'         => 'paid',
            'period_end'     => $period_end,
            'created_at'     => current_time( 'mysql' ),
        ] );
    }

    // Also update the client's main status to 'active' if they were
    // pending, grace, or suspended — a paid plan should always bring
    // them back to active.
    $needs_activation = [ 'pending_review', 'pending', 'grace', 'suspended' ];
    $client = OFP_Client::get( $client_id );
    if ( $client && in_array( $client->status, $needs_activation, true ) ) {
        OFP_Client::update_status( $client_id, 'active' );
    }
}
```

---

## Why period_end is always +30 days from today

Manual payments don't carry a specific billing period timestamp the
way gateway webhooks do (those match an amount to a period). For
manual payments, admin is the one confirming the money arrived, so
"30 days from the day admin approves it" is the right, simple rule.
If a client's subscription was already active and they're renewing
early, this does extend from today (not from their current period_end)
— same as how `manual_toggle()` already works in the original v2.0
code. A "extend from existing period_end" option can be added later
if clients ever pay in advance regularly enough for it to matter.
