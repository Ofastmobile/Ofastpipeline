<?php
/**
 * OFP_Property_Package
 *
 * The Total Move-in Package of a rental property, kept in ONE place.
 *
 * What it does
 *  - Stores the fee breakdown on the property: legal, agency, caution, service charge
 *    (these are the same fields the lease offer form has).
 *  - Calculates the total: rent + fees, and saves it in `ofp_total_package`
 *    (the field the rest of the plugin already reads).
 *  - Keeps `ofp_price_effective` in sync: the "real" price tenants pay to move in.
 *    The marketplace price filter and price sorting use it, so a search for
 *    "under N2m" compares real move-in cost, not bare rent.
 *  - Gives the lease offer form its defaults, so staff do not retype the fees.
 *  - Gives every listing card one helper to show the total consistently.
 *
 * Old listings keep working: if only a typed total exists (no fees), that total is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Package {

    const FEES = [
        'legal'   => 'ofp_fee_legal',
        'agency'  => 'ofp_fee_agency',
        'caution' => 'ofp_fee_caution',
        'service' => 'ofp_fee_service',
    ];
    const META_RECURRING = 'ofp_fee_service_recurring';
    const META_EFFECTIVE = 'ofp_price_effective';
    const BACKFILL_FLAG  = 'ofp_package_backfill_v1';
    const RENT_TYPES     = [ 'rent', 'shortlet', 'lease' ];

    /** Stops refresh() from calling itself when it writes meta. */
    private static $busy = false;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'maybe_backfill' ], 30 );
        add_action( 'added_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );
        add_action( 'updated_post_meta', [ __CLASS__, 'on_meta_change' ], 10, 4 );
    }

    // ── Keeping totals in sync ─────────────────────────────────────────

    /** Any change to a price-related field recalculates the total and the effective price. */
    public static function on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
        static $watch = null;
        if ( null === $watch ) {
            $watch = array_merge(
                array_values( self::FEES ),
                [ self::META_RECURRING, 'ofp_price', 'ofp_total_package', 'ofp_listing_type', 'ofp_price_period' ]
            );
        }
        if ( ! in_array( $meta_key, $watch, true ) || 'ofp_property' !== get_post_type( $post_id ) ) {
            return;
        }
        self::refresh( (int) $post_id );
    }

    /** Recalculate the total (when fees are itemized) and the effective price. */
    public static function refresh( int $post_id ): void {
        if ( self::$busy ) {
            return;
        }
        self::$busy = true;

        $is_rent = in_array( (string) get_post_meta( $post_id, 'ofp_listing_type', true ), self::RENT_TYPES, true );
        $rent    = (float) get_post_meta( $post_id, 'ofp_price', true );
        $fees    = self::fees_sum( $post_id );

        // Itemized fees win: the total is always rent + fees, so it can never disagree with the offer.
        if ( $is_rent && $fees > 0 ) {
            $total = round( $rent + $fees, 2 );
            if ( abs( (float) get_post_meta( $post_id, 'ofp_total_package', true ) - $total ) > 0.004 ) {
                update_post_meta( $post_id, 'ofp_total_package', $total );
            }
        }

        $total     = (float) get_post_meta( $post_id, 'ofp_total_package', true );
        $effective = ( $is_rent && $total > $rent + 0.004 ) ? $total : $rent;
        update_post_meta( $post_id, self::META_EFFECTIVE, $effective );

        self::$busy = false;
    }

    private static function fees_sum( int $post_id ): float {
        $sum = 0.0;
        foreach ( self::FEES as $meta ) {
            $sum += max( 0.0, (float) get_post_meta( $post_id, $meta, true ) );
        }
        return round( $sum, 2 );
    }

    /**
     * Save the fee fields posted by a property form.
     * Does nothing unless the form sent the marker field, so programmatic saves never wipe fees.
     *
     * @param array $src Usually $_POST.
     */
    public static function save( int $post_id, array $src ): void {
        if ( empty( $src['ofp_fee_present'] ) ) {
            return;
        }
        foreach ( self::FEES as $meta ) {
            update_post_meta( $post_id, $meta, max( 0, round( (float) ( $src[ $meta ] ?? 0 ), 2 ) ) );
        }
        update_post_meta( $post_id, self::META_RECURRING, ! empty( $src[ self::META_RECURRING ] ) ? '1' : '0' );
        self::refresh( $post_id );
    }

    // ── Reading ────────────────────────────────────────────────────────

    /**
     * The package for display, or null when this listing has none to show
     * (not a rental, or the total is not more than the rent).
     *
     * @return array|null { rent, total, fees, lines[label=>amount], period, itemized, service_recurring }
     */
    public static function get( int $post_id ): ?array {
        if ( ! in_array( (string) get_post_meta( $post_id, 'ofp_listing_type', true ), self::RENT_TYPES, true ) ) {
            return null;
        }
        $rent  = (float) get_post_meta( $post_id, 'ofp_price', true );
        $total = (float) get_post_meta( $post_id, 'ofp_total_package', true );
        if ( $total <= $rent + 0.004 ) {
            return null;
        }

        $labels = [ 'legal' => 'Legal fee', 'agency' => 'Agency fee', 'caution' => 'Caution deposit', 'service' => 'Service charge' ];
        $lines  = [];
        foreach ( self::FEES as $key => $meta ) {
            $v = (float) get_post_meta( $post_id, $meta, true );
            if ( $v > 0 ) {
                $lines[ $labels[ $key ] ] = $v;
            }
        }

        return [
            'rent'              => $rent,
            'total'             => $total,
            'fees'              => round( $total - $rent, 2 ),
            'lines'             => $lines,
            'period'            => self::period_label( (string) get_post_meta( $post_id, 'ofp_price_period', true ) ),
            'itemized'          => ! empty( $lines ),
            'service_recurring' => '1' === (string) get_post_meta( $post_id, self::META_RECURRING, true ),
        ];
    }

    public static function period_label( string $period ): string {
        $map = [ 'year' => 'year', '2years' => '2 years', 'month' => 'month' ];
        return $map[ $period ] ?? 'year';
    }

    /** One short line for a listing card: "Total move-in · Rent N1,500,000 / year". */
    public static function note( ?array $pkg ): string {
        if ( ! $pkg ) {
            return '';
        }
        return 'Total move-in · Rent ₦' . number_format( $pkg['rent'] ) . ' / ' . $pkg['period'];
    }

    /** Defaults for the lease offer form, taken from the property. */
    public static function offer_defaults( int $property_id ): array {
        $is_rent = in_array( (string) get_post_meta( $property_id, 'ofp_listing_type', true ), self::RENT_TYPES, true );
        $out     = [ 'legal' => 0.0, 'agency' => 0.0, 'caution' => 0.0, 'service' => 0.0, 'recurring' => 0 ];
        if ( ! $is_rent ) {
            return $out;
        }
        foreach ( self::FEES as $key => $meta ) {
            $out[ $key ] = max( 0.0, (float) get_post_meta( $property_id, $meta, true ) );
        }
        $out['recurring'] = '1' === (string) get_post_meta( $property_id, self::META_RECURRING, true ) ? 1 : 0;
        return $out;
    }

    // ── Form fields (shared by the portal, admin portal and wp-admin forms) ──

    /**
     * Print the fee inputs. Drop this inside the existing "Total Move-in Package" block of a form.
     *
     * @param int    $post_id       Property being edited (0 for a new one).
     * @param string $rent_selector CSS selector of the rent/price input in this form.
     * @param string $total_selector CSS selector of the typed total input in this form.
     */
    public static function render_fields( int $post_id, string $rent_selector, string $total_selector ): void {
        $v = [];
        foreach ( self::FEES as $key => $meta ) {
            $raw       = $post_id ? get_post_meta( $post_id, $meta, true ) : '';
            $v[ $key ] = ( '' !== $raw && (float) $raw > 0 ) ? $raw : '';
        }
        $recurring = $post_id && '1' === (string) get_post_meta( $post_id, self::META_RECURRING, true );

        $fields = [
            'legal'   => [ 'Legal fee', 'ofp_fee_legal' ],
            'agency'  => [ 'Agency fee', 'ofp_fee_agency' ],
            'caution' => [ 'Caution deposit', 'ofp_fee_caution' ],
            'service' => [ 'Service charge', 'ofp_fee_service' ],
        ];
        $uid = 'ofp-pkg-' . wp_unique_id();
        ?>
        <div class="ofp-pkg" id="<?php echo esc_attr( $uid ); ?>" data-rent="<?php echo esc_attr( $rent_selector ); ?>" data-total="<?php echo esc_attr( $total_selector ); ?>">
            <style>
                .ofp-pkg{margin-top:12px;padding:12px;border:1px solid rgba(128,128,128,.3);border-radius:10px}
                .ofp-pkg-title{margin:0 0 4px;font-size:12.5px;font-weight:700}
                .ofp-pkg-hint{margin:0 0 10px;font-size:11.5px;opacity:.75}
                .ofp-pkg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
                .ofp-pkg label{display:block;font-size:12px;font-weight:600;margin-bottom:4px}
                .ofp-pkg input[type=number]{width:100%;padding:9px 10px;border-radius:8px;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;box-sizing:border-box}
                .ofp-pkg-recur{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:12px;font-weight:500}
                .ofp-pkg-sum{margin-top:10px;font-size:13px;font-weight:700}
            </style>
            <input type="hidden" name="ofp_fee_present" value="1">
            <p class="ofp-pkg-title">Move-in fees (optional)</p>
            <p class="ofp-pkg-hint">Fill these in and the total move-in price is calculated for you. Tenants see that total as the price, and it pre-fills your lease offers.</p>
            <div class="ofp-pkg-grid">
                <?php foreach ( $fields as $key => $f ) : ?>
                    <div>
                        <label><?php echo esc_html( $f[0] ); ?> (₦)</label>
                        <input type="number" step="0.01" min="0" name="<?php echo esc_attr( $f[1] ); ?>" value="<?php echo esc_attr( $v[ $key ] ); ?>" placeholder="0.00" data-fee>
                    </div>
                <?php endforeach; ?>
            </div>
            <label class="ofp-pkg-recur">
                <input type="checkbox" name="<?php echo esc_attr( self::META_RECURRING ); ?>" value="1" <?php checked( $recurring ); ?>>
                Service charge repeats every renewal
            </label>
            <div class="ofp-pkg-sum" data-sum></div>
            <script>
            (function () {
                var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
                if (!root) { return; }
                var form = root.closest('form') || document;
                var rent = form.querySelector(root.getAttribute('data-rent'));
                var total = form.querySelector(root.getAttribute('data-total'));
                var sumEl = root.querySelector('[data-sum]');
                var fees = root.querySelectorAll('[data-fee]');
                var auto = false;

                function fmt(n) { return '₦' + Math.round(n).toLocaleString(); }
                function update() {
                    var f = 0;
                    fees.forEach(function (i) { f += parseFloat(i.value) || 0; });
                    var r = rent ? (parseFloat(rent.value) || 0) : 0;
                    if (f > 0) {
                        if (total) { total.value = (r + f).toFixed(2); total.readOnly = true; auto = true; }
                        sumEl.textContent = 'Total move-in package: ' + fmt(r + f) + '  (rent ' + fmt(r) + ' + fees ' + fmt(f) + ')';
                    } else {
                        if (total) { total.readOnly = false; if (auto) { total.value = ''; auto = false; } }
                        sumEl.textContent = '';
                    }
                }
                fees.forEach(function (i) { i.addEventListener('input', update); });
                if (rent) { rent.addEventListener('input', update); }
                update();
            })();
            </script>
        </div>
        <?php
    }

    // ── One-time fill for listings that already exist ──────────────────

    /** Gives every existing property an effective price so marketplace filters and sorting keep working. */
    public static function maybe_backfill(): void {
        if ( '1' === (string) get_option( self::BACKFILL_FLAG, '' ) ) {
            return;
        }
        global $wpdb;
        $pm = $wpdb->postmeta;

        // 1. Every property gets an effective price (its rent or sale price).
        $wpdb->query(
            "INSERT INTO {$pm} (post_id, meta_key, meta_value)
             SELECT p.ID, 'ofp_price_effective', COALESCE(pr.meta_value, '0')
             FROM {$wpdb->posts} p
             LEFT JOIN {$pm} pr ON pr.post_id = p.ID AND pr.meta_key = 'ofp_price'
             WHERE p.post_type = 'ofp_property'
               AND NOT EXISTS (SELECT 1 FROM {$pm} e WHERE e.post_id = p.ID AND e.meta_key = 'ofp_price_effective')"
        );

        // 2. Rentals that already have a typed total larger than the rent use that total.
        $wpdb->query(
            "UPDATE {$pm} e
             JOIN {$pm} t  ON t.post_id  = e.post_id AND t.meta_key  = 'ofp_total_package'
             JOIN {$pm} lt ON lt.post_id = e.post_id AND lt.meta_key = 'ofp_listing_type'
             JOIN {$pm} pr ON pr.post_id = e.post_id AND pr.meta_key = 'ofp_price'
             SET e.meta_value = t.meta_value
             WHERE e.meta_key = 'ofp_price_effective'
               AND lt.meta_value IN ('rent','shortlet','lease')
               AND CAST(t.meta_value AS DECIMAL(14,2)) > CAST(pr.meta_value AS DECIMAL(14,2))"
        );

        update_option( self::BACKFILL_FLAG, '1', false );
    }
}
