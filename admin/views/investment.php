<?php
/**
 * Admin View: Real Estate Investment Engine (Coming Soon).
 *
 * Full-featured preview and architecture roadmap for the platform owner,
 * including investor interest waitlist tracking.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! OFP_Auth::is_admin_user() ) wp_die( 'Access denied.' );

global $wpdb;
$p = $wpdb->prefix;

// Query clients who joined the waitlist
$waitlist_users = get_users( [
    'meta_key'   => 'ofp_investment_waitlist_joined',
    'meta_value' => '1',
    'number'     => 50,
] );
$total_waitlist_count = count( $waitlist_users );
?>

<div class="wrap ofp-admin-wrap" style="max-width: 1200px;">
    <!-- Top Executive Notice / Hero -->
    <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 16px; padding: 32px 28px; color: #fff; margin-bottom: 26px; border: 1px solid rgba(255, 255, 255, 0.12); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3); position: relative; overflow: hidden;">
        <div style="position: absolute; top: -80px; right: -60px; width: 280px; height: 280px; background: radial-gradient(circle, rgba(245, 158, 11, 0.22) 0%, transparent 70%); border-radius: 50%; pointer-events: none;"></div>
        
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(245, 158, 11, 0.18); border: 1px solid rgba(245, 158, 11, 0.35); color: #fde047; padding: 5px 12px; border-radius: 9999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 14px;">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: #fde047; display: inline-block;"></span>
            <span>Coming Soon • Platform Roadmap</span>
        </div>

        <h1 style="font-size: 26px; font-weight: 700; color: #ffffff; margin: 0 0 12px; line-height: 1.25;">
            Real Estate Syndication & Investment Engine
        </h1>
        <p style="font-size: 14px; color: #f1f5f9; font-weight: 400; max-width: 760px; line-height: 1.6; margin: 0 0 22px;">
            A centralized administrative hub allowing the platform owner to syndicate high-ticket real estate projects into fractional slots, manage automated yield disbursements, oversee secondary trading liquidity, and review investor compliance.
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px;">
            <div style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; padding: 14px 18px;">
                <div style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; font-weight: 500; letter-spacing: 0.05em; margin-bottom: 4px;">VIP Waitlist Demand</div>
                <div style="font-size: 22px; font-weight: 700; color: #38bdf8;"><?php echo esc_html( $total_waitlist_count ); ?> Clients</div>
                <div style="font-size: 11px; color: #34d399; font-weight: 500; margin-top: 2px;">Registered early access</div>
            </div>
            <div style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; padding: 14px 18px;">
                <div style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; font-weight: 500; letter-spacing: 0.05em; margin-bottom: 4px;">Syndication Target</div>
                <div style="font-size: 22px; font-weight: 700; color: #ffffff;">₦5.0 Billion</div>
                <div style="font-size: 11px; color: #e2e8f0; font-weight: 400; margin-top: 2px;">Year 1 projected pool</div>
            </div>
            <div style="background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px; padding: 14px 18px;">
                <div style="font-size: 11px; color: #cbd5e1; text-transform: uppercase; font-weight: 500; letter-spacing: 0.05em; margin-bottom: 4px;">Disbursement Engine</div>
                <div style="font-size: 22px; font-weight: 700; color: #ffffff;">Paystack & Escrow</div>
                <div style="font-size: 11px; color: #34d399; font-weight: 500; margin-top: 2px;">Automated bank payouts</div>
            </div>
        </div>
    </div>

    <!-- Core Engine Capabilities (Architecture Roadmap) -->
    <div style="margin-bottom: 26px;">
        <h2 style="font-size: 18px; font-weight: 600; color: #0f172a; margin: 0 0 14px;">
            Planned Administrative Modules
        </h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
            <div style="background: var(--ofp-card-bg, #ffffff); border: 1px solid var(--ofp-border-color, #e2e8f0); border-radius: 12px; padding: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 15px; color: #0f172a;">Deal Syndication Desk</span>
                    <span style="font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: rgba(59, 130, 246, 0.12); color: #2563eb;">Module 1</span>
                </div>
                <p style="font-size: 13px; color: #334155; font-weight: 400; line-height: 1.55; margin: 0;">
                    Configure and publish fractional real estate offerings. Set minimum ticket sizes, total slot limits, project timeline, and projected IRR.
                </p>
            </div>

            <div style="background: var(--ofp-card-bg, #ffffff); border: 1px solid var(--ofp-border-color, #e2e8f0); border-radius: 12px; padding: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 15px; color: #0f172a;">Yield & Dividend Engine</span>
                    <span style="font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: rgba(16, 185, 129, 0.12); color: #059669;">Module 2</span>
                </div>
                <p style="font-size: 13px; color: #334155; font-weight: 400; line-height: 1.55; margin: 0;">
                    Calculate rental income dividends and disburse directly to investor bank accounts in bulk with detailed transaction ledgers.
                </p>
            </div>

            <div style="background: var(--ofp-card-bg, #ffffff); border: 1px solid var(--ofp-border-color, #e2e8f0); border-radius: 12px; padding: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 15px; color: #0f172a;">Investor Compliance & KYC</span>
                    <span style="font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: rgba(139, 92, 246, 0.12); color: #7c3aed;">Module 3</span>
                </div>
                <p style="font-size: 13px; color: #334155; font-weight: 400; line-height: 1.55; margin: 0;">
                    Automated BVN/NIN verification, accreditation audit trails, and cryptographically signed trustee agreements.
                </p>
            </div>

            <div style="background: var(--ofp-card-bg, #ffffff); border: 1px solid var(--ofp-border-color, #e2e8f0); border-radius: 12px; padding: 20px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                    <span style="font-weight: 600; font-size: 15px; color: #0f172a;">Secondary Order Book</span>
                    <span style="font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 9999px; background: rgba(245, 158, 11, 0.12); color: #d97706;">Module 4</span>
                </div>
                <p style="font-size: 13px; color: #334155; font-weight: 400; line-height: 1.55; margin: 0;">
                    Peer-to-peer liquidity matching engine with automated ownership reassignment and platform secondary transaction fees.
                </p>
            </div>
        </div>
    </div>

    <!-- Registered Client Waitlist Table -->
    <div style="background: var(--ofp-card-bg, #ffffff); border: 1px solid var(--ofp-border-color, #e2e8f0); border-radius: 12px; padding: 22px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 3px;">
                    Early Access Priority Waitlist
                </h3>
                <p style="font-size: 13px; color: #475569; font-weight: 400; margin: 0;">
                    Clients who have clicked "Join VIP Early Access" on their portal.
                </p>
            </div>
            <span style="font-size: 12px; font-weight: 600; background: #e2e8f0; color: #0f172a; padding: 4px 10px; border-radius: 6px;">
                <?php echo esc_html( $total_waitlist_count ); ?> Registered
            </span>
        </div>

        <?php if ( empty( $waitlist_users ) ) : ?>
            <div style="text-align: center; padding: 36px 20px; color: #475569;">
                <svg width="36" height="36" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin: 0 auto 10px; display: block; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                <p style="font-size: 14px; font-weight: 600; margin: 0 0 4px; color: #0f172a;">No waitlist signups yet</p>
                <p style="font-size: 13px; font-weight: 400; color: #475569; margin: 0;">When clients click "Join Early Access" on <code>/investment</code>, their records will appear here.</p>
            </div>
        <?php else : ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0; text-align: left;">
                            <th style="padding: 10px 12px; color: #475569; font-size: 11px; font-weight: 600; text-transform: uppercase;">Client / User</th>
                            <th style="padding: 10px 12px; color: #475569; font-size: 11px; font-weight: 600; text-transform: uppercase;">Email</th>
                            <th style="padding: 10px 12px; color: #475569; font-size: 11px; font-weight: 600; text-transform: uppercase;">Registered Date</th>
                            <th style="padding: 10px 12px; color: #475569; font-size: 11px; font-weight: 600; text-transform: uppercase;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $waitlist_users as $wu ) : 
                            $date_joined = get_user_meta( $wu->ID, 'ofp_investment_waitlist_date', true );
                        ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; font-weight: 600; color: #0f172a;">
                                    <?php echo esc_html( $wu->display_name ?: $wu->user_login ); ?>
                                </td>
                                <td style="padding: 12px; font-weight: 400; color: #334155;">
                                    <?php echo esc_html( $wu->user_email ); ?>
                                </td>
                                <td style="padding: 12px; font-weight: 400; color: #475569;">
                                    <?php echo esc_html( $date_joined ? date( 'M j, Y — g:ia', strtotime( $date_joined ) ) : 'Recently' ); ?>
                                </td>
                                <td style="padding: 12px;">
                                    <span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">
                                        Priority Listed
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
