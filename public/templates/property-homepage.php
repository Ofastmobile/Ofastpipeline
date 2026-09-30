<!DOCTYPE html>
<html lang="en"
    x-data="{
        mobileMenu: false,
        megaMenuOpen: false,
        darkMode: document.documentElement.classList.contains('dark'),
        toggleDark() { ofpToggleTheme(); }
    }">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_title('|', true, 'right'); ?></title>
    <script>
        (function() {
            var theme = localStorage.getItem('ofp_theme');
            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (theme === 'dark' || (!theme && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();

        function ofpToggleTheme() {
            var html = document.documentElement;
            var isDark = html.classList.toggle('dark');
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
            window.dispatchEvent(new CustomEvent('ofp-theme-changed', { detail: { dark: isDark } }));
        }
        window.ofpToggleTheme = ofpToggleTheme;
        window.toggleDark = ofpToggleTheme;
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
    <style>
        body,
        .font-system,
        .font-sans {
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol" !important;
        }

        /* Hero background: white overlay on light mode, dark overlay on dark mode */
        .hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(255, 255, 255, 0.45) 0%, rgba(255, 255, 255, 0.65) 40%, rgba(255, 255, 255, 0.95) 85%, #ffffff 100%),
                url('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=2000&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: scroll !important;
        }

        html.dark .hero-bg {
            background-image: 
                linear-gradient(to bottom, rgba(11, 17, 32, 0.5) 0%, rgba(11, 17, 32, 0.8) 50%, rgba(2, 6, 23, 0.95) 100%),
                url('https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=2000&q=80');
        }
    </style>
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
            " class="fixed top-0 left-0 w-full z-50 bg-transparent transition-transform duration-300"
        :class="scrolledDown ? '-translate-y-full' : 'translate-y-0'">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="flex justify-between items-center h-16 transition-all duration-300 mt-2">
                <!-- Left: Logo & Hamburger -->
                <div class="flex items-center gap-3 sm:gap-5">
                    <!-- Logo (No icon, Uppercase, Small font) -->
                    <a href="<?php echo esc_url( home_url('/') ); ?>" class="flex items-center">
                        <span
                            class="text-[13px] sm:text-sm font-black tracking-[0.25em] uppercase transition-colors text-slate-900 dark:text-white drop-shadow-sm">
                            BOFAST HOMES
                        </span>
                    </a>

                    <!-- Mega Menu Hamburger Button (Reduced size) -->
                    <button @click="megaMenuOpen = !megaMenuOpen"
                        class="rounded-lg px-2.5 py-1.5 transition-colors flex items-center justify-center cursor-pointer border bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white border-slate-300/80 dark:border-white/20">
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
                        class="p-2 rounded-xl border transition-all cursor-pointer border-slate-300/80 dark:border-white/20 bg-slate-900/5 hover:bg-slate-900/10 dark:bg-white/10 dark:hover:bg-white/20 backdrop-blur-md text-slate-900 dark:text-white">
                        <svg class="w-4 h-4 theme-toggle-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z">
                            </path>
                        </svg>
                        <svg class="w-4 h-4 text-amber-500 dark:text-amber-400 theme-toggle-sun" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z">
                            </path>
                        </svg>
                    </button>
                    <!-- Post Property Button -->
                    <button
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-4 sm:px-6 py-1.5 sm:py-2 rounded-xl text-[11px] sm:text-xs tracking-wider shadow-lg shadow-blue-500/20 transition-all cursor-pointer whitespace-nowrap uppercase">
                        Post Property
                    </button>
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

    <!-- 1. Hero Section -->
    <section
        class="hero-bg relative pt-20 pb-32 px-4 sm:px-6 flex flex-col items-center justify-center text-center overflow-hidden h-[600px]">
        <div class="z-10 animate-fade-in-up flex flex-col items-center -translate-y-8 sm:-translate-y-12">
            <h1 class="text-4xl sm:text-5xl md:text-[60px] font-system font-black text-slate-950 dark:text-white tracking-tight leading-[1.1] mb-6 drop-shadow-sm dark:drop-shadow-lg">
                Find Verified <span class="text-blue-600 dark:text-blue-500">Properties &</span><br>
                <span class="text-blue-600 dark:text-blue-500">Homes</span> in Nigeria
            </h1>

            <p class="text-[16px] sm:text-[18px] font-system font-medium text-slate-700 dark:text-slate-300 max-w-3xl leading-relaxed">
                Search over 4,800+ vetted duplexes, serviced apartments, and commercial plots with<br
                    class="hidden md:block">
                verified titles and direct broker connections.
            </p>
        </div>
    </section>

    <!-- 2. Search Filter (Overlapping Hero) -->
    <section class="relative -mt-44 md:-mt-48 mb-10 md:mb-12 z-20 px-4 sm:px-6 max-w-5xl mx-auto w-full" x-data="{ 
            activeTab: 'sale',
            filterExpanded: false,
            filters: {
                serviced: false,
                furnished: false,
                newlyBuilt: true,
                swimmingPool: true,
                waterfront: false,
                gym: false
            },
            get activeFilterCount() {
                return Object.values(this.filters).filter(v => v).length;
            }
        }">
        <div
            class="bg-white dark:bg-[#0B1120] rounded-[24px] shadow-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-6 transition-colors duration-300">

            <!-- Tabs -->
            <div
                class="flex items-center gap-2 mb-4 border-b border-slate-200 dark:border-slate-800/60 pb-3 overflow-x-auto hide-scrollbar">
                <button @click="activeTab = 'sale'"
                    :class="activeTab === 'sale' ? 'bg-blue-600 text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    class="px-4 py-1.5 rounded-lg text-[14px] font-semibold transition-all whitespace-nowrap cursor-pointer">
                    For Sale
                </button>
                <button @click="activeTab = 'rent'"
                    :class="activeTab === 'rent' ? 'bg-blue-600 text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    class="px-4 py-1.5 rounded-lg text-[14px] font-semibold transition-all whitespace-nowrap cursor-pointer">
                    For Rent
                </button>
                <button @click="activeTab = 'shortlet'"
                    :class="activeTab === 'shortlet' ? 'bg-blue-600 text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    class="px-4 py-1.5 rounded-lg text-[14px] font-semibold transition-all whitespace-nowrap flex items-center gap-1.5 cursor-pointer">
                    <svg class="w-4 h-4 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z">
                        </path>
                    </svg>
                    Short Let
                </button>
                <button @click="activeTab = 'commercial'"
                    :class="activeTab === 'commercial' ? 'bg-blue-600 text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                    class="px-4 py-1.5 rounded-lg text-[14px] font-semibold transition-all whitespace-nowrap cursor-pointer">
                    Commercial
                </button>
                <button @click="activeTab = 'land'"
                    :class="activeTab === 'land' ? 'bg-blue-600 text-white border-blue-600' : 'border border-slate-900 dark:border-slate-600 text-slate-900 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800'"
                    class="px-4 py-1.5 rounded-lg text-[14px] font-semibold transition-all whitespace-nowrap cursor-pointer">
                    Land / Plots
                </button>
            </div>

            <!-- Form -->
            <form action="<?php echo esc_url( home_url( '/marketplace/' ) ); ?>" method="GET" class="grid grid-cols-1 md:grid-cols-12 gap-3 mb-4">
                <input type="hidden" name="listing_type" :value="activeTab">

                <!-- Location / Area -->
                <div class="md:col-span-4 relative">
                    <label
                        class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1">
                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        Location / Area
                    </label>
                    <input type="text" name="location" placeholder="e.g. Lekki, Ikoyi, Abuja, VI..."
                        class="w-full bg-slate-50 dark:bg-[#050B14] border border-slate-200 dark:border-slate-800/80 rounded-[12px] px-3 py-2.5 text-[14px] font-semibold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-slate-900 dark:text-white transition-all">
                </div>

                <!-- Type -->
                <div class="md:col-span-3">
                    <label
                        class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1">
                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                            </path>
                        </svg>
                        Type
                    </label>
                    <div class="relative">
                        <select name="property_type"
                            class="w-full bg-slate-50 dark:bg-[#050B14] border border-slate-200 dark:border-slate-800/80 rounded-[12px] px-3 py-2.5 pr-8 text-[14px] font-semibold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-slate-900 dark:text-white appearance-none cursor-pointer transition-all">
                            <option value="">All Types</option>
                            <option value="duplex">Duplex</option>
                            <option value="apartment">Apartment</option>
                            <option value="terrace">Terrace</option>
                            <option value="land">Land</option>
                        </select>
                        <div
                            class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Bedrooms -->
                <div class="md:col-span-2">
                    <label
                        class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1">
                        <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z">
                            </path>
                        </svg>
                        Bedrooms
                    </label>
                    <div class="relative">
                        <select name="beds"
                            class="w-full bg-slate-50 dark:bg-[#050B14] border border-slate-200 dark:border-slate-800/80 rounded-[12px] px-3 py-2.5 pr-8 text-[14px] font-semibold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-slate-900 dark:text-white appearance-none cursor-pointer transition-all">
                            <option value="">Any Bed</option>
                            <option value="1">1 Bed</option>
                            <option value="2">2 Beds</option>
                            <option value="3">3 Beds</option>
                            <option value="4">4+ Beds</option>
                        </select>
                        <div
                            class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="md:col-span-3 flex items-end gap-2">
                    <button type="submit"
                        class="flex-1 h-[42px] bg-blue-600 hover:bg-blue-700 text-white font-semibold text-[14px] rounded-[12px] flex items-center justify-center gap-2 shadow-[0_0_20px_rgba(37,99,235,0.3)] hover:shadow-[0_0_25px_rgba(37,99,235,0.5)] transition-all active:scale-95 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        Search (3)
                    </button>
                    <button type="button" @click="filterExpanded = !filterExpanded"
                        :class="filterExpanded ? 'bg-blue-600/10 border-blue-500/30 text-blue-500' : 'border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        class="h-[42px] px-2 min-w-[42px] flex-shrink-0 border rounded-[12px] flex items-center justify-center gap-1.5 transition-colors cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4">
                            </path>
                        </svg>
                        <div x-show="activeFilterCount > 0" style="display: none;"
                            class="w-4 h-4 bg-blue-500 rounded-full flex items-center justify-center text-white text-[10px] font-bold shadow"
                            x-text="activeFilterCount"></div>
                    </button>
                </div>

                <!-- Expanded Filters Area -->
                <div x-show="filterExpanded" style="display: none;"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="md:col-span-12 mt-2 pt-4 border-t border-slate-200 dark:border-slate-800/60">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                        <!-- Min Price -->
                        <div>
                            <label
                                class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">
                                <span class="text-green-500 font-bold">$</span> MIN PRICE (₦)
                            </label>
                            <div class="relative">
                                <select name="min_price"
                                    class="w-full bg-slate-50 dark:bg-[#050B14] border border-slate-200 dark:border-slate-800/80 rounded-[12px] px-3 py-2.5 pr-8 text-[14px] font-semibold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-slate-900 dark:text-white appearance-none cursor-pointer transition-all">
                                    <option value="">No Min</option>
                                    <option value="1000000">1,000,000</option>
                                    <option value="5000000">5,000,000</option>
                                    <option value="10000000">10,000,000</option>
                                </select>
                                <div
                                    class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <!-- Max Price -->
                        <div>
                            <label
                                class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1.5">
                                <span class="text-green-500 font-bold">$</span> MAX PRICE (₦)
                            </label>
                            <div class="relative">
                                <select name="max_price"
                                    class="w-full bg-slate-50 dark:bg-[#050B14] border border-slate-200 dark:border-slate-800/80 rounded-[12px] px-3 py-2.5 pr-8 text-[14px] font-semibold focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 text-slate-900 dark:text-white appearance-none cursor-pointer transition-all">
                                    <option value="">No Max</option>
                                    <option value="5000000">5,000,000</option>
                                    <option value="10000000">10,000,000</option>
                                    <option value="50000000">50,000,000</option>
                                </select>
                                <div
                                    class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Property Features & Verification -->
                    <div class="mb-5">
                        <label
                            class="flex items-center gap-1.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-3">
                            PROPERTY FEATURES & VERIFICATION
                        </label>
                        <div class="flex flex-wrap gap-2 sm:gap-3">
                            <!-- Serviced -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.serviced" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.serviced ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.serviced ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.serviced" class="w-2.5 h-2.5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Serviced</span>
                                </div>
                            </label>

                            <!-- Furnished -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.furnished" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.furnished ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.furnished ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.furnished" class="w-2.5 h-2.5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Furnished</span>
                                </div>
                            </label>

                            <!-- Newly Built -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.newlyBuilt" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.newlyBuilt ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.newlyBuilt ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.newlyBuilt" class="w-2.5 h-2.5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Newly Built</span>
                                </div>
                            </label>

                            <!-- Swimming Pool -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.swimmingPool" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.swimmingPool ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.swimmingPool ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.swimmingPool" class="w-2.5 h-2.5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Swimming Pool</span>
                                </div>
                            </label>

                            <!-- Waterfront -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.waterfront" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.waterfront ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.waterfront ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.waterfront" class="w-2.5 h-2.5" fill="none"
                                            stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Waterfront</span>
                                </div>
                            </label>

                            <!-- Gym / Fitness -->
                            <label class="cursor-pointer">
                                <input type="checkbox" x-model="filters.gym" class="hidden">
                                <div class="flex items-center gap-2.5 px-4 py-2 rounded-[10px] border transition-all"
                                    :class="filters.gym ? 'bg-[#0A1635] border-blue-600/50 text-blue-100' : 'bg-transparent border-slate-200 dark:border-slate-800/80 text-slate-600 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-700'">
                                    <div class="w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors"
                                        :class="filters.gym ? 'bg-blue-600 border-blue-600 text-white' : 'border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-[#050B14]'">
                                        <svg x-show="filters.gym" class="w-2.5 h-2.5" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                    </div>
                                    <span class="text-[12px] font-semibold">Gym / Fitness</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Reset All Filters -->
                    <div class="flex justify-end pt-2">
                        <button type="button" @click="Object.keys(filters).forEach(k => filters[k] = false)"
                            class="flex items-center gap-1.5 text-[12px] font-medium text-slate-500 hover:text-slate-300 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Reset All Filters
                        </button>
                    </div>
                </div>
            </form>

            <!-- Popular Keywords -->
            <div class="flex flex-wrap items-center gap-2.5">
                <span
                    class="text-[10px] font-semibold text-slate-500 dark:text-slate-500 mr-1 tracking-widest">POPULAR:</span>
                <a href="#"
                    class="text-[11px] border border-slate-200 dark:border-slate-800/80 rounded-full px-3 py-1 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/80 transition-colors">Lekki
                    Phase 1</a>
                <a href="#"
                    class="text-[11px] border border-slate-200 dark:border-slate-800/80 rounded-full px-3 py-1 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/80 transition-colors">Ikoyi</a>
                <a href="#"
                    class="text-[11px] border border-slate-200 dark:border-slate-800/80 rounded-full px-3 py-1 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/80 transition-colors">Victoria
                    Island</a>
                <a href="#"
                    class="text-[11px] border border-slate-200 dark:border-slate-800/80 rounded-full px-3 py-1 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/80 transition-colors">Maitama
                    Abuja</a>
                <a href="#"
                    class="text-[11px] border border-slate-200 dark:border-slate-800/80 rounded-full px-3 py-1 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/80 transition-colors">Banana
                    Island</a>
            </div>
        </div>
    </section>

    <!-- 3. Four Grid Boxes (Trust Badges) -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-16 md:mb-20">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
            <!-- Card 1: Verified Properties -->
            <div
                class="bg-white dark:bg-[#0F1423] p-5 sm:p-6 rounded-2xl border border-slate-100 dark:border-[#1C2438] shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-md hover:border-slate-200 dark:hover:border-white/10 transition-all flex items-center gap-4 cursor-default">
                <div
                    class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25">
                        </path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-slate-900 dark:text-white mb-0.5">Verified Properties</h3>
                    <p class="trust-badge-desc" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'; font-style: normal; font-weight: 400; font-size: 12px; line-height: 17px;">All properties are verified for your peace of mind.</p>
                </div>
            </div>

            <!-- Card 2: Safe & Secure -->
            <div
                class="bg-white dark:bg-[#0F1423] p-5 sm:p-6 rounded-2xl border border-slate-100 dark:border-[#1C2438] shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-md hover:border-slate-200 dark:hover:border-white/10 transition-all flex items-center gap-4 cursor-default">
                <div
                    class="w-12 h-12 rounded-2xl bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-600 dark:text-purple-400 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-slate-900 dark:text-white mb-0.5">Safe & Secure</h3>
                    <p class="trust-badge-desc" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'; font-style: normal; font-weight: 400; font-size: 12px; line-height: 17px;">Your safety is our priority in every transaction.</p>
                </div>
            </div>

            <!-- Card 3: 24/7 Support -->
            <div
                class="bg-white dark:bg-[#0F1423] p-5 sm:p-6 rounded-2xl border border-slate-100 dark:border-[#1C2438] shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-md hover:border-slate-200 dark:hover:border-white/10 transition-all flex items-center gap-4 cursor-default">
                <div
                    class="w-12 h-12 rounded-2xl bg-sky-50 dark:bg-sky-900/30 flex items-center justify-center text-sky-600 dark:text-sky-400 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19.114 5.636a9 9 0 00-14.228 0M3 13.5A2.25 2.25 0 015.25 11.25h1.5A2.25 2.25 0 019 13.5v3A2.25 2.25 0 016.75 18.75H5.25A2.25 2.25 0 013 16.5v-3zm12 0a2.25 2.25 0 012.25-2.25h1.5A2.25 2.25 0 0121 13.5v3a2.25 2.25 0 01-2.25 2.25h-1.5A2.25 2.25 0 0115 16.5v-3z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-slate-900 dark:text-white mb-0.5">24/7 Support</h3>
                    <p class="trust-badge-desc" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'; font-style: normal; font-weight: 400; font-size: 12px; line-height: 17px;">Our team is here to help you anytime, anywhere.</p>
                </div>
            </div>

            <!-- Card 4: Best Price Guarantee -->
            <div
                class="bg-white dark:bg-[#0F1423] p-5 sm:p-6 rounded-2xl border border-slate-100 dark:border-[#1C2438] shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-md hover:border-slate-200 dark:hover:border-white/10 transition-all flex items-center gap-4 cursor-default">
                <div
                    class="w-12 h-12 rounded-2xl bg-amber-50 dark:bg-amber-900/30 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z">
                        </path>
                    </svg>
                </div>
                <div>
                    <h3 class="text-[15px] font-bold text-slate-900 dark:text-white mb-0.5">Best Price Guarantee</h3>
                    <p class="trust-badge-desc" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', 'Noto Sans', Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji'; font-style: normal; font-weight: 400; font-size: 12px; line-height: 17px;">Get the best deals at the best prices.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. Why Choose Us -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div class="flex flex-col lg:flex-row gap-12 items-center">
            <div class="flex-1 space-y-6">
                <div
                    class="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full bg-blue-50 dark:bg-blue-900/30 border border-blue-200/80 dark:border-blue-800/40">
                    <span class="text-[11px] uppercase font-bold tracking-wider text-blue-600 dark:text-blue-400">WHY CHOOSE APEXREALTY</span>
                </div>
                <h2 class="text-3xl sm:text-4xl lg:text-[46px] font-black font-system text-slate-950 dark:text-white leading-[1.15] tracking-tight">
                    More Than Just <br><span class="text-blue-600 dark:text-blue-500">A Property</span>
                </h2>
                <p class="text-slate-600 dark:text-slate-400 text-sm md:text-base leading-relaxed">
                    We offer more than just a place to live. We deliver experiences that fit your lifestyle by merging luxury living with strict due diligence and verified opportunities.
                </p>
                <ul class="space-y-4 pt-2">
                    <li class="flex items-center gap-3 text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <div
                            class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        Full Legal Title Verification
                    </li>
                    <li class="flex items-center gap-3 text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <div
                            class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        Direct Developer Engagements
                    </li>
                    <li class="flex items-center gap-3 text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <div
                            class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        Remote Virtual Tours
                    </li>
                    <li class="flex items-center gap-3 text-sm font-semibold text-slate-800 dark:text-slate-200">
                        <div
                            class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center text-blue-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                        Seamless Diaspora Payment Solutions
                    </li>
                </ul>
                <div class="pt-4">
                    <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                        class="inline-flex items-center gap-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-6 py-3 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                        Explore Listings
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </a>
                </div>
            </div>
            <div class="flex-1 relative self-stretch">
                <div class="rounded-3xl overflow-hidden shadow-2xl relative h-full">
                    <img src="https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?auto=format&fit=crop&w=1200&q=80"
                        alt="Luxury Home Exterior"
                        class="w-full h-full object-cover hover:scale-105 transition-transform duration-700">
                    <div
                        class="absolute bottom-6 left-6 right-6 bg-slate-900/80 backdrop-blur-md p-4 rounded-2xl border border-slate-700/50">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-full bg-emerald-500 flex items-center justify-center text-white shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-white font-bold text-sm">100% Secure Process</p>
                                <p class="text-slate-300 text-xs">End-to-end verification and protection.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Decorative element -->
                <div class="absolute -z-10 -top-6 -right-6 w-32 h-32 bg-blue-500/20 rounded-full blur-3xl"></div>
                <div class="absolute -z-10 -bottom-6 -left-6 w-32 h-32 bg-purple-500/20 rounded-full blur-3xl"></div>
            </div>
        </div>
    </section>

    <!-- 5. Category (4 Boxes) -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div class="mb-8">
            <div class="flex items-center gap-1.5 text-xs font-bold text-blue-600 dark:text-blue-500 uppercase tracking-wider mb-2">
                <svg class="w-4 h-4 text-blue-600 dark:text-blue-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polygon points="12 2 21 7 21 17 12 22 3 17 3 7 12 2"></polygon>
                </svg>
                CURATED ASSET TYPES
            </div>
            <h2 class="text-2xl sm:text-[32px] font-black font-system text-slate-950 dark:text-white leading-tight tracking-tight">
                Explore by Property Category
            </h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 sm:gap-6">
            <!-- Card 1: Detached Duplexes -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?property_type=duplex"
                class="group bg-white dark:bg-[#0F1423] border border-slate-100 dark:border-[#1C2438] hover:border-blue-200 dark:hover:border-blue-800/50 rounded-2xl p-6 sm:p-7 flex flex-col justify-between shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-lg transition-all duration-300 cursor-pointer">
                <div class="flex items-start justify-between">
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-900/30 group-hover:bg-blue-600 flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:text-white shrink-0 group-hover:shadow-md group-hover:shadow-blue-500/25 transition-all duration-300">
                        <svg class="w-6 h-6 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"></path>
                        </svg>
                    </div>
                    <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/80 px-3.5 py-1 rounded-full group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors">Browse</span>
                </div>
                <div class="mt-8">
                    <p class="text-xl sm:text-[22px] font-black text-slate-950 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-500 tracking-tight mb-1 transition-colors">140+ Listed</p>
                    <h3 class="text-sm sm:text-[15px] font-bold text-slate-900 dark:text-slate-200 mb-0.5">Detached Duplexes</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500">C of O / Consent</p>
                </div>
            </a>

            <!-- Card 2: Luxury Apartments -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?property_type=apartment"
                class="group bg-white dark:bg-[#0F1423] border border-slate-100 dark:border-[#1C2438] hover:border-blue-200 dark:hover:border-blue-800/50 rounded-2xl p-6 sm:p-7 flex flex-col justify-between shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-lg transition-all duration-300 cursor-pointer">
                <div class="flex items-start justify-between">
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-900/30 group-hover:bg-blue-600 flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:text-white shrink-0 group-hover:shadow-md group-hover:shadow-blue-500/25 transition-all duration-300">
                        <svg class="w-6 h-6 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/80 px-3.5 py-1 rounded-full group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors">Browse</span>
                </div>
                <div class="mt-8">
                    <p class="text-xl sm:text-[22px] font-black text-slate-950 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-500 tracking-tight mb-1 transition-colors">210+ Units</p>
                    <h3 class="text-sm sm:text-[15px] font-bold text-slate-900 dark:text-slate-200 mb-0.5">Luxury Apartments</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500">Serviced Flats</p>
                </div>
            </a>

            <!-- Card 3: Sky Penthouses -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?property_type=penthouse"
                class="group bg-white dark:bg-[#0F1423] border border-slate-100 dark:border-[#1C2438] hover:border-blue-200 dark:hover:border-blue-800/50 rounded-2xl p-6 sm:p-7 flex flex-col justify-between shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-lg transition-all duration-300 cursor-pointer">
                <div class="flex items-start justify-between">
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-900/30 group-hover:bg-blue-600 flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:text-white shrink-0 group-hover:shadow-md group-hover:shadow-blue-500/25 transition-all duration-300">
                        <svg class="w-6 h-6 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"></path>
                        </svg>
                    </div>
                    <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/80 px-3.5 py-1 rounded-full group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors">Browse</span>
                </div>
                <div class="mt-8">
                    <p class="text-xl sm:text-[22px] font-black text-slate-950 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-500 tracking-tight mb-1 transition-colors">45+ Mansions</p>
                    <h3 class="text-sm sm:text-[15px] font-bold text-slate-900 dark:text-slate-200 mb-0.5">Sky Penthouses</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500">Ocean Views</p>
                </div>
            </a>

            <!-- Card 4: Serviced Shortlets -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?listing_type=shortlet"
                class="group bg-white dark:bg-[#0F1423] border border-slate-100 dark:border-[#1C2438] hover:border-blue-200 dark:hover:border-blue-800/50 rounded-2xl p-6 sm:p-7 flex flex-col justify-between shadow-[0_2px_12px_rgba(0,0,0,0.03)] hover:shadow-lg transition-all duration-300 cursor-pointer">
                <div class="flex items-start justify-between">
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 dark:bg-blue-900/30 group-hover:bg-blue-600 flex items-center justify-center text-blue-600 dark:text-blue-400 group-hover:text-white shrink-0 group-hover:shadow-md group-hover:shadow-blue-500/25 transition-all duration-300">
                        <svg class="w-6 h-6 transition-colors" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"></path>
                        </svg>
                    </div>
                    <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/80 px-3.5 py-1 rounded-full group-hover:bg-slate-200 dark:group-hover:bg-slate-700 transition-colors">Browse</span>
                </div>
                <div class="mt-8">
                    <p class="text-xl sm:text-[22px] font-black text-slate-950 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-500 tracking-tight mb-1 transition-colors">85+ Suites</p>
                    <h3 class="text-sm sm:text-[15px] font-bold text-slate-900 dark:text-slate-200 mb-0.5">Serviced Shortlets</h3>
                    <p class="text-xs text-slate-400 dark:text-slate-500">24/7 Power & WiFi</p>
                </div>
            </a>
        </div>
    </section>

    <!-- 6. Featured Properties -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 mb-8 border-b border-slate-200 dark:border-slate-800 pb-4">
            <div>
                <h2 class="text-2xl sm:text-[32px] font-black font-system text-slate-950 dark:text-white leading-tight tracking-tight">
                    Featured Properties in Nigeria
                </h2>
            </div>
            <div>
                <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>"
                    class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30 px-4 py-2 rounded-xl border border-blue-200/80 dark:border-blue-800/40 hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors">
                    <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                    </svg>
                    Explore Full Marketplace
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                    </svg>
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php
            $featured_query = new WP_Query([
                'post_type'      => 'ofp_property',
                'post_status'    => 'publish',
                'meta_key'       => 'ofp_is_featured',
                'meta_value'     => '1',
                'posts_per_page' => 3,
            ]);

            if ( ! $featured_query->have_posts() ) {
                $featured_query = new WP_Query([
                    'post_type'      => 'ofp_property',
                    'post_status'    => 'publish',
                    'posts_per_page' => 3,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ]);
            }

            $cards_data = [];

            if ( $featured_query->have_posts() ) {
                while ( $featured_query->have_posts() ) {
                    $featured_query->the_post();
                    $p_id = get_the_ID();
                    $raw_price = get_post_meta( $p_id, 'ofp_price', true );
                    $price_num = floatval( preg_replace( '/[^\d.]/', '', (string) $raw_price ) );
                    if ( $price_num >= 1000000 ) {
                        $formatted_price = '₦' . rtrim( rtrim( number_format( $price_num / 1000000, 1 ), '0' ), '.' ) . ' Million';
                    } elseif ( $price_num > 0 ) {
                        $formatted_price = '₦' . number_format( $price_num );
                    } else {
                        $formatted_price = '₦' . ( $raw_price ?: 'Contact Agent' );
                    }

                    $c_id = get_post_meta( $p_id, 'ofp_client_id', true );
                    $ag_name = 'Tunde Adeleke';
                    $ag_comp = 'Apex Capital Realty';
                    $ag_avatar = 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=120&h=120&q=80';
                    $ag_profile_url = home_url( '/agent/' . sanitize_title( $ag_name ) );
                    if ( $c_id ) {
                        global $wpdb;
                        $client_row = $wpdb->get_row( $wpdb->prepare( "SELECT owner_name, business_name, profile_slug FROM {$wpdb->prefix}ofp_clients WHERE id = %d LIMIT 1", $c_id ) );
                        if ( $client_row ) {
                            $ag_name = $client_row->owner_name ?: $client_row->business_name;
                            $ag_comp = $client_row->business_name ?: 'Apex Capital Realty';
                            if ( ! empty( $client_row->profile_slug ) ) {
                                $ag_profile_url = home_url( '/agent/' . $client_row->profile_slug );
                            } else {
                                $ag_profile_url = home_url( '/agent/' . sanitize_title( $ag_name ) );
                            }
                        }
                    }

                    $cards_data[] = [
                        'id'            => $p_id,
                        'title'         => get_the_title(),
                        'link'          => get_permalink(),
                        'image'         => has_post_thumbnail() ? get_the_post_thumbnail_url( $p_id, 'large' ) : 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800&q=80',
                        'price'         => $formatted_price,
                        'location'      => get_post_meta( $p_id, 'ofp_location_text', true ) ?: 'Lekki Phase 1, Lagos',
                        'listing_type'  => get_post_meta( $p_id, 'ofp_listing_type', true ) ?: 'sale',
                        'prop_type'     => get_post_meta( $p_id, 'ofp_property_type', true ) ?: 'Detached Duplex',
                        'beds'          => get_post_meta( $p_id, 'ofp_bedrooms', true ) ?: '5',
                        'baths'         => get_post_meta( $p_id, 'ofp_bathrooms', true ) ?: '6',
                        'parking'       => get_post_meta( $p_id, 'ofp_parking', true ) ?: '4',
                        'sqm'           => get_post_meta( $p_id, 'ofp_area_sqm', true ) ?: '650',
                        'is_verified'   => true,
                        'is_featured'   => true,
                        'agent_name'    => $ag_name,
                        'agent_company' => $ag_comp,
                        'agent_avatar'  => $ag_avatar,
                        'profile_url'   => $ag_profile_url,
                    ];
                }
                wp_reset_postdata();
            }

            // Fallback showcase properties matching user's reference mockup if database has none
            if ( empty( $cards_data ) ) {
                $cards_data = [
                    [
                        'id'            => 1,
                        'title'         => 'Ultra-Luxury 5-Bedroom Fully Detached Duplex with Cinema & Pool',
                        'link'          => esc_url( home_url('/marketplace/') ),
                        'image'         => 'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=800&q=80',
                        'price'         => '₦450 Million',
                        'location'      => 'Admiralty Way, Lekki Phase 1, Lagos',
                        'listing_type'  => 'sale',
                        'prop_type'     => 'Detached Duplex',
                        'beds'          => '5',
                        'baths'         => '6',
                        'parking'       => '4',
                        'sqm'           => '650',
                        'is_verified'   => true,
                        'is_featured'   => true,
                        'agent_name'    => 'Tunde Adeleke',
                        'agent_company' => 'Apex Capital Realty',
                        'agent_avatar'  => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=120&h=120&q=80',
                        'profile_url'   => home_url( '/agent/tunde-adeleke' ),
                    ],
                    [
                        'id'            => 2,
                        'title'         => 'Modern 4-Bedroom Semi-Detached House in Diplomatic Zone',
                        'link'          => esc_url( home_url('/marketplace/') ),
                        'image'         => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=800&q=80',
                        'price'         => '₦580 Million',
                        'location'      => 'Maitama District, Abuja, FCT',
                        'listing_type'  => 'sale',
                        'prop_type'     => 'Detached Duplex',
                        'beds'          => '4',
                        'baths'         => '5',
                        'parking'       => '3',
                        'sqm'           => '520',
                        'is_verified'   => true,
                        'is_featured'   => true,
                        'agent_name'    => 'Ibrahim Danjuma',
                        'agent_company' => 'Capital Peak Prime',
                        'agent_avatar'  => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=120&h=120&q=80',
                        'profile_url'   => home_url( '/agent/ibrahim-danjuma' ),
                    ],
                    [
                        'id'            => 3,
                        'title'         => 'Contemporary 4-Bedroom Terrace Duplex with BQ in Gated Estate',
                        'link'          => esc_url( home_url('/marketplace/') ),
                        'image'         => 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=800&q=80',
                        'price'         => '₦220 Million',
                        'location'      => 'Ikeja GRA, Lagos Mainland',
                        'listing_type'  => 'sale',
                        'prop_type'     => 'Terraced Duplex',
                        'beds'          => '4',
                        'baths'         => '4',
                        'parking'       => '3',
                        'sqm'           => '340',
                        'is_verified'   => true,
                        'is_featured'   => false,
                        'agent_name'    => 'Babatunde Fash',
                        'agent_company' => 'Mainland Premier Realty',
                        'agent_avatar'  => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=120&h=120&q=80',
                        'profile_url'   => home_url( '/agent/babatunde-fash' ),
                    ],
                ];
            }

            foreach ( $cards_data as $card ) :
            ?>
            <div class="bg-white dark:bg-[#0F1423] rounded-2xl overflow-hidden border border-slate-100 dark:border-[#1C2438] shadow-[0_4px_20px_rgba(0,0,0,0.06)] hover:shadow-xl transition-all duration-300 flex flex-col group">
                <!-- Image with Badges & Price Overlay -->
                <div class="relative h-60 sm:h-64 overflow-hidden">
                    <a href="<?php echo esc_url( $card['link'] ); ?>" class="block w-full h-full">
                        <img src="<?php echo esc_url( $card['image'] ); ?>"
                             alt="<?php echo esc_attr( $card['title'] ); ?>"
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                    </a>
                    <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent pointer-events-none"></div>
                    
                    <!-- Top Badges -->
                    <div class="absolute top-3.5 left-3.5 right-3.5 flex items-center justify-between pointer-events-none">
                        <div class="flex items-center gap-1.5">
                            <span class="bg-[#00875A] text-white text-[11px] font-bold uppercase tracking-wider px-2.5 py-0.5 rounded-full shadow-sm">
                                <?php echo esc_html( $card['listing_type'] === 'rent' ? 'FOR RENT' : ( $card['listing_type'] === 'shortlet' ? 'SHORT LET' : 'FOR SALE' ) ); ?>
                            </span>
                            <?php if ( ! empty( $card['is_featured'] ) ) : ?>
                                <span class="bg-slate-900/40 backdrop-blur-md text-white text-[11px] font-medium px-2.5 py-0.5 rounded-full border border-white/20">Featured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $card['is_verified'] ) ) : ?>
                            <span class="bg-emerald-950/70 text-emerald-400 border border-emerald-500/50 backdrop-blur-md text-[11px] font-semibold px-2.5 py-0.5 rounded-full flex items-center gap-1">
                                <svg class="w-3 h-3 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z" /></svg>
                                Verified
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Bottom Price & Property Type -->
                    <div class="absolute bottom-3.5 left-3.5 right-3.5 flex justify-between items-end pointer-events-none">
                        <div class="text-white font-black text-2xl sm:text-[26px] tracking-tight drop-shadow-md">
                            <?php echo esc_html( $card['price'] ); ?>
                        </div>
                        <div class="bg-slate-950/75 backdrop-blur-md text-white text-[11px] font-medium px-2.5 py-1 rounded-md border border-white/10 capitalize">
                            <?php echo esc_html( str_replace('-', ' ', $card['prop_type']) ); ?>
                        </div>
                    </div>
                </div>

                <!-- Card Body -->
                <div class="p-5 flex-1 flex flex-col justify-between">
                    <div>
                        <!-- Location -->
                        <div class="flex items-center gap-1.5 text-blue-600 dark:text-blue-400 text-xs sm:text-[13px] font-medium mb-1.5">
                            <svg class="w-3.5 h-3.5 text-blue-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            <span class="truncate"><?php echo esc_html( $card['location'] ); ?></span>
                        </div>

                        <!-- Title -->
                        <h3 class="mb-3">
                            <a href="<?php echo esc_url( $card['link'] ); ?>" class="font-system text-[15px] sm:text-base font-bold text-slate-900 dark:text-white leading-snug hover:text-blue-600 dark:hover:text-blue-400 transition-colors line-clamp-2">
                                <?php echo esc_html( $card['title'] ); ?>
                            </a>
                        </h3>

                        <!-- 4 Specs: Beds, Baths, Parking, SQM -->
                        <div class="grid grid-cols-4 gap-2 py-3 border-y border-slate-100 dark:border-white/5 mb-4">
                            <div class="flex items-start gap-1.5">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2z"></path>
                                </svg>
                                <div class="leading-none">
                                    <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $card['beds'] ?: '-' ); ?></span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Beds</span>
                                </div>
                            </div>
                            <div class="flex items-start gap-1.5">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <div class="leading-none">
                                    <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $card['baths'] ?: '-' ); ?></span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Baths</span>
                                </div>
                            </div>
                            <div class="flex items-start gap-1.5">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 17H3a2 2 0 01-2-2V7a2 2 0 012-2h11l4 4v8a2 2 0 01-2 2h-3"></path>
                                </svg>
                                <div class="leading-none">
                                    <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $card['parking'] ?: '-' ); ?></span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">Parking</span>
                                </div>
                            </div>
                            <div class="flex items-start gap-1.5">
                                <svg class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                                </svg>
                                <div class="leading-none">
                                    <span class="text-[13px] font-bold text-slate-900 dark:text-white block"><?php echo esc_html( $card['sqm'] ?: '-' ); ?></span>
                                    <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 block">SQM</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer: Owner link & Details button only -->
                    <div class="flex items-center justify-between pt-1">
                        <a href="<?php echo esc_url( $card['profile_url'] ); ?>" class="flex items-center gap-2.5 group/owner hover:opacity-90 transition-opacity">
                            <img src="<?php echo esc_url( $card['agent_avatar'] ); ?>" alt="<?php echo esc_attr( $card['agent_name'] ); ?>" class="w-9 h-9 rounded-full object-cover shrink-0 ring-1 ring-slate-100 dark:ring-slate-800 group-hover/owner:ring-blue-500 transition-all">
                            <div>
                                <p class="text-[13px] font-bold text-slate-900 dark:text-white leading-tight group-hover/owner:text-blue-600 dark:group-hover/owner:text-blue-400 transition-colors"><?php echo esc_html( $card['agent_name'] ); ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-tight"><?php echo esc_html( $card['agent_company'] ); ?></p>
                            </div>
                        </a>
                        <div>
                            <!-- Details Button Only -->
                            <a href="<?php echo esc_url( $card['link'] ); ?>" 
                               class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs sm:text-[13px] px-4 py-2 rounded-xl flex items-center gap-1 shadow-sm shadow-blue-500/20 transition-colors">
                                Details <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 7. Trust & Due Diligence -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div class="mb-10">
            <div class="text-blue-500 text-xs md:text-sm font-extrabold tracking-widest uppercase mb-3">
                DUE DILIGENCE FIRST
            </div>
            <h2 class="text-2xl sm:text-[30px] font-black font-system text-slate-950 dark:text-white leading-[36px] tracking-tight">
                Why Discerning Investors Trust Us
            </h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Card 1 -->
            <div
                class="bg-white dark:bg-[#0c1527]/90 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-7 flex flex-col items-start shadow-sm hover:shadow-md dark:hover:border-slate-700 transition-all duration-300">
                <div
                    class="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-950/80 border border-blue-100 dark:border-blue-800/40 flex items-center justify-center shadow-inner">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
                <h3 class="text-slate-900 dark:text-white font-bold text-[17px] font-system tracking-tight mt-6 mb-3">
                    Lagos Lands Bureau Verification
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-[13px] leading-relaxed font-system">
                    Every title document (Governor's Consent, C of O, Gazette) undergoes rigorous Alausa searches before
                    listing.
                </p>
            </div>

            <!-- Card 2 -->
            <div
                class="bg-white dark:bg-[#0c1527]/90 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-7 flex flex-col items-start shadow-sm hover:shadow-md dark:hover:border-slate-700 transition-all duration-300">
                <div
                    class="w-12 h-12 rounded-xl bg-teal-50 dark:bg-teal-950/80 border border-teal-100 dark:border-teal-800/40 flex items-center justify-center shadow-inner">
                    <svg class="w-6 h-6 text-teal-600 dark:text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                </div>
                <h3 class="text-slate-900 dark:text-white font-bold text-[17px] font-system tracking-tight mt-6 mb-3">
                    Diaspora 4K Video Inspections
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-[13px] leading-relaxed font-system">
                    Live interactive video walkthroughs for buyers in the UK, USA, and Canada with independent surveyor
                    sign-off.
                </p>
            </div>

            <!-- Card 3 -->
            <div
                class="bg-white dark:bg-[#0c1527]/90 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-7 flex flex-col items-start shadow-sm hover:shadow-md dark:hover:border-slate-700 transition-all duration-300">
                <div
                    class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-slate-800/80 border border-emerald-100 dark:border-slate-700/50 flex items-center justify-center shadow-inner">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                </div>
                <h3 class="text-slate-900 dark:text-white font-bold text-[17px] font-system tracking-tight mt-6 mb-3">
                    Milestone Escrow Protection
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-[13px] leading-relaxed font-system">
                    Buyer funds remain securely held in escrow until legal contracts and Governor's Consent documents
                    are executed.
                </p>
            </div>

            <!-- Card 4 -->
            <div
                class="bg-white dark:bg-[#0c1527]/90 border border-slate-100 dark:border-slate-800/80 rounded-2xl p-7 flex flex-col items-start shadow-sm hover:shadow-md dark:hover:border-slate-700 transition-all duration-300">
                <div
                    class="w-12 h-12 rounded-xl bg-purple-50 dark:bg-purple-950/80 border border-purple-100 dark:border-purple-800/40 flex items-center justify-center shadow-inner">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
                <h3 class="text-slate-900 dark:text-white font-bold text-[17px] font-system tracking-tight mt-6 mb-3">
                    Zero Hidden Markups
                </h3>
                <p class="text-slate-600 dark:text-slate-400 text-[13px] leading-relaxed font-system">
                    Direct developer prices and transparent NIESV regulated broker fees with full fee disclosure from
                    day one.
                </p>
            </div>
        </div>
    </section>

    <!-- 8. Neighborhood -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div
            class="flex flex-col md:flex-row justify-between md:items-end gap-4 mb-8 border-b border-slate-200 dark:border-slate-800 pb-4">
            <div>
                <div class="text-xs uppercase font-extrabold text-blue-600 dark:text-blue-500 tracking-wider mb-1">
                    Bofast Home Location</div>
                <h2 class="text-2xl sm:text-[30px] font-black font-system text-slate-950 dark:text-white leading-[36px] tracking-tight">Explore
                    High-Growth Neighborhoods</h2>
            </div>
            <div class="md:text-right max-w-md text-slate-600 dark:text-slate-400 text-sm">
                Discover verified commercial hubs, gated residential estates, and diplomatic corridors with proven
                capital appreciation.
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Card 1: Lekki Phase 1 -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=lekki"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1545324418-cc1a3fa10c00?w=600&auto=format&fit=crop&q=80"
                    alt="Lekki Phase 1"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">TRENDING
                        HUB</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Lagos, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Lekki Phase 1</h3>
                        <div class="text-[11px] text-slate-300">240+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Card 2: Ikoyi -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=ikoyi"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?w=600&auto=format&fit=crop&q=80"
                    alt="Ikoyi"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">PRIME
                        LUXURY</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Lagos, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Ikoyi</h3>
                        <div class="text-[11px] text-slate-300">180+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Card 3: Victoria Island -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=victoria-island"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?w=600&auto=format&fit=crop&q=80"
                    alt="Victoria Island"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">COMMERCIAL
                        & HIGHRISE</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Lagos, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Victoria Island</h3>
                        <div class="text-[11px] text-slate-300">195+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Card 4: Maitama -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=maitama"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1600585154340-be6161a56a0c?w=600&auto=format&fit=crop&q=80"
                    alt="Maitama Abuja"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">DIPLOMATIC
                        ZONE</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Abuja, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Maitama</h3>
                        <div class="text-[11px] text-slate-300">142+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Card 5: Ikeja GRA -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=ikeja-gra"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1613545325278-f24b0cae1224?w=600&auto=format&fit=crop&q=80"
                    alt="Ikeja GRA"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">MAINLAND
                        ELITE</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Lagos, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Ikeja GRA</h3>
                        <div class="text-[11px] text-slate-300">110+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>

            <!-- Card 6: Banana Island -->
            <a href="<?php echo esc_url( home_url('/marketplace/') ); ?>?location=banana-island"
                class="relative rounded-2xl overflow-hidden h-60 group shadow-sm hover:shadow-lg transition-all hover:border-blue-500 border border-[#1C2438]">
                <img src="https://images.unsplash.com/photo-1600607687920-4e2a09cf159d?w=600&auto=format&fit=crop&q=80"
                    alt="Banana Island"
                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                <div class="absolute inset-0 bg-gradient-to-t from-[#0F1423]/90 via-[#0F1423]/40 to-transparent"></div>
                <div class="absolute top-4 left-4">
                    <span
                        class="bg-[#1C2438]/80 backdrop-blur-sm text-white text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-full border border-white/10">ULTRA
                        ULTRA HIGH NET WORTH</span>
                </div>
                <div class="absolute bottom-4 left-4 right-4 flex justify-between items-end">
                    <div>
                        <div class="text-[11px] text-blue-400 font-medium mb-1 flex items-center gap-1">
                            <svg class="w-3 h-3 shrink-0" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                            </svg> Lagos, Nigeria
                        </div>
                        <h3
                            class="text-white group-hover:text-blue-400 transition-colors font-system text-xl font-bold leading-7 mb-1">
                            Banana Island</h3>
                        <div class="text-[11px] text-slate-300">65+ Active Listings</div>
                    </div>
                    <div
                        class="w-8 h-8 rounded-full bg-white/10 group-hover:bg-blue-600 backdrop-blur-sm flex items-center justify-center text-white transition-all border border-white/10 group-hover:border-blue-500">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                        </svg>
                    </div>
                </div>
            </a>
        </div>
    </section>

    <!-- 9. Testimonials -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28 overflow-hidden" x-data="{}">
        <div class="mb-8 border-b border-slate-200 dark:border-slate-800 pb-4">
            <div class="text-xs uppercase font-extrabold text-blue-600 dark:text-blue-500 tracking-wider mb-1">Reviews
            </div>
            <h2 class="text-[30px] font-extrabold font-system text-white leading-[36px] tracking-tight">Client
                Testimonials & Verified
                Stories</h2>
        </div>

        <div class="flex gap-6 overflow-x-auto hide-scrollbar pb-4 snap-x">
            <div
                class="min-w-[300px] md:min-w-[400px] snap-center bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm relative">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex text-amber-400">â˜…â˜…â˜…â˜…â˜…</div>
                    <span
                        class="text-[10px] font-bold uppercase bg-emerald-50 text-emerald-600 px-2 py-1 rounded">Verified
                        Buyer</span>
                </div>
                <p class="text-slate-600 dark:text-slate-300 text-sm italic mb-6">"Got my C of O verified easily. Their
                    escrow system made me feel safe buying from London."</p>
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center font-bold text-blue-700">
                        JB</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">Jide Bankole</h4>
                        <p class="text-[10px] text-slate-500">Purchased in Lekki Phase 1</p>
                    </div>
                </div>
            </div>
            <div
                class="min-w-[300px] md:min-w-[400px] snap-center bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm relative">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex text-amber-400">â˜…â˜…â˜…â˜…â˜…</div>
                    <span
                        class="text-[10px] font-bold uppercase bg-emerald-50 text-emerald-600 px-2 py-1 rounded">Verified
                        Tenant</span>
                </div>
                <p class="text-slate-600 dark:text-slate-300 text-sm italic mb-6">"Smooth shortlet booking process.
                    Property was exactly as seen in the 4K video tour."</p>
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center font-bold text-purple-700">
                        AM</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">Amina Mohammed</h4>
                        <p class="text-[10px] text-slate-500">Rented in Victoria Island</p>
                    </div>
                </div>
            </div>
            <div
                class="min-w-[300px] md:min-w-[400px] snap-center bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm relative">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex text-amber-400">â˜…â˜…â˜…â˜…â˜…</div>
                    <span
                        class="text-[10px] font-bold uppercase bg-emerald-50 text-emerald-600 px-2 py-1 rounded">Verified
                        Developer</span>
                </div>
                <p class="text-slate-600 dark:text-slate-300 text-sm italic mb-6">"Bofast Homes CRM brought me 3 serious
                    diaspora buyers in one week. The platform is unmatched."</p>
                <div class="flex items-center gap-3">
                    <div
                        class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center font-bold text-amber-700">
                        CEO</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">Chidi E. Properties</h4>
                        <p class="text-[10px] text-slate-500">Developer in Ikoyi</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 10. Stats Counter -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 mb-24 md:mb-28">
        <div class="bg-[#125BDB] rounded-3xl py-10 px-6 sm:px-10 shadow-2xl relative overflow-hidden">
            <!-- Subtle background effect -->
            <div
                class="absolute inset-0 bg-gradient-to-r from-blue-600/0 via-white/5 to-blue-600/0 pointer-events-none">
            </div>

            <div
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-0 lg:divide-x divide-white/20 relative z-10">
                <!-- Stat 1 -->
                <div class="flex items-center gap-5 lg:px-8 justify-center lg:justify-start">
                    <div
                        class="w-[52px] h-[52px] shrink-0 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-white border border-white/10 shadow-inner">
                        <svg class="w-[26px] h-[26px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                            </path>
                        </svg>
                    </div>
                    <div>
                        <div
                            class="text-[32px] font-extrabold text-white mb-0.5 leading-none font-system tracking-tight">
                            â‚¦120B+</div>
                        <div class="text-[13px] text-white/90 font-medium">Verified Transactions</div>
                    </div>
                </div>

                <!-- Stat 2 -->
                <div class="flex items-center gap-5 lg:px-8 justify-center lg:justify-start">
                    <div
                        class="w-[52px] h-[52px] shrink-0 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-white border border-white/10 shadow-inner">
                        <svg class="w-[26px] h-[26px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                    </div>
                    <div>
                        <div
                            class="text-[32px] font-extrabold text-white mb-0.5 leading-none font-system tracking-tight">
                            4,800+</div>
                        <div class="text-[13px] text-white/90 font-medium">Active Listings</div>
                    </div>
                </div>

                <!-- Stat 3 -->
                <div class="flex items-center gap-5 lg:px-8 justify-center lg:justify-start">
                    <div
                        class="w-[52px] h-[52px] shrink-0 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-white border border-white/10 shadow-inner">
                        <svg class="w-[26px] h-[26px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M14.4 14.4l-4.8 4.8a2.5 2.5 0 0 1-3.5 0l-2.7-2.7a2.5 2.5 0 0 1 0-3.5l10-10a2.5 2.5 0 0 1 3.5 0l2.7 2.7a2.5 2.5 0 0 1 0 3.5z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 8l8 8M18.8 9.2l-4.8 4.8M9.2 18.8l4.8-4.8"></path>
                        </svg>
                    </div>
                    <div>
                        <div
                            class="text-[32px] font-extrabold text-white mb-0.5 leading-none font-system tracking-tight">
                            99.8%</div>
                        <div class="text-[13px] text-white/90 font-medium">Clean Title Diligence</div>
                    </div>
                </div>

                <!-- Stat 4 -->
                <div class="flex items-center gap-5 lg:px-8 justify-center lg:justify-start">
                    <div
                        class="w-[52px] h-[52px] shrink-0 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center text-white border border-white/10 shadow-inner">
                        <svg class="w-[26px] h-[26px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <circle cx="12" cy="8" r="6" stroke-width="2"></circle>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8.21 13.89L7 21l5-3 5 3-1.21-7.11"></path>
                        </svg>
                    </div>
                    <div>
                        <div
                            class="text-[32px] font-extrabold text-white mb-0.5 leading-none font-system tracking-tight">
                            15 Mins</div>
                        <div class="text-[13px] text-white/90 font-medium">Average Broker Response</div>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- 11. FAQ -->
    <section class="max-w-3xl mx-auto px-4 sm:px-6 mb-24 md:mb-28" x-data="{ activeAccordion: 1 }">
        <div class="flex justify-center mb-10">
            <div
                class="inline-flex items-center gap-3 px-6 py-3 rounded-full border border-white/10 bg-slate-900/50 backdrop-blur-sm text-blue-500 shadow-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z">
                    </path>
                </svg>
                <span class="text-[15px] font-system font-extrabold tracking-wider uppercase">FREQUENTLY ASKED
                    QUESTIONS</span>
            </div>
        </div>
        <div class="space-y-4">
            <!-- Item 1 -->
            <div class="border rounded-2xl bg-slate-900/40 overflow-hidden transition-all duration-300"
                :class="activeAccordion === 1 ? 'border-blue-600/60 shadow-lg shadow-blue-950/20' : 'border-white/10 hover:border-white/20'">
                <button @click="activeAccordion = activeAccordion === 1 ? null : 1"
                    class="w-full px-6 py-5 text-left flex justify-between items-center font-bold focus:outline-none transition-colors duration-300"
                    :class="activeAccordion === 1 ? 'text-blue-400' : 'text-white'">
                    <span class="text-[17px] font-system tracking-tight">How do physical and virtual inspection tours
                        work?</span>
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-white shrink-0 transition-all duration-300"
                        :class="activeAccordion === 1 ? 'bg-blue-600 rotate-180' : 'bg-white/10 border border-white/10'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </button>
                <div x-show="activeAccordion === 1" x-collapse
                    class="px-6 pb-6 text-[15px] leading-relaxed text-slate-300 border-t border-white/5 pt-4">
                    Simply click "Schedule Inspection Tour" on any property page. You can choose between an in-person
                    guided walk-through with a certified broker or an interactive live video call tour. Our concierge
                    confirms your date, coordinates building access, and sends you a calendar invite within 15 minutes.
                    <div
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1 rounded-full border border-blue-900/50 bg-blue-950/40 text-blue-300/80 text-[10px] font-semibold font-system tracking-wide">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Flexible 7 days a week inspection availability.
                    </div>
                </div>
            </div>

            <!-- Item 2 -->
            <div class="border rounded-2xl bg-slate-900/40 overflow-hidden transition-all duration-300"
                :class="activeAccordion === 2 ? 'border-blue-600/60 shadow-lg shadow-blue-950/20' : 'border-white/10 hover:border-white/20'">
                <button @click="activeAccordion = activeAccordion === 2 ? null : 2"
                    class="w-full px-6 py-5 text-left flex justify-between items-center font-bold focus:outline-none transition-colors duration-300"
                    :class="activeAccordion === 2 ? 'text-blue-400' : 'text-white'">
                    <span class="text-[17px] font-system tracking-tight">How is the property verification process
                        handled?</span>
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-white shrink-0 transition-all duration-300"
                        :class="activeAccordion === 2 ? 'bg-blue-600 rotate-180' : 'bg-white/10 border border-white/10'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </button>
                <div x-show="activeAccordion === 2" x-collapse
                    class="px-6 pb-6 text-[15px] leading-relaxed text-slate-300 border-t border-white/5 pt-4">
                    We strictly vet every listing. Our internal legal team conducts searches at the state Lands Bureau
                    to confirm Governor's Consent, C of O, and ensures there are no encumbrances before assigning the
                    "Verified Title" badge.
                    <div
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1 rounded-full border border-blue-900/50 bg-blue-950/40 text-blue-300/80 text-[10px] font-semibold font-system tracking-wide">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Flexible 7 days a week inspection availability.
                    </div>
                </div>
            </div>

            <!-- Item 3 -->
            <div class="border rounded-2xl bg-slate-900/40 overflow-hidden transition-all duration-300"
                :class="activeAccordion === 3 ? 'border-blue-600/60 shadow-lg shadow-blue-950/20' : 'border-white/10 hover:border-white/20'">
                <button @click="activeAccordion = activeAccordion === 3 ? null : 3"
                    class="w-full px-6 py-5 text-left flex justify-between items-center font-bold focus:outline-none transition-colors duration-300"
                    :class="activeAccordion === 3 ? 'text-blue-400' : 'text-white'">
                    <span class="text-[17px] font-system tracking-tight">Can diaspora buyers purchase properties?</span>
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-white shrink-0 transition-all duration-300"
                        :class="activeAccordion === 3 ? 'bg-blue-600 rotate-180' : 'bg-white/10 border border-white/10'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </button>
                <div x-show="activeAccordion === 3" x-collapse
                    class="px-6 pb-6 text-[15px] leading-relaxed text-slate-300 border-t border-white/5 pt-4">
                    Yes! Over 40% of our buyers are based in the US, UK, and Canada. We facilitate virtual inspections
                    and use milestone escrow payments so your funds are safe until conditions are met.
                    <div
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1 rounded-full border border-blue-900/50 bg-blue-950/40 text-blue-300/80 text-[10px] font-semibold font-system tracking-wide">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Flexible 7 days a week inspection availability.
                    </div>
                </div>
            </div>

            <!-- Item 4 -->
            <div class="border rounded-2xl bg-slate-900/40 overflow-hidden transition-all duration-300"
                :class="activeAccordion === 4 ? 'border-blue-600/60 shadow-lg shadow-blue-950/20' : 'border-white/10 hover:border-white/20'">
                <button @click="activeAccordion = activeAccordion === 4 ? null : 4"
                    class="w-full px-6 py-5 text-left flex justify-between items-center font-bold focus:outline-none transition-colors duration-300"
                    :class="activeAccordion === 4 ? 'text-blue-400' : 'text-white'">
                    <span class="text-[17px] font-system tracking-tight">What does Escrow Protection mean?</span>
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-white shrink-0 transition-all duration-300"
                        :class="activeAccordion === 4 ? 'bg-blue-600 rotate-180' : 'bg-white/10 border border-white/10'">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                </button>
                <div x-show="activeAccordion === 4" x-collapse
                    class="px-6 pb-6 text-[15px] leading-relaxed text-slate-300 border-t border-white/5 pt-4">
                    Your payment is held in a neutral third-party trust account and only released to the
                    seller/developer when all agreed legal documents and property handovers are successfully verified.
                    <div
                        class="mt-4 inline-flex items-center gap-1.5 px-3 py-1 rounded-full border border-blue-900/50 bg-blue-950/40 text-blue-300/80 text-[10px] font-semibold font-system tracking-wide">
                        <svg class="w-3.5 h-3.5 text-emerald-500 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Flexible 7 days a week inspection availability.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 11. CRM Portal -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 mb-24">
        <div
            class="relative bg-[#0B0F1A] rounded-3xl p-8 sm:p-12 border border-slate-800/60 shadow-2xl flex flex-col lg:flex-row items-start gap-12 overflow-hidden">

            <!-- Bottom-left emerald glow -->
            <div class="absolute bottom-0 left-0 w-72 h-72 rounded-full blur-3xl pointer-events-none"
                style="background: radial-gradient(circle, rgba(16,185,129,0.22) 0%, transparent 70%); transform: translate(-30%, 30%);">
            </div>
            <!-- Top-right blue glow -->
            <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/10 rounded-full blur-3xl pointer-events-none">
            </div>

            <!-- Left: Content -->
            <div class="flex-1 z-10">
                <!-- Badge -->
                <div
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-slate-600/50 bg-slate-800/50 text-slate-300 text-[10px] font-bold uppercase tracking-wider mb-6">
                    <svg class="w-3 h-3 text-yellow-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z" />
                    </svg>
                    NIESV &amp; REDAN Partner Broker Network
                </div>

                <h2 style="font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue','Noto Sans',Arial,sans-serif,'Apple Color Emoji','Segoe UI Emoji','Segoe UI Symbol','Noto Color Emoji';font-size:36px;font-weight:900;line-height:1.1"
                    class="text-white tracking-tight mb-4">Real Estate Agent &amp; Developer CRM Portal</h2>

                <p class="text-slate-400 text-sm leading-relaxed mb-8 max-w-lg">
                    Are you a verified real estate agent, developer, or property surveyor? Sign in to your dedicated
                    broker workstation to manage buyer inquiries, schedule virtual inspections, and receive automated
                    WhatsApp lead broadcasts.
                </p>

                <!-- 2-column feature list -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3">
                    <div class="flex items-center gap-2.5 text-slate-300 text-sm">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Instant WhatsApp Lead Broadcasts
                    </div>
                    <div class="flex items-center gap-2.5 text-slate-300 text-sm">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Direct Commission Escrow Payouts
                    </div>
                    <div class="flex items-center gap-2.5 text-slate-300 text-sm">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Lands Bureau Title Verification Tools
                    </div>
                    <div class="flex items-center gap-2.5 text-slate-300 text-sm">
                        <svg class="w-4 h-4 text-emerald-400 shrink-0" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Calendar Sync for Inspection Tours
                    </div>
                </div>
            </div>

            <!-- Right: Portal Access Panel -->
            <div
                class="w-full lg:w-[360px] z-10 bg-slate-900/70 border border-slate-700/50 rounded-2xl p-6 shadow-2xl backdrop-blur-sm">
                <!-- Header -->
                <div class="flex items-start justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center">
                            <svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-white font-bold text-sm leading-tight">Broker / Agent Login</p>
                            <p class="text-slate-500 text-[11px] mt-0.5">Secure 256-bit encrypted access</p>
                        </div>
                    </div>
                    <!-- Live green dot -->
                    <span class="relative flex h-2.5 w-2.5 mt-1">
                        <span
                            class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                    </span>
                </div>

                <!-- Divider -->
                <div class="border-t border-slate-700/50 mb-5"></div>

                <!-- Description -->
                <p class="text-slate-400 text-xs leading-relaxed mb-6">
                    Access your dedicated CRM dashboard to manage listings, track leads, and coordinate inspections â€”
                    all in one secure workspace.
                </p>

                <!-- CTA Button -->
                <a href="/agent-login.html"
                    class="flex items-center justify-center gap-2 w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-xl transition-colors text-sm shadow-lg shadow-blue-600/20 cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    Sign In to Agent Portal
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </a>

                <p class="text-center text-[11px] text-slate-500 mt-4">
                    Not a registered agent yet? <a href="#" class="text-blue-400 hover:underline font-semibold">Apply
                        for Verification</a>
                </p>
            </div>

        </div>
    </section>

    <!-- 13. Footer -->
    <footer class="bg-slate-50 dark:bg-slate-950 text-slate-600 dark:text-slate-400"
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
                                class="tracking-tight text-slate-900 dark:text-white">Bofast Homes</span>
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
                <p>&copy; 2026 Bofast Homes. Nigeria's most trusted verified property marketplace. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>

</html>
