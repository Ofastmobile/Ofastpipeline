<?php
/**
 * OFP_Field_Agent
 *
 * Field Agents are the platform's own people on the ground: they visit properties,
 * source landlords and submit listings for review. They work INSIDE the platform
 * account, so everything they bring in belongs to the platform.
 *
 * How it fits the existing plugin (nothing existing is rewritten)
 *  - A Field Agent is a normal team member with role "Field Agent". The existing
 *    invite and login system is reused.
 *  - They get their OWN simple dashboard (/field). Every other portal page, and every
 *    plugin AJAX action, is blocked for them on the server.
 *  - Their submissions use the existing property type, in the existing "pending"
 *    state, so the rest of the plugin already knows how to treat them.
 *  - Reviewers (/field-team): the platform owner, Managers, and any team member given
 *    the "Approve listings" permission. Selling and land listings need owner/Manager.
 *  - A Field Agent can never publish anything, set fees, or see tenants and rent.
 *
 * Money rule: the design has no place where an agent handles money. Every submission
 * carries a signed-off statement that no money was asked for or collected.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OFP_Field_Agent {

    const ROLE          = 'Field Agent';
    const PERM_AGENT    = 'field_agent';
    const PERM_APPROVE  = 'approve_listings';
    const SCHEMA_OPTION = 'ofp_fa_schema_v2';

    /** Portal pages a Field Agent may NOT open (anything else on the public site is fine). */
    const PORTAL_ROUTES = [
        'dashboard', 'leads', 'customers', 'import', 'pipeline-settings', 'api-settings', 'api',
        'communications', 'credits', 'reports', 'account', 'profile', 'my-listing', 'properties',
        'inspections', 'listing-billing', 'notifications', 'notification-settings', 'funding',
        'pricing', 'plans-pricing', 'pricing-plans', 'plans', 'plans-and-pricing', 'team',
        'message-templates', 'tenants', 'rent', 'property-sales', 'property-purchases',
        'property-payments', 'activity-logs', 'agents', 'our-agents', 'investment',
        'field', 'field-team',
    ];

    const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River',
        'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano',
        'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
        'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

    /** Every box must be ticked to submit. Together they are the agent's statement about the visit. */
    const CHECKLIST = [
        'visited'      => 'I physically visited this property.',
        'met_owner'    => 'I met the owner or an authorised caretaker.',
        'photos_match' => 'These photos are of this exact property.',
        'details_true' => 'The details (rooms, price, condition) are accurate.',
        'no_money'     => 'I did not ask for or collect any money from anyone.',
    ];

    const AGREEMENT = [
        'I will never ask for, collect or accept money from landlords, tenants or buyers.',
        'I will only submit properties I have personally visited and that the owner has agreed to list.',
        'I understand my commission is paid only on leases that close on the platform.',
        'I will keep landlords\' and tenants\' personal details private and use them only for my assigned work.',
        'Breaking these rules means immediate suspension and loss of unpaid commission.',
    ];

    // ═══════════════════════════════════════════════════════════════════
    // Setup
    // ═══════════════════════════════════════════════════════════════════

    public static function init(): void {
        self::ensure_schema();
        add_action( 'init', [ __CLASS__, 'serve_private_file' ], 6 );
        add_action( 'template_redirect', [ __CLASS__, 'guard_routes' ], 4 );
        add_action( 'admin_init', [ __CLASS__, 'guard_ajax' ], 1 );
    }

    public static function ensure_schema(): void {
        if ( '1' === (string) get_option( self::SCHEMA_OPTION, '' ) ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        dbDelta( "CREATE TABLE {$p}ofp_fa_areas (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            state varchar(60) NOT NULL,
            area varchar(120) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY state_area (state,area)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$p}ofp_fa_agent_areas (
            agent_id bigint(20) unsigned NOT NULL,
            area_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (agent_id,area_id)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$p}ofp_fa_profiles (
            agent_id bigint(20) unsigned NOT NULL,
            address text,
            id_type varchar(40) DEFAULT '',
            id_last4 varchar(8) DEFAULT '',
            id_file varchar(80) DEFAULT '',
            selfie_file varchar(80) DEFAULT '',
            agreement_at datetime DEFAULT NULL,
            kyc_status varchar(20) NOT NULL DEFAULT 'incomplete',
            kyc_note text,
            reviewed_by varchar(120) DEFAULT '',
            reviewed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (agent_id)
        ) {$c};" );

        dbDelta( "CREATE TABLE {$p}ofp_fa_reports (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            property_id bigint(20) unsigned NOT NULL,
            agent_id bigint(20) unsigned NOT NULL,
            area_id bigint(20) unsigned NOT NULL DEFAULT 0,
            visited_at datetime DEFAULT NULL,
            checklist text,
            lat varchar(24) DEFAULT '',
            lng varchar(24) DEFAULT '',
            landlord_name varchar(150) DEFAULT '',
            landlord_phone varchar(32) DEFAULT '',
            notes text,
            doc_files text,
            video_id varchar(16) DEFAULT '',
            video_title varchar(160) DEFAULT '',
            video_author varchar(100) DEFAULT '',
            video_state varchar(12) DEFAULT '',
            flags text,
            status varchar(24) NOT NULL DEFAULT 'submitted',
            review_note text,
            reviewed_by varchar(120) DEFAULT '',
            reviewed_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY property_id (property_id),
            KEY agent_status (agent_id,status),
            KEY status_created (status,created_at)
        ) {$c};" );

        update_option( self::SCHEMA_OPTION, '1', false );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Who is who
    // ═══════════════════════════════════════════════════════════════════

    /** The logged-in portal person (client owner or team member), or null. */
    public static function user(): ?object {
        static $u = false;
        if ( false === $u ) {
            $u = ( class_exists( 'OFP_Auth' ) && OFP_Auth::is_logged_in() ) ? OFP_Auth::current_user() : null;
        }
        return $u;
    }

    /** The logged-in Field Agent (team member of the platform account), or null. */
    public static function agent(): ?object {
        $u = self::user();
        if ( ! $u || empty( $u->is_team_member ) || ( $u->role_name ?? '' ) !== self::ROLE ) {
            return null;
        }
        return OFP_Platform::is_platform( (int) $u->parent_client_id ) ? $u : null;
    }

    /** Owner or Manager of the platform account: invites agents, manages areas and ID checks. */
    public static function can_manage(): bool {
        $u = self::user();
        if ( ! $u ) {
            return false;
        }
        if ( empty( $u->is_team_member ) ) {
            return OFP_Platform::is_platform( (int) $u->id );
        }
        return ( $u->role_name ?? '' ) === 'Manager' && OFP_Platform::is_platform( (int) $u->parent_client_id );
    }

    /** May review submissions: owner, Manager, or anyone given "Approve listings". */
    public static function can_approve(): bool {
        if ( self::can_manage() ) {
            return true;
        }
        $u = self::user();
        return $u && ! empty( $u->is_team_member )
            && ( $u->role_name ?? '' ) !== self::ROLE
            && OFP_Platform::is_platform( (int) $u->parent_client_id )
            && in_array( self::PERM_APPROVE, (array) ( $u->perms ?? [] ), true );
    }

    public static function platform_id(): int {
        return OFP_Platform::platform_client_id();
    }

    /** The portal page being requested, even when rewrite rules have not been refreshed yet. */
    public static function current_route(): string {
        $route = (string) get_query_var( 'ofp_route', '' );
        if ( '' !== $route ) {
            return $route;
        }
        $path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
        $seg  = array_values( array_filter( explode( '/', $path ) ) );
        return ( 1 === count( $seg ) && in_array( $seg[0], self::PORTAL_ROUTES, true ) ) ? $seg[0] : '';
    }

    // ═══════════════════════════════════════════════════════════════════
    // Server-side guards
    // ═══════════════════════════════════════════════════════════════════

    public static function guard_routes(): void {
        $route = self::current_route();
        if ( '' === $route || ! self::user() ) {
            return;
        }

        if ( self::agent() ) {
            // A Field Agent sees one page only.
            if ( 'field' !== $route ) {
                wp_safe_redirect( home_url( '/field' ) );
                exit;
            }
            return;
        }
        if ( 'field' === $route ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }
        if ( 'field-team' === $route && ! self::can_approve() ) {
            wp_safe_redirect( home_url( '/dashboard' ) );
            exit;
        }
    }

    /** Field Agents cannot call any plugin AJAX action (their pages use plain forms). */
    public static function guard_ajax(): void {
        if ( ! wp_doing_ajax() || ! self::agent() ) {
            return;
        }
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( 0 === strpos( $action, 'ofp_' ) ) {
            wp_send_json_error( 'Not available for field agents.' );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Navigation
    // ═══════════════════════════════════════════════════════════════════

    /** Called from nav.php: a reduced menu for agents, an extra item for reviewers. */
    public static function filter_nav( array $sections ): array {
        $home = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955a1.126 1.126 0 0 1 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';
        $plus = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>';
        $team = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>';

        if ( self::agent() ) {
            return [
                'field' => [
                    'title' => 'Field Work',
                    'items' => [
                        [ 'label' => 'My Work', 'icon' => $home, 'url' => home_url( '/field' ), 'slug' => 'field', 'locked' => false ],
                        [ 'label' => 'Submit a Property', 'icon' => $plus, 'url' => home_url( '/field?new=1' ), 'slug' => 'field-new', 'locked' => false ],
                    ],
                ],
            ];
        }

        if ( self::can_approve() ) {
            $count = self::pending_count();
            $sections['properties']['items'][] = [
                'label'  => 'Field Agents' . ( $count > 0 ? ' (' . $count . ')' : '' ),
                'icon'   => $team,
                'url'    => home_url( '/field-team' ),
                'slug'   => 'field-team',
                'locked' => false,
            ];
        }
        return $sections;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Areas
    // ═══════════════════════════════════════════════════════════════════

    public static function areas( bool $active_only = true ): array {
        global $wpdb;
        $where = $active_only ? 'WHERE active = 1' : '';
        return (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ofp_fa_areas {$where} ORDER BY state ASC, area ASC" );
    }

    public static function add_area( string $state, string $area ): array {
        global $wpdb;
        $state = in_array( $state, self::STATES, true ) ? $state : '';
        $area  = mb_substr( trim( sanitize_text_field( $area ) ), 0, 120 );
        if ( '' === $state || '' === $area ) {
            return [ false, 'Choose a state and type an area name.' ];
        }
        $ok = $wpdb->insert( $wpdb->prefix . 'ofp_fa_areas', [ 'state' => $state, 'area' => $area, 'active' => 1, 'created_at' => current_time( 'mysql' ) ] );
        return $ok ? [ true, "Added {$area}, {$state}." ] : [ false, 'That area already exists.' ];
    }

    public static function toggle_area( int $area_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ofp_fa_areas SET active = 1 - active WHERE id = %d", $area_id ) );
    }

    public static function agent_area_ids( int $agent_id ): array {
        global $wpdb;
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT area_id FROM {$wpdb->prefix}ofp_fa_agent_areas WHERE agent_id = %d", $agent_id
        ) ) );
    }

    /** Areas an agent may work in (active ones only). */
    public static function agent_areas( int $agent_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT a.* FROM {$wpdb->prefix}ofp_fa_areas a
             JOIN {$wpdb->prefix}ofp_fa_agent_areas x ON x.area_id = a.id
             WHERE x.agent_id = %d AND a.active = 1 ORDER BY a.state, a.area",
            $agent_id
        ) );
    }

    public static function set_agent_areas( int $agent_id, array $area_ids ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'ofp_fa_agent_areas';
        $wpdb->delete( $t, [ 'agent_id' => $agent_id ] );
        foreach ( array_unique( array_map( 'absint', $area_ids ) ) as $id ) {
            if ( $id > 0 ) {
                $wpdb->insert( $t, [ 'agent_id' => $agent_id, 'area_id' => $id ] );
            }
        }
    }

    public static function area_label( int $area_id ): string {
        global $wpdb;
        $r = $wpdb->get_row( $wpdb->prepare( "SELECT state, area FROM {$wpdb->prefix}ofp_fa_areas WHERE id = %d", $area_id ) );
        return $r ? $r->area . ', ' . $r->state : '—';
    }

    // ═══════════════════════════════════════════════════════════════════
    // Agents: invite, profile, ID check
    // ═══════════════════════════════════════════════════════════════════

    /** All Field Agents of the platform account, with their profile status. */
    public static function agents(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT m.id, m.name, m.email, m.phone, m.status AS invite_status, m.created_at,
                    COALESCE(f.kyc_status,'incomplete') AS kyc_status, f.kyc_note, f.id_type, f.id_last4,
                    f.id_file, f.selfie_file, f.address, f.agreement_at
             FROM {$p}ofp_team_members m
             LEFT JOIN {$p}ofp_fa_profiles f ON f.agent_id = m.id
             WHERE m.client_id = %d AND m.role_name = %s
             ORDER BY m.id DESC",
            self::platform_id(), self::ROLE
        ) );
    }

    public static function invite_agent( string $name, string $email, string $phone ): array {
        global $wpdb;
        $pid = self::platform_id();
        if ( $pid <= 0 ) {
            return [ false, 'No platform account exists yet.' ];
        }
        $email = sanitize_email( $email );
        if ( $email && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d AND email = %s", $pid, $email ) ) ) {
            return [ false, 'Someone with that email is already on the team.' ];
        }
        $res = OFP_Team_Member::invite( $pid, [
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'role_name'   => self::ROLE,
            'permissions' => [ self::PERM_AGENT ],
        ] );
        if ( is_wp_error( $res ) ) {
            return [ false, $res->get_error_message() ];
        }
        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_team_members WHERE client_id = %d AND email = %s ORDER BY id DESC LIMIT 1", $pid, $email
        ) );
        if ( $id ) {
            self::profile( $id );
        }
        return [ true, 'Invitation sent to ' . $email . '. They set a password, then complete their profile.' ];
    }

    public static function remove_agent( int $agent_id ): array {
        global $wpdb;
        $pid = self::platform_id();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ofp_team_members WHERE id = %d AND client_id = %d AND role_name = %s", $agent_id, $pid, self::ROLE
        ) );
        if ( ! $row ) {
            return [ false, 'Agent not found.' ];
        }
        OFP_Team_Member::delete( $agent_id, $pid );
        $wpdb->delete( $wpdb->prefix . 'ofp_fa_agent_areas', [ 'agent_id' => $agent_id ] );
        $wpdb->update( $wpdb->prefix . 'ofp_fa_profiles', [ 'kyc_status' => 'suspended' ], [ 'agent_id' => $agent_id ] );
        return [ true, 'Agent removed. Their submitted properties stay on the platform.' ];
    }

    /** Profile row for an agent (created on first use). */
    public static function profile( int $agent_id ): object {
        global $wpdb;
        $t   = $wpdb->prefix . 'ofp_fa_profiles';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE agent_id = %d", $agent_id ) );
        if ( ! $row ) {
            $wpdb->insert( $t, [ 'agent_id' => $agent_id, 'kyc_status' => 'incomplete', 'created_at' => current_time( 'mysql' ) ] );
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE agent_id = %d", $agent_id ) );
        }
        return $row;
    }

    /** The agent completes their profile: address, ID, selfie, agreement. */
    public static function save_profile( object $agent, array $in, array $files ): array {
        global $wpdb;
        $profile = self::profile( (int) $agent->id );
        if ( in_array( $profile->kyc_status, [ 'submitted', 'approved', 'suspended' ], true ) ) {
            return [ false, 'Your profile cannot be changed right now.' ];
        }

        $address = sanitize_textarea_field( $in['address'] ?? '' );
        $id_type = sanitize_text_field( $in['id_type'] ?? '' );
        $last4   = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['id_number'] ?? '' ) ), -4 );
        if ( '' === trim( $address ) || '' === $id_type || strlen( $last4 ) < 4 ) {
            return [ false, 'Please fill in your address and ID details.' ];
        }
        if ( empty( $in['agree'] ) ) {
            return [ false, 'You must accept the agreement to continue.' ];
        }

        $id_file     = self::store_private_file( $files['id_photo'] ?? [] ) ?: $profile->id_file;
        $selfie_file = self::store_private_file( $files['selfie'] ?? [] ) ?: $profile->selfie_file;
        if ( '' === $id_file || '' === $selfie_file ) {
            return [ false, 'Please upload a clear photo of your ID and a selfie (JPG, PNG or PDF, up to 8 MB).' ];
        }

        $wpdb->update( $wpdb->prefix . 'ofp_fa_profiles', [
            'address'      => $address,
            'id_type'      => $id_type,
            'id_last4'     => $last4,
            'id_file'      => $id_file,
            'selfie_file'  => $selfie_file,
            'agreement_at' => current_time( 'mysql' ),
            'kyc_status'   => 'submitted',
            'kyc_note'     => '',
        ], [ 'agent_id' => (int) $agent->id ] );

        self::notify_reviewers( 'Field agent waiting for approval', esc_html( $agent->name ) . ' completed their profile and is waiting to be approved.' );
        return [ true, 'Thank you. Your profile is with our team for review. You will get an email when you are approved.' ];
    }

    /** approve | reject | suspend | reactivate */
    public static function kyc_decide( int $agent_id, string $action, string $note ): array {
        global $wpdb;
        $profile = self::profile( $agent_id );
        $member  = $wpdb->get_row( $wpdb->prepare(
            "SELECT name, email FROM {$wpdb->prefix}ofp_team_members WHERE id = %d AND client_id = %d AND role_name = %s",
            $agent_id, self::platform_id(), self::ROLE
        ) );
        if ( ! $member ) {
            return [ false, 'Agent not found.' ];
        }
        $map = [ 'approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reactivate' => 'approved' ];
        if ( ! isset( $map[ $action ] ) ) {
            return [ false, 'Unknown action.' ];
        }
        if ( 'approve' === $action && 'submitted' !== $profile->kyc_status ) {
            return [ false, 'This agent has not submitted their profile for review.' ];
        }
        $note = sanitize_textarea_field( $note );
        if ( 'reject' === $action && '' === trim( $note ) ) {
            return [ false, 'Please say what needs fixing.' ];
        }

        $wpdb->update( $wpdb->prefix . 'ofp_fa_profiles', [
            'kyc_status'  => $map[ $action ],
            'kyc_note'    => $note,
            'reviewed_by' => self::reviewer_name(),
            'reviewed_at' => current_time( 'mysql' ),
        ], [ 'agent_id' => $agent_id ] );

        $text = [
            'approve'    => 'You are approved. Open your dashboard to start submitting properties.',
            'reactivate' => 'Your field agent account is active again.',
            'reject'     => 'Your profile needs changes before we can approve it: ' . $note,
            'suspend'    => 'Your field agent account has been suspended. Please contact the team.',
        ][ $action ];
        self::email( $member->email, $member->name, 'Your field agent account', '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( home_url( '/field' ) ) . '">Open your dashboard</a></p>' );
        self::log( 'Field agent ' . $action, [ 'agent_id' => $agent_id ] );

        return [ true, 'Done.' ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Private files (ID photos, selfies, title documents)
    // ═══════════════════════════════════════════════════════════════════

    private static function private_dir(): string {
        $u   = wp_upload_dir();
        $dir = trailingslashit( $u['basedir'] ) . 'ofp-private/fa';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents(
                $dir . '/.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
            file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        }
        return $dir;
    }

    /** Save one uploaded file privately under a random name. @return string File name, or '' on failure. */
    private static function store_private_file( array $f ): string {
        if ( empty( $f['tmp_name'] ) || ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_uploaded_file( $f['tmp_name'] ) ) {
            return '';
        }
        if ( (int) ( $f['size'] ?? 0 ) > 8 * MB_IN_BYTES ) {
            return '';
        }
        $check = wp_check_filetype_and_ext( $f['tmp_name'], (string) $f['name'], [
            'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        ] );
        if ( empty( $check['ext'] ) ) {
            return '';
        }
        $ext  = 'jpeg' === $check['ext'] || 'jpe' === $check['ext'] ? 'jpg' : $check['ext'];
        $name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
        return move_uploaded_file( $f['tmp_name'], self::private_dir() . '/' . $name ) ? $name : '';
    }

    /** Turn PHP's $_FILES['x'] array-of-arrays shape into a list of single files. */
    public static function normalize_files( array $f ): array {
        $out = [];
        if ( empty( $f['name'] ) || ! is_array( $f['name'] ) ) {
            return ! empty( $f['name'] ) ? [ $f ] : [];
        }
        foreach ( array_keys( $f['name'] ) as $i ) {
            $out[] = [ 'name' => $f['name'][ $i ], 'type' => $f['type'][ $i ], 'tmp_name' => $f['tmp_name'][ $i ], 'error' => $f['error'][ $i ], 'size' => $f['size'][ $i ] ];
        }
        return $out;
    }

    public static function file_url( string $name ): string {
        return '' === $name ? '' : add_query_arg( 'ofp_fa_file', rawurlencode( $name ), home_url( '/' ) );
    }

    /** Only reviewers can open private files, and every opening is logged. */
    public static function serve_private_file(): void {
        if ( empty( $_GET['ofp_fa_file'] ) ) {
            return;
        }
        $name = basename( (string) wp_unslash( $_GET['ofp_fa_file'] ) );
        if ( ! self::can_approve() || ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $name ) ) {
            status_header( 403 );
            exit( 'Not allowed.' );
        }
        $path = self::private_dir() . '/' . $name;
        if ( ! is_file( $path ) ) {
            status_header( 404 );
            exit( 'Not found.' );
        }
        $types = [ 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ];
        self::log( 'Field agent document viewed', [ 'file' => $name ] );
        nocache_headers();
        header( 'Content-Type: ' . $types[ pathinfo( $name, PATHINFO_EXTENSION ) ] );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // YouTube videos
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Pull the 11-character video ID out of a pasted link. Returns '' unless it is a plain
     * YouTube video link. Only the ID is trusted afterwards: we rebuild the link ourselves,
     * so nothing the agent typed (scripts, odd domains, extra parameters) is ever stored.
     */
    public static function youtube_id( string $input ): string {
        $input = trim( $input );
        if ( '' === $input || strlen( $input ) > 300 || preg_match( '/[\x00-\x20\x7F]/', $input ) ) {
            return '';
        }
        if ( ! preg_match( '#^https?://#i', $input ) ) {
            $input = 'https://' . $input; // allow "youtu.be/abc" pasted without https://
        }
        $p = wp_parse_url( $input );
        if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] )
            || ( isset( $p['port'] ) && ! in_array( (int) $p['port'], [ 80, 443 ], true ) ) ) {
            return '';
        }

        $host = strtolower( $p['host'] );
        $path = (string) ( $p['path'] ?? '' );
        $id   = '';

        if ( 'youtu.be' === $host ) {
            $parts = explode( '/', ltrim( $path, '/' ) );
            $id    = $parts[0];
        } elseif ( in_array( $host, [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ], true ) ) {
            if ( '/watch' === rtrim( $path, '/' ) ) {
                parse_str( (string) ( $p['query'] ?? '' ), $q );
                $id = isset( $q['v'] ) && is_string( $q['v'] ) ? $q['v'] : '';
            } elseif ( preg_match( '#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $m ) ) {
                $id = $m[1];
            }
        }
        return preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ? $id : '';
    }

    /** True only for a well-formed video ID (used again wherever an ID is shown). */
    public static function is_video_id( string $id ): bool {
        return (bool) preg_match( '/^[A-Za-z0-9_-]{11}$/', $id );
    }

    /** The one link format we ever store or show. */
    public static function youtube_url( string $id ): string {
        return 'https://www.youtube.com/watch?v=' . $id;
    }

    /**
     * Ask YouTube whether the video exists and can be watched.
     *
     * @return array { state: ok|missing|unknown, title: string, author: string }
     */
    public static function check_youtube( string $id ): array {
        $none = [ 'state' => 'unknown', 'title' => '', 'author' => '' ];
        if ( ! self::is_video_id( $id ) ) {
            return [ 'state' => 'missing', 'title' => '', 'author' => '' ];
        }
        // The address is built from the validated ID only, and wp_safe_remote_get refuses internal addresses.
        $res = wp_safe_remote_get(
            'https://www.youtube.com/oembed?format=json&url=' . rawurlencode( self::youtube_url( $id ) ),
            [ 'timeout' => 5, 'redirection' => 0 ]
        );
        if ( is_wp_error( $res ) ) {
            return $none;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( 200 === $code ) {
            $j = json_decode( wp_remote_retrieve_body( $res ), true );
            $j = is_array( $j ) ? $j : [];
            return [
                'state'  => 'ok',
                'title'  => mb_substr( sanitize_text_field( (string) ( $j['title'] ?? '' ) ), 0, 150 ),
                'author' => mb_substr( sanitize_text_field( (string) ( $j['author_name'] ?? '' ) ), 0, 80 ),
            ];
        }
        // 401 = private or embedding switched off, 404 = removed or never existed.
        return in_array( $code, [ 400, 401, 403, 404 ], true ) ? [ 'state' => 'missing', 'title' => '', 'author' => '' ] : $none;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Submitting a property
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Create (or resubmit) a property for review.
     *
     * @return array { ok: bool, message: string, post_id: int }
     */
    public static function submit_property( object $agent, array $in, array $files, int $edit_id = 0 ): array {
        $fail = static function ( string $m ): array {
            return [ 'ok' => false, 'message' => $m, 'post_id' => 0 ];
        };
        global $wpdb;

        $agent_id = (int) $agent->id;
        $pid      = (int) $agent->parent_client_id;
        $profile  = self::profile( $agent_id );
        if ( 'approved' !== $profile->kyc_status ) {
            return $fail( 'Your profile must be approved before you can submit properties.' );
        }

        $type     = in_array( $in['listing_type'] ?? '', OFP_Property_CPT::LISTING_TYPES, true ) ? $in['listing_type'] : '';
        $title    = sanitize_text_field( $in['title'] ?? '' );
        $desc     = sanitize_textarea_field( $in['description'] ?? '' );
        $price    = (float) ( $in['price'] ?? 0 );
        $area_id  = (int) ( $in['area_id'] ?? 0 );
        $location = sanitize_text_field( $in['location_text'] ?? '' );
        $ll_name  = sanitize_text_field( $in['landlord_name'] ?? '' );
        $ll_phone = OFP_Security::sanitize_phone( $in['landlord_phone'] ?? '' );
        $visited  = sanitize_text_field( $in['visited_at'] ?? '' );
        $notes    = sanitize_textarea_field( $in['notes'] ?? '' );

        if ( '' === $type || '' === $title || $price <= 0 || '' === $location ) {
            return $fail( 'Please choose a type and fill in the title, price and address.' );
        }
        if ( ! in_array( $area_id, self::agent_area_ids( $agent_id ), true ) ) {
            return $fail( 'Choose one of the areas assigned to you.' );
        }
        if ( '' === $ll_name || '' === $ll_phone ) {
            return $fail( 'The owner or caretaker\'s name and phone number are required.' );
        }
        $visit_ts = strtotime( $visited );
        if ( ! $visit_ts || $visit_ts > time() + DAY_IN_SECONDS ) {
            return $fail( 'Enter the date you visited the property.' );
        }
        foreach ( array_keys( self::CHECKLIST ) as $key ) {
            if ( empty( $in['check'][ $key ] ) ) {
                return $fail( 'Please confirm every statement in the visit checklist.' );
            }
        }

        // Optional YouTube walkthrough. Checked before anything is saved.
        $video = [ 'id' => '', 'title' => '', 'author' => '', 'state' => '' ];
        $video_in = trim( (string) ( $in['video_url'] ?? '' ) );
        if ( '' !== $video_in ) {
            $vid = self::youtube_id( $video_in );
            if ( '' === $vid ) {
                return $fail( 'That is not a YouTube video link. Paste the full link from YouTube, or leave the video empty.' );
            }
            $chk = self::check_youtube( $vid );
            if ( 'missing' === $chk['state'] ) {
                return $fail( 'We could not find that video. On YouTube set it to Public or Unlisted (and allow embedding), then try again.' );
            }
            $video = [ 'id' => $vid, 'title' => $chk['title'], 'author' => $chk['author'], 'state' => 'ok' === $chk['state'] ? 'verified' : 'unverified' ];
        }

        // Editing is only allowed on the agent's own submission that was sent back.
        $report = null;
        if ( $edit_id ) {
            $report = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ofp_fa_reports WHERE property_id = %d AND agent_id = %d", $edit_id, $agent_id
            ) );
            if ( ! $report || 'changes_requested' !== $report->status ) {
                return $fail( 'This submission cannot be edited.' );
            }
        }

        $photos   = array_filter( self::normalize_files( $files['photos'] ?? [] ), static function ( $f ) {
            return ! empty( $f['name'] ) && ( $f['error'] ?? 0 ) === UPLOAD_ERR_OK;
        } );
        $existing = 0;
        if ( $edit_id ) {
            $gal      = json_decode( (string) get_post_meta( $edit_id, 'ofp_gallery_ids', true ), true );
            $existing = ( has_post_thumbnail( $edit_id ) ? 1 : 0 ) + ( is_array( $gal ) ? count( $gal ) : 0 );
        }
        if ( count( $photos ) + $existing < 3 ) {
            return $fail( 'Please add at least 3 photos of the property.' );
        }

        $is_sale_like = OFP_Property_CPT::is_sale_like( $type );
        $new_docs     = [];
        foreach ( self::normalize_files( $files['docs'] ?? [] ) as $f ) {
            $stored = self::store_private_file( $f );
            if ( '' !== $stored ) {
                $new_docs[] = $stored;
            }
        }
        $old_docs = $report ? (array) json_decode( (string) $report->doc_files, true ) : [];
        if ( $is_sale_like && empty( $new_docs ) && empty( $old_docs ) ) {
            return $fail( 'Add a photo or scan of the title documents for sale and land listings.' );
        }

        $period = sanitize_text_field( $in['price_period'] ?? 'year' );
        $period = $is_sale_like ? 'sales' : ( in_array( $period, [ 'year', '2years', 'month', 'night', 'week' ], true ) ? $period : 'year' );
        $beds   = 'land' === $type ? 0 : max( 0, (int) ( $in['bedrooms'] ?? 0 ) );
        $baths  = 'land' === $type ? 0 : max( 0, (int) ( $in['bathrooms'] ?? 0 ) );

        $post_data = [ 'post_title' => $title, 'post_content' => $desc, 'post_type' => 'ofp_property', 'post_status' => 'pending' ];
        if ( $edit_id ) {
            $post_data['ID'] = $edit_id;
            $post_id         = wp_update_post( $post_data, true );
        } else {
            $post_id = wp_insert_post( $post_data, true );
        }
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return $fail( 'Something went wrong saving this property. Please try again.' );
        }
        $post_id = (int) $post_id;

        $bank_id = 0;
        if ( class_exists( 'OFP_Bank_Account' ) ) {
            $banks = (array) OFP_Bank_Account::list( $pid );
            if ( $banks ) {
                $first   = reset( $banks );
                $bank_id = (int) ( is_object( $first ) ? $first->id : ( $first['id'] ?? 0 ) );
            }
        }

        $meta = [
            'ofp_client_id'            => $pid,
            'ofp_price'                => $price,
            'ofp_price_period'         => $period,
            'ofp_listing_type'         => $type,
            'ofp_property_type'        => 'land' === $type ? 'land' : sanitize_text_field( $in['property_type'] ?? 'apartment' ),
            'ofp_bedrooms'             => $beds,
            'ofp_bathrooms'            => $baths,
            'ofp_location_text'        => $location,
            'ofp_status'               => 'pending_upload',
            'ofp_is_featured'          => '0',
            'ofp_bank_account_id'      => $bank_id,
            'ofp_sourced_by'           => $agent_id,
            'ofp_area_id'              => $area_id,
            'ofp_verification_status'  => 'submitted',
        ];
        foreach ( $meta as $k => $v ) {
            update_post_meta( $post_id, $k, $v );
        }

        self::attach_photos( $post_id, array_values( $photos ) );

        $lat = preg_match( '/^-?\d{1,3}\.\d{3,}$/', (string) ( $in['lat'] ?? '' ) ) ? (string) $in['lat'] : '';
        $lng = preg_match( '/^-?\d{1,3}\.\d{3,}$/', (string) ( $in['lng'] ?? '' ) ) ? (string) $in['lng'] : '';
        $row = [
            'property_id'   => $post_id,
            'agent_id'      => $agent_id,
            'area_id'       => $area_id,
            'visited_at'    => gmdate( 'Y-m-d H:i:s', $visit_ts ),
            'checklist'     => wp_json_encode( array_fill_keys( array_keys( self::CHECKLIST ), 1 ) ),
            'lat'           => $lat,
            'lng'           => $lng,
            'landlord_name' => $ll_name,
            'landlord_phone'=> $ll_phone,
            'notes'         => $notes,
            'doc_files'     => wp_json_encode( array_values( array_merge( $old_docs, $new_docs ) ) ),
            'video_id'      => $video['id'],
            'video_title'   => $video['title'],
            'video_author'  => $video['author'],
            'video_state'   => $video['state'],
            'flags'         => wp_json_encode( self::duplicate_flags( $post_id, $ll_phone, $location ) ),
            'status'        => 'submitted',
            'review_note'   => '',
        ];
        if ( $report ) {
            $wpdb->update( $wpdb->prefix . 'ofp_fa_reports', $row, [ 'id' => (int) $report->id ] );
            $report_id = (int) $report->id;
        } else {
            $row['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $wpdb->prefix . 'ofp_fa_reports', $row );
            $report_id = (int) $wpdb->insert_id;
        }
        update_post_meta( $post_id, 'ofp_verification_report_id', $report_id );

        // The property already has a video field that the public page shows. We only ever store our own rebuilt link.
        if ( '' !== $video['id'] ) {
            update_post_meta( $post_id, 'ofp_video_url', self::youtube_url( $video['id'] ) );
            update_post_meta( $post_id, 'ofp_video_source', 'youtube' );
        } elseif ( 'youtube' === (string) get_post_meta( $post_id, 'ofp_video_source', true ) ) {
            delete_post_meta( $post_id, 'ofp_video_url' ); // the agent removed it when fixing the submission
            delete_post_meta( $post_id, 'ofp_video_source' );
        }

        if ( class_exists( 'OFP_Property_CPT' ) && method_exists( 'OFP_Property_CPT', 'sync_to_plugin_table' ) ) {
            OFP_Property_CPT::sync_to_plugin_table( $post_id, $pid );
        }

        self::notify_reviewers(
            'New property to review',
            esc_html( $agent->name ) . ' submitted "' . esc_html( $title ) . '" (' . esc_html( self::area_label( $area_id ) ) . ').'
        );
        self::log( $edit_id ? 'Field submission resubmitted' : 'Field submission created', [ 'property_id' => $post_id, 'agent_id' => $agent_id ] );

        return [ 'ok' => true, 'message' => 'Submitted for review.', 'post_id' => $post_id ];
    }

    /** Upload photos to the media library and add them to the property gallery. */
    private static function attach_photos( int $post_id, array $photos ): void {
        if ( empty( $photos ) ) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $gallery = json_decode( (string) get_post_meta( $post_id, 'ofp_gallery_ids', true ), true );
        $gallery = is_array( $gallery ) ? $gallery : [];

        foreach ( $photos as $f ) {
            $_FILES['ofp_fa_single'] = $f;
            $att = media_handle_upload( 'ofp_fa_single', $post_id );
            if ( is_wp_error( $att ) || ! $att ) {
                continue;
            }
            if ( ! has_post_thumbnail( $post_id ) ) {
                set_post_thumbnail( $post_id, (int) $att );
            } else {
                $gallery[] = (int) $att;
            }
        }
        update_post_meta( $post_id, 'ofp_gallery_ids', wp_json_encode( array_values( array_unique( $gallery ) ) ) );
    }

    /** Warnings for the reviewer: the same owner phone or address already used elsewhere. */
    private static function duplicate_flags( int $post_id, string $phone, string $location ): array {
        global $wpdb;
        $flags = [];

        $same_phone = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT property_id FROM {$wpdb->prefix}ofp_fa_reports
             WHERE landlord_phone = %s AND property_id <> %d AND status <> 'rejected' LIMIT 3",
            $phone, $post_id
        ) );
        if ( $same_phone ) {
            $flags[] = 'Same owner phone as property #' . implode( ', #', array_map( 'intval', $same_phone ) );
        }

        $same_addr = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'ofp_location_text'
             WHERE p.post_type = 'ofp_property' AND p.post_status IN ('publish','pending','draft')
               AND p.ID <> %d AND LOWER(TRIM(m.meta_value)) = %s LIMIT 3",
            $post_id, strtolower( trim( $location ) )
        ) );
        if ( $same_addr ) {
            $flags[] = 'Same address as property #' . implode( ', #', array_map( 'intval', $same_addr ) );
        }
        return $flags;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Reviewing
    // ═══════════════════════════════════════════════════════════════════

    public static function pending_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ofp_fa_reports WHERE status = 'submitted'" );
    }

    public static function queue( string $status = 'submitted', int $limit = 20 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, m.name AS agent_name, m.phone AS agent_phone
             FROM {$p}ofp_fa_reports r LEFT JOIN {$p}ofp_team_members m ON m.id = r.agent_id
             WHERE r.status = %s ORDER BY r.created_at ASC LIMIT %d",
            $status, $limit
        ) );
    }

    public static function agent_reports( int $agent_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ofp_fa_reports WHERE agent_id = %d ORDER BY id DESC LIMIT 100", $agent_id
        ) );
    }

    public static function agent_stats( int $agent_id ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}ofp_fa_reports WHERE agent_id = %d GROUP BY status", $agent_id
        ) );
        $out = [ 'submitted' => 0, 'approved' => 0, 'changes_requested' => 0, 'rejected' => 0 ];
        foreach ( $rows as $r ) {
            $out[ $r->status ] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Approve, send back, or reject a submission.
     *
     * @param string $action approve | changes | reject
     * @param array  $in     Posted form (price, fees, note, landlord_confirmed).
     * @return array [ bool ok, string message ]
     */
    public static function decide( int $report_id, string $action, array $in ): array {
        global $wpdb;
        if ( ! self::can_approve() ) {
            return [ false, 'You do not have permission to review listings.' ];
        }
        $t      = $wpdb->prefix . 'ofp_fa_reports';
        $report = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $report_id ) );
        if ( ! $report || 'submitted' !== $report->status ) {
            return [ false, 'This submission is no longer waiting for review.' ];
        }
        $post_id = (int) $report->property_id;
        $type    = (string) get_post_meta( $post_id, 'ofp_listing_type', true );

        // Selling and land listings carry more risk: owner or Manager only.
        if ( ( OFP_Property_CPT::is_sale_like( $type ) || 'commercial' === $type ) && ! self::can_manage() ) {
            return [ false, 'Sale, land and commercial listings must be approved by the owner or a Manager.' ];
        }

        $note   = sanitize_textarea_field( $in['note'] ?? '' );
        $member = $wpdb->get_row( $wpdb->prepare( "SELECT name, email FROM {$wpdb->prefix}ofp_team_members WHERE id = %d", (int) $report->agent_id ) );
        $title  = get_the_title( $post_id );

        if ( 'approve' === $action ) {
            if ( empty( $in['landlord_confirmed'] ) ) {
                return [ false, 'Tick the box to confirm you spoke to the owner before approving.' ];
            }
            $price = (float) ( $in['price'] ?? 0 );
            if ( $price <= 0 ) {
                return [ false, 'Enter the price.' ];
            }
            if ( '' !== (string) $report->video_id ) {
                if ( ! empty( $in['remove_video'] ) ) {
                    delete_post_meta( $post_id, 'ofp_video_url' );
                    delete_post_meta( $post_id, 'ofp_video_source' );
                    $wpdb->update( $t, [ 'video_id' => '', 'video_title' => '', 'video_author' => '', 'video_state' => '' ], [ 'id' => $report_id ] );
                } elseif ( empty( $in['video_checked'] ) ) {
                    return [ false, 'Watch the video and tick the box to confirm it shows this property, or tick "Remove the video".' ];
                } elseif ( self::is_video_id( (string) $report->video_id ) ) {
                    update_post_meta( $post_id, 'ofp_video_url', self::youtube_url( (string) $report->video_id ) ); // always our own rebuilt link
                }
            }
            update_post_meta( $post_id, 'ofp_price', $price );
            if ( in_array( $type, OFP_Property_Package::RENT_TYPES, true ) ) {
                OFP_Property_Package::save( $post_id, $in ); // move-in fees, total calculated
            }
            update_post_meta( $post_id, 'ofp_status', 'live' );
            update_post_meta( $post_id, 'ofp_verification_status', 'approved' );
            update_post_meta( $post_id, 'ofp_is_verified', '1' );
            update_post_meta( $post_id, 'ofp_verified_by_agent', (int) $report->agent_id );
            update_post_meta( $post_id, 'ofp_verified_at', current_time( 'mysql' ) );
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
            if ( method_exists( 'OFP_Property_CPT', 'sync_to_plugin_table' ) ) {
                OFP_Property_CPT::sync_to_plugin_table( $post_id, self::platform_id() );
            }
            $new = 'approved';
            $msg = 'Approved and published.';
            $mail = 'Your submission "' . $title . '" was approved and is now live.';
        } elseif ( 'changes' === $action ) {
            if ( '' === trim( $note ) ) {
                return [ false, 'Say what needs to change.' ];
            }
            update_post_meta( $post_id, 'ofp_verification_status', 'changes_requested' );
            $new = 'changes_requested';
            $msg = 'Sent back to the agent.';
            $mail = 'Your submission "' . $title . '" needs changes: ' . $note;
        } elseif ( 'reject' === $action ) {
            if ( '' === trim( $note ) ) {
                return [ false, 'Give a reason for rejecting.' ];
            }
            update_post_meta( $post_id, 'ofp_verification_status', 'rejected' );
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            $new = 'rejected';
            $msg = 'Rejected.';
            $mail = 'Your submission "' . $title . '" was not accepted: ' . $note;
        } else {
            return [ false, 'Unknown action.' ];
        }

        $wpdb->update( $t, [
            'status'      => $new,
            'review_note' => $note,
            'reviewed_by' => self::reviewer_name(),
            'reviewed_at' => current_time( 'mysql' ),
        ], [ 'id' => $report_id ] );

        if ( $member ) {
            self::email( $member->email, $member->name, 'Update on your property submission', '<p>' . esc_html( $mail ) . '</p><p><a href="' . esc_url( home_url( '/field' ) ) . '">Open your dashboard</a></p>' );
        }
        self::log( 'Field submission ' . $new, [ 'property_id' => $post_id, 'report_id' => $report_id ] );

        return [ true, $msg ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // Small helpers
    // ═══════════════════════════════════════════════════════════════════

    public static function reviewer_name(): string {
        $u = self::user();
        if ( ! $u ) {
            return '';
        }
        return (string) ( ! empty( $u->is_team_member ) ? $u->name : ( $u->business_name ?: ( $u->name ?? 'Owner' ) ) );
    }

    /** In-app notification plus an email to the owner (the team is copied by the platform rules). */
    private static function notify_reviewers( string $title, string $message_html ): void {
        $pid = self::platform_id();
        if ( $pid <= 0 ) {
            return;
        }
        if ( class_exists( 'OFP_Notification' ) ) {
            OFP_Notification::create( $pid, 'field_agent', $title, html_entity_decode( wp_strip_all_tags( $message_html ), ENT_QUOTES, 'UTF-8' ) );
        }
        $client = OFP_Client::get( $pid );
        if ( $client && ! empty( $client->email ) ) {
            // The subject avoids words like "verification" so the team copy rules do not treat it as private mail.
            self::email(
                $client->email,
                $client->business_name ?: 'Team',
                $title,
                '<p>' . $message_html . '</p><p><a href="' . esc_url( home_url( '/field-team' ) ) . '">Open the review queue</a></p>'
            );
        }
    }

    private static function email( string $to, string $name, string $subject, string $html ): void {
        if ( class_exists( 'OFP_Mailer' ) && is_email( $to ) ) {
            OFP_Mailer::send( $to, $name, $subject, $html );
        }
    }

    private static function log( string $what, array $data = [] ): void {
        if ( class_exists( 'OFP_Logger' ) ) {
            OFP_Logger::log( $what, self::platform_id(), $data + [ 'by' => self::reviewer_name() ] );
        }
    }
}
