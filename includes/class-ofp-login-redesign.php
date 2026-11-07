<?php
/**
 * OFP_Login_Redesign
 *
 * Modern Dark Login Customizer for MyPropertyHQ / OFast Pipeline.
 * Transforms wp-login.php with a sleek frosted glassmorphism interface,
 * dark theme styling, branded logo, and glowing buttons.
 *
 * Exclusively provides the Modern-Dark design template as requested.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Login_Redesign {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Get singleton instance.
     */
    public static function get_instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bootstraps the modern dark login hooks.
     */
    public static function init(): void {
        $inst = self::get_instance();

        add_action( 'login_enqueue_scripts',    [ $inst, 'enqueue_login_styles' ], 20 );
        add_filter( 'login_headerurl',          [ $inst, 'filter_login_headerurl' ] );
        add_filter( 'login_headertext',         [ $inst, 'filter_login_headertext' ] );
        add_filter( 'login_title',              [ $inst, 'filter_login_title' ] );
        add_filter( 'register',                 [ $inst, 'filter_register_link' ], 999 );
        add_filter( 'the_privacy_policy_link',  [ $inst, 'filter_privacy_policy_link' ], 999 );
        add_action( 'login_footer',             [ $inst, 'render_login_footer_cleanup' ], 999 );
    }

    /**
     * Check if login redesign is active.
     */
    public function is_enabled(): bool {
        return (bool) get_option( 'ofp_login_redesign_enabled', 1 );
    }

    /**
     * Get module configuration settings.
     *
     * @return array<string, mixed>
     */
    public function get_settings(): array {
        return [
            'enabled'            => (bool) get_option( 'ofp_login_redesign_enabled', 1 ),
            'card_color'         => (string) get_option( 'ofp_login_card_color', '#0f172a' ),
            'card_opacity'       => (int) get_option( 'ofp_login_card_opacity', 75 ),
            'bg_image'           => (string) get_option( 'ofp_login_bg_image', '' ),
            'overlay_color'      => (string) get_option( 'ofp_login_overlay_color', '#000000' ),
            'overlay_opacity'    => (int) get_option( 'ofp_login_overlay_opacity', 40 ),
            'logo_width'         => (int) get_option( 'ofp_login_logo_width', 220 ),
            'logo_height'        => (int) get_option( 'ofp_login_logo_height', 64 ),
            'hide_back_link'      => (bool) get_option( 'ofp_login_hide_back_link', 0 ),
            'hide_register'       => (bool) get_option( 'ofp_login_hide_register', 0 ),
            'hide_lost_password'  => (bool) get_option( 'ofp_login_hide_lost_password', 0 ),
            'hide_privacy_policy' => (bool) get_option( 'ofp_login_hide_privacy_policy', 0 ),
            'custom_css'          => (string) get_option( 'ofp_login_custom_css', '' ),
        ];
    }

    /**
     * Suppress registration link if hide_register is enabled.
     */
    public function filter_register_link( string $link ): string {
        if ( ! $this->is_enabled() ) {
            return $link;
        }
        $s = $this->get_settings();
        return ! empty( $s['hide_register'] ) ? '' : $link;
    }

    /**
     * Suppress privacy policy link if hide_privacy_policy is enabled.
     */
    public function filter_privacy_policy_link( string $link ): string {
        if ( ! $this->is_enabled() ) {
            return $link;
        }
        $s = $this->get_settings();
        return ! empty( $s['hide_privacy_policy'] ) ? '' : $link;
    }

    /**
     * Cleanup orphan separator (|) in #nav when Register is hidden.
     */
    public function render_login_footer_cleanup(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }
        $s = $this->get_settings();
        if ( empty( $s['hide_register'] ) && empty( $s['hide_lost_password'] ) ) {
            return;
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var nav = document.getElementById('nav');
            if (nav) {
                <?php if ( ! empty( $s['hide_register'] ) && ! empty( $s['hide_lost_password'] ) ) : ?>
                    nav.style.display = 'none';
                <?php else : ?>
                    var nodes = nav.childNodes;
                    for (var i = 0; i < nodes.length; i++) {
                        if (nodes[i].nodeType === 3 && nodes[i].textContent.indexOf('|') !== -1) {
                            nodes[i].textContent = nodes[i].textContent.replace(/\|/g, '').trim();
                        }
                    }
                <?php endif; ?>
            }
        });
        </script>
        <?php
    }

    /**
     * Redirect login logo link to home URL.
     */
    public function filter_login_headerurl(): string {
        return esc_url( home_url( '/' ) );
    }

    /**
     * Tooltip / alt text for login logo.
     */
    public function filter_login_headertext(): string {
        return class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
    }

    /**
     * Browser tab title for login screen.
     */
    public function filter_login_title( string $title ): string {
        $brand_name = class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : get_bloginfo( 'name' );
        return esc_html( 'Log In ‹ ' . $brand_name );
    }

    /**
     * Enqueue modern dark glassmorphism CSS into wp-login.php.
     */
    public function enqueue_login_styles(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $s            = $this->get_settings();
        $cardColor    = ! empty( $s['card_color'] ) ? $s['card_color'] : '#0f172a';
        $cardOpacity  = max( 10, min( 100, $s['card_opacity'] ) ) / 100;
        $overlayColor = ! empty( $s['overlay_color'] ) ? $s['overlay_color'] : '#000000';
        $overlayOpac  = max( 0, min( 100, $s['overlay_opacity'] ) ) / 100;
        $cardRgb      = $this->hex_to_rgb( $cardColor );
        $overlayRgb   = $this->hex_to_rgb( $overlayColor );
        $bgImage      = $s['bg_image'];
        $logoUrl      = class_exists( 'OFP_Brand' ) ? OFP_Brand::logo_url() : '';
        $logoWidth    = max( 40, min( 500, $s['logo_width'] ) );
        $logoHeight   = max( 20, min( 200, $s['logo_height'] ) );

        $css = '<style id="ofp-login-modern-dark">' . "\n";

        // Global background & reset
        $css .= 'body.login {';
        $css .= '  background-color: ' . esc_attr( $cardColor ) . ' !important;';
        if ( ! empty( $bgImage ) ) {
            $css .= '  background-image: url("' . esc_url( $bgImage ) . '") !important;';
            $css .= '  background-size: cover !important;';
            $css .= '  background-position: center !important;';
            $css .= '  background-repeat: no-repeat !important;';
            $css .= '  background-attachment: fixed !important;';
        } else {
            // High-tech subtle dark radial mesh background
            $css .= '  background-image: radial-gradient(at 15% 15%, rgba(37,99,235,0.18) 0px, transparent 50%), radial-gradient(at 85% 85%, rgba(14,165,233,0.14) 0px, transparent 50%) !important;';
        }
        $css .= '  min-height: 100vh;';
        $css .= '  display: flex !important;';
        $css .= '  flex-direction: column !important;';
        $css .= '  align-items: center !important;';
        $css .= '  justify-content: center !important;';
        $css .= '  margin: 0 !important;';
        $css .= '  position: relative;';
        $css .= '  color: #f1f5f9;';
        $css .= '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;';
        $css .= '}' . "\n";

        // Optional full-page background dark overlay
        if ( $overlayOpac > 0 && ! empty( $bgImage ) ) {
            $css .= 'body.login::before {';
            $css .= '  content: "";';
            $css .= '  position: fixed;';
            $css .= '  top: 0; left: 0; right: 0; bottom: 0;';
            $css .= '  background: rgba(' . $overlayRgb . ', ' . $overlayOpac . ');';
            $css .= '  z-index: 0;';
            $css .= '}' . "\n";
        }

        // Login wrapper container
        $css .= '#login {';
        $css .= '  position: relative;';
        $css .= '  z-index: 1;';
        $css .= '  width: 100% !important;';
        $css .= '  max-width: 380px !important;';
        $css .= '  padding: 24px 16px !important;';
        $css .= '  margin: auto !important;';
        $css .= '}' . "\n";

        // Logo lockup
        $css .= '#login h1 {';
        $css .= '  text-align: center !important;';
        $css .= '  margin-bottom: 24px !important;';
        $css .= '}' . "\n";

        $css .= '#login h1 a {';
        if ( ! empty( $logoUrl ) ) {
            $css .= '  background-image: url("' . esc_url( $logoUrl ) . '") !important;';
        }
        $css .= '  background-size: contain !important;';
        $css .= '  background-position: center !important;';
        $css .= '  background-repeat: no-repeat !important;';
        $css .= '  width: ' . esc_attr( $logoWidth ) . 'px !important;';
        $css .= '  height: ' . esc_attr( $logoHeight ) . 'px !important;';
        $css .= '  max-width: 100% !important;';
        $css .= '  margin: 0 auto !important;';
        $css .= '  display: block !important;';
        $css .= '  border: none !important;';
        $css .= '  box-shadow: none !important;';
        $css .= '  outline: none !important;';
        $css .= '}' . "\n";

        // Glassmorphism Form Card
        $css .= '#loginform, #registerform, #lostpasswordform {';
        $css .= '  background: rgba(' . $cardRgb . ', ' . $cardOpacity . ') !important;';
        $css .= '  backdrop-filter: blur(16px);';
        $css .= '  -webkit-backdrop-filter: blur(16px);';
        $css .= '  border: 1px solid rgba(255, 255, 255, 0.12) !important;';
        $css .= '  border-radius: 16px !important;';
        $css .= '  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.05) !important;';
        $css .= '  padding: 34px 28px !important;';
        $css .= '  margin-top: 0 !important;';
        $css .= '}' . "\n";

        // Form labels
        $css .= 'body.login label {';
        $css .= '  color: #cbd5e1 !important;';
        $css .= '  font-size: 13px !important;';
        $css .= '  font-weight: 500 !important;';
        $css .= '  margin-bottom: 6px !important;';
        $css .= '  display: block !important;';
        $css .= '}' . "\n";

        // Modern Input Fields
        $css .= '.login form .input, .login input[type="text"], .login input[type="password"], .login input[type="email"] {';
        $css .= '  background: rgba(0, 0, 0, 0.32) !important;';
        $css .= '  border: 1px solid rgba(255, 255, 255, 0.14) !important;';
        $css .= '  border-radius: 9px !important;';
        $css .= '  color: #ffffff !important;';
        $css .= '  padding: 10px 14px !important;';
        $css .= '  font-size: 14px !important;';
        $css .= '  line-height: 1.4 !important;';
        $css .= '  margin-top: 4px !important;';
        $css .= '  margin-bottom: 16px !important;';
        $css .= '  width: 100% !important;';
        $css .= '  box-sizing: border-box !important;';
        $css .= '  box-shadow: none !important;';
        $css .= '  transition: border-color 0.18s ease, box-shadow 0.18s ease !important;';
        $css .= '}' . "\n";

        $css .= '.login form .input:focus, .login input[type="text"]:focus, .login input[type="password"]:focus, .login input[type="email"]:focus {';
        $css .= '  border-color: #3b82f6 !important;';
        $css .= '  background: rgba(0, 0, 0, 0.45) !important;';
        $css .= '  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.28) !important;';
        $css .= '  outline: none !important;';
        $css .= '}' . "\n";

        // Remember Me Checkbox
        $css .= '.login .forgetmenot {';
        $css .= '  float: none !important;';
        $css .= '  margin-bottom: 18px !important;';
        $css .= '  display: flex !important;';
        $css .= '  align-items: center !important;';
        $css .= '}' . "\n";

        $css .= '.login .forgetmenot label {';
        $css .= '  margin-bottom: 0 !important;';
        $css .= '  display: inline-flex !important;';
        $css .= '  align-items: center !important;';
        $css .= '  font-size: 13px !important;';
        $css .= '  color: #94a3b8 !important;';
        $css .= '  cursor: pointer !important;';
        $css .= '}' . "\n";

        $css .= '.login .forgetmenot input[type="checkbox"] {';
        $css .= '  background: rgba(0, 0, 0, 0.35) !important;';
        $css .= '  border: 1px solid rgba(255, 255, 255, 0.2) !important;';
        $css .= '  border-radius: 4px !important;';
        $css .= '  margin-right: 8px !important;';
        $css .= '}' . "\n";

        // Primary Submit Button: Pure iOS Glassmorphic (no color, blends with glass card)
        $css .= '.wp-core-ui .button-primary {';
        $css .= '  width: 100% !important;';
        $css .= '  float: none !important;';
        $css .= '  background: rgba(255, 255, 255, 0.08) !important;';
        $css .= '  backdrop-filter: blur(20px) saturate(180%) !important;';
        $css .= '  -webkit-backdrop-filter: blur(20px) saturate(180%) !important;';
        $css .= '  border: 1px solid rgba(255, 255, 255, 0.2) !important;';
        $css .= '  color: #ffffff !important;';
        $css .= '  text-shadow: none !important;';
        $css .= '  border-radius: 10px !important;';
        $css .= '  padding: 0 16px !important;';
        $css .= '  height: 44px !important;';
        $css .= '  line-height: 44px !important;';
        $css .= '  font-size: 15px !important;';
        $css .= '  font-weight: 600 !important;';
        $css .= '  letter-spacing: -0.01em !important;';
        $css .= '  cursor: pointer !important;';
        $css .= '  box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.25), inset 0 1px 0 0 rgba(255, 255, 255, 0.25) !important;';
        $css .= '  transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;';
        $css .= '  margin-top: 10px !important;';
        $css .= '}' . "\n";

        $css .= '.wp-core-ui .button-primary:hover, .wp-core-ui .button-primary:focus {';
        $css .= '  background: rgba(255, 255, 255, 0.16) !important;';
        $css .= '  border-color: rgba(255, 255, 255, 0.35) !important;';
        $css .= '  box-shadow: 0 12px 36px 0 rgba(0, 0, 0, 0.35), inset 0 1px 0 0 rgba(255, 255, 255, 0.4) !important;';
        $css .= '  transform: translateY(-1px) !important;';
        $css .= '  outline: none !important;';
        $css .= '}' . "\n";

        $css .= '.wp-core-ui .button-primary:active {';
        $css .= '  background: rgba(255, 255, 255, 0.05) !important;';
        $css .= '  transform: translateY(0) scale(0.99) !important;';
        $css .= '}' . "\n";

        // Error & Notice Cards
        $css .= '.login #login_error, .login .message, .login .success {';
        $css .= '  background: rgba(15, 23, 42, 0.85) !important;';
        $css .= '  border: 1px solid rgba(255, 255, 255, 0.1) !important;';
        $css .= '  border-left: 4px solid #3b82f6 !important;';
        $css .= '  color: #e2e8f0 !important;';
        $css .= '  border-radius: 8px !important;';
        $css .= '  padding: 12px 16px !important;';
        $css .= '  margin-bottom: 20px !important;';
        $css .= '  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3) !important;';
        $css .= '  backdrop-filter: blur(10px) !important;';
        $css .= '}' . "\n";

        $css .= '.login #login_error { border-left-color: #ef4444 !important; }' . "\n";
        $css .= '.login .success     { border-left-color: #10b981 !important; }' . "\n";

        // Nav Links (Lost Password, Register)
        $css .= '.login #nav, .login #backtoblog {';
        $css .= '  padding: 0 !important;';
        $css .= '  margin-top: 18px !important;';
        $css .= '  text-align: center !important;';
        $css .= '}' . "\n";

        $css .= '.login #nav a, .login #backtoblog a {';
        $css .= '  color: #94a3b8 !important;';
        $css .= '  font-size: 13px !important;';
        $css .= '  text-decoration: none !important;';
        $css .= '  transition: color 0.15s ease !important;';
        $css .= '}' . "\n";

        $css .= '.login #nav a:hover, .login #backtoblog a:hover {';
        $css .= '  color: #ffffff !important;';
        $css .= '}' . "\n";

        // Privacy Policy Link
        $css .= '.login .privacy-policy-page-link {';
        $css .= '  text-align: center !important;';
        $css .= '  margin-top: 14px !important;';
        $css .= '}' . "\n";

        $css .= '.login .privacy-policy-page-link a {';
        $css .= '  color: #64748b !important;';
        $css .= '  font-size: 12px !important;';
        $css .= '  text-decoration: none !important;';
        $css .= '}' . "\n";

        // Hide Back to Site if enabled
        if ( ! empty( $s['hide_back_link'] ) ) {
            $css .= '#backtoblog { display: none !important; }' . "\n";
        }

        // Hide Register link if enabled
        if ( ! empty( $s['hide_register'] ) ) {
            $css .= '.login #nav a[href*="action=register"], #login #nav a[href*="action=register"], .login #nav a.registration { display: none !important; }' . "\n";
        }

        // Hide Lost Password link if enabled
        if ( ! empty( $s['hide_lost_password'] ) ) {
            $css .= '.login #nav a[href*="action=lostpassword"], #login #nav a[href*="action=lostpassword"] { display: none !important; }' . "\n";
        }

        // If both register and lost password are hidden, hide #nav wrapper completely
        if ( ! empty( $s['hide_register'] ) && ! empty( $s['hide_lost_password'] ) ) {
            $css .= '.login #nav, #login #nav { display: none !important; }' . "\n";
        }

        // Hide Privacy Policy link if enabled
        if ( ! empty( $s['hide_privacy_policy'] ) ) {
            $css .= '.login .privacy-policy-page-link, #login .privacy-policy-page-link { display: none !important; }' . "\n";
        }

        // Custom CSS Override
        if ( ! empty( $s['custom_css'] ) ) {
            $css .= wp_strip_all_tags( $s['custom_css'] ) . "\n";
        }

        $css .= '</style>' . "\n";

        echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Convert 3- or 6-digit hex color to RGB string (e.g. "15, 23, 42").
     */
    private function hex_to_rgb( string $hex ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return '15, 23, 42'; // Default dark slate
        }
        return hexdec( substr( $hex, 0, 2 ) ) . ', ' . hexdec( substr( $hex, 2, 2 ) ) . ', ' . hexdec( substr( $hex, 4, 2 ) );
    }
}

