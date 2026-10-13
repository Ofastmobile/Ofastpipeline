<?php
/**
 * Template: /pricing
 * Client Plans & Pricing page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

// Solo companies have no plan to buy or renew.
if ( OFP_Brand::hide_plan_prompts( $client ) ) {
    wp_safe_redirect( home_url( '/dashboard' ) );
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Plans & Pricing', $client ?? null ) ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-header-area" style="margin-bottom: 24px;">
        <h1 style="font-size: 24px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">Plans & Pricing</h1>
        <p style="color: var(--text-muted); font-size: 15px;">Choose the right tools to grow your business.</p>
    </div>
    <div class="ofp-pricing-grid">
        
        <?php
        $prices = class_exists('OFP_Property_CPT') && method_exists('OFP_Property_CPT', 'get_plan_prices') ? OFP_Property_CPT::get_plan_prices() : ['free'=>0,'silver'=>50000,'gold'=>100000];
        $caps   = class_exists('OFP_Property_CPT') && method_exists('OFP_Property_CPT', 'get_plan_caps') ? OFP_Property_CPT::get_plan_caps() : ['free'=>1,'silver'=>3,'gold'=>10];

        $current_plan = OFP_Subscription::client_plan( $client->id );

        // ── Calculate days until subscription expires ─────────────────────
        // Use the client row's subscription_expires as the primary source
        $days_to_expiry = 999;
        if ( ! empty( $client->subscription_expires ) ) {
            $expiry = strtotime( $client->subscription_expires );
            if ( $expiry ) {
                $days_to_expiry = ( $expiry - time() ) / DAY_IN_SECONDS;
            }
        }

        $plans = [
            'free' => [
                'name' => 'Free',
                'price' => '₦' . number_format( $prices['free'] ?? 0 ),
                'desc' => 'Essential tools for getting started.',
                'features' => [
                    'CRM & Automation (Basic)',
                    'Leads & Buyers Management',
                    ($caps['free'] ?? 1) . ' Property Listing',
                    'Payment records & Customer portal',
                    '0 Team members'
                ]
            ],
            'silver' => [
                'name' => 'Silver',
                'price' => '₦' . number_format( $prices['silver'] ?? 50000 ),
                'desc' => 'For growing teams and standard reporting.',
                'features' => [
                    'CRM & Automation (Standard)',
                    'Leads & Buyers Management',
                    ($caps['silver'] ?? 3) . ' Property Listings',
                    'Payment records & Customer portal',
                    'Editable email templates',
                    '2 Team members',
                    'Standard Reports'
                ]
            ],
            'gold' => [
                'name' => 'Gold',
                'price' => '₦' . number_format( $prices['gold'] ?? 100000 ),
                'desc' => 'Advanced features, ROI and unlimited potential.',
                'features' => [
                    'CRM & Automation (Advanced)',
                    'Leads & Buyers Management',
                    ($caps['gold'] ?? 10) . ' Property Listings',
                    'Payment records & Customer portal',
                    'Editable email templates',
                    '3 Team members',
                    'Installment payments & ROI/Investment',
                    'Advanced Reports'
                ]
            ]
        ];

        foreach ( $plans as $id => $p ) :
            $is_active = ( $id === $current_plan );
        ?>
        <div class="ofp-pricing-card <?php echo $is_active ? 'is-active' : ''; ?>">
            <?php if ( $is_active ) : ?>
                <div class="ofp-active-badge">Active Plan</div>
            <?php endif; ?>
            <div class="ofp-pricing-header">
                <div class="ofp-pricing-title"><?php echo esc_html($p['name']); ?> Plan</div>
                <div class="ofp-pricing-price"><?php echo esc_html($p['price']); ?> <span>/ month</span></div>
                <div class="ofp-pricing-desc"><?php echo esc_html($p['desc']); ?></div>
            </div>
            <ul class="ofp-pricing-features">
                <?php foreach ( $p['features'] as $feat ) : ?>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    <?php echo esc_html($feat); ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="ofp-pricing-action">
                <?php 
                // Rank the plans so we know what is an upgrade vs downgrade
                $plan_ranks = ['free' => 1, 'silver' => 2, 'gold' => 3];
                $current_rank = $plan_ranks[$current_plan] ?? 1;
                $this_rank = $plan_ranks[$id] ?? 1;

                if ( $id === 'free' ) : ?>
                    <?php if ( $is_active ) : ?>
                        <button class="ofp-btn" disabled style="width:100%; opacity:0.7; cursor:default;">Currently Active</button>
                    <?php endif; // Free has no select button otherwise ?>
                <?php elseif ( $is_active ) : ?>
                    <?php if ( $days_to_expiry <= 7 ) : ?>
                        <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-accent">Renew <?php echo esc_html($p['name']); ?></a>
                    <?php else : ?>
                        <button class="ofp-btn" disabled style="width:100%; opacity:0.7; cursor:default;">Currently Active</button>
                    <?php endif; ?>
                <?php elseif ( $this_rank < $current_rank && $days_to_expiry > 7 ) : ?>
                    <button class="ofp-btn" disabled style="width:100%; opacity:0.5; cursor:not-allowed;">Locked</button>
                <?php else : ?>
                    <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-accent">Select <?php echo esc_html($p['name']); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

    </div>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
