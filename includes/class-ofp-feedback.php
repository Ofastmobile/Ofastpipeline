<?php
/**
 * OFP_Feedback
 *
 * Handles Client-Side Feature Requests and Bug Reports.
 * Provides schema management, video parsing (YouTube, Loom),
 * client notifications, and admin oversight.
 *
 * @package OFast_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Feedback {

    /**
     * Bootstraps the feedback system.
     */
    public static function init(): void {
        self::ensure_schema();

        // AJAX handlers for clients submitting feedback
        add_action( 'wp_ajax_ofp_submit_feedback',        [ __CLASS__, 'ajax_submit_feedback' ] );
        add_action( 'wp_ajax_nopriv_ofp_submit_feedback', [ __CLASS__, 'ajax_submit_feedback' ] );

        // AJAX handlers for Admin actions
        add_action( 'wp_ajax_ofp_update_feedback',        [ __CLASS__, 'ajax_update_feedback' ] );
        add_action( 'wp_ajax_ofp_delete_feedback',        [ __CLASS__, 'ajax_delete_feedback' ] );
    }

    /**
     * Ensures the database table exists.
     */
    public static function ensure_schema(): void {
        if ( false !== get_transient( 'ofp_feedback_schema_ready_v1' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_feedback';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(30) NOT NULL DEFAULT 'feature_request',
            title VARCHAR(255) NOT NULL,
            category VARCHAR(100) DEFAULT 'general',
            description LONGTEXT NOT NULL,
            video_url VARCHAR(500) DEFAULT NULL,
            priority VARCHAR(30) DEFAULT 'medium',
            status VARCHAR(30) NOT NULL DEFAULT 'new',
            admin_notes TEXT DEFAULT NULL,
            page_url VARCHAR(500) DEFAULT NULL,
            device_info TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY client_id (client_id),
            KEY type (type),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        set_transient( 'ofp_feedback_schema_ready_v1', 1, MONTH_IN_SECONDS );
    }

    /**
     * Extracts an embeddable URL for YouTube or Loom videos.
     *
     * @param string $url The raw URL provided by the user.
     * @return string|null Embeddable iframe URL or null if unsupported.
     */
    public static function get_embed_url( ?string $url ): ?string {
        if ( empty( $url ) ) {
            return null;
        }

        $url = trim( $url );

        // YouTube matches (standard, share, embed, shorts)
        if ( preg_match( '/(?:youtube\.com\/(?:watch\?.*v=|embed\/|v\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $url, $matches ) ) {
            return 'https://www.youtube-nocookie.com/embed/' . $matches[1] . '?rel=0&modestbranding=1';
        }

        // Loom matches
        if ( preg_match( '/loom\.com\/(?:share|embed)\/([a-zA-Z0-9]+)/i', $url, $matches ) ) {
            return 'https://www.loom.com/embed/' . $matches[1];
        }

        // Vimeo matches
        if ( preg_match( '/vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|)(\d+)/i', $url, $matches ) ) {
            return 'https://player.vimeo.com/video/' . end( $matches );
        }

        return null;
    }

    /**
     * Detects the video provider.
     *
     * @param string $url
     * @return string 'youtube'|'loom'|'vimeo'|'external'|'none'
     */
    public static function get_video_provider( ?string $url ): string {
        if ( empty( $url ) ) return 'none';
        if ( strpos( $url, 'youtu' ) !== false ) return 'youtube';
        if ( strpos( $url, 'loom.com' ) !== false ) return 'loom';
        if ( strpos( $url, 'vimeo.com' ) !== false ) return 'vimeo';
        return 'external';
    }

    /**
     * Get count of new/unresolved feedback items for admin badges.
     */
    public static function get_new_count(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_feedback';
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
    }

    /**
     * Handle client feedback submission via AJAX.
     */
    public static function ajax_submit_feedback(): void {
        check_ajax_referer( 'ofp_client_ajax', 'nonce' );

        $client = null;
        if ( class_exists( 'OFP_Auth' ) ) {
            $client = OFP_Auth::current_client();
        }

        // Also permit logged in WordPress administrators/testing
        if ( ! $client && current_user_can( 'manage_options' ) ) {
            $wp_user = wp_get_current_user();
            $client = (object) [
                'id'            => 0,
                'business_name' => 'Admin Test (' . $wp_user->display_name . ')',
                'owner_name'    => $wp_user->display_name,
                'email'         => $wp_user->user_email,
            ];
        }

        if ( ! $client ) {
            wp_send_json_error( [ 'message' => 'Please log in to submit feedback or report an issue.' ], 401 );
        }

        $type        = sanitize_key( $_POST['type'] ?? 'feature_request' );
        if ( ! in_array( $type, [ 'feature_request', 'bug_report' ], true ) ) {
            $type = 'feature_request';
        }

        $title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $category    = sanitize_text_field( wp_unslash( $_POST['category'] ?? 'General' ) );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
        $video_url   = esc_url_raw( wp_unslash( $_POST['video_url'] ?? '' ) );
        $priority    = sanitize_key( $_POST['priority'] ?? 'medium' );
        $page_url    = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
        $device_info = sanitize_text_field( wp_unslash( $_POST['device_info'] ?? '' ) );

        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => 'Please provide a title for your ' . ( $type === 'bug_report' ? 'bug report' : 'feature request' ) . '.' ], 400 );
        }

        if ( empty( $description ) ) {
            wp_send_json_error( [ 'message' => 'Please provide a detailed description so we can understand your request.' ], 400 );
        }

        if ( ! in_array( $priority, [ 'low', 'medium', 'high', 'critical' ], true ) ) {
            $priority = 'medium';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_feedback';

        $inserted = $wpdb->insert(
            $table,
            [
                'client_id'   => (int) ( $client->id ?? 0 ),
                'type'        => $type,
                'title'       => $title,
                'category'    => $category,
                'description' => $description,
                'video_url'   => ! empty( $video_url ) ? $video_url : null,
                'priority'    => $priority,
                'status'      => 'new',
                'page_url'    => ! empty( $page_url ) ? $page_url : null,
                'device_info' => ! empty( $device_info ) ? $device_info : null,
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            wp_send_json_error( [ 'message' => 'Unable to save feedback at this time. Please try again.' ], 500 );
        }

        $feedback_id = $wpdb->insert_id;

        // Create client in-app notification confirming submission
        if ( ! empty( $client->id ) && class_exists( 'OFP_Notification' ) ) {
            $notif_title = ( $type === 'feature_request' )
                ? 'Feature Request Received'
                : 'Bug Report Received';
            $notif_msg = sprintf(
                'We received your submission "%s". Our team reviews all suggestions and issue reports.',
                esc_html( wp_trim_words( $title, 8, '…' ) )
            );

            OFP_Notification::create( [
                'client_id' => (int) $client->id,
                'title'     => $notif_title,
                'message'   => $notif_msg,
                'type'      => 'info',
            ] );
        }

        // Notify Administrator via email
        self::send_admin_email_alert( $feedback_id, $client, $type, $title, $description, $video_url, $priority, $category );

        wp_send_json_success( [
            'id'      => $feedback_id,
            'type'    => $type,
            'message' => ( $type === 'feature_request' )
                ? 'Thank you! Your feature request has been sent to our product team.'
                : 'Thank you! Your bug report has been logged and our team is investigating.',
        ] );
    }

    /**
     * Send email notification to site admin when new feedback is submitted.
     */
    private static function send_admin_email_alert( int $id, object $client, string $type, string $title, string $description, string $video_url, string $priority, string $category ): void {
        $admin_email = get_option( 'admin_email' );
        if ( empty( $admin_email ) ) return;

        $type_label = ( $type === 'feature_request' ) ? 'Feature Request' : 'Bug Report';
        $subject    = sprintf( '[%s] New %s: %s', class_exists( 'OFP_Brand' ) ? OFP_Brand::name() : 'OFast Pipeline', $type_label, $title );

        $client_info = ! empty( $client->business_name )
            ? sprintf( '%s (%s, %s)', $client->business_name, $client->owner_name ?? '', $client->email ?? '' )
            : 'Unknown Client';

        $admin_portal_url = home_url( '/admin-portal?tab=feedback' );

        $message  = "A new {$type_label} has been submitted on the client portal.\n\n";
        $message .= "----------------------------------------\n";
        $message .= "Title:       {$title}\n";
        $message .= "Type:        {$type_label}\n";
        $message .= "Category:    {$category}\n";
        $message .= "Priority:    " . strtoupper( $priority ) . "\n";
        $message .= "Submitted by:{$client_info}\n";
        $message .= "----------------------------------------\n\n";
        $message .= "Description:\n{$description}\n\n";

        if ( ! empty( $video_url ) ) {
            $message .= "Video Explanation URL:\n{$video_url}\n\n";
        }

        $message .= "Review and manage this in the Admin Portal:\n{$admin_portal_url}\n";

        wp_mail( $admin_email, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
    }

    /**
     * Admin status and note updater via AJAX.
     */
    public static function ajax_update_feedback(): void {
        if ( ! current_user_can( 'manage_options' ) && ! ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_admin_user() ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        check_ajax_referer( 'ofp_admin_nonce', 'nonce' );

        $id          = absint( $_POST['id'] ?? 0 );
        $status      = sanitize_key( $_POST['status'] ?? '' );
        $admin_notes = sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) );

        $allowed_statuses = [ 'new', 'under_review', 'planned', 'in_progress', 'resolved', 'declined' ];
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid status' ], 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_feedback';

        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $existing ) {
            wp_send_json_error( [ 'message' => 'Feedback item not found' ], 404 );
        }

        $wpdb->update(
            $table,
            [
                'status'      => $status,
                'admin_notes' => $admin_notes,
                'updated_at'  => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        // If status changed and client notification requested
        if ( ! empty( $_POST['notify_client'] ) && ! empty( $existing->client_id ) && class_exists( 'OFP_Notification' ) ) {
            $status_labels = [
                'under_review' => 'Under Review',
                'planned'      => 'Planned for Release',
                'in_progress'  => 'In Progress',
                'resolved'     => 'Completed & Resolved',
                'declined'     => 'Closed',
            ];
            $friendly_status = $status_labels[ $status ] ?? ucfirst( $status );

            $notif_title = 'Update on your ' . ( $existing->type === 'feature_request' ? 'Feature Request' : 'Bug Report' );
            $notif_msg   = sprintf(
                'Status changed to "%s" for: %s%s',
                $friendly_status,
                $existing->title,
                ! empty( $admin_notes ) ? "\nNote: " . $admin_notes : ''
            );

            OFP_Notification::create( [
                'client_id' => (int) $existing->client_id,
                'title'     => $notif_title,
                'message'   => $notif_msg,
                'type'      => ( $status === 'resolved' ) ? 'success' : 'info',
            ] );
        }

        wp_send_json_success( [ 'message' => 'Feedback updated successfully!' ] );
    }

    /**
     * Admin delete feedback item via AJAX.
     */
    public static function ajax_delete_feedback(): void {
        if ( ! current_user_can( 'manage_options' ) && ! ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_admin_user() ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        check_ajax_referer( 'ofp_admin_nonce', 'nonce' );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID' ], 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ofp_feedback';
        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        wp_send_json_success( [ 'message' => 'Feedback item deleted.' ] );
    }
}
