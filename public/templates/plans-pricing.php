<?php
/**
 * Template: /plans-pricing, /pricing-plans, /plans, /plans-and-pricing
 * Public Plans & Pricing page with universal header and footer,
 * accurate hero banner matching /agents regarding Light & Dark mode overlays,
 * in-card billing period tabs,
 * and high-end GSAP interactive effects.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Check if user is already logged in as a client
$client = class_exists( 'OFP_Auth' ) ? OFP_Auth::current_client() : null;

// Determine active plan if logged in
$is_solo = false; // true for an unlimited account (the platform's own account)
$current_plan = 'guest';

if ( $client ) {
    $is_solo = class_exists('OFP_Subscription') && OFP_Subscription::is_unlimited( (int) $client->id );
    $current_plan = $is_solo ? 'unlimited' : ( class_exists('OFP_Subscription') ? OFP_Subscription::client_plan( (int) $client->id ) : 'free' );
}

// Fetch dynamic prices from plugin settings or defaults
$raw_prices = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_plan_prices() : [];
$price_silver_mo = isset( $raw_prices['silver'] ) && $raw_prices['silver'] > 0 ? (float) $raw_prices['silver'] : 50000.0;
$price_gold_mo   = isset( $raw_prices['gold'] ) && $raw_prices['gold'] > 0 ? (float) $raw_prices['gold'] : 100000.0;

// Calculated quarterly (3 months 5% off) and annual (12 months 15% off)
$silver_3m_tot  = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_period_price( 'silver', 3 ) : round( $price_silver_mo * 3 * 0.95 );
$silver_12m_tot = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_period_price( 'silver', 12 ) : round( $price_silver_mo * 12 * 0.85 );
$gold_3m_tot    = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_period_price( 'gold', 3 ) : round( $price_gold_mo * 3 * 0.95 );
$gold_12m_tot   = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_period_price( 'gold', 12 ) : round( $price_gold_mo * 12 * 0.85 );

$prices = [
    'free' => [
        'monthly'   => 0,
        'quarterly' => 0,
        'annually'  => 0,
    ],
    'silver' => [
        'monthly'       => $price_silver_mo,
        'quarterly_mo'  => round( $silver_3m_tot / 3 ),
        'quarterly_tot' => round( $silver_3m_tot ),
        'annually_mo'   => round( $silver_12m_tot / 12 ),
        'annually_tot'  => round( $silver_12m_tot ),
    ],
    'gold' => [
        'monthly'       => $price_gold_mo,
        'quarterly_mo'  => round( $gold_3m_tot / 3 ),
        'quarterly_tot' => round( $gold_3m_tot ),
        'annually_mo'   => round( $gold_12m_tot / 12 ),
        'annually_tot'  => round( $gold_12m_tot ),
    ],
];

// Header social links for universal navigation

// Hero banner image: dedicated real estate finance & pricing theme
$plans_hero_img = function_exists( 'ofp_image_url' ) 
    ? ofp_image_url( 'Miniature House and Coin Stacks.webp' )
    : 'https://dl.ofastshop.com/mypropertyhq/images/Miniature%20House%20and%20Coin%20Stacks.webp';

$page_title = OFP_Brand::title( 'Plans & Pricing · Transparent Real Estate Tech & Marketing' );
?>
<!DOCTYPE html>
<html lang="en"
    x-data="{
        billingCycle: 'monthly',
        megaMenuOpen: false,
        expandedFaq: null,
        toggleFaq(idx) {
            this.expandedFaq = this.expandedFaq === idx ? null : idx;
        },
        setCycle(cycle) {
            this.billingCycle = cycle;
            if (window.gsap) {
                gsap.fromTo('.gsap-price-anim', { opacity: 0.4, y: -4 }, { opacity: 1, y: 0, duration: 0.25, ease: 'power2.out' });
            }
        },
        darkMode: document.documentElement.classList.contains('dark'),
        toggleDark() { ofpToggleTheme(); }
    }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $page_title ); ?></title>

    <!-- Dark Mode Init Script (Prevents flash of white) -->
    <script>
        (function() {
            var theme = localStorage.getItem('ofp_theme');
            var isDark = (theme === 'dark');
            var html = document.documentElement;
            if (isDark) {
                html.classList.add('dark');
                html.classList.remove('light');
                html.setAttribute('data-theme', 'dark');
            } else {
                html.classList.remove('dark');
                html.classList.add('light');
                html.setAttribute('data-theme', 'light');
            }
        })();

        function ofpToggleTheme() {
            var html = document.documentElement;
            var isDark = html.classList.toggle('dark');
            if (isDark) {
                html.classList.remove('light');
                html.setAttribute('data-theme', 'dark');
            } else {
                html.classList.add('light');
                html.setAttribute('data-theme', 'light');
            }
            try {
                localStorage.setItem('ofp_theme', isDark ? 'dark' : 'light');
            } catch (e) {}
            if (typeof tailwind !== 'undefined' && tailwind.config) {
                tailwind.config.darkMode = 'class';
            }
            if (window.Alpine) {
                document.querySelectorAll('[x-data]').forEach(function(el) {
                    try {
                        if (el._x_dataStack) {
                            el._x_dataStack.forEach(function(s) {
                                if (typeof s.darkMode !== 'undefined') s.darkMode = isDark;
                            });
                        }
                    } catch(err) {}
                });
            }
            window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: isDark, theme: isDark ? 'dark' : 'light' } }));
        }
        window.ofpToggleTheme = ofpToggleTheme;
        window.toggleDark = ofpToggleTheme;
    </script>

    <!-- Tailwind Config & Libraries -->
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif'],
                        system: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <!-- GSAP 3 Animation Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>

    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/alpine-tailwind-overrides.css' ); ?>">

    <?php wp_head(); ?>

    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif'],
                            system: ['"Plus Jakarta Sans"', '"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'sans-serif']
                        }
                    }
                }
            };
        }
    </script>

    <link rel="stylesheet" href="<?php echo esc_url( OFP_URL . 'assets/css/plans-pricing.css' ); ?>">
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300 font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Mega Menu Backdrop Overlay (Desktop) -->
    <?php
        // Shared top bar + mega menu + mobile menu (X5.03). Settings: partials/site-nav.php
        $ofp_nav_mode = 'ios';
        $ofp_nav_active = 'pricing';
        $ofp_nav_dashboard = ! empty( $client );
        include OFP_PATH . 'public/templates/partials/site-nav.php';
    ?>

    <!-- ── HERO BANNER ────────────────────────────────────────────────── -->
    <section class="plans-hero-bg relative overflow-hidden border-b border-slate-200 dark:border-slate-800 pt-28 sm:pt-36 pb-16 sm:pb-24 transition-colors duration-300" style="--ofp-plans-hero-img: url('<?php echo esc_url( $plans_hero_img ); ?>');">
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <!-- Badge matching /agents -->
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/90 dark:bg-slate-900/80 border border-slate-200 dark:border-white/20 backdrop-blur-md text-slate-800 dark:text-blue-300 text-xs font-bold uppercase tracking-wider mb-6 shadow-sm">
                <svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span>Transparent &amp; Scalable Pricing</span>
            </div>

            <!-- Title matching /agents typography -->
            <h1 class="text-3xl sm:text-5xl lg:text-6xl font-black tracking-tight max-w-4xl mx-auto leading-tight sm:leading-tight mb-4 text-slate-950 dark:text-white drop-shadow-sm">
                Everything You Need to <span class="text-blue-600 dark:text-blue-500">List, Automate, &amp; Close</span> Real Estate Deals
            </h1>

            <p class="text-slate-700 dark:text-slate-300 text-base sm:text-lg max-w-2xl mx-auto leading-relaxed font-normal">
                Whether you are an independent realtor starting out or an enterprise property developer looking for a full enterprise platform, choose the tier that powers your vision.
            </p>
        </div>
    </section>

    <!-- ── "WHAT WE DO" VALUE STRIP (FULLY VISIBLE & RESPONSIVE) ───────────── -->
    <section id="what-we-do-section" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20">
        <div class="text-center max-w-2xl mx-auto mb-12">
            <h2 class="text-xs font-black text-blue-600 dark:text-blue-400 uppercase tracking-widest mb-2">What We Do</h2>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight">The 3 Pillars of <?php echo esc_html( OFP_Brand::name() ); ?> &amp; Pipeline</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">
            <!-- Pillar 1 -->
            <div class="plan-hover-card p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-xl transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center mb-6">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                </div>
                <h3 class="text-lg font-black text-slate-900 dark:text-white mb-2">Verified Real Estate Marketplace</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-4">
                    List properties with full confidence. Every development and title is vetted, putting your properties directly in front of over 24,000+ active Nigerian homebuyers and diaspora investors.
                </p>
                <div class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 dark:text-blue-400">
                    <span>24k+ Active Buyers · Verified Deals</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </div>
            </div>

            <!-- Pillar 2 -->
            <div class="plan-hover-card p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-xl transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center mb-6">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <h3 class="text-lg font-black text-slate-900 dark:text-white mb-2">Automated Broker &amp; Team CRM</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-4">
                    Capture leads straight into your private deal pipeline. Trigger automated WhatsApp notifications, SMS broadcasts, inspect-to-close milestones, and multi-agent task delegations.
                </p>
                <div class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-600 dark:text-indigo-400">
                    <span>WhatsApp Alerts · 3x Faster Closings</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </div>
            </div>

            <!-- Pillar 3 -->
            <div class="plan-hover-card p-6 sm:p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-xl transition-all duration-300">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mb-6">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <h3 class="text-lg font-black text-slate-900 dark:text-white mb-2">Digital Leases, Rent &amp; Installments</h3>
                <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-4">
                    Automate off-plan milestone installment schedules and tenancy agreements. Buyers and tenants receive automated payment reminders with transparent digital ledgers.
                </p>
                <div class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 dark:text-emerald-400">
                    <span>Online Checkout · Automated Ledgers</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </div>
            </div>
        </div>
    </section>

    <!-- ── PRICING CARDS (3 TIERS WITH IN-CARD TABS) ──────────────────────── -->
    <section id="pricing-plans" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20 sm:pb-28">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-stretch">

            <!-- ── TIER 1: FREE STARTER ───────────────────────────────────── -->
            <div class="plan-hover-card flex flex-col justify-between p-6 sm:p-7 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 transition-all hover:border-slate-300 dark:hover:border-slate-700 relative">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <span class="text-xs font-black uppercase tracking-wider px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                            Starter
                        </span>
                        <?php if ( $client && $current_plan === 'free' ) : ?>
                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Current</span>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-xl font-black text-slate-900 dark:text-white">Free</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 mb-5 min-h-[32px]">
                        Essential tools for independent realtors getting their first listings online.
                    </p>

                    <!-- In-Card Billing Tab (Fixed for Free) -->
                    <div class="flex items-center p-1 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 mb-5 text-[11px] font-bold text-center">
                        <span class="w-full py-1 text-slate-600 dark:text-slate-300">Free Forever</span>
                    </div>

                    <!-- Price -->
                    <div class="mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl sm:text-4xl font-black text-slate-900 dark:text-white">₦0</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 font-semibold">/ month</span>
                        </div>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1">Zero fees · No credit card required</p>
                    </div>

                    <!-- Features -->
                    <ul class="space-y-3 text-xs text-slate-700 dark:text-slate-300 mb-8">
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>1 Property Listing</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Lead &amp; Buyer Management</span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Transactions &amp; Client Portal</span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Light Identity Verification</span>
                        </li>
                        <li class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500 line-through">
                            <svg class="w-4 h-4 text-slate-400 dark:text-slate-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            <span>0 Team Sub-Accounts</span>
                        </li>
                        <li class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500 line-through">
                            <svg class="w-4 h-4 text-slate-400 dark:text-slate-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            <span>Rent &amp; Installment Engine</span>
                        </li>
                        <li class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500 line-through">
                            <svg class="w-4 h-4 text-slate-400 dark:text-slate-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            <span>Custom Domain Routing</span>
                        </li>
                    </ul>
                </div>

                <!-- CTA -->
                <div>
                    <?php if ( $client && $current_plan === 'free' ) : ?>
                        <button disabled class="w-full py-3 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 font-bold text-xs uppercase tracking-wider cursor-default">
                            Active Plan
                        </button>
                    <?php elseif ( $client ) : ?>
                        <button disabled class="w-full py-3 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs uppercase tracking-wider cursor-not-allowed">
                            Included In Higher Tier
                        </button>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( '/signup?plan=free' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl border border-slate-300 dark:border-slate-700 hover:border-slate-400 dark:hover:border-slate-600 text-slate-900 dark:text-white font-bold text-xs uppercase tracking-wider transition-all">
                            Start Free
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── TIER 2: SILVER AGENCY ──────────────────────────────────── -->
            <div class="plan-hover-card flex flex-col justify-between p-6 sm:p-7 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 transition-all hover:border-blue-300 dark:hover:border-blue-700 relative">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <span class="text-xs font-black uppercase tracking-wider px-3 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                            Agency
                        </span>
                        <?php if ( $client && $current_plan === 'silver' ) : ?>
                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Current</span>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-xl font-black text-slate-900 dark:text-white">Silver</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 mb-5 min-h-[32px]">
                        For growing agencies and boutique brokerage teams needing extra listings and staff.
                    </p>

                    <!-- In-Card Billing Period Tabs -->
                    <div class="flex items-center p-1 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 mb-5 text-[11px] font-bold">
                        <button type="button" @click="setCycle('monthly')"
                            :class="billingCycle === 'monthly' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer">
                            Monthly
                        </button>
                        <button type="button" @click="setCycle('quarterly')"
                            :class="billingCycle === 'quarterly' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer" title="Save 5%">
                            Qtr <span class="text-[9px] text-emerald-600 dark:text-emerald-400 font-extrabold">-5%</span>
                        </button>
                        <button type="button" @click="setCycle('annually')"
                            :class="billingCycle === 'annually' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer" title="Save 15%">
                            Year <span class="text-[9px] text-emerald-600 dark:text-emerald-400 font-extrabold">-15%</span>
                        </button>
                    </div>

                    <!-- Price -->
                    <div class="mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-baseline gap-1">
                            <span class="gsap-price-anim text-3xl sm:text-4xl font-black text-slate-900 dark:text-white"
                                x-text="billingCycle === 'monthly' ? '₦<?php echo number_format( $prices['silver']['monthly'] ); ?>' : (billingCycle === 'quarterly' ? '₦<?php echo number_format( $prices['silver']['quarterly_mo'] ); ?>' : '₦<?php echo number_format( $prices['silver']['annually_mo'] ); ?>')">
                                ₦<?php echo number_format( $prices['silver']['monthly'] ); ?>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 font-semibold">/ month</span>
                        </div>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1"
                            x-text="billingCycle === 'monthly' ? 'Billed monthly' : (billingCycle === 'quarterly' ? '₦<?php echo number_format( $prices['silver']['quarterly_tot'] ); ?> billed every 3 months' : '₦<?php echo number_format( $prices['silver']['annually_tot'] ); ?> billed annually')">
                            Billed monthly
                        </p>
                    </div>

                    <!-- Features -->
                    <ul class="space-y-3 text-xs text-slate-700 dark:text-slate-300 mb-8">
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>3 Property Listings</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>Verified Agency Badge</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>2 Team Member Seats</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Editable Email Templates</span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Standard Analytics &amp; Reports</span>
                        </li>
                        <li class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500 line-through">
                            <svg class="w-4 h-4 text-slate-400 dark:text-slate-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            <span>Rent &amp; Installment Engine</span>
                        </li>
                        <li class="flex items-center gap-2.5 text-slate-400 dark:text-slate-500 line-through">
                            <svg class="w-4 h-4 text-slate-400 dark:text-slate-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            <span>Custom Domain Routing</span>
                        </li>
                    </ul>
                </div>

                <!-- CTA -->
                <div>
                    <?php if ( $client && $current_plan === 'silver' ) : ?>
                        <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all">
                            Renew Silver
                        </a>
                    <?php elseif ( $client && ( $current_plan === 'gold' || $is_solo ) ) : ?>
                        <button disabled class="w-full py-3 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs uppercase tracking-wider cursor-not-allowed">
                            Included in <?php echo $is_solo ? 'your account' : 'Gold'; ?>
                        </button>
                    <?php elseif ( $client ) : ?>
                        <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-blue-500/20">
                            Upgrade to Silver
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( '/signup?plan=silver' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md shadow-blue-500/20">
                            Choose Silver
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── TIER 3: GOLD ENTERPRISE (MOST POPULAR) ────────────────── -->
            <div class="plan-hover-card flex flex-col justify-between p-6 sm:p-7 rounded-3xl bg-white dark:bg-slate-900 border-2 border-blue-600 dark:border-blue-500 transition-all shadow-xl shadow-blue-500/10 relative">
                <!-- Floating Popular Pill -->
                <div class="absolute -top-3.5 left-1/2 -translate-x-1/2 bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-[10px] font-black uppercase tracking-widest px-3.5 py-1 rounded-full shadow-md whitespace-nowrap">
                    Most Popular
                </div>

                <div>
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <span class="text-xs font-black uppercase tracking-wider px-3 py-1 rounded-full bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300">
                            Developer &amp; Scale
                        </span>
                        <?php if ( $client && $current_plan === 'gold' && ! $is_solo ) : ?>
                            <span class="text-[10px] font-black uppercase tracking-widest px-2.5 py-0.5 rounded-full bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">Current</span>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-xl font-black text-slate-900 dark:text-white">Gold</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 mb-5 min-h-[32px]">
                        For commercial brokers, developers, and expanding firms managing rent &amp; installments.
                    </p>

                    <!-- In-Card Billing Period Tabs -->
                    <div class="flex items-center p-1 rounded-xl bg-slate-100 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 mb-5 text-[11px] font-bold">
                        <button type="button" @click="setCycle('monthly')"
                            :class="billingCycle === 'monthly' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer">
                            Monthly
                        </button>
                        <button type="button" @click="setCycle('quarterly')"
                            :class="billingCycle === 'quarterly' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer" title="Save 5%">
                            Qtr <span class="text-[9px] text-emerald-600 dark:text-emerald-400 font-extrabold">-5%</span>
                        </button>
                        <button type="button" @click="setCycle('annually')"
                            :class="billingCycle === 'annually' ? 'bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-400 shadow-sm' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                            class="flex-1 py-1 rounded-lg transition-all text-center cursor-pointer" title="Save 15%">
                            Year <span class="text-[9px] text-emerald-600 dark:text-emerald-400 font-extrabold">-15%</span>
                        </button>
                    </div>

                    <!-- Price -->
                    <div class="mb-6 pb-6 border-b border-slate-100 dark:border-slate-800">
                        <div class="flex items-baseline gap-1">
                            <span class="gsap-price-anim text-3xl sm:text-4xl font-black text-slate-900 dark:text-white"
                                x-text="billingCycle === 'monthly' ? '₦<?php echo number_format( $prices['gold']['monthly'] ); ?>' : (billingCycle === 'quarterly' ? '₦<?php echo number_format( $prices['gold']['quarterly_mo'] ); ?>' : '₦<?php echo number_format( $prices['gold']['annually_mo'] ); ?>')">
                                ₦<?php echo number_format( $prices['gold']['monthly'] ); ?>
                            </span>
                            <span class="text-xs text-slate-500 dark:text-slate-400 font-semibold">/ month</span>
                        </div>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-1"
                            x-text="billingCycle === 'monthly' ? 'Billed monthly' : (billingCycle === 'quarterly' ? '₦<?php echo number_format( $prices['gold']['quarterly_tot'] ); ?> billed every 3 months' : '₦<?php echo number_format( $prices['gold']['annually_tot'] ); ?> billed annually')">
                            Billed monthly
                        </p>
                    </div>

                    <!-- Features -->
                    <ul class="space-y-3 text-xs text-slate-700 dark:text-slate-300 mb-8">
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>10 Property Listings</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>Verified Partner Gold Seal</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>3 Team Member Seats</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>Rent Monitoring &amp; Digital Leases</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><strong>Off-Plan Installments &amp; Ledgers</strong></span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>ROI &amp; Investment Yield Calculator</span>
                        </li>
                        <li class="flex items-center gap-2.5">
                            <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span>Full Email &amp; Portal Branding</span>
                        </li>
                    </ul>
                </div>

                <!-- CTA -->
                <div>
                    <?php if ( $client && $current_plan === 'gold' && ! $is_solo ) : ?>
                        <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all">
                            Renew Gold
                        </a>
                    <?php elseif ( $client && $is_solo ) : ?>
                        <button disabled class="w-full py-3 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 font-bold text-xs uppercase tracking-wider cursor-not-allowed">
                            Included in your account
                        </button>
                    <?php elseif ( $client ) : ?>
                        <a href="<?php echo esc_url( home_url( '/funding' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all shadow-lg shadow-blue-500/25">
                            Upgrade to Gold
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( '/signup?plan=gold' ) ); ?>"
                            class="w-full inline-block text-center py-3 px-4 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs uppercase tracking-wider transition-all shadow-lg shadow-blue-500/25">
                            Get Gold
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </section>

    <!-- ── DETAILED COMPARISON MATRIX ────────────────────────────────────── -->
    <section id="comparison-matrix-section" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20">
        <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 sm:p-10 shadow-sm">
            <div class="text-center max-w-2xl mx-auto mb-10">
                <h2 class="text-xs font-black text-blue-600 dark:text-blue-400 uppercase tracking-widest mb-2">Feature by Feature</h2>
                <p class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight">Full Plan Matrix Comparison</p>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-2">Everything itemized so you can select with complete certainty.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse min-w-[650px]">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white">
                            <th class="py-4 px-4 font-black text-sm uppercase tracking-wider w-2/5">Platform Features</th>
                            <th class="py-4 px-3 font-bold text-center text-xs uppercase tracking-wider w-[15%]">Free</th>
                            <th class="py-4 px-3 font-bold text-center text-xs uppercase tracking-wider w-[15%]">Silver</th>
                            <th class="py-4 px-3 font-bold text-center text-xs uppercase tracking-wider w-[15%] text-blue-600 dark:text-blue-400">Gold</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-xs text-slate-700 dark:text-slate-300">
                        <?php
                        $dyn_matrix = class_exists( 'OFP_Property_CPT' ) ? OFP_Property_CPT::get_plan_matrix() : [];
                        foreach ( $dyn_matrix as $group_title => $items ) :
                        ?>
                            <tr class="bg-slate-50/60 dark:bg-slate-800/40">
                                <td colspan="4" class="py-2.5 px-4 font-black text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                    <?php echo esc_html( $group_title ); ?>
                                </td>
                            </tr>
                            <?php foreach ( $items as $row ) :
                                $render_client_cell = function( $val, $col = 'free' ) {
                                    $val = trim( (string) $val );
                                    if ( $val === '✓' ) {
                                        $cls = ( $col === 'silver' || $col === 'gold' ) ? 'text-emerald-500 font-bold' : 'text-emerald-500';
                                        return '<span class="' . $cls . '">✓</span>';
                                    }
                                    if ( $val === '—' || $val === '' ) {
                                        return '<span class="text-slate-400">—</span>';
                                    }
                                    $cls = 'font-semibold';
                                    if ( $col === 'gold' ) {
                                        $cls = 'font-bold text-blue-600 dark:text-blue-400';
                                    } elseif ( $col === 'silver' ) {
                                        $cls = 'font-bold';
                                    }
                                    return '<span class="' . $cls . '">' . esc_html( $val ) . '</span>';
                                };
                            ?>
                                <tr>
                                    <td class="py-3 px-4 font-semibold"><?php echo esc_html( $row['feature'] ?? '' ); ?></td>
                                    <td class="py-3 px-3 text-center"><?php echo $render_client_cell( $row['free'] ?? '—', 'free' ); ?></td>
                                    <td class="py-3 px-3 text-center"><?php echo $render_client_cell( $row['silver'] ?? '—', 'silver' ); ?></td>
                                    <td class="py-3 px-3 text-center"><?php echo $render_client_cell( $row['gold'] ?? '—', 'gold' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- ── FAQ ACCORDION ──────────────────────────────────────────────────── -->
    <section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pb-20">
        <div class="text-center max-w-2xl mx-auto mb-10">
            <h2 class="text-xs font-black text-blue-600 dark:text-blue-400 uppercase tracking-widest mb-2">Frequently Asked Questions</h2>
            <p class="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight">Everything You Need to Know</p>
        </div>

        <div class="space-y-3">
            <!-- FAQ 1 -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden transition-colors">
                <button type="button" @click="toggleFaq(1)" class="w-full text-left p-5 flex items-center justify-between gap-4 font-black text-sm text-slate-900 dark:text-white cursor-pointer">
                    <span>Can I upgrade, downgrade, or cancel at any time?</span>
                    <svg class="w-4 h-4 text-slate-500 transition-transform duration-200 shrink-0" :class="expandedFaq === 1 ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="expandedFaq === 1" x-collapse class="px-5 pb-5 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed border-t border-slate-100 dark:border-slate-800/60 pt-3">
                    Yes, absolutely! You can upgrade your tier at any moment from your subscription dashboard. Upgrades are prorated immediately so you only pay the difference. If you downgrade, your current limits stay active until the end of your billing cycle.
                </div>
            </div>

            <!-- FAQ 3 -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden transition-colors">
                <button type="button" @click="toggleFaq(3)" class="w-full text-left p-5 flex items-center justify-between gap-4 font-black text-sm text-slate-900 dark:text-white cursor-pointer">
                    <span>How do Installment Payment Plans and Buyer Ledgers work?</span>
                    <svg class="w-4 h-4 text-slate-500 transition-transform duration-200 shrink-0" :class="expandedFaq === 3 ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="expandedFaq === 3" x-collapse class="px-5 pb-5 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed border-t border-slate-100 dark:border-slate-800/60 pt-3">
                    Available on Gold, the installment engine lets you structure off-plan payment plans for buyers (e.g. 20% down payment, 6 quarterly installments). The buyer gets a secure login to view their payment ledger, download official receipts, and pay upcoming installments via automated payment reminders.
                </div>
            </div>

            <!-- FAQ 4 -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden transition-colors">
                <button type="button" @click="toggleFaq(4)" class="w-full text-left p-5 flex items-center justify-between gap-4 font-black text-sm text-slate-900 dark:text-white cursor-pointer">
                    <span>What payment methods are supported?</span>
                    <svg class="w-4 h-4 text-slate-500 transition-transform duration-200 shrink-0" :class="expandedFaq === 4 ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="expandedFaq === 4" x-collapse class="px-5 pb-5 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed border-t border-slate-100 dark:border-slate-800/60 pt-3">
                    We accept all Nigerian debit and credit cards (Mastercard, Visa, Verve), direct bank transfers, and USSD via our secured payment gateways. Instant activation happens the second payment clears.
                </div>
            </div>

            <!-- FAQ 5 -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden transition-colors">
                <button type="button" @click="toggleFaq(5)" class="w-full text-left p-5 flex items-center justify-between gap-4 font-black text-sm text-slate-900 dark:text-white cursor-pointer">
                    <span>What happens when I reach my property listing cap?</span>
                    <svg class="w-4 h-4 text-slate-500 transition-transform duration-200 shrink-0" :class="expandedFaq === 5 ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div x-show="expandedFaq === 5" x-collapse class="px-5 pb-5 text-xs sm:text-sm text-slate-600 dark:text-slate-400 leading-relaxed border-t border-slate-100 dark:border-slate-800/60 pt-3">
                    If you reach your cap (e.g. 1 on Free, 3 on Silver, 10 on Gold), existing listings stay active and fully accessible. To publish additional properties, you can upgrade to the next tier or step up to the White-Label tier for unlimited listings.
                </div>
            </div>
        </div>
    </section>

    <!-- ── BOTTOM CONVERSION CTA BANNER ──────────────────────────────────── -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20">
        <div class="relative rounded-3xl overflow-hidden bg-gradient-to-r from-blue-700 via-blue-800 to-indigo-900 text-white p-8 sm:p-14 shadow-2xl">
            <!-- Background circles decoration -->
            <div class="absolute -top-24 -right-24 w-80 h-80 rounded-full bg-blue-500/20 blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-24 -left-24 w-80 h-80 rounded-full bg-indigo-500/20 blur-3xl pointer-events-none"></div>

            <div class="relative z-10 max-w-3xl">
                <span class="text-xs font-black uppercase tracking-widest text-blue-300 mb-3 inline-block">Join Nigeria's Premier PropTech Ecosystem</span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-tight mb-4">
                    Ready to Scale Your Real Estate Agency?
                </h2>
                <p class="text-sm sm:text-base text-blue-100 leading-relaxed mb-8 max-w-2xl font-normal">
                    Start free today in less than 2 minutes. Get instant marketplace exposure, close deals faster with automated lead workflows, and upgrade whenever you are ready.
                </p>

                <div class="flex flex-col sm:flex-row items-center gap-4">
                    <a href="<?php echo esc_url( home_url( '/signup' ) ); ?>"
                        class="w-full sm:w-auto text-center px-8 py-3.5 rounded-xl bg-white text-blue-900 hover:bg-blue-50 font-black text-xs uppercase tracking-wider shadow-xl transition-all">
                        Create Free Account
                    </a>
                    <a href="<?php echo esc_url( home_url( '/marketplace/' ) ); ?>"
                        class="w-full sm:w-auto text-center px-8 py-3.5 rounded-xl bg-blue-900/60 hover:bg-blue-900/80 text-white border border-blue-400/30 font-black text-xs uppercase tracking-wider transition-all">
                        Browse Marketplace
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ── UNIVERSAL FOOTER ────────────────────────────────────────────────── -->
    <?php
        // Shared footer (X5.03). Content: partials/site-footer.php
        $ofp_footer_margin = '';
        include OFP_PATH . 'public/templates/partials/site-footer.php';
    ?>

    <!-- ── GSAP SCRIPT (PROGRESSIVE MICRO-INTERACTIONS ONLY, NEVER HIDES CONTENT) ── -->
    <script src="<?php echo esc_url( OFP_URL . 'assets/js/plans-pricing.js' ); ?>" defer></script>

    <?php wp_footer(); ?>
</body>
</html>
