<?php
/**
 * OFP_Team_Member
 *
 * Handles team member operations: invite, validate, fetch.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Team_Member {

    /**
     * Invite a new team member.
     * Deducts 1 SMS credit for sending the invite via SMS.
     *
     * @param int $client_id Main client ID
     * @param array $data {
     *     @type string $name
     *     @type string $email
     *     @type string $phone
     *     @type string $role_name
     *     @type array  $permissions
     * }
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function invite( int $client_id, array $data ) {
        global $wpdb;

        // Plan Check
        $client = OFP_Client::get( $client_id );
        if ( ! $client ) return new WP_Error( 'not_found', 'Client not found.' );

        $plan        = OFP_Subscription::client_plan( $client_id );
        $max_members = OFP_Subscription::team_member_limit( $plan );

        if ( $max_members <= 0 ) {
            return new WP_Error( 'not_allowed', 'Free plan does not support team members. Upgrade to Silver or Gold.' );
        }

        $current_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d", $client_id ) );
        if ( $current_count >= $max_members ) {
            return new WP_Error( 'limit_reached', "Your plan allows a maximum of {$max_members} team members." );
        }

        // Validate data
        $email = sanitize_email( $data['email'] );
        $phone = OFP_Security::sanitize_phone( $data['phone'] );
        $name = sanitize_text_field( $data['name'] );
        
        if ( empty( $email ) || empty( $phone ) || empty( $name ) ) {
            return new WP_Error( 'invalid_data', 'Name, email, and phone are required.' );
        }

        // Deduct SMS credit
        // Bug fix: this used to call OFP_Credit::deduct($client_id, 1,
        // 'team_invite_sms', "...") — deduct()'s real signature is
        // (client_id, channel, amount), so the 2nd/3rd args were swapped
        // and a non-numeric string was passed where a float was expected.
        // That throws a fatal TypeError in PHP 8, so every single team
        // invite attempt was crashing at this line before this fix.
        if ( class_exists( 'OFP_Credit' ) ) {
            $sms_cost = class_exists( 'OFP_Queue' ) ? OFP_Queue::SMS_COST : 6.99;
            if ( ! OFP_Credit::has_balance( $client_id, 'sms', $sms_cost ) ) {
                return new WP_Error( 'insufficient_credit', 'Insufficient SMS credits to send the invitation.' );
            }
            OFP_Credit::deduct( $client_id, 'sms', $sms_cost );
        }

        // Insert Record
        $invite_token = bin2hex( random_bytes( 32 ) );
        $permissions = isset( $data['permissions'] ) && is_array( $data['permissions'] ) ? wp_json_encode( $data['permissions'] ) : null;
        $role_name = sanitize_text_field( $data['role_name'] ?? 'Manager' );

        $wpdb->insert(
            $wpdb->prefix . 'ofp_team_members',
            [
                'client_id'    => $client_id,
                'name'         => $name,
                'email'        => $email,
                'phone'        => $phone,
                'status'       => 'pending',
                'invite_token' => $invite_token,
                'role_name'    => $role_name,
                'permissions'  => $permissions,
                'created_at'   => current_time( 'mysql' ),
            ]
        );

        $team_member_id = $wpdb->insert_id;

        // Send Invites
        $invite_url = home_url( "/team-invite?token={$invite_token}" );
        
        if ( class_exists( 'OFP_Mailer' ) ) {
            $subject = 'You have been invited to join ' . $client->business_name;
            $html = '
                <h2>You\'re invited!</h2>
                <p>Hi ' . esc_html( $name ) . ',</p>
                <p><strong>' . esc_html( $client->business_name ) . '</strong> has invited you to join their
                   team on Ofast Pipeline as a <strong>' . esc_html( $role_name ) . '</strong>.</p>
                <p>Click below to accept and set your password:</p>
                <p>
                    <a href="' . esc_url( $invite_url ) . '"
                       style="display:inline-block;background:#1a73e8;color:#fff;
                              padding:12px 28px;border-radius:8px;text-decoration:none;
                              font-weight:600;margin:12px 0;">
                        Accept Invitation
                    </a>
                </p>
                <p style="color:#6b7280;font-size:13px;">
                    You\'ll log in using this same link\'s email address once you set your
                    password. You\'ll see the same dashboard as ' . esc_html( $client->business_name ) . ',
                    scoped to what your role allows.
                </p>
            ';
            OFP_Mailer::send_system_email( $email, $subject, $html );
        }
        
        if ( class_exists( 'OFP_SMS' ) ) {
            $sms = "You're invited to join {$client->business_name} on Ofast Pipeline. Click here to accept: {$invite_url}";
            OFP_SMS::send_system_sms( $phone, $sms );
        }

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Team member invited', $client_id, [
                'name'      => $name,
                'email'     => $email,
                'role_name' => $role_name,
            ] );
        }

        return true;
    }

    /**
     * Accept an invite and set password.
     */
    public static function accept_invite( string $token, string $password ) {
        global $wpdb;

        $team_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE invite_token = %s LIMIT 1", $token ) );

        if ( ! $team_member ) {
            return new WP_Error( 'invalid_token', 'Invalid or expired invitation token.' );
        }

        $hashed = password_hash( $password, PASSWORD_BCRYPT );

        $wpdb->update(
            $wpdb->prefix . 'ofp_team_members',
            [
                'password'     => $hashed,
                'status'       => 'active',
                'invite_token' => null
            ],
            [ 'id' => $team_member->id ]
        );

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Team member accepted invite', $team_member->client_id, [
                'name'  => $team_member->name,
                'email' => $team_member->email,
            ] );
        }

        return true;
    }

    /**
     * Fetch all team members for a client.
     */
    public static function get_all_for_client( int $client_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d ORDER BY created_at DESC", $client_id ) );
    }

    /**
     * Delete a team member.
     */
    public static function delete( int $team_member_id, int $client_id ) {
        global $wpdb;
        $team_member = $wpdb->get_row( $wpdb->prepare( "SELECT name, email FROM {$wpdb->prefix}ofp_team_members WHERE id = %d AND client_id = %d", $team_member_id, $client_id ) );
        $wpdb->delete( $wpdb->prefix . 'ofp_team_members', [ 'id' => $team_member_id, 'client_id' => $client_id ] );
        $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'team_member_id' => $team_member_id ] );

        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Team member removed', $client_id, [
                'name'  => $team_member->name ?? null,
                'email' => $team_member->email ?? null,
            ] );
        }

        return true;
    }

    /**
     * Update a team member's role and permissions.
     */
    public static function update_permissions( int $team_member_id, int $client_id, string $role_name, array $permissions ) {
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'ofp_team_members',
            [
                'role_name'   => sanitize_text_field( $role_name ),
                'permissions' => wp_json_encode( $permissions ),
            ],
            [ 'id' => $team_member_id, 'client_id' => $client_id ]
        );

        if ( $result !== false && class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( 'Team member role updated', $client_id, [
                'team_member_id' => $team_member_id,
                'role_name'      => $role_name,
            ] );
        }

        return $result;
    }
}
