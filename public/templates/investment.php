<?php
/**
 * Template: /investment (client portal) — Real Estate Investment & Syndication (Coming Soon).
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;

OFP_Auth::require_client_login();
$client = OFP_Auth::current_client();
OFP_Auth::require_active_subscription( $client );

$notice = '';
$joined_waitlist = (bool) get_user_meta( get_current_user_id(), 'ofp_investment_waitlist_joined', true );

if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['ofp_join_waitlist'] ) ) {
    if ( wp_verify_nonce( $_POST['ofp_inv_nonce'] ?? '', 'ofp_investment_waitlist' ) ) {
        update_user_meta( get_current_user_id(), 'ofp_investment_waitlist_joined', 1 );
        update_user_meta( get_current_user_id(), 'ofp_investment_waitlist_date', current_time( 'mysql' ) );
        $joined_waitlist = true;
        $notice = 'Congratulations! You have been prioritized on the VIP Early Access list. We will notify you the moment deals go live.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::title( 'Real Estate Investment', $client ) ); ?></title>
    <?php wp_head(); ?>
    <style>
        .ofp-inv-hero {
            position: relative;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            padding: 40px 32px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 16px 36px -12px rgba(0, 0, 0, 0.4);
            color: #ffffff;
        }
        .ofp-inv-hero::before {
            content: '';
            position: absolute;
            top: -120px;
            right: -100px;
            width: 360px;
            height: 360px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, rgba(139, 92, 246, 0.12) 50%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .ofp-inv-hero::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: 20%;
            width: 280px;
            height: 280px;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.18) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .ofp-inv-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 13px;
            border-radius: 9999px;
            background: rgba(245, 158, 11, 0.18);
            border: 1px solid rgba(245, 158, 11, 0.35);
            color: #fbbf24;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 18px;
        }
        .ofp-inv-pill .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fbbf24;
            box-shadow: 0 0 8px #fbbf24;
            animation: ofpPulse 2s infinite ease-in-out;
        }
        @keyframes ofpPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }
        .ofp-inv-title {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: -0.015em;
            margin: 0 0 14px;
            color: #ffffff;
        }
        .ofp-inv-subtitle {
            font-size: 15px;
            color: #f1f5f9;
            font-weight: 400;
            max-width: 680px;
            line-height: 1.6;
            margin: 0 0 28px;
        }
        .ofp-inv-metrics-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }
        .ofp-inv-metric-box {
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            padding: 14px 18px;
            backdrop-filter: blur(10px);
        }
        .ofp-inv-metric-box .label {
            font-size: 11px;
            font-weight: 500;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 5px;
        }
        .ofp-inv-metric-box .value {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.01em;
        }
        .ofp-inv-metric-box .hint {
            font-size: 11px;
            color: #34d399;
            font-weight: 500;
            margin-top: 3px;
        }
        .ofp-inv-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .ofp-inv-card {
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 14px;
            padding: 24px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .ofp-inv-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px -6px rgba(0, 0, 0, 0.08);
        }
        .ofp-inv-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .ofp-inv-icon svg {
            width: 22px;
            height: 22px;
        }
        .ofp-inv-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin: 0 0 8px;
        }
        .ofp-inv-card-desc {
            font-size: 13px;
            color: #334155;
            font-weight: 400;
            line-height: 1.6;
            margin: 0;
        }
        [data-theme="dark"] .ofp-inv-card {
            background: #1e293b;
            border-color: #334155;
        }
        [data-theme="dark"] .ofp-inv-card-title {
            color: #f8fafc;
        }
        [data-theme="dark"] .ofp-inv-card-desc {
            color: #cbd5e1;
        }
        .ofp-waitlist-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            padding: 12px 28px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            box-shadow: 0 6px 16px -3px rgba(37, 99, 235, 0.4);
            transition: all 0.2s ease;
        }
        .ofp-waitlist-btn:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
        }
        .ofp-waitlist-success {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(16, 185, 129, 0.16);
            border: 1px solid rgba(16, 185, 129, 0.35);
            color: #34d399;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
        }
    </style>
</head>
<body class="ofp-portal-body">
    <?php include OFP_PATH . 'public/templates/partials/nav.php'; ?>

    <div class="ofp-container" style="padding-bottom: 60px;">

        <?php if ( $notice ) : ?>
            <div class="ofp-alert ofp-alert-success" style="margin-bottom: 24px; border-radius: 10px; padding: 14px 20px; font-size: 14px; font-weight: 500; color: #065f46; background: #d1fae5; border: 1px solid #a7f3d0;">
                ✓ <?php echo esc_html( $notice ); ?>
            </div>
        <?php endif; ?>

        <!-- Hero Card -->
        <div class="ofp-inv-hero">
            <div class="ofp-inv-pill">
                <span class="dot"></span>
                <span>Coming Soon • Real Estate Investment</span>
            </div>

            <h1 class="ofp-inv-title">Fractional Ownership & Co-Investment Syndicates</h1>
            <p class="ofp-inv-subtitle">
                Unlock high-yield commercial developments, luxury rental syndicates, and verified real estate notes. Diversify and build generational property wealth directly from your NaijaHub pipeline.
            </p>

            <div class="ofp-inv-metrics-bar">
                <div class="ofp-inv-metric-box">
                    <div class="label">Target Annual Return</div>
                    <div class="value">18.5% – 24.2%</div>
                    <div class="hint">Annualized IRR & Dividends</div>
                </div>
                <div class="ofp-inv-metric-box">
                    <div class="label">Pipeline in Review</div>
                    <div class="value">₦2.8 Billion+</div>
                    <div class="hint">Prime Lagos & Abuja Assets</div>
                </div>
                <div class="ofp-inv-metric-box">
                    <div class="label">Security & Title</div>
                    <div class="value">100% Escrow</div>
                    <div class="hint">C of O & Governor's Consent</div>
                </div>
                <div class="ofp-inv-metric-box">
                    <div class="label">Minimum Entry</div>
                    <div class="value">₦100,000</div>
                    <div class="hint">Accessible Micro-Slots</div>
                </div>
            </div>

            <?php if ( $joined_waitlist ) : ?>
                <div class="ofp-waitlist-success">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>VIP Priority Access Confirmed — You will be notified the moment allocations open.</span>
                </div>
            <?php else : ?>
                <form method="POST" style="display: inline-block;">
                    <?php wp_nonce_field( 'ofp_investment_waitlist', 'ofp_inv_nonce' ); ?>
                    <button type="submit" name="ofp_join_waitlist" value="1" class="ofp-waitlist-btn">
                        🚀 Join VIP Investor Early Access List
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- 4 Pillars Feature Grid -->
        <div style="margin-bottom: 18px;">
            <h2 style="font-size: 20px; font-weight: 600; color: #0f172a; margin: 0 0 6px;">How It Will Work</h2>
            <p style="font-size: 14px; color: #475569; font-weight: 400; margin: 0 0 18px;">Engineered specifically for Nigerian real estate developers, agencies, and diaspora investors.</p>
        </div>

        <div class="ofp-inv-grid">
            <!-- Card 1 -->
            <div class="ofp-inv-card">
                <div class="ofp-inv-icon" style="background: rgba(59, 130, 246, 0.12); color: #2563eb;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="ofp-inv-card-title">Fractional Property Ownership</h3>
                <p class="ofp-inv-card-desc">
                    Invest in prime commercial towers, residential terraces, and shortlets in fractional units. Hold digital ownership records backed by legal trustee deeds.
                </p>
            </div>

            <!-- Card 2 -->
            <div class="ofp-inv-card">
                <div class="ofp-inv-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h3 class="ofp-inv-card-title">Automated Rental Yields</h3>
                <p class="ofp-inv-card-desc">
                    Earn passive dividends directly into your bank account from rent collections and daily shortlet bookings, fully automated and transparent.
                </p>
            </div>

            <!-- Card 3 -->
            <div class="ofp-inv-card">
                <div class="ofp-inv-icon" style="background: rgba(139, 92, 246, 0.12); color: #7c3aed;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                </div>
                <h3 class="ofp-inv-card-title">Secondary Liquidity Exchange</h3>
                <p class="ofp-inv-card-desc">
                    Need liquidity before maturity? Offer and trade your property slots to other verified portal buyers with zero lock-in penalty.
                </p>
            </div>

            <!-- Card 4 -->
            <div class="ofp-inv-card">
                <div class="ofp-inv-icon" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h3 class="ofp-inv-card-title">Rigorous Due Diligence</h3>
                <p class="ofp-inv-card-desc">
                    Every asset undergoes comprehensive title perfection checks, physical structural integrity assessments, and licensed independent valuation.
                </p>
            </div>
        </div>

    </div>

    <?php wp_footer(); ?>
</body>
</html>
