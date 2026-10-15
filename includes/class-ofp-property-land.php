<?php
/**
 * Nigerian Land Sizing & Plot Breakdown Helper
 *
 * Provides accurate calculations, unit conversions, and market-standard
 * plot analytics tailored to Nigerian real estate (Lagos, Abuja, etc.).
 * Standard Lagos plot = 600 SQM (approx. 6,458 Sq Ft / 60ft x 120ft).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Property_Land {

    const STANDARD_PLOT_SQM = 600.0;
    const SQM_TO_SQFT       = 10.7639104;
    const ACRE_SQM          = 4046.85642;
    const HECTARE_SQM       = 10000.0;

    /**
     * Compute comprehensive plot and SQM breakdown for a property.
     *
     * @param int|WP_Post|null $post Property ID or object.
     * @return array Calculated metrics and labels.
     */
    public static function get_breakdown( $post = null ): array {
        $post_id = 0;
        if ( is_numeric( $post ) ) {
            $post_id = (int) $post;
        } elseif ( $post instanceof WP_Post ) {
            $post_id = $post->ID;
        } else {
            $post_id = get_the_ID();
        }

        if ( ! $post_id ) {
            return self::empty_breakdown();
        }

        $sqm             = (float) get_post_meta( $post_id, 'ofp_area_sqm', true );
        $price           = (float) get_post_meta( $post_id, 'ofp_price', true );
        $plots_override  = (float) get_post_meta( $post_id, 'ofp_plots_count', true );
        $topography      = get_post_meta( $post_id, 'ofp_land_topography', true );
        $zoning          = get_post_meta( $post_id, 'ofp_land_zoning', true );
        $dimensions      = get_post_meta( $post_id, 'ofp_land_dimensions', true );
        $survey_status   = get_post_meta( $post_id, 'ofp_survey_status', true );
        $title_document  = get_post_meta( $post_id, 'ofp_title_document', true );

        return self::calculate( $sqm, $price, $plots_override, [
            'topography'     => $topography,
            'zoning'         => $zoning,
            'dimensions'     => $dimensions,
            'survey_status'  => $survey_status,
            'title_document' => $title_document,
        ] );
    }

    /**
     * Calculate breakdown metrics from raw numeric values.
     *
     * @param float $sqm
     * @param float $price
     * @param float|null $plots_override
     * @param array $extra
     * @return array
     */
    public static function calculate( float $sqm, float $price = 0.0, ?float $plots_override = null, array $extra = [] ): array {
        if ( $sqm <= 0 && ( empty( $plots_override ) || $plots_override <= 0 ) ) {
            return array_merge( self::empty_breakdown(), $extra );
        }

        // If SQM is missing but plot override is provided, derive SQM
        if ( $sqm <= 0 && $plots_override > 0 ) {
            $sqm = $plots_override * self::STANDARD_PLOT_SQM;
        }

        // Compute plots
        $plots = ( $plots_override > 0 ) ? $plots_override : ( $sqm / self::STANDARD_PLOT_SQM );
        $sqft  = $sqm * self::SQM_TO_SQFT;

        // Nigerian Plot label identification
        $plot_label = '';
        $plot_short = '';

        if ( $sqm >= 280 && $sqm <= 325 ) {
            $plot_label = 'Half Plot (~300 SQM)';
            $plot_short = 'Half Plot';
        } elseif ( $sqm >= 580 && $sqm <= 650 ) {
            $plot_label = '1 Standard Plot (~600 SQM)';
            $plot_short = '1 Plot';
        } elseif ( $sqm >= 880 && $sqm <= 950 ) {
            $plot_label = '1.5 Plots (~900 SQM)';
            $plot_short = '1.5 Plots';
        } elseif ( $sqm >= 1150 && $sqm <= 1300 ) {
            $plot_label = '2 Standard Plots (~1,200 SQM)';
            $plot_short = '2 Plots';
        } elseif ( $sqm >= 1700 && $sqm <= 1900 ) {
            $plot_label = '3 Standard Plots (~1,800 SQM)';
            $plot_short = '3 Plots';
        } elseif ( $sqm >= 2300 && $sqm <= 2500 ) {
            $plot_label = '4 Standard Plots (~2,400 SQM)';
            $plot_short = '4 Plots';
        } elseif ( $sqm >= 3900 && $sqm <= 4200 ) {
            $plot_label = '1 Acre (~6 Standard Plots)';
            $plot_short = '1 Acre';
        } elseif ( $sqm >= 7800 && $sqm <= 8400 ) {
            $plot_label = '2 Acres (~12 Standard Plots)';
            $plot_short = '2 Acres';
        } elseif ( $sqm >= 9800 && $sqm <= 10500 ) {
            $plot_label = '1 Hectare (~16.7 Plots / 2.47 Acres)';
            $plot_short = '1 Hectare';
        } else {
            if ( $plots < 1.0 ) {
                $plot_label = sprintf( '%.1f of a Plot (%s SQM)', $plots, number_format( $sqm ) );
                $plot_short = sprintf( '%.1f Plot', $plots );
            } elseif ( $plots < 6.0 ) {
                $plot_label = ( abs( $plots - round( $plots ) ) < 0.05 )
                    ? sprintf( '%d Standard Plots (%s SQM)', round( $plots ), number_format( $sqm ) )
                    : sprintf( '%.1f Standard Plots (%s SQM)', $plots, number_format( $sqm ) );
                $plot_short = ( abs( $plots - round( $plots ) ) < 0.05 )
                    ? sprintf( '%d Plots', round( $plots ) )
                    : sprintf( '%.1f Plots', $plots );
            } else {
                $acres = $sqm / self::ACRE_SQM;
                $plot_label = sprintf( '%.1f Plots (~%.1f Acres)', $plots, $acres );
                $plot_short = sprintf( '%.1f Acres', $acres );
            }
        }

        // Standard dimension estimate
        $est_dimensions = '';
        if ( ! empty( $extra['dimensions'] ) ) {
            $est_dimensions = $extra['dimensions'];
        } elseif ( $sqm >= 580 && $sqm <= 650 ) {
            $est_dimensions = '60ft × 120ft (~18m × 36m)';
        } elseif ( $sqm >= 280 && $sqm <= 325 ) {
            $est_dimensions = '60ft × 60ft (~18m × 18m) or 50ft × 60ft';
        } elseif ( $sqm >= 1150 && $sqm <= 1300 ) {
            $est_dimensions = '120ft × 120ft (~36m × 36m)';
        }

        // Financial rates
        $price_per_sqm  = 0.0;
        $price_per_plot = 0.0;
        if ( $price > 0 && $sqm > 0 ) {
            $price_per_sqm = $price / $sqm;
        }
        if ( $price > 0 && $plots > 0 ) {
            $price_per_plot = $price / $plots;
        }

        return [
            'has_data'           => true,
            'sqm'                => $sqm,
            'sqm_formatted'      => number_format( $sqm ),
            'sqft'               => $sqft,
            'sqft_formatted'     => number_format( round( $sqft ) ),
            'plots'              => $plots,
            'plots_formatted'    => ( abs( $plots - round( $plots ) ) < 0.05 ) ? (string) round( $plots ) : sprintf( '%.1f', $plots ),
            'plot_label'         => $plot_label,
            'plot_short'         => $plot_short,
            'price'              => $price,
            'price_per_sqm'      => $price_per_sqm,
            'price_per_sqm_fmt'  => $price_per_sqm > 0 ? '₦' . number_format( round( $price_per_sqm ) ) : '',
            'price_per_plot'     => $price_per_plot,
            'price_per_plot_fmt' => $price_per_plot > 0 ? '₦' . number_format( round( $price_per_plot ) ) : '',
            'dimensions'         => $est_dimensions,
            'topography'         => $extra['topography'] ?? '',
            'zoning'             => $extra['zoning'] ?? '',
            'survey_status'      => $extra['survey_status'] ?? '',
            'title_document'     => $extra['title_document'] ?? '',
        ];
    }

    /**
     * Default empty breakdown when no area data exists.
     */
    public static function empty_breakdown(): array {
        return [
            'has_data'           => false,
            'sqm'                => 0.0,
            'sqm_formatted'      => '-',
            'sqft'               => 0.0,
            'sqft_formatted'     => '-',
            'plots'              => 0.0,
            'plots_formatted'    => '-',
            'plot_label'         => 'Plot Size on Request',
            'plot_short'         => '-',
            'price'              => 0.0,
            'price_per_sqm'      => 0.0,
            'price_per_sqm_fmt'  => '-',
            'price_per_plot'     => 0.0,
            'price_per_plot_fmt' => '-',
            'dimensions'         => '',
            'topography'         => '',
            'zoning'             => '',
            'survey_status'      => '',
            'title_document'     => '',
        ];
    }
}
