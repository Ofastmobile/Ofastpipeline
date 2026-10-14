<?php
// Phase 23: Facebook Meta Pixel Injection
add_action( 'wp_head', function() {
    $post_id = get_the_ID();
    if ( ! $post_id ) return;
    
    $client_id = get_post_meta( $post_id, 'ofp_client_id', true );
    if ( ! $client_id ) return;
    
    global $wpdb;
    $pixel = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_pixel_id FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1",
            $client_id
        )
    );
    
    if ( ! empty( $pixel ) ) {
        ?>
        <!-- Phase 23 Meta Pixel -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js( $pixel ); ?>');
        fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel ); ?>&ev=PageView&noscript=1"/></noscript>
        <?php
    }
} );

if ( have_posts() ) {
    the_post();
}

$post_id       = get_the_ID();
$price         = (float) get_post_meta( $post_id, 'ofp_price', true );
$listing_type  = get_post_meta( $post_id, 'ofp_listing_type', true ) ?: 'sale';
$property_type = get_post_meta( $post_id, 'ofp_property_type', true ) ?: 'apartment';
$location      = get_post_meta( $post_id, 'ofp_location_text', true ) ?: 'Nigeria';
$beds          = get_post_meta( $post_id, 'ofp_bedrooms', true );
$baths         = get_post_meta( $post_id, 'ofp_bathrooms', true );
$parking       = get_post_meta( $post_id, 'ofp_parking', true );
$sqm           = get_post_meta( $post_id, 'ofp_area_sqm', true );
$status        = get_post_meta( $post_id, 'ofp_status', true ) ?: 'live';
$title_doc     = get_post_meta( $post_id, 'ofp_title_document', true );
$condition     = get_post_meta( $post_id, 'ofp_condition', true );
$furnishing    = get_post_meta( $post_id, 'ofp_furnishing', true );
$video_url     = get_post_meta( $post_id, 'ofp_video_url', true );
$amenities     = json_decode( get_post_meta( $post_id, 'ofp_amenities', true ) ?: '[]', true ) ?: [];
$client_id     = (int) get_post_meta( $post_id, 'ofp_client_id', true );
$total_package = (float) get_post_meta( $post_id, 'ofp_total_package', true );
$insp_days     = get_post_meta( $post_id, 'ofp_inspection_days', true ) ?: 'Mondays - Saturdays';
$insp_hours    = get_post_meta( $post_id, 'ofp_inspection_hours', true ) ?: '10:00 AM - 5:00 PM';
$insp_notice   = get_post_meta( $post_id, 'ofp_inspection_notice', true );
$allow_installment = ( '1' === (string) get_post_meta( $post_id, 'ofp_allow_installment', true ) || 'yes' === (string) get_post_meta( $post_id, 'ofp_allow_installment', true ) );

// Dynamic Map Location
$raw_address       = trim( (string) get_post_meta( $post_id, 'ofp_location_text', true ) );
$map_address_query = $raw_address ?: 'Nigeria';
if ( stripos( $map_address_query, 'nigeria' ) === false ) {
    $map_address_query .= ', Nigeria';
}
$map_embed_url    = 'https://maps.google.com/maps?q=' . rawurlencode( $map_address_query ) . '&t=&z=15&ie=UTF8&iwloc=&output=embed';
$map_external_url = 'https://maps.google.com/?q=' . rawurlencode( $map_address_query );

// Client / Agent details
global $wpdb;
$agent = null;
if ( $client_id ) {
    $agent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $client_id ) );
}
$agent_name    = $agent ? ( $agent->owner_name ?: $agent->business_name ) : get_bloginfo( 'name' );
$agent_company = $agent ? ( $agent->business_name ?: 'Verified Partner' ) : 'Internal Listing';
$agent_phone   = $agent ? $agent->phone : '';
$clean_phone   = preg_replace( '/[^0-9]/', '', $agent_phone );
if ( strpos( $clean_phone, '0' ) === 0 ) {
    $clean_phone = '234' . substr( $clean_phone, 1 );
}

$listing_label = 'FOR SALE';
if ( $listing_type === 'rent' ) $listing_label = 'FOR RENT';
if ( $listing_type === 'shortlet' ) $listing_label = 'SHORT LET';
if ( $listing_type === 'commercial' ) $listing_label = 'COMMERCIAL';
if ( $listing_type === 'land' ) $listing_label = 'LAND';

// Gallery images array
$gallery_urls = [];
if ( has_post_thumbnail( $post_id ) ) {
    $thumb = get_the_post_thumbnail_url( $post_id, 'full' );
    if ( $thumb ) $gallery_urls[] = $thumb;
}
$gallery_meta = json_decode( get_post_meta( $post_id, 'ofp_gallery_ids', true ) ?: '[]', true );
if ( is_array( $gallery_meta ) ) {
    foreach ( $gallery_meta as $att_id ) {
        $url = wp_get_attachment_image_url( (int) $att_id, 'full' );
        if ( $url && ! in_array( $url, $gallery_urls, true ) ) {
            $gallery_urls[] = $url;
        }
    }
}
if ( empty( $gallery_urls ) ) {
    $gallery_urls[] = 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1600&q=80';
}
?>
<!DOCTYPE html>
<html lang="en" x-data="propertySingle">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( OFP_Brand::public_page_title( 'Property Details', 'Bofast Homes' ) ); ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ofp_theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var isDark = theme ? (theme === 'dark') : prefersDark;
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
                html.classList.remove('dark');
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

        // Cleanly register propertySingle component for Alpine.js without HTML attribute quote collisions
        function ofpInitPropertySingle() {
            const dataFactory = () => ({
                darkMode: document.documentElement.classList.contains('dark'),
                toggleDark() { ofpToggleTheme(); },
                mobileMenu: false,
                currentImage: 0,
                showGallery: false,
                activeTab: 'overview',
                megaMenuOpen: false,
                galleryImages: <?php echo json_encode( array_values( $gallery_urls ) ); ?>,
                propertyPrice: <?php echo (float) $price; ?>,
                downPaymentPercent: 20,
                loanYears: 20,
                interestRate: 0.16,
                get downPayment() { return Math.round(this.propertyPrice * (this.downPaymentPercent / 100)); },
                get principal() { return Math.max(0, this.propertyPrice - this.downPayment); },
                get monthlyPayment() {
                    if (this.principal <= 0) return 0;
                    const r = this.interestRate / 12;
                    const n = this.loanYears * 12;
                    const p = (this.principal * (r * Math.pow(1 + r, n))) / (Math.pow(1 + r, n) - 1);
                    return Math.round(p);
                },
                inquiryLoading: false,
                actionLoading: '',
                inquirySuccess: false,
                inquiryError: '',
                tourType: 'physical',
                inquiryDate: <?php echo json_encode( gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ); ?>,
                inquiryTime: '10:00 AM',
                inquiryName: '',
                inquiryPhone: '',
                inquiryEmail: '',
                inquiryMessage: '',
                shareModalOpen: false,
                shareCopied: false,
                isSaved: (function() {
                    try {
                        const saved = JSON.parse(localStorage.getItem('ofp_saved_properties') || '[]');
                        return saved.includes(<?php echo (int) $post_id; ?>);
                    } catch(e) { return false; }
                })(),
                get shareTitle() {
                    return <?php echo json_encode( get_the_title() . ' - ₦' . number_format( (float) $price ) . ' | Bofast Homes' ); ?>;
                },
                toggleSaveProperty() {
                    try {
                        let saved = JSON.parse(localStorage.getItem('ofp_saved_properties') || '[]');
                        const pid = <?php echo (int) $post_id; ?>;
                        if (saved.includes(pid)) {
                            saved = saved.filter(id => id !== pid);
                            this.isSaved = false;
                        } else {
                            saved.push(pid);
                            this.isSaved = true;
                        }
                        localStorage.setItem('ofp_saved_properties', JSON.stringify(saved));
                    } catch(e) {
                        this.isSaved = !this.isSaved;
                    }
                },
                copyShareLink() {
                    const url = window.location.href;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(() => {
                            this.shareCopied = true;
                            setTimeout(() => { this.shareCopied = false; }, 2500);
                        }).catch(() => {
                            this.fallbackCopy(url);
                        });
                    } else {
                        this.fallbackCopy(url);
                    }
                },
                fallbackCopy(text) {
                    const el = document.createElement('textarea');
                    el.value = text;
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                    this.shareCopied = true;
                    setTimeout(() => { this.shareCopied = false; }, 2500);
                },
                shareNative() {
                    if (navigator.share) {
                        navigator.share({
                            title: this.shareTitle,
                            text: 'Check out this property: ' + this.shareTitle,
                            url: window.location.href
                        }).catch(() => {});
                    } else {
                        this.copyShareLink();
                    }
                },
                get cleanWhatsAppUrl() {
                    const cleanPhone = <?php echo json_encode( $clean_phone ?: '2348000000000' ); ?>;
                    const tourLabel = this.tourType === 'whatsapp_video' ? 'WhatsApp Video Call Tour' : 'Physical Inspection Tour';
                    const agent = <?php echo json_encode( $agent_name ); ?>;
                    const title = <?php echo json_encode( get_the_title() ); ?>;
                    const postId = <?php echo (int) $post_id; ?>;
                    let text = "Hello " + agent + ",\n" +
                        "I am interested in " + title + " (PID: " + postId + ").\n" +
                        "Name: " + (this.inquiryName || 'Buyer/Tenant') + "\n" +
                        "Phone: " + (this.inquiryPhone || 'Not provided') + "\n" +
                        "Tour Type: " + tourLabel + "\n" +
                        "Preferred Date: " + this.inquiryDate + " (" + this.inquiryTime + ")\n";
                    if (this.inquiryMessage) {
                        text += "Requirements/Notes: " + this.inquiryMessage;
                    }
                    return 'https://wa.me/' + cleanPhone + '?text=' + encodeURIComponent(text);
                },
                submitInquiry(action = 'booking') {
                    this.inquiryError = '';
                    if (!this.inquiryName || !this.inquiryName.trim()) {
                        this.inquiryError = 'Please enter your full name.';
                        return;
                    }
                    if (!this.inquiryPhone || !this.inquiryPhone.trim()) {
                        this.inquiryError = 'Please enter your WhatsApp phone number.';
                        return;
                    }
                    if (!this.inquiryMessage || !this.inquiryMessage.trim()) {
                        this.inquiryError = 'Please enter what you are looking for in the request box below.';
                        return;
                    }

                    this.inquiryLoading = true;
                    this.actionLoading = action;

                    const payload = {
                        client_id: <?php echo (int) ( $client_id ?: 0 ); ?>,
                        name: this.inquiryName.trim(),
                        phone: this.inquiryPhone.trim(),
                        email: this.inquiryEmail.trim(),
                        property_id: <?php echo (int) $post_id; ?>,
                        source: action === 'whatsapp' ? 'property_whatsapp_click' : (action === 'call' ? 'property_call_click' : 'property_inspection_booking'),
                        tour_type: this.tourType === 'whatsapp_video' ? 'whatsapp_video' : 'physical',
                        viewing_date: this.inquiryDate,
                        viewing_time: this.inquiryTime,
                        message: this.inquiryMessage.trim()
                    };

                    const targetWaUrl = this.cleanWhatsAppUrl;
                    const agentPhone = <?php echo json_encode( $agent_phone ?: '+2348000000000' ); ?>;

                    fetch(<?php echo json_encode( esc_url_raw( rest_url( 'ofp/v1/capture-lead' ) ) ); ?>, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    })
                    .then(res => res.json())
                    .then(data => {
                        this.inquiryLoading = false;
                        this.actionLoading = '';
                        this.inquirySuccess = true;

                        if (action === 'whatsapp') {
                            window.open(targetWaUrl, '_blank');
                        } else if (action === 'call') {
                            window.location.href = 'tel:' + agentPhone;
                        }
                    })
                    .catch(() => {
                        this.inquiryLoading = false;
                        this.actionLoading = '';
                        if (action === 'whatsapp') {
                            window.open(targetWaUrl, '_blank');
                            this.inquirySuccess = true;
                        } else if (action === 'call') {
                            window.location.href = 'tel:' + agentPhone;
                            this.inquirySuccess = true;
                        } else {
                            this.inquiryError = 'Network error. Please try again or connect directly on WhatsApp.';
                        }
                    });
                }
            });

            if (window.Alpine) {
                window.Alpine.data('propertySingle', dataFactory);
            } else {
                document.addEventListener('alpine:init', () => {
                    window.Alpine.data('propertySingle', dataFactory);
                });
            }
        }
        ofpInitPropertySingle();
    </script>
    <script>
        window.tailwind = window.tailwind || {};
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'],
                        system: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <?php wp_head(); ?>
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                darkMode: 'class',
                theme: {
                    extend: {
                        fontFamily: {
                            sans: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif'],
                            system: ['"Segoe UI"', '-apple-system', 'BlinkMacSystemFont', 'Roboto', 'Arial', 'sans-serif']
                        }
                    }
                }
            };
        }
    </script>
</head>

<body class="bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100 transition-colors duration-300">

    <!-- Mega Menu Backdrop Overlay -->
    <div x-show="megaMenuOpen" class="fixed inset-0 bg-slate-900/20 backdrop-blur-md z-40"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display: none;"
        @click="megaMenuOpen = false"></div>

    <!-- Top Navigation -->
    <header x-data="{ scrolledDown: false, lastScroll: 0 }" @scroll.window="
                if (!megaMenuOpen) {
                    const current = window.pageYOffset;
                    if (current > lastScroll && current > 50) scrolledDown = true;
                    else if (current < lastScroll) scrolledDown = false;
                    lastScroll = current;
                }
            "
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border-b border-slate-200 dark:border-slate-800 transition-transform duration-300"
        :class="scrolledDown ? '-translate-y-full' : 'translate-y-0'">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="flex justify-between items-center h-16 transition-all duration-300">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <!-- Logo (No icon, Uppercase, Small font) -->
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                        <span
                            class="text-[13px] sm:text-sm font-black tracking-[0.25em] text-slate-900 dark:text-white uppercase transition-colors">
                            <?php echo OFP_Brand::public_logo_html( 'BOFAST HOMES' ); ?>
                        </span>
                    </a>

                    <!-- Mega Menu Hamburger Button (Reduced size) -->
                    <button @click="megaMenuOpen = !megaMenuOpen"
                        class="bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-lg px-2.5 py-1.5 text-slate-700 dark:text-slate-300 transition-colors flex items-center justify-center cursor-pointer border border-slate-200 dark:border-slate-700">
                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path x-show="!megaMenuOpen" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path>
                            <path x-show="megaMenuOpen" style="display: none;" stroke-linecap="round"
                                stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <!-- Right: Action Button -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <button type="button" onclick="ofpToggleTheme()" aria-label="Toggle Dark Mode"
                        class="p-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-all cursor-pointer">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z">
                            </path>
                        </svg>
                        <svg class="w-4 h-4 text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
                            </path>
                        </svg>
                    </button>
                    <!-- Post Property Button -->
                    <a href="<?php echo esc_url( home_url( '/login' ) ); ?>"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 sm:px-6 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase inline-flex items-center justify-center">
                        Post Property
                    </a>
                </div>
            </div>

            <!-- Mega Menu Dropdown (Boxed, not full width) -->
            <div x-show="megaMenuOpen" style="display: none;" @click.away="megaMenuOpen = false"
                class="absolute top-full left-4 right-4 sm:left-6 sm:right-6 lg:left-8 lg:right-8 mt-2 z-[100] bg-white/90 dark:bg-slate-900/90 backdrop-blur-2xl border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl overflow-hidden font-sans"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-4" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-4">

                <!-- Main Content Area -->
                <div class="p-5 sm:p-8 grid grid-cols-1 lg:grid-cols-4 gap-6 sm:gap-8">
                    <!-- Image Cards Area (Takes 3 columns) -->
                    <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

                        <!-- Card 1: Properties -->
                        <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=600&q=80"
                                alt="Properties"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                PROPERTIES</div>
                        </a>

                        <!-- Card 2: Off-Plan -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?auto=format&fit=crop&w=600&q=80"
                                alt="Off-Plan"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                OFF-PLAN</div>
                        </a>

                        <!-- Card 3: Map -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-blue-50 dark:bg-blue-900/20">
                            <div class="absolute inset-0 bg-blue-500/10 group-hover:bg-blue-500/20 transition-colors z-10"
                                style="background-image: radial-gradient(circle, #3B82F6 1px, transparent 1px); background-size: 20px 20px;">
                            </div>
                            <div
                                class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent z-20">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors z-30">
                                MAP SEARCH</div>
                        </a>

                        <!-- Card 4: Agents -->
                        <a href="#"
                            class="group relative rounded-2xl overflow-hidden aspect-[4/3] sm:aspect-square border border-slate-200 dark:border-slate-800 hover:border-blue-500 transition-colors bg-slate-100 dark:bg-slate-800">
                            <img src="https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80"
                                alt="Agents"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-90">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent">
                            </div>
                            <div
                                class="absolute bottom-4 left-4 text-white font-black text-lg sm:text-xl uppercase tracking-wide group-hover:text-blue-400 transition-colors">
                                OUR AGENTS</div>
                        </a>
                    </div>

                    <!-- Side Links (Takes 1 column) -->
                    <div
                        class="flex flex-col justify-between h-full pt-4 lg:pt-0 lg:pl-8 lg:border-l border-slate-200 dark:border-slate-800">
                        <ul class="space-y-4">
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> BECOME A PARTNER</a></li>
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> DEVELOPERS HUB</a></li>
                            <li><a href="#"
                                    class="text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-bold text-[11px] sm:text-sm tracking-widest transition-colors uppercase flex items-center gap-2"><span
                                        class="w-2 h-2 rounded-full bg-blue-500"></span> CAREERS</a></li>
                        </ul>
                        <div class="mt-8 lg:mt-0 pt-4 border-t border-slate-200 dark:border-slate-800 lg:border-none">
                            <a href="#"
                                class="text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 text-[10px] sm:text-xs font-bold tracking-widest transition-colors uppercase">PRIVACY
                                POLICY</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Breadcrumb Bar (fits directly under nav bar) -->
    <div class="bg-slate-900 dark:bg-slate-950 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-4 flex-wrap">
            <!-- Back button -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                class="flex items-center gap-2 text-slate-300 hover:text-white text-xs font-semibold transition-colors whitespace-nowrap shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to All Listings
            </a>

            <!-- Breadcrumb trail -->
            <nav class="flex items-center gap-1 text-xs text-slate-400 overflow-hidden min-w-0" aria-label="Breadcrumb">
                <a href="<?php echo esc_url( home_url('/') ); ?>" class="hover:text-white transition-colors shrink-0">Home</a>
                <span class="text-slate-600 mx-1">/</span>
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="text-blue-400 hover:text-blue-300 transition-colors shrink-0">Marketplace</a>
                <span class="text-slate-600 mx-1">/</span>
                <span class="shrink-0"><?php echo esc_html( $listing_label ); ?></span>
                <span class="text-slate-600 mx-1">/</span>
                <span class="shrink-0"><?php echo esc_html( $location ); ?></span>
                <span class="text-slate-600 mx-1">/</span>
                <span class="text-slate-200 font-semibold truncate"><?php the_title(); ?></span>
            </nav>

            <!-- Right actions -->
            <div class="flex items-center gap-2 shrink-0">
                <!-- Save Button (leave save) -->
                <button type="button" @click="toggleSaveProperty()"
                    :class="isSaved ? 'bg-rose-500/10 border-rose-500/50 text-rose-400' : 'text-slate-300 hover:text-white border-slate-700 hover:border-slate-500'"
                    class="flex items-center gap-1.5 text-xs font-semibold border rounded-lg px-3 py-1.5 transition-all cursor-pointer hover:bg-slate-800">
                    <svg class="w-3.5 h-3.5 transition-colors" :class="isSaved ? 'text-rose-500 fill-current' : 'text-current'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                    </svg>
                    <span x-text="isSaved ? 'Saved' : 'Save'">Save</span>
                </button>

                <!-- Share Button (share to all social media) -->
                <button type="button" @click="shareModalOpen = true"
                    class="flex items-center gap-1.5 text-slate-300 hover:text-white text-xs font-semibold border border-slate-700 hover:border-slate-500 rounded-lg px-3 py-1.5 transition-all cursor-pointer hover:bg-slate-800 shadow-sm">
                    <svg class="w-3.5 h-3.5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                    </svg>
                    <span>Share</span>
                </button>

                <span
                    class="bg-slate-800 border border-slate-700 text-slate-300 text-[10px] font-bold px-2.5 py-1.5 rounded-lg tracking-wider">PID:
                    <?php the_ID(); ?></span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-8">

        <!-- Property Hero: full-width cinematic image -->
        <div class="relative w-full rounded-2xl overflow-hidden mb-3 cursor-pointer" style="height:480px"
            @click="showGallery = true; currentImage = 0">

            <!-- Main image -->
            <img src="<?php $thumb = get_the_post_thumbnail_url(get_the_ID(), 'full'); echo $thumb ? esc_url($thumb) : 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1600&q=80'; ?>"
                alt="<?php the_title_attribute(); ?>"
                class="w-full h-full object-cover transition-transform duration-700 hover:scale-[1.02]">

            <!-- Bottom gradient -->
            <div class="absolute inset-0 pointer-events-none"
                style="background: linear-gradient(to top, rgba(0,0,0,0.82) 0%, rgba(0,0,0,0.35) 45%, transparent 100%);">
            </div>

            <!-- Tags -- top-left -->
            <div class="absolute top-4 left-4 z-20 flex items-center gap-2 flex-wrap">
                <span class="bg-blue-600 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow-lg"><?php echo esc_html( $listing_label ); ?></span>
                <?php if ( $status === 'taken' ) : ?>
                    <span class="bg-rose-600 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow-lg">
                        ● <?php echo ( $listing_type === 'rent' || $listing_type === 'shortlet' ) ? 'Taken / Leased' : 'Sold Out'; ?>
                    </span>
                <?php elseif ( $status === 'under_offer' ) : ?>
                    <span class="bg-amber-500 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow-lg">
                        ● Under Offer
                    </span>
                <?php else : ?>
                    <span class="bg-emerald-600 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow-lg flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                        Available
                    </span>
                <?php endif; ?>
                <span
                    class="bg-emerald-500/90 text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full shadow-lg flex items-center gap-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Registry Verified
                </span>
            </div>

            <!-- Bottom-left: address + title -->
            <div class="absolute bottom-5 left-5 z-20 max-w-[60%]">
                <div class="flex items-center gap-1.5 text-white/70 text-xs mb-2">
                    <svg class="w-3.5 h-3.5 text-blue-400 shrink-0" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    </svg>
                    <?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_location_text', true ) ); ?>
                </div>
                <h1 class="text-white font-black leading-tight drop-shadow-lg" style="font-size:28px;line-height:1.15">
                    <?php the_title(); ?>
                </h1>
            </div>

            <!-- Bottom-right: price card -->
            <div
                class="absolute bottom-5 right-5 z-20 bg-slate-900/85 backdrop-blur-sm border border-slate-700/60 rounded-xl px-5 py-3 text-right shadow-2xl">
                <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest mb-0.5">Price</p>
                <p class="text-emerald-400 font-black text-xl leading-tight">₦<?php echo number_format( (float) get_post_meta( get_the_ID(), 'ofp_price', true ) ); ?></p>
                <?php if ( $listing_type === "rent" || $listing_type === "shortlet" ) : ?>
                    <p class="text-slate-400 text-xs font-semibold">/ <?php echo esc_html( get_post_meta( $post_id, "ofp_price_period", true ) ?: "year" ); ?></p>
                <?php endif; ?>
                <?php if ( ( $listing_type === 'rent' || $listing_type === 'shortlet' ) && $total_package > 0 ) : ?>
                    <div class="mt-1.5 pt-1.5 border-t border-slate-700/70">
                        <span class="text-[9px] font-bold text-slate-400 uppercase tracking-wider block">Total Move-in Package</span>
                        <span class="text-xs font-black text-blue-400">₦<?php echo number_format( $total_package ); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Thumbnail strip -->
        <div class="flex gap-2 mb-8 overflow-x-auto hide-scrollbar pb-1">
            <template x-for="(img, idx) in galleryImages" :key="idx">
                <button type="button" @click="currentImage = idx; showGallery = true"
                    :class="currentImage === idx ? 'border-blue-500 ring-2 ring-blue-500/30' : 'border-transparent hover:border-slate-400'"
                    class="shrink-0 w-24 h-16 rounded-xl overflow-hidden border-2 focus:outline-none transition-all cursor-pointer">
                    <img :src="img" class="w-full h-full object-cover">
                </button>
            </template>
        </div>

        <!-- Layout Grid -->
        <div class="flex flex-col lg:flex-row gap-8 items-start">

            <!-- Main Details -->
            <div class="flex-1 min-w-0">

                <!-- Quick Info Ribbon -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-2xl p-4 border border-slate-200 dark:border-slate-800 shadow-sm flex flex-wrap justify-between items-center gap-4 mb-8">
                    <div class="flex flex-col items-center flex-1 min-w-[80px]">
                        <svg class="w-6 h-6 text-blue-500 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                            </path>
                        </svg>
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Type</span>
                        <span class="font-bold" style="text-transform: capitalize;"><?php echo esc_html( str_replace('-', ' ', get_post_meta( get_the_ID(), 'ofp_property_type', true )) ); ?></span>
                    </div>
                    <?php if ( $listing_type !== 'land' ) : ?>
                    <div class="w-px h-10 bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                    <div class="flex flex-col items-center flex-1 min-w-[80px]">
                        <svg class="w-6 h-6 text-blue-500 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Bedrooms</span>
                        <span class="font-bold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_bedrooms', true ) ?: '-' ); ?> Beds</span>
                    </div>
                    <div class="w-px h-10 bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                    <div class="flex flex-col items-center flex-1 min-w-[80px]">
                        <svg class="w-6 h-6 text-blue-500 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9">
                            </path>
                        </svg>
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Bathrooms</span>
                        <span class="font-bold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_bathrooms', true ) ?: '-' ); ?> Baths</span>
                    </div>
                    <?php endif; ?>
                    <div class="w-px h-10 bg-slate-200 dark:bg-slate-700 hidden sm:block"></div>
                    <div class="flex flex-col items-center flex-1 min-w-[80px]">
                        <svg class="w-6 h-6 text-blue-500 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4">
                            </path>
                        </svg>
                        <span class="text-xs text-slate-500 font-semibold uppercase tracking-wider"><?php echo $listing_type === 'land' ? 'Plot Size' : 'Area Size'; ?></span>
                        <span class="font-bold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_area_sqm', true ) ?: '-' ); ?> SQM</span>
                    </div>
                </div>

                <!-- Tabs -->
                <div
                    class="border-b border-slate-200 dark:border-slate-800 mb-6 flex gap-8 overflow-x-auto hide-scrollbar">
                    <button @click="activeTab = 'overview'"
                        :class="activeTab === 'overview' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                        class="pb-4 font-bold text-sm whitespace-nowrap border-b-2 transition-colors cursor-pointer">Overview</button>
                    <button @click="activeTab = 'features'"
                        :class="activeTab === 'features' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                        class="pb-4 font-bold text-sm whitespace-nowrap border-b-2 transition-colors cursor-pointer">Amenities
                        &amp; Features</button>
                    <button @click="activeTab = 'location'"
                        :class="activeTab === 'location' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                        class="pb-4 font-bold text-sm whitespace-nowrap border-b-2 transition-colors cursor-pointer">Location
                        &amp; Map</button>
                    <?php if ( $allow_installment ) : ?>
                    <button @click="activeTab = 'calculator'"
                        :class="activeTab === 'calculator' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'"
                        class="pb-4 font-bold text-sm whitespace-nowrap border-b-2 transition-colors cursor-pointer">Installment &amp; Mortgage</button>
                    <?php endif; ?>
                </div>

                <!-- Tab Content: Overview -->
                <div x-show="activeTab === 'overview'" class="space-y-6 animate-fade-in">
                    <div>
                        <h2 class="text-xl font-bold mb-4">Property Description</h2>
                        <div class="text-slate-600 dark:text-slate-400 text-sm leading-relaxed space-y-4">
                            <?php the_content(); ?>
                        </div>
                    </div>

                    <div
                        class="my-8 bg-blue-50 dark:bg-slate-900/50 rounded-2xl p-6 sm:p-7 border border-blue-100 dark:border-slate-800 shadow-sm">
                        <h3 class="font-bold mb-4">Property Details</h3>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-y-4 gap-x-2 text-sm">
                            <div><span class="text-slate-500 block mb-1">Property ID:</span><span
                                    class="font-semibold"><?php the_ID(); ?></span></div>
                            <div><span class="text-slate-500 block mb-1">Status:</span><span
                                    class="font-semibold text-emerald-600"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_status', true ) === 'live' ? 'Live / Available' : 'Unavailable' ); ?></span></div>
                            <div><span class="text-slate-500 block mb-1">Title Document:</span><span
                                    class="font-semibold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_title_document', true ) ?: 'N/A' ); ?></span></div>
                            <?php if ( $listing_type !== 'land' ) : ?>
                            <div><span class="text-slate-500 block mb-1">Condition:</span><span
                                    class="font-semibold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_condition', true ) ?: 'N/A' ); ?></span></div>
                            <div><span class="text-slate-500 block mb-1">Furnishing:</span><span
                                    class="font-semibold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_furnishing', true ) ?: 'N/A' ); ?></span></div>
                            <div><span class="text-slate-500 block mb-1">Parking:</span><span class="font-semibold"><?php echo esc_html( get_post_meta( get_the_ID(), 'ofp_parking', true ) ?: '-' ); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Property Video Tour -->
                    <?php if ( ! empty( $video_url ) ) : ?>
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                Property Video Tour
                            </h3>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-800">
                                Walkthrough Video
                            </span>
                        </div>
                        <a href="<?php echo esc_url( $video_url ); ?>" target="_blank" rel="noopener noreferrer"
                            class="relative block aspect-video rounded-2xl overflow-hidden bg-slate-900 group cursor-pointer border border-slate-200 dark:border-slate-800 shadow-md">
                            <img src="<?php echo esc_url( $gallery_urls[0] ); ?>"
                                alt="Property Video Walkthrough"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500 opacity-80">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-black/35"></div>
                            
                            <!-- Play Button -->
                            <div class="absolute inset-0 flex items-center justify-center">
                                <div class="w-16 h-16 rounded-full bg-blue-600 text-white flex items-center justify-center shadow-2xl shadow-blue-600/50 group-hover:scale-110 group-hover:bg-blue-500 transition-all duration-300">
                                    <svg class="w-7 h-7 ml-1" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </div>
                            </div>
                            <div class="absolute bottom-4 left-4 right-4 flex items-center justify-between text-white text-xs">
                                <span class="font-medium text-slate-200">Watch Guided Video Tour</span>
                                <span class="bg-blue-600/90 backdrop-blur-sm px-3 py-1 rounded font-bold">Open Tour &rarr;</span>
                            </div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <!-- Official Registry Certified Card -->
                    <div class="rounded-2xl border border-emerald-800/50 p-5 flex gap-4 items-start"
                        style="background: linear-gradient(135deg, #071a14 0%, #0a2118 60%, #0d2e1e 100%);">
                        <div class="shrink-0 w-12 h-12 rounded-xl flex items-center justify-center"
                            style="background: linear-gradient(135deg, #064e3b, #065f46);">
                            <svg class="w-6 h-6 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-emerald-400 font-bold text-sm mb-1">Official Registry Search Certified</h3>
                            <p class="text-emerald-200/60 text-xs leading-relaxed mb-3">
                                This property has passed our multi-tier title search at the Lagos State Lands Bureau
                                (Alausa). Verified Governor's Consent / Certificate of Occupancy (C of O), free from any
                                dispute, charge, or government excision encumbrance.
                            </p>
                            <div class="flex items-center gap-4 flex-wrap">
                                <span class="flex items-center gap-1.5 text-emerald-400 text-xs font-bold">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Deed Audit Passed
                                </span>
                                <span class="flex items-center gap-1.5 text-emerald-400 text-xs font-bold">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Survey Coordinates Validated
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Financing Teaser linking to Calculator Tab -->
                    <div @click="activeTab = 'calculator'"
                        class="cursor-pointer bg-slate-100 hover:bg-slate-200/80 dark:bg-slate-900 dark:hover:bg-slate-800/80 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 flex items-center justify-between transition-all group shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-600/10 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-900 dark:text-white">Planning to finance this property with a mortgage?</p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">Estimate your monthly repayment from ₦5.2M/mo with standard 16% p.a. rates</p>
                            </div>
                        </div>
                        <span class="text-blue-600 dark:text-blue-400 font-bold text-xs flex items-center gap-1 group-hover:translate-x-1 transition-transform">
                            Calculate <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </span>
                    </div>
                </div>

                <!-- Tab Content: Features -->
                <div x-show="activeTab === 'features'" style="display: none;" class="space-y-6">
                    <h2 class="text-xl font-bold mb-4">Amenities & Features</h2>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php
                        $standard_amenities = [
                            'swimming_pool'   => 'Swimming Pool',
                            'smart_home'      => 'Smart Home Automation',
                            'power_247'       => '24/7 Electricity',
                            'cctv_security'   => 'CCTV & Uniformed Security',
                            'gym'             => 'Gym / Fitness Center',
                            'elevator'        => 'Elevator / Lift',
                            'playground'      => 'Children Play Area',
                            'bq'              => 'Boys Quarters (BQ)',
                            'water_treatment' => 'Water Treatment Plant',
                            'fitted_kitchen'  => 'Fully Fitted Kitchen',
                        ];
                        foreach ( $standard_amenities as $key => $lbl ) :
                            $is_active = in_array( $key, $amenities, true );
                        ?>
                            <div class="flex items-center gap-3 <?php echo $is_active ? 'text-slate-900 dark:text-white' : 'text-slate-400 opacity-40'; ?>">
                                <div class="w-8 h-8 rounded-full <?php echo $is_active ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-600 dark:text-blue-400' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'; ?> flex items-center justify-center">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                </div>
                                <span class="text-sm font-medium"><?php echo esc_html( $lbl ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Tab Content: Location -->
                <div x-show="activeTab === 'location'" style="display: none;" class="space-y-6">
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                        <h2 class="text-xl font-bold">Location &amp; Map</h2>
                        <?php if ( ! empty( $raw_address ) ) : ?>
                            <span class="text-xs text-slate-500 dark:text-slate-400 font-medium flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <?php echo esc_html( $raw_address ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div
                        class="relative w-full h-[500px] rounded-3xl overflow-hidden border border-slate-200 dark:border-slate-800 shadow-lg bg-slate-100 dark:bg-slate-900">
                        <iframe
                            class="w-full h-full border-0"
                            src="<?php echo esc_url( $map_embed_url ); ?>"
                            loading="lazy"
                            allowfullscreen>
                        </iframe>

                        <!-- Floating Location Badge -->
                        <div class="absolute top-4 right-4 z-10 bg-slate-900/90 backdrop-blur-md border border-slate-700/60 rounded-xl px-4 py-2.5 shadow-xl text-white flex items-center gap-2.5 max-w-[85%] sm:max-w-sm">
                            <div class="relative flex items-center justify-center shrink-0">
                                <div class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></div>
                                <div class="absolute w-2 h-2 rounded-full bg-emerald-500"></div>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-bold leading-tight truncate"><?php echo esc_html( $raw_address ?: 'Location on Request' ); ?></p>
                                <p class="text-[10px] text-slate-400">Nigeria</p>
                            </div>
                        </div>

                        <!-- External Maps link button -->
                        <div class="absolute bottom-4 right-4 z-10">
                            <a href="<?php echo esc_url( $map_external_url ); ?>" target="_blank" rel="noopener noreferrer"
                                class="bg-white/95 dark:bg-slate-900/95 backdrop-blur-md hover:bg-white dark:hover:bg-slate-800 text-slate-800 dark:text-white text-xs font-bold px-3.5 py-2 rounded-xl border border-slate-200 dark:border-slate-700 shadow-lg flex items-center gap-1.5 transition-all cursor-pointer">
                                <svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                                Open in Google Maps
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ( $allow_installment ) : ?>
                <!-- Tab Content: Mortgage / Installment Estimator -->
                <div x-show="activeTab === 'calculator'" style="display: none;" class="space-y-6 animate-fade-in"
                    x-data="{
                        price: <?php echo (float) $price; ?>,
                        downPaymentPercent: 20,
                        loanYears: 15,
                        rate: 0.16,
                        get downPayment() {
                            return Math.round((this.price * this.downPaymentPercent) / 100);
                        },
                        get principal() {
                            return this.price - this.downPayment;
                        },
                        get totalMonths() {
                            return this.loanYears * 12;
                        },
                        get monthlyPayment() {
                            const r = this.rate / 12;
                            const n = this.totalMonths;
                            if (r === 0 || n === 0) return 0;
                            const factor = Math.pow(1 + r, n);
                            return Math.round(this.principal * (r * factor) / (factor - 1));
                        }
                    }">

                    <!-- Calculator Card matching reference image -->
                    <div class="rounded-3xl p-6 sm:p-8 border border-slate-800 shadow-2xl text-white"
                        style="background-color: #0B132B;">

                        <!-- Header with Icon & Subtitle -->
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-12 h-12 rounded-2xl bg-blue-950/60 border border-blue-600/30 flex items-center justify-center text-blue-500 shrink-0 shadow-inner">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                                    </path>
                                </svg>
                            </div>
                            <div>
                                <h2 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Mortgage &amp; Installment Estimator</h2>
                                <p class="text-xs sm:text-sm text-slate-400 font-medium mt-0.5">16% p.a. standard commercial mortgage / installment rate</p>
                            </div>
                        </div>

                        <!-- Slider 1: Down Payment -->
                        <div class="mb-7">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-bold text-white text-sm sm:text-base">
                                    Down Payment (<span x-text="downPaymentPercent + '%'"></span>)
                                </span>
                                <span class="font-extrabold text-[#38bdf8] sm:text-blue-500 text-sm sm:text-base tracking-tight"
                                    x-text="'₦' + downPayment.toLocaleString()">
                                </span>
                            </div>
                            <div class="relative py-1 flex items-center">
                                <input type="range" min="10" max="70" step="5" x-model.number="downPaymentPercent"
                                    :style="`background: linear-gradient(to right, #2563eb 0%, #2563eb ${((downPaymentPercent - 10) / (70 - 10)) * 100}%, #ffffff ${((downPaymentPercent - 10) / (70 - 10)) * 100}%, #ffffff 100%)`"
                                    class="mortgage-range">
                            </div>
                            <div class="flex justify-between text-[11px] text-slate-400 mt-2 font-medium">
                                <span>Min: 10%</span>
                                <span>Max: 70%</span>
                            </div>
                        </div>

                        <!-- Slider 2: Loan Duration -->
                        <div class="mb-8">
                            <div class="flex justify-between items-center mb-3">
                                <span class="font-bold text-white text-sm sm:text-base">
                                    Loan Duration (<span x-text="loanYears + ' Years'"></span>)
                                </span>
                                <span class="font-extrabold text-white text-sm sm:text-base"
                                    x-text="(loanYears * 12) + ' Months'">
                                </span>
                            </div>
                            <div class="relative py-1 flex items-center">
                                <input type="range" min="5" max="30" step="1" x-model.number="loanYears"
                                    :style="`background: linear-gradient(to right, #2563eb 0%, #2563eb ${((loanYears - 5) / (30 - 5)) * 100}%, #ffffff ${((loanYears - 5) / (30 - 5)) * 100}%, #ffffff 100%)`"
                                    class="mortgage-range">
                            </div>
                            <div class="flex justify-between text-[11px] text-slate-400 mt-2 font-medium">
                                <span>5 Years (60 Months)</span>
                                <span>30 Years (360 Months)</span>
                            </div>
                        </div>

                        <!-- Estimated Monthly Payment Box -->
                        <div class="rounded-2xl border border-blue-900/60 bg-[#071329] py-6 px-4 sm:px-6 text-center shadow-inner">
                            <p class="text-[11px] sm:text-xs font-bold text-slate-400 uppercase tracking-widest mb-1.5">
                                ESTIMATED MONTHLY PAYMENT
                            </p>
                            <p class="text-3xl sm:text-4xl font-extrabold text-[#38bdf8] sm:text-blue-500 tracking-tight"
                                x-text="'₦' + monthlyPayment.toLocaleString() + '/mo'">
                            </p>
                        </div>

                        <!-- Key Metrics Breakdown -->
                        <div class="mt-6 pt-6 border-t border-slate-800/80 grid grid-cols-1 sm:grid-cols-3 gap-3 text-center">
                            <div class="bg-slate-900/60 rounded-xl p-3 border border-slate-800/60">
                                <span class="text-slate-400 text-xs block mb-1">Property Value</span>
                                <span class="font-bold text-sm text-white">₦<?php echo number_format( (float) $price ); ?></span>
                            </div>
                            <div class="bg-slate-900/60 rounded-xl p-3 border border-slate-800/60">
                                <span class="text-slate-400 text-xs block mb-1">Loan Principal</span>
                                <span class="font-bold text-sm text-white" x-text="'₦' + principal.toLocaleString()"></span>
                            </div>
                            <div class="bg-slate-900/60 rounded-xl p-3 border border-slate-800/60">
                                <span class="text-slate-400 text-xs block mb-1">Standard Rate</span>
                                <span class="font-bold text-sm text-emerald-400">16.0% Fixed p.a.</span>
                            </div>
                        </div>

                        <!-- Advisory & Pre-Approval Partner Action -->
                        <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-slate-400 bg-blue-950/20 rounded-xl p-3.5 border border-blue-900/30">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Pre-approved mortgage available through NMRC &amp; accredited commercial banks.</span>
                            </div>
                            <button @click="alert('Pre-approval inquiry submitted! A verified mortgage advisor will contact you within 24 hours.')"
                                class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-4 py-2 rounded-xl text-xs transition-all whitespace-nowrap cursor-pointer shadow">
                                Request Pre-Approval
                            </button>
                        </div>

                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- Sidebar -->
            <div class="w-full lg:w-[380px] xl:w-[420px] shrink-0 space-y-4 sticky top-24">

                <!-- Agent Card -->
                <div
                    class="bg-[#0B132B] rounded-3xl p-6 border border-slate-800 shadow-2xl shadow-blue-950/30 text-white">
                    <!-- Agent Info Header -->
                    <div class="flex items-center gap-4 mb-6">
                        <div class="relative">
                            <div class="w-16 h-16 rounded-2xl bg-blue-900/60 ring-2 ring-blue-500/80 flex items-center justify-center text-white font-black text-2xl uppercase tracking-wider"><?php echo esc_html( strtoupper( substr( $agent_name, 0, 1 ) ) ); ?></div>
                            <!-- Verified Shield Badge -->
                            <div
                                class="absolute -bottom-1 -right-1 w-5 h-5 bg-blue-600 rounded-full border-2 border-[#0B132B] flex items-center justify-center text-white shadow">
                                <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" stroke-width="2.5"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                                    </path>
                                </svg>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-extrabold text-lg text-white tracking-tight"><?php echo esc_html( $agent_name ); ?></h3>
                                <span
                                    class="bg-blue-900/60 text-blue-400 border border-blue-500/30 text-[10px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wide">NIESV</span>
                            </div>
                            <p class="text-xs text-slate-400 font-medium mt-0.5"><?php echo esc_html( $agent_company ); ?></p>
                            <div class="flex items-center gap-1.5 text-xs text-emerald-400 font-medium mt-1">
                                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                                <span>Responds in &lt; 15 mins</span>
                            </div>
                        </div>
                    </div>

                    <!-- Book Inspection Tour Header -->
                    <div class="border-t border-slate-800/80 pt-5">
                        <div class="flex items-center gap-2 text-xs sm:text-sm font-extrabold uppercase tracking-wider text-slate-100 mb-3">
                            <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            <span>SCHEDULE INSPECTION &amp; INQUIRY</span>
                        </div>

                        <!-- Inspection Schedule Notice Badge -->
                        <div class="bg-blue-950/40 border border-blue-900/40 rounded-xl p-2.5 mb-3.5 text-[11px] text-slate-300">
                            <div class="flex items-center gap-1.5 font-bold text-blue-400 mb-0.5">
                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <span><?php echo esc_html( $insp_days ); ?> &bull; <?php echo esc_html( $insp_hours ); ?></span>
                            </div>
                            <?php if ( $insp_notice ) : ?>
                                <p class="text-slate-400 text-[10px] mt-0.5 leading-tight">Notice: <?php echo esc_html( $insp_notice ); ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Tour Type Selector Tabs (Physical vs WhatsApp Video Call) -->
                        <div class="bg-[#050B18] p-1 rounded-2xl flex gap-1 border border-slate-800/90 mb-4">
                            <button type="button" @click="tourType = 'physical'"
                                :class="tourType === 'physical' ? 'bg-blue-600 text-white font-bold shadow-md border border-blue-400/50' : 'text-slate-400 hover:text-white font-semibold border border-transparent'"
                                class="flex-1 py-2.5 px-3 rounded-xl flex items-center justify-center gap-1.5 text-xs transition-all cursor-pointer">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6m6 0l-3-3m3 3l-3 3"></path></svg>
                                <span>Physical Tour</span>
                            </button>
                            <button type="button" @click="tourType = 'whatsapp_video'"
                                :class="tourType === 'whatsapp_video' ? 'bg-[#25D366] text-white font-bold shadow-md border border-emerald-400/50' : 'text-slate-400 hover:text-white font-semibold border border-transparent'"
                                class="flex-1 py-2.5 px-3 rounded-xl flex items-center justify-center gap-1.5 text-xs transition-all cursor-pointer">
                                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z" :fill="tourType === 'whatsapp_video' ? '#ffffff' : '#25D366'"/>
                                    <path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" :fill="tourType === 'whatsapp_video' ? '#25D366' : '#ffffff'"/>
                                </svg>
                                <span>WhatsApp Video</span>
                            </button>
                        </div>

                        <!-- Success Message Banner -->
                        <div x-show="inquirySuccess" x-cloak class="space-y-3">
                            <div class="p-4 bg-emerald-950/80 border border-emerald-500/40 rounded-2xl text-emerald-300 text-xs font-semibold leading-relaxed">
                                <div class="flex items-center gap-2 font-bold text-sm mb-1 text-emerald-200">
                                    <svg class="w-4 h-4 shrink-0 text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Inspection Request Logged!
                                </div>
                                Your request and requirements have been sent to <?php echo esc_html( $agent_name ); ?>. You will be contacted shortly to confirm access.
                            </div>

                            <a :href="cleanWhatsAppUrl" target="_blank"
                                class="w-full bg-[#25D366] hover:bg-[#20ba59] text-white font-semibold rounded-xl py-2.5 px-4 flex items-center justify-center gap-2 text-xs shadow-md shadow-emerald-950/30 transition-all cursor-pointer">
                                <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z" fill="#ffffff"/>
                                    <path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#25D366"/>
                                </svg>
                                <span>Continue to WhatsApp with Agent</span>
                            </a>

                            <button type="button" @click="inquirySuccess = false; inquiryMessage = ''"
                                class="w-full text-center text-xs text-slate-400 hover:text-white py-2 transition-colors cursor-pointer">
                                Submit another request
                            </button>
                        </div>

                        <!-- Active Form View -->
                        <div x-show="!inquirySuccess" class="space-y-3">
                            <!-- Error Message Banner -->
                            <div x-show="inquiryError" x-cloak class="p-3 bg-rose-950/80 border border-rose-500/40 rounded-xl text-rose-300 text-xs font-semibold" x-text="inquiryError"></div>

                            <!-- Date & Time Row -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[11px] font-semibold text-slate-400 mb-1.5 block">Preferred Date</label>
                                    <input type="date" x-model="inquiryDate" required
                                        class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-blue-500 cursor-pointer [color-scheme:dark]">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold text-slate-400 mb-1.5 block">Preferred Time</label>
                                    <div class="relative">
                                        <select x-model="inquiryTime"
                                            class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-3 py-2.5 text-xs text-white appearance-none cursor-pointer focus:outline-none focus:border-blue-500 pr-7">
                                            <option value="10:00 AM">10:00 AM</option>
                                            <option value="11:30 AM">11:30 AM</option>
                                            <option value="01:00 PM">01:00 PM</option>
                                            <option value="02:30 PM">02:30 PM</option>
                                            <option value="04:00 PM">04:00 PM</option>
                                        </select>
                                        <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-slate-400">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Full Name -->
                            <div>
                                <label class="text-[11px] font-semibold text-slate-400 mb-1.5 block">Your Full Name</label>
                                <input type="text" x-model="inquiryName" placeholder="e.g. Chukwuma Adebayo" required
                                    class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-4 py-3 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                            </div>

                            <!-- WhatsApp Phone & Email -->
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-[11px] font-semibold text-slate-400 mb-1.5 block">WhatsApp Phone</label>
                                    <input type="tel" x-model="inquiryPhone" placeholder="0801 234 5678" required
                                        class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-3.5 py-3 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold text-slate-400 mb-1.5 block">Email Address (Optional)</label>
                                    <input type="email" x-model="inquiryEmail" placeholder="you@email.com"
                                        class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-3.5 py-3 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors">
                                </div>
                            </div>

                            <!-- Detailed Request Textarea -->
                            <div>
                                <label class="text-[11px] font-semibold text-slate-400 mb-1.5 flex items-center justify-between">
                                    <span>Detailed Request / Specific Notes</span>
                                    <span class="text-[10px] text-blue-400 font-normal">Required for agent dispatch</span>
                                </label>
                                <textarea x-model="inquiryMessage" rows="3" required
                                    placeholder="Tell the agent what you are looking for (e.g. your budget, move-in date, key questions, or preferences)..."
                                    class="w-full bg-[#050B18] border border-slate-800/90 rounded-xl px-4 py-3 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition-colors resize-none leading-relaxed"></textarea>
                            </div>

                            <!-- Primary Submit Button -->
                            <div class="pt-1">
                                <button type="button" @click="submitInquiry('booking')" :disabled="inquiryLoading"
                                    class="w-full bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white font-bold py-3.5 rounded-2xl transition-all shadow-lg shadow-blue-600/30 text-sm tracking-wide active:scale-[0.99] flex items-center justify-center gap-2 cursor-pointer">
                                    <span x-show="!inquiryLoading || actionLoading !== 'booking'">Book Inspection Tour</span>
                                    <span x-show="inquiryLoading && actionLoading === 'booking'" x-cloak class="flex items-center gap-2">
                                        <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                        Submitting Booking...
                                    </span>
                                </button>
                            </div>

                            <!-- Divider: Or Connect Directly -->
                            <div class="relative flex py-2 items-center">
                                <div class="flex-grow border-t border-slate-800"></div>
                                <span class="flex-shrink mx-3 text-[10px] font-bold uppercase tracking-wider text-slate-500">Or Connect With Agent</span>
                                <div class="flex-grow border-t border-slate-800"></div>
                            </div>

                            <!-- Direct WhatsApp & Call Buttons (Under Form & Textarea with silent lead capture) -->
                            <div class="grid grid-cols-2 gap-2.5">
                                <button type="button" @click="submitInquiry('whatsapp')" :disabled="inquiryLoading"
                                    class="bg-[#25D366] hover:bg-[#20ba59] disabled:opacity-50 text-white font-semibold rounded-xl py-2 px-2.5 flex items-center justify-center gap-1.5 text-xs shadow-md shadow-emerald-950/30 transition-all cursor-pointer">
                                    <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z" fill="#ffffff"/>
                                        <path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#25D366"/>
                                    </svg>
                                    <span x-show="!inquiryLoading || actionLoading !== 'whatsapp'" class="truncate">WhatsApp Agent</span>
                                    <span x-show="inquiryLoading && actionLoading === 'whatsapp'" x-cloak>Logging...</span>
                                </button>

                                <button type="button" @click="submitInquiry('call')" :disabled="inquiryLoading"
                                    class="bg-slate-800/80 hover:bg-slate-700/80 disabled:opacity-50 border border-slate-700/70 text-white font-semibold rounded-xl py-2 px-2.5 flex items-center justify-center gap-1.5 text-xs transition-all cursor-pointer">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                    </svg>
                                    <span x-show="!inquiryLoading || actionLoading !== 'call'" class="truncate">Call Agent</span>
                                    <span x-show="inquiryLoading && actionLoading === 'call'" x-cloak>Connecting...</span>
                                </button>
                            </div>
                            <p class="text-[10px] text-center text-slate-500 pt-1">
                                WhatsApp &amp; Call automatically log your request for instant priority inspection response.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Buyer Safety Diligence Tips Card -->
                <div class="!mt-6 rounded-2xl border border-amber-800/40 p-4 shadow-xl font-system"
                    style="background: linear-gradient(145deg, #180d05 0%, #110802 100%); font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, 'Helvetica Neue', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <h4 class="text-amber-400 font-bold tracking-tight"
                            style="font-size: 12px; font-weight: 700;">Buyer Safety Diligence Tips</h4>
                        <div class="shrink-0 text-amber-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                </path>
                            </svg>
                        </div>
                    </div>
                    <p class="text-amber-500/90 leading-relaxed"
                        style="font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, 'Helvetica Neue', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'; font-size: 11px; font-style: normal; font-weight: 400; font-variant-caps: normal; font-variant-east-asian: normal; font-variant-ligatures: normal; font-variant-numeric: normal;">
                        Never pay upfront fees before inspection. All purchase payments are secured through
                        institutional escrow accounts pending title deed transfer.
                    </p>
                </div>
            </div>
        </div>
    </main>

    <!-- Similar Properties (Bottom section) -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 py-12 border-t border-slate-200 dark:border-slate-800">
        <h2 class="text-2xl font-black mb-8 text-slate-900 dark:text-white">Similar Properties you may like</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php
            $similar_query = new WP_Query([
                'post_type'      => 'ofp_property',
                'post_status'    => 'publish',
                'posts_per_page' => 3,
                'post__not_in'   => [ $post_id ],
                'meta_query'     => [
                    [
                        'key'     => 'ofp_property_type',
                        'value'   => $property_type,
                        'compare' => '=',
                    ]
                ]
            ]);
            if ( ! $similar_query->have_posts() ) {
                $similar_query = new WP_Query([
                    'post_type'      => 'ofp_property',
                    'post_status'    => 'publish',
                    'posts_per_page' => 3,
                    'post__not_in'   => [ $post_id ],
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ]);
            }
            if ( $similar_query->have_posts() ) :
                while ( $similar_query->have_posts() ) : $similar_query->the_post();
                    $s_price = (float) get_post_meta( get_the_ID(), 'ofp_price', true );
                    $s_lt    = get_post_meta( get_the_ID(), 'ofp_listing_type', true );
                    $s_beds  = get_post_meta( get_the_ID(), 'ofp_bedrooms', true );
                    $s_baths = get_post_meta( get_the_ID(), 'ofp_bathrooms', true );
                    $s_sqm   = get_post_meta( get_the_ID(), 'ofp_area_sqm', true );
                    $s_loc   = get_post_meta( get_the_ID(), 'ofp_location_text', true );
            ?>
            <div class="bg-white dark:bg-slate-900 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-800 shadow-sm hover:shadow-xl transition-all group flex flex-col h-full relative">
                <div class="relative h-48 overflow-hidden">
                    <a href="<?php the_permalink(); ?>">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <?php the_post_thumbnail( 'large', [ 'class' => 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-500' ] ); ?>
                        <?php else : ?>
                            <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=800&q=80" alt="<?php the_title_attribute(); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                        <?php endif; ?>
                    </a>
                    <div class="absolute inset-0 bg-gradient-to-t from-slate-900/90 via-transparent to-transparent pointer-events-none"></div>
                    <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                        <div class="text-white font-black text-xl drop-shadow-md">₦<?php echo number_format( $s_price ); ?></div>
                    </div>
                </div>
                <div class="p-4 flex-1 flex flex-col">
                    <a href="<?php the_permalink(); ?>" class="text-sm font-bold text-slate-900 dark:text-white leading-snug hover:text-blue-600 dark:hover:text-blue-400 mb-2 line-clamp-1">
                        <?php the_title(); ?>
                    </a>
                    <div class="text-xs text-slate-500 flex items-center gap-1 mb-4">
                        <svg class="w-3 h-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                        <?php echo esc_html( $s_loc ); ?>
                    </div>
                    <div class="flex justify-between text-[10px] uppercase font-bold text-slate-500 mt-auto border-t border-slate-100 dark:border-slate-800 pt-3">
                        <?php if ( $s_lt !== 'land' ) : ?><span><?php echo esc_html( $s_beds ?: '-' ); ?> Beds</span><?php endif; ?>
                        <?php if ( $s_lt !== 'land' ) : ?><span><?php echo esc_html( $s_baths ?: '-' ); ?> Baths</span><?php endif; ?>
                        <span><?php echo esc_html( $s_sqm ?: '-' ); ?> SQM</span>
                    </div>
                </div>
            </div>
            <?php
                endwhile;
                wp_reset_postdata();
            endif;
            ?>
        </div>
    </section>

    <!-- Fullscreen Image Gallery Modal (Alpine.js controlled) -->
    <div x-show="showGallery" x-cloak class="fixed inset-0 z-[100] bg-black/95 flex items-center justify-center p-4">
        <!-- Close Button -->
        <button @click="showGallery = false" class="absolute top-6 right-6 text-white hover:text-red-500 z-50">
            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        <!-- Next/Prev Buttons -->
        <button @click="currentImage = currentImage > 0 ? currentImage - 1 : (galleryImages.length - 1)"
            class="absolute left-4 top-1/2 -translate-y-1/2 p-3 bg-white/10 hover:bg-white/20 rounded-full text-white backdrop-blur">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </button>
        <button @click="currentImage = currentImage < (galleryImages.length - 1) ? currentImage + 1 : 0"
            class="absolute right-4 top-1/2 -translate-y-1/2 p-3 bg-white/10 hover:bg-white/20 rounded-full text-white backdrop-blur">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>

        <!-- Current Image -->
        <div class="max-w-5xl w-full max-h-[85vh] relative flex items-center justify-center">
            <img :src="galleryImages[currentImage]" class="max-h-full max-w-full object-contain rounded-lg shadow-2xl">
        </div>

        <!-- Image Counter -->
        <div class="absolute bottom-6 text-white font-bold bg-black/50 px-4 py-2 rounded-full backdrop-blur">
            <span x-text="currentImage + 1"></span> / <span x-text="galleryImages.length"></span>
        </div>
    </div>

    <!-- Social Media Share Modal (Alpine.js controlled) -->
    <div x-show="shareModalOpen" x-cloak class="fixed inset-0 z-[110] flex items-center justify-center p-4"
        @keydown.escape.window="shareModalOpen = false" style="display: none;">
        
        <!-- Backdrop Overlay -->
        <div class="fixed inset-0 bg-slate-950/75 backdrop-blur-sm transition-opacity"
             @click="shareModalOpen = false"></div>

        <!-- Modal Box -->
        <div class="relative bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-7 max-w-md w-full shadow-2xl z-10 text-slate-900 dark:text-white"
             @click.stop>
            
            <!-- Modal Header -->
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-blue-50 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center shadow-inner">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-black text-lg text-slate-900 dark:text-white tracking-tight">Share Property</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Share to your social networks &amp; contacts</p>
                    </div>
                </div>
                <button type="button" @click="shareModalOpen = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1.5 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Property Thumbnail & Info Preview -->
            <div class="my-4 p-3 bg-slate-50 dark:bg-slate-800/60 rounded-2xl flex items-center gap-3 border border-slate-100 dark:border-slate-800">
                <img src="<?php echo esc_url( !empty($gallery_urls[0]) ? $gallery_urls[0] : '' ); ?>" alt="" class="w-14 h-14 rounded-xl object-cover shrink-0">
                <div class="min-w-0 flex-1">
                    <h4 class="font-bold text-xs text-slate-900 dark:text-white truncate"><?php echo esc_html( get_the_title() ); ?></h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate"><?php echo esc_html( $location ); ?></p>
                    <p class="text-xs font-black text-emerald-600 dark:text-emerald-400 mt-0.5">₦<?php echo number_format( (float) $price ); ?></p>
                </div>
            </div>

            <!-- Social Media Share Grid -->
            <div class="grid grid-cols-4 gap-2.5 my-5">
                <!-- WhatsApp -->
                <a :href="'https://api.whatsapp.com/send?text=' + encodeURIComponent(shareTitle + '\n' + window.location.href)"
                   target="_blank" rel="noopener noreferrer"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:hover:bg-emerald-900/50 text-emerald-700 dark:text-emerald-400 border border-emerald-200/50 dark:border-emerald-800/40 transition-all group">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-10 h-10 shrink-0" viewBox="0 0 24 24" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2z" fill="#25D366"/>
                            <path d="M17.84 16.03c-.24.68-1.2 1.26-1.98 1.42-.53.11-1.22.2-3.56-.76-2.99-1.24-4.91-4.28-5.06-4.48-.15-.2-1.2-1.6-1.2-3.05s.76-2.16 1.03-2.46c.27-.3.59-.37.78-.37.2 0 .39 0 .56.01.18.01.42-.07.66.5.24.58.83 2.03.9 2.18.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.18-.32.4-.45.53-.15.15-.31.31-.13.61.18.3.8 1.32 1.72 2.14 1.18 1.05 2.18 1.38 2.48 1.53.3.15.48.13.66-.08.18-.2.76-.89.96-1.19.2-.3.4-.25.66-.15.27.1 1.72.81 2.01.96.3.15.49.22.56.35.07.13.07.73-.17 1.41z" fill="#ffffff"/>
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold">WhatsApp</span>
                </a>

                <!-- Facebook -->
                <a :href="'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(window.location.href)"
                   target="_blank" rel="noopener noreferrer"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/40 dark:hover:bg-blue-900/50 text-blue-700 dark:text-blue-400 border border-blue-200/50 dark:border-blue-800/40 transition-all group">
                    <div class="w-10 h-10 rounded-full bg-[#1877F2] text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">Facebook</span>
                </a>

                <!-- X (Twitter) -->
                <a :href="'https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareTitle) + '&url=' + encodeURIComponent(window.location.href)"
                   target="_blank" rel="noopener noreferrer"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800/60 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition-all group">
                    <div class="w-10 h-10 rounded-full bg-black dark:bg-white text-white dark:text-black flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">X (Twitter)</span>
                </a>

                <!-- Telegram -->
                <a :href="'https://t.me/share/url?url=' + encodeURIComponent(window.location.href) + '&text=' + encodeURIComponent(shareTitle)"
                   target="_blank" rel="noopener noreferrer"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-sky-50 hover:bg-sky-100 dark:bg-sky-950/40 dark:hover:bg-sky-900/50 text-sky-700 dark:text-sky-400 border border-sky-200/50 dark:border-sky-800/40 transition-all group">
                    <div class="w-10 h-10 rounded-full bg-[#229ED9] text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">Telegram</span>
                </a>

                <!-- LinkedIn -->
                <a :href="'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(window.location.href)"
                   target="_blank" rel="noopener noreferrer"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-blue-50 hover:bg-blue-100 dark:bg-blue-950/40 dark:hover:bg-blue-900/50 text-blue-700 dark:text-blue-400 border border-blue-200/50 dark:border-blue-800/40 transition-all group">
                    <div class="w-10 h-10 rounded-full bg-[#0A66C2] text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.46 8.76a1.45 1.45 0 0 0 1.46-1.45 1.46 1.46 0 0 0-1.46-1.46 1.46 1.46 0 0 0-1.46 1.46c0 .8.65 1.45 1.46 1.45m1.39 9.74v-8.37H5.07v8.37h2.78z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">LinkedIn</span>
                </a>

                <!-- Email -->
                <a :href="'mailto:?subject=' + encodeURIComponent(shareTitle) + '&body=' + encodeURIComponent('Check out this verified property listing: ' + window.location.href)"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/40 dark:hover:bg-amber-900/50 text-amber-700 dark:text-amber-400 border border-amber-200/50 dark:border-amber-800/40 transition-all group">
                    <div class="w-10 h-10 rounded-full bg-amber-500 text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">Email</span>
                </a>

                <!-- Device Native Share -->
                <button type="button" @click="shareNative()"
                   class="flex flex-col items-center gap-1.5 p-2.5 rounded-2xl bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-400 border border-indigo-200/50 dark:border-indigo-800/40 transition-all group cursor-pointer col-span-2">
                    <div class="w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    </div>
                    <span class="text-[10px] font-bold">Share via Device Apps</span>
                </button>
            </div>

            <!-- Direct Copy Link Section -->
            <div class="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800">
                <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">Direct Listing URL</label>
                <div class="flex items-center gap-2">
                    <input type="text" readonly :value="window.location.href"
                           class="w-full bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-3 py-2 text-xs text-slate-600 dark:text-slate-300 select-all outline-none">
                    <button type="button" @click="copyShareLink()"
                            class="px-4 py-2 rounded-xl font-bold text-xs bg-blue-600 hover:bg-blue-500 text-white transition-all flex items-center gap-1.5 shrink-0 cursor-pointer shadow">
                        <span x-show="!shareCopied">Copy</span>
                        <span x-show="shareCopied" x-cloak class="flex items-center gap-1 text-emerald-300">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            Copied!
                        </span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-400 mt-16"
        style="font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue','Noto Sans',Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji';font-size:12px;font-weight:400">
        <!-- Glass Divider -->
        <div class="border-t border-slate-200 dark:border-slate-800"></div>
        <!-- Top Subscription Area -->
        <div class="border-b border-slate-200 dark:border-slate-800 py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-6">
                <div>
                    <h3 style="font-size:16px;font-weight:700" class="text-slate-900 dark:text-white mb-2">Get
                        Off-Market Real Estate Alerts & Price Drops</h3>
                    <p class="text-slate-500 dark:text-slate-400">Join over 24,000 verified investors and homebuyers
                        receiving
                        weekly curated deals in Nigeria.</p>
                </div>
                <form class="flex w-full md:w-auto gap-4">
                    <input type="email" placeholder="Enter your email address"
                        class="w-full md:w-72 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-4 py-3 text-slate-900 dark:text-white focus:outline-none focus:border-blue-500"
                        style="font-size:12px">
                    <button type="button"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-3 rounded-lg transition-colors cursor-pointer whitespace-nowrap flex items-center gap-2">
                        Subscribe <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>

        <!-- Main Footer Links -->
        <div class="py-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_1fr] gap-8 lg:gap-10">
                    <!-- Column 1 -->
                    <div>
                        <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center gap-2 mb-6">
                            <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                    </path>
                                </svg>
                            </div>
                            <span style="font-size:16px;font-weight:700"
                                class="tracking-tight text-slate-900 dark:text-white"><?php echo esc_html( OFP_Brand::public_name_text( 'Bofast Homes' ) ); ?></span>
                        </a>
                        <p class="text-slate-500 dark:text-slate-400 leading-relaxed mb-6">
                            Nigeria's leading verified real estate marketplace and integrated broker CRM portal. Fast,
                            transparent property acquisitions.
                        </p>
                        <div class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <div class="flex items-start gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                    </path>
                                </svg>
                                <span>Plot 14 Admiralty Way, Lekki Phase 1, Lagos</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z">
                                    </path>
                                </svg>
                                <span>+234 (01) 888 4000</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                    </path>
                                </svg>
                                <span>support@propertyportal.ng</span>
                            </div>
                        </div>
                    </div>
                    <!-- Column 2 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">TOP LOCATIONS</h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Lekki Phase 1</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Ikoyi</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Victoria Island</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Maitama Abuja</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Ikeja GRA</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Properties in
                                    Banana Island</a>
                            </li>
                        </ul>
                    </div>
                    <!-- Column 3 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">POPULAR CATEGORIES</h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Houses
                                    for Sale in Lekki</a></li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Flats &
                                    Apartments for Rent</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Serviced Luxury
                                    Short Lets</a>
                            </li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Prime
                                    Residential Lands (C of
                                    O)</a></li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Grade-A
                                    Commercial Offices</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Distressed /
                                    Below-Market
                                    Deals</a></li>
                        </ul>
                    </div>
                    <!-- Column 4 -->
                    <div>
                        <h4 style="font-weight:700"
                            class="text-slate-900 dark:text-white mb-6 uppercase tracking-wider">CRM PORTAL & AGENTS
                        </h4>
                        <ul class="space-y-3 text-slate-500 dark:text-slate-400 text-[12px]">
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Agent /
                                    Broker CRM Login</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Developer
                                    Project Showcases</a>
                            </li>
                            <li><a href="#" class="hover:text-slate-900 dark:hover:text-white transition-colors">Land
                                    Title Verification
                                    Service</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Inspection
                                    Scheduling API</a>
                            </li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">Mortgage &
                                    Financing
                                    Calculators</a></li>
                            <li><a href="#"
                                    class="hover:text-slate-900 dark:hover:text-white transition-colors">WordPress
                                    Plugin Integration
                                    Docs</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-slate-200 dark:border-slate-800 py-6">
            <div
                class="max-w-7xl mx-auto px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-4 text-slate-500 dark:text-slate-500">
                <div class="flex items-center gap-2 text-emerald-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>All listings strictly verified against state land registry archives.</span>
                </div>
                <p><?php echo OFP_Brand::public_copyright(); ?></p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>

</html>
