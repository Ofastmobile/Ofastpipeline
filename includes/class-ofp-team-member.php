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

        // Determine limits based on plan
        $plan = $client->plan;
        $max_members = 0;
        if ( $plan === 'growth' || $plan === 'sliver' ) {
            $max_members = 2;
        } elseif ( $plan === 'pro' || $plan === 'gold' ) {
            $max_members = 3;
        }

        if ( $max_members > 0 ) {
            $current_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d", $client_id ) );
            if ( $current_count >= $max_members ) {
                return new WP_Error( 'limit_reached', "Your plan allows a maximum of {$max_members} team members." );
            }
        } elseif ( $plan === 'starter' || $plan === 'free' ) {
            return new WP_Error( 'not_allowed', 'Free plan does not support team members.' );
        }

        // Validate data
        $email = sanitize_email( $data['email'] );
        $phone = OFP_Security::sanitize_phone( $data['phone'] );
        $name = sanitize_text_field( $data['name'] );
        
        if ( empty( $email ) || empty( $phone ) || empty( $name ) ) {
            return new WP_Error( 'invalid_data', 'Name, email, and phone are required.' );
        }

        // Deduct SMS credit
        if ( class_exists( 'OFP_Credit' ) ) {
            $balance = OFP_Credit::get_balance( $client_id );
            if ( $balance < 1 ) {
                return new WP_Error( 'insufficient_credit', 'Insufficient SMS credits to send the invitation.' );
            }
            OFP_Credit::deduct( $client_id, 1, 'team_invite_sms', "Sent team invitation to {$phone}" );
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
            $subject = "You have been invited to join " . $client->business_name;
            $html = "<p>Hi {$name},</p><p>You have been invited to join <strong>{$client->business_name}</strong> on the Ofast Pipeline dashboard.</p><p><a href='{$invite_url}'>Click here to accept the invitation and set your password</a>.</p>";
            OFP_Mailer::send_system_email( $email, $subject, $html );
        }
        
        if ( class_exists( 'OFP_SMS' ) ) {
            $sms = "You're invited to join {$client->business_name} on Ofast Pipeline. Click here to accept: {$invite_url}";
            OFP_SMS::send_system_sms( $phone, $sms );
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
        $wpdb->delete( $wpdb->prefix . 'ofp_team_members', [ 'id' => $team_member_id, 'client_id' => $client_id ] );
        $wpdb->delete( $wpdb->prefix . 'ofp_client_sessions', [ 'team_member_id' => $team_member_id ] );
        return true;
    }

    /**
     * Update a team member's role and permissions.
     */
    public static function update_permissions( int $team_member_id, int $client_id, string $role_name, array $permissions ) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'ofp_team_members',
            [
                'role_name'   => sanitize_text_field( $role_name ),
                'permissions' => wp_json_encode( $permissions ),
            ],
            [ 'id' => $team_member_id, 'client_id' => $client_id ]
        );
    }
}
