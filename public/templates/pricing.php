<?php
/**
 * Template: /pricing
 * Client Plans & Pricing page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

$has_crm     = OFP_Subscription::has_active( 'crm',     $client->id );
$has_listing = OFP_Subscription::has_active( 'listing', $client->id );

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plans & Pricing — OFast Pipeline</title>
    <!-- Dark theme script to avoid FOUC -->
    <script>
        (function() {
            var currentTheme = localStorage.getItem('ofp_theme') || 'dark';
            if (currentTheme === 'light') { document.documentElement.setAttribute('data-theme', 'light'); }
        })();
    </script>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/client-portal.css?v=' . OFP_VERSION ); ?>">
    <style>
        .ofp-pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 32px;
        }
        .ofp-pricing-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 32px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .ofp-pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.1);
            border-color: var(--accent-blue);
        }
        .ofp-pricing-header {
            margin-bottom: 24px;
            text-align: center;
        }
        .ofp-pricing-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 8px;
        }
        .ofp-pricing-price {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 8px;
        }
        .ofp-pricing-price span {
            font-size: 16px;
            font-weight: 400;
            color: var(--text-muted);
        }
        .ofp-pricing-desc {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.5;
        }
        .ofp-pricing-features {
            list-style: none;
            padding: 0;
            margin: 0 0 32px 0;
            flex: 1;
        }
        .ofp-pricing-features li {
            font-size: 14px;
            color: var(--text-main);
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .ofp-pricing-features li svg {
            width: 18px;
            height: 18px;
            color: var(--accent-green);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .ofp-pricing-action {
            text-align: center;
            margin-top: auto;
        }
        .ofp-pricing-action .ofp-btn-accent {
            display: block;
            width: 100%;
            padding: 12px;
            font-size: 15px;
            text-align: center;
        }
        .ofp-active-badge {
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--accent-green);
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .ofp-pricing-card.is-active {
            border-color: var(--accent-green);
        }
        .ofp-pricing-card.is-active:hover {
            border-color: var(--accent-green);
        }
    </style>
</head>
<body class="ofp-portal-body">

<?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-header-area" style="margin-bottom: 24px;">
        <h1 style="font-size: 24px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">Plans & Pricing</h1>
        <p style="color: var(--text-muted); font-size: 15px;">Choose the right tools to grow your business.</p>
    </div>

    <div class="ofp-pricing-grid">
        
        <!-- CRM Plan -->
        <div class="ofp-pricing-card <?php echo $has_crm ? 'is-active' : ''; ?>">
            <?php if ( $has_crm ) : ?>
                <div class="ofp-active-badge">Active Plan</div>
            <?php endif; ?>
            <div class="ofp-pricing-header">
                <div class="ofp-pricing-title">CRM & Automation</div>
                <div class="ofp-pricing-price">Custom <span>/ month</span></div>
                <div class="ofp-pricing-desc">Everything you need to capture, nurture, and convert leads on autopilot.</div>
            </div>
            <ul class="ofp-pricing-features">
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Automated SMS follow-ups
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Voice Calls & IVR Routing
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Lead Pipeline Management
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Comprehensive Analytics & Reporting
                </li>
            </ul>
            <div class="ofp-pricing-action">
                <?php if ( $has_crm ) : ?>
                    <button class="ofp-btn" disabled style="width:100%; opacity:0.7; cursor:default;">Currently Active</button>
                <?php else : ?>
                    <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-accent">Upgrade to CRM</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Listing Plan -->
        <div class="ofp-pricing-card <?php echo $has_listing ? 'is-active' : ''; ?>">
            <?php if ( $has_listing ) : ?>
                <div class="ofp-active-badge">Active Plan</div>
            <?php endif; ?>
            <div class="ofp-pricing-header">
                <div class="ofp-pricing-title">Property Listings</div>
                <div class="ofp-pricing-price">Custom <span>/ month</span></div>
                <div class="ofp-pricing-desc">Showcase your properties and reach thousands of potential buyers.</div>
            </div>
            <ul class="ofp-pricing-features">
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Unlimited Property Listings
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    High-quality Image Galleries
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Direct Buyer Inquiries
                </li>
                <li>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Featured Placement Options
                </li>
            </ul>
            <div class="ofp-pricing-action">
                <?php if ( $has_listing ) : ?>
                    <button class="ofp-btn" disabled style="width:100%; opacity:0.7; cursor:default;">Currently Active</button>
                <?php else : ?>
                    <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>" class="ofp-btn-accent">Upgrade to Listing</a>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div> <!-- .ofp-content-area -->
</main>
</div> <!-- .ofp-shell -->

<?php wp_footer(); ?>
</body>
</html>
