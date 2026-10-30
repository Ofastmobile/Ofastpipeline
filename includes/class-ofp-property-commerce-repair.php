<?php
/**
 * Canonical property-commerce lifecycle repair layer.
 *
 * Fixes integration bugs without replacing the core property-commerce engine.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class OFP_Property_Commerce_Repair {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'reconcile_properties' ], 60 );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
        add_action( 'save_post_ofp_property', [ __CLASS__, 'notify_listing_owner' ], 1010, 3 );
        add_action( 'admin_menu', [ __CLASS__, 'replace_admin_pages' ], 100 );
        add_action( 'ofp_property_offer_created', [ __CLASS__, 'offer_created_notification' ], 10, 3 );
        add_action( 'template_redirect', [ __CLASS__, 'handle_offer_acceptance' ], 1 );
        add_action( 'wp_footer', [ __CLASS__, 'client_listing_actions' ], 998 );
    }

    public static function reconcile_properties(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ofp_properties';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) return;

        $posts = get_posts([
            'post_type'      => 'ofp_property',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'ofp_include_occupied' => true,
        ]);

        foreach ( $posts as $post_id ) {
            $client_id  = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );
            $owner_type = sanitize_key( get_post_meta( $post_id, 'ofp_owner_type', true ) );
            $owner_type = in_array( $owner_type, [ 'platform', 'client' ], true ) ? $owner_type : ( $client_id ? 'client' : 'platform' );
            $status = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
            $expected_wp_status = in_array( $status, [ 'live', 'taken' ], true ) ? 'publish' : 'draft';

            if ( get_post_status( $post_id ) !== $expected_wp_status && ! wp_is_post_revision( $post_id ) ) {
                remove_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000 );
                wp_update_post([ 'ID' => $post_id, 'post_status' => $expected_wp_status ]);
                add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
            }

            $row_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_post_id = %d LIMIT 1", $post_id ) );
            $data = [
                'client_id'  => 'client' === $owner_type ? $client_id : 0,
                'owner_type' => $owner_type,
                'owner_id'   => 'client' === $owner_type && $client_id ? $client_id : null,
                'status'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ];

            if ( $row_id ) {
                $wpdb->update( $table, $data, [ 'id' => (int) $row_id ] );
            } else {
                OFP_Property_CPT::sync_to_plugin_table( $post_id, 'client' === $owner_type ? $client_id : 0 );
                $wpdb->update( $table, [ 'owner_type' => $owner_type, 'owner_id' => $data['owner_id'], 'status' => $status ], [ 'wp_post_id' => $post_id ] );
            }
        }
    }

    public static function sync_property_status( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        $status = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
        $expected = in_array( $status, [ 'live', 'taken' ], true ) ? 'publish' : 'draft';
        if ( $post->post_status !== $expected && current_user_can( 'edit_post', $post_id ) ) {
            remove_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000 );
            wp_update_post([ 'ID' => $post_id, 'post_status' => $expected ]);
            add_action( 'save_post_ofp_property', [ __CLASS__, 'sync_property_status' ], 1000, 3 );
        }
    }

    public static function notify_listing_owner( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        $client_id  = absint( get_post_meta( $post_id, 'ofp_client_id', true ) );
        $owner_type = sanitize_key( get_post_meta( $post_id, 'ofp_owner_type', true ) );
        $status = sanitize_key( get_post_meta( $post_id, 'ofp_status', true ) ?: 'pending_upload' );
        if ( 'client' !== $owner_type || ! $client_id || ! in_array( $status, [ 'pending_upload', 'live', 'taken', 'expired' ], true ) ) return;
        $last = sanitize_key( get_post_meta( $post_id, '_ofp_last_listing_notice_status', true ) );
        if ( $last === $status ) return;
        update_post_meta( $post_id, '_ofp_last_listing_notice_status', $status );

        $title = get_the_title( $post_id ) ?: 'Property listing';
        $messages = [
            'pending_upload' => [ 'Property listing submitted', sprintf( 'Your property "%s" has been added and is awaiting publishing review.', $title ) ],
            'live'           => [ 'Property listing published', sprintf( 'Your property "%s" is now live on the property directory.', $title ) ],
            'taken'          => [ 'Property listing updated', sprintf( 'Your property "%s" has been marked as taken.', $title ) ],
            'expired'        => [ 'Property listing expired', sprintf( 'Your property "%s" has been marked as expired.', $title ) ],
        ];
        [ $subject, $message ] = $messages[ $status ];
        if ( class_exists( 'OFP_Notification' ) ) OFP_Notification::create( $client_id, 'property_listing_' . $status, $subject, $message );
    }

    public static function replace_admin_pages(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $parent = 'edit.php?post_type=ofp_property';
        // Removed double table hooks for purchases
    }

    private static function sale_properties( bool $exclude_committed = true ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $exclude = $exclude_committed ? " AND NOT EXISTS (SELECT 1 FROM {$p}ofp_property_purchases pu WHERE pu.property_id = pr.id AND pu.status IN ('active','completed'))" : '';
        return $wpdb->get_results( "SELECT pr.id, pr.title, pr.price, pr.listing_type, pr.client_id, pr.owner_type, pr.owner_id, c.business_name FROM {$p}ofp_properties pr LEFT JOIN {$p}ofp_clients c ON c.id = pr.client_id WHERE pr.listing_type IN ('sale','land') AND pr.status = 'live' {$exclude} ORDER BY pr.title ASC" );
    }

    public static function render_create_offer(): void {
        $properties = self::sale_properties( false );
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        $created = isset( $_GET['created'] ) && '1' === $_GET['created'];
        $offer_url = isset( $_GET['offer_url'] ) ? rawurldecode( wp_unslash( $_GET['offer_url'] ) ) : '';
        ?><div class="wrap"><h1>Create Installment Offer</h1><p>Only live sale properties that are not already committed to an active or completed purchase are available.</p>
        <?php if ( $created ) : ?><div class="notice notice-success"><p><strong>Offer created.</strong><?php if ( $offer_url ) : ?> Send this secure acceptance link to the buyer:<br><input readonly value="<?php echo esc_attr( $offer_url ); ?>" style="width:75%;max-width:800px"><button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">Copy Link</button><?php endif; ?></p></div><?php endif; ?>
        <?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'ofp_create_property_offer' ); ?><input type="hidden" name="action" value="ofp_create_property_offer"><table class="form-table"><tr><th>Property</th><td><select name="property_id" required style="min-width:520px"><option value="">Select property</option><?php foreach ( $properties as $property ) : ?><option value="<?php echo esc_attr( $property->id ); ?>"><?php echo esc_html( $property->title . ' — ₦' . number_format( (float) $property->price, 0 ) . ' — ' . ( $property->business_name ?: ( OFP_Brand::name() . ' / Admin' ) ) ); ?></option><?php endforeach; ?></select><?php if ( empty( $properties ) ) : ?><p class="description">No eligible live sale properties are currently available.</p><?php endif; ?></td></tr><tr><th>Buyer name</th><td><input class="regular-text" name="buyer_name" required></td></tr><tr><th>Buyer phone</th><td><input class="regular-text" name="buyer_phone" required></td></tr><tr><th>Buyer email</th><td><input type="email" class="regular-text" name="buyer_email"></td></tr><tr><th>Initial payment</th><td><input type="number" step="0.01" min="0" name="initial_payment" required></td></tr><tr><th>Payment frequency</th><td><select name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select></td></tr><tr><th>Installment amount</th><td><input type="number" step="0.01" min="0" name="installment_amount" required></td></tr><tr><th>Number of installments</th><td><input type="number" min="1" name="installment_count" required></td></tr><tr><th>Payment starts</th><td><input type="date" name="payment_start_date" required></td></tr><tr><th>First due date</th><td><input type="date" name="first_due_date" required></td></tr><tr><th>Grace period</th><td><input type="number" min="0" max="365" value="7" name="grace_period_days"> days</td></tr><tr><th>Offer expires</th><td><input type="date" name="offer_expires"></td></tr><tr><th>Terms / agreement</th><td><textarea class="large-text" rows="10" name="terms_text"></textarea></td></tr></table><?php submit_button( 'Create Offer', 'primary', 'submit', true, empty( $properties ) ? [ 'disabled' => 'disabled' ] : [] ); ?></form></div><?php
    }

    // Removed render_completed_purchases() to avoid duplicate tables

    private static function mark_property_taken(int $property_id): void { global $wpdb; $p=$wpdb->prefix; $wpdb->update("{$p}ofp_properties",['status'=>'taken','updated_at'=>current_time('mysql')],['id'=>$property_id]); $wp_post_id=$wpdb->get_var($wpdb->prepare("SELECT wp_post_id FROM {$p}ofp_properties WHERE id=%d LIMIT 1",$property_id)); if($wp_post_id){update_post_meta((int)$wp_post_id,'ofp_status','taken'); if('publish'!==get_post_status((int)$wp_post_id))wp_update_post(['ID'=>(int)$wp_post_id,'post_status'=>'publish']);} }

    public static function offer_created_notification(int $offer_id,string $raw_token,string $offer_url):void{
        global $wpdb; $p=$wpdb->prefix; $offer=$wpdb->get_row($wpdb->prepare("SELECT o.*,p.title AS property_title,c.sms_provider FROM {$p}ofp_property_offers o LEFT JOIN {$p}ofp_properties p ON p.id=o.property_id LEFT JOIN {$p}ofp_clients c ON c.id=o.client_id WHERE o.id=%d LIMIT 1",$offer_id)); if(!$offer)return;
        $message=sprintf('Your installment offer for %s is ready. Review and accept or decline it here: %s',$offer->property_title?:'the property',$offer_url);
        if(!empty($offer->buyer_email)){
            $message_html = sprintf('<p>Hello %s,</p><p>An installment payment plan has been created for the property: <strong>%s</strong>.</p>', esc_html($offer->buyer_name?:'there'), esc_html($offer->property_title?:'the property'));
            $message_html .= sprintf('<p>Total Price: NGN %s<br>Initial Payment: NGN %s</p>', number_format((float)$offer->total_price, 2), number_format((float)$offer->initial_payment, 2));
            $message_html .= sprintf('<p>Please review and accept the offer here:<br><a href="%s">Accept Installment Offer</a></p>', esc_url($offer_url));
            $message_html .= '<p>If you have any questions, please contact us.</p>';
            OFP_Mailer::send($offer->buyer_email, $offer->buyer_name?:'there', 'Property Installment Offer - ' . ($offer->property_title?:''), $message_html);
        }
        if(OFP_SMS::ready()&&!empty($offer->buyer_phone)&&!empty($offer->client_id)&&class_exists('OFP_Credit')&&OFP_Credit::has_balance((int)$offer->client_id,'sms',6.99)){ $sms=OFP_SMS::for_client( (int) (int )$offer->client_id); $sent=$sms->send($offer->buyer_phone,$message); if(!empty($sent['success']))OFP_Credit::deduct((int)$offer->client_id,'sms',6.99); }
        if(!empty($offer->client_id)&&class_exists('OFP_Notification'))OFP_Notification::create((int)$offer->client_id,'property_offer_created','Installment offer created',sprintf('An installment offer has been created for %s and sent to %s.', $offer->property_title?:'a property',$offer->buyer_name));
    }

    public static function handle_offer_acceptance(): void {
        if('POST'!==($_SERVER['REQUEST_METHOD']??'')||'accept'!==sanitize_key($_POST['ofp_offer_action']??''))return;
        $token=sanitize_text_field(wp_unslash($_GET['offer']??'')); if(!$token)return; global $wpdb; $p=$wpdb->prefix; $hash=hash('sha256',$token); $offer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ofp_property_offers WHERE offer_token_hash=%s LIMIT 1",$hash)); if(!$offer||'pending'!==$offer->status)return;
        $nonce=sanitize_text_field(wp_unslash($_POST['ofp_offer_nonce']??'')); if(!wp_verify_nonce($nonce,'ofp_offer_action_'.$offer->id)||empty($_POST['accept_terms'])||'1'!==$_POST['accept_terms'])return;
        $purchase_id=OFP_Property_Commerce::create_purchase_from_offer((int)$offer->id,isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):''); if(is_wp_error($purchase_id))return;
        // create_purchase_from_offer() already emits the purchase-created event.
        // Emit only the acceptance event here so the buyer receives one clear
        // purchase confirmation rather than duplicate emails.
        do_action('ofp_property_offer_accepted',(int)$offer->id,(int)$purchase_id);
        $installment_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ofp_property_installments WHERE purchase_id=%d AND amount_paid<amount_due ORDER BY installment_no ASC LIMIT 1",$purchase_id)); $expires=time()+OFP_Property_Manual_Payment::TOKEN_TTL; $payload=$purchase_id.'.'.$expires; $checkout_token=$payload.'.'.hash_hmac('sha256',$payload,wp_salt('auth')); $checkout_link=add_query_arg(['token'=>rawurlencode($checkout_token),'installment'=>$installment_id],home_url('/property-checkout/')); wp_safe_redirect($checkout_link); exit;
    }

    public static function client_listing_actions(): void {
        if ( ! is_page() || ! class_exists( 'OFP_Auth' ) ) return;
        $client = OFP_Auth::current_client();
        if ( ! $client || ! class_exists( 'OFP_Subscription' ) || ! OFP_Subscription::has_platform_access( $client->id ) ) return;
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT title,status,wp_post_id FROM {$wpdb->prefix}ofp_properties WHERE client_id=%d ORDER BY created_at DESC", (int) $client->id ) );
        if ( empty( $rows ) ) return;
        $payload = [];
        foreach ( $rows as $row ) {
            $payload[] = [
                'title'  => (string) $row->title,
                'status' => (string) $row->status,
                'url'    => $row->wp_post_id ? get_permalink( (int) $row->wp_post_id ) : '',
            ];
        }
        ?>
        <script>(function(){var p=<?php echo wp_json_encode($payload); ?>,h=document.querySelectorAll('.ofp-container h3');p.forEach(function(x){for(var i=0;i<h.length;i++){if(h[i].textContent.trim()!==x.title.trim())continue;var b=h[i].parentNode;if(!b||b.querySelector('.ofp-client-property-action'))break;var a=document.createElement('div');a.className='ofp-client-property-action';a.style.cssText='display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap;';if(x.status==='live'&&x.url){var l=document.createElement('a');l.href=x.url;l.textContent='View Listing';l.style.cssText='display:inline-block;text-decoration:none;padding:8px 12px;border-radius:7px;background:#2563eb;color:#fff;';a.appendChild(l);}else if(x.status==='pending_upload'){var s=document.createElement('span');s.textContent='Awaiting admin publishing';s.style.color='#b45309';a.appendChild(s);}var m=document.createElement('span');m.textContent='Managed by ' + <?php echo wp_json_encode( OFP_Brand::name() ); ?>;m.style.cssText='font-size:12px;color:#64748b;';a.appendChild(m);b.appendChild(a);break;}});})();</script>
        <?php
    }
}
