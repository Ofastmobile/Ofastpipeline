<?php
/** Admin property purchase creation. */
if ( ! defined( 'ABSPATH' ) ) exit;
class OFP_Property_Purchase_Admin {
    public static function init(): void { add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] ); add_action( 'admin_post_ofp_create_property_purchase', [ __CLASS__, 'handle_create_purchase' ] ); }
    public static function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        add_submenu_page( 'edit.php?post_type=ofp_property', 'Add Property Purchase', 'Add Purchase', 'manage_options', 'ofp-property-add-purchase', [ __CLASS__, 'render_create' ] );
    }
    public static function render_create(): void {
        global $wpdb; $p = $wpdb->prefix;
        $properties = $wpdb->get_results( "SELECT id, title, price FROM {$p}ofp_properties WHERE listing_type = 'sale' AND status IN ('live','pending_upload') ORDER BY title ASC" );
        $leads = $wpdb->get_results( "SELECT l.id,l.client_id,l.property_id,l.name,l.phone,l.email,p.title AS property_title FROM {$p}ofp_leads l LEFT JOIN {$p}ofp_properties p ON p.id=l.property_id ORDER BY l.created_at DESC LIMIT 300" );
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : ''; $created_id = absint($_GET['created'] ?? 0);
        ?>
        <div class="wrap"><h1>Add Property Purchase</h1><p>Create an outright or installment purchase. No buyer account is created.</p>
        <?php if($created_id): ?><div class="notice notice-success"><p>Purchase <strong>#<?php echo esc_html($created_id); ?></strong> created.</p></div><?php endif; ?>
        <?php if($error): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ofp_create_property_purchase'); ?><input type="hidden" name="action" value="ofp_create_property_purchase">
        <table class="form-table"><tr><th>Property</th><td><select name="property_id" required><option value="">Select sale property</option><?php foreach($properties as $property): ?><option value="<?php echo esc_attr($property->id); ?>"><?php echo esc_html($property->title.' — NGN '.number_format((float)$property->price,0)); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th>Purchase type</th><td><select name="purchase_type" id="purchase_type"><option value="installment">Installment</option><option value="outright">Outright / Full Payment</option></select></td></tr>
        <tr><th>Buyer source</th><td><select name="buyer_source" id="buyer_source"><option value="offline">Offline buyer</option><option value="lead">Existing lead</option></select></td></tr>
        <tr><th>Existing lead</th><td><select name="lead_id" id="lead_id"><option value="">— No lead / offline buyer —</option><?php foreach($leads as $lead): ?><option value="<?php echo esc_attr($lead->id); ?>" data-name="<?php echo esc_attr($lead->name); ?>" data-phone="<?php echo esc_attr($lead->phone); ?>" data-email="<?php echo esc_attr($lead->email); ?>"><?php echo esc_html('#'.$lead->id.' — '.($lead->name?:'Unnamed').' — '.$lead->phone); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th>Buyer name</th><td><input class="regular-text" name="buyer_name" id="buyer_name" required></td></tr><tr><th>Buyer phone</th><td><input class="regular-text" name="buyer_phone" id="buyer_phone" required></td></tr><tr><th>Buyer email</th><td><input type="email" class="regular-text" name="buyer_email" id="buyer_email"></td></tr>
        <tr><th>Initial payment</th><td><input type="number" step="0.01" min="0" name="initial_payment" id="initial_payment"></td></tr><tr><th>Installment amount</th><td><input type="number" step="0.01" min="0" name="installment_amount" id="installment_amount"></td></tr><tr><th>Number of installments</th><td><input type="number" min="1" name="installment_count" id="installment_count"></td></tr><tr><th>Payment starts</th><td><input type="date" name="payment_start_date" id="payment_start_date"></td></tr><tr><th>First due date</th><td><input type="date" name="first_due_date" id="first_due_date"></td></tr><tr><th>Grace period</th><td><input type="number" min="0" max="365" value="7" name="grace_period_days" id="grace_period_days"></td></tr>
        <tr><th>Payment method</th><td><select name="payment_method"><option value="manual">Manual</option><option value="checkout">Checkout</option><option value="virtual_account">Virtual Account</option></select></td></tr></table><?php submit_button('Create Purchase'); ?></form></div>
        <script>(function(){var t=document.getElementById('purchase_type'), fields=['initial_payment','installment_amount','installment_count','payment_start_date','first_due_date','grace_period_days'];function sync(){var outright=t.value==='outright';fields.forEach(function(id){var e=document.getElementById(id),r=e.closest('tr');r.style.display=outright&&id!=='grace_period_days'?'none':(outright&&id==='grace_period_days'?'none':'');e.required=!outright&&id!=='grace_period_days';});}t.addEventListener('change',sync);sync();var s=document.getElementById('buyer_source'),l=document.getElementById('lead_id');function ls(){l.disabled=s.value!=='lead';if(s.value!=='lead')l.value='';}s.addEventListener('change',ls);ls();l.addEventListener('change',function(){var o=l.options[l.selectedIndex];if(!l.value)return;document.getElementById('buyer_name').value=o.dataset.name||'';document.getElementById('buyer_phone').value=o.dataset.phone||'';document.getElementById('buyer_email').value=o.dataset.email||'';});})();</script><?php }
    public static function handle_create_purchase(): void {
        if(!current_user_can('manage_options')) wp_die('Access denied.'); check_admin_referer('ofp_create_property_purchase');
        $result=OFP_Property_Purchase_Service::create([
            'property_id'=>absint($_POST['property_id']??0),'purchase_type'=>sanitize_key($_POST['purchase_type']??'installment'),'lead_id'=>absint($_POST['lead_id']??0)?:null,
            'buyer_name'=>sanitize_text_field(wp_unslash($_POST['buyer_name']??'')),'buyer_phone'=>sanitize_text_field(wp_unslash($_POST['buyer_phone']??'')),'buyer_email'=>sanitize_email(wp_unslash($_POST['buyer_email']??''))?:null,
            'initial_payment'=>(float)($_POST['initial_payment']??0),'installment_amount'=>(float)($_POST['installment_amount']??0),'installment_count'=>absint($_POST['installment_count']??0),'payment_start_date'=>sanitize_text_field(wp_unslash($_POST['payment_start_date']??'')),'first_due_date'=>sanitize_text_field(wp_unslash($_POST['first_due_date']??'')),'grace_period_days'=>absint($_POST['grace_period_days']??7),'payment_method'=>sanitize_key($_POST['payment_method']??'manual')
        ]);
        if(is_wp_error($result)){wp_safe_redirect(add_query_arg('error',rawurlencode($result->get_error_message()),admin_url('edit.php?post_type=ofp_property&page=ofp-property-add-purchase')));exit;}
        wp_safe_redirect(add_query_arg('created',(int)$result,admin_url('edit.php?post_type=ofp_property&page=ofp-property-add-purchase')));exit;
    }
}
