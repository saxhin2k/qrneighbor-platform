<?php
/**
 * Plugin Name: QR Neighbor – Deals & Leads (Pro v2.0)
 * Description: Deals CPT + client dashboard + front-end submit/update (pending/draft) + admin approve/reject + auto-expire + publish email. Custom “Owner (Client)” box reliably sets post_author. Includes QR Leads module.
 * Version: 2.0
 * Author: QR Neighbor
 */

if (!defined('ABSPATH')) exit;

/* ============================================================
   SHARED BUSINESS KEY HELPER + SHORTCODES
   ========================================================== */

if ( ! function_exists( 'qrn_get_business_key' ) ) {
  function qrn_get_business_key() {

    // If router defined a constant, trust that first
    if ( defined( 'QRN_BUSINESS_KEY' ) && QRN_BUSINESS_KEY ) {
      return sanitize_title( QRN_BUSINESS_KEY );
    }

    // Fallback to detection logic (QR Leads function, defined later)
    if ( function_exists( 'qrn_detect_business' ) ) {
      return sanitize_title( qrn_detect_business() );
    }

    return '';
  }
}

if ( ! function_exists( 'qrn_business_key_shortcode' ) ) {
  function qrn_business_key_shortcode() {
    return esc_html( qrn_get_business_key() );
  }
}
add_shortcode( 'qrn-business-key', 'qrn_business_key_shortcode' );
add_shortcode( 'qrn_business_key', 'qrn_business_key_shortcode' );

/* ============================================================================
   DEALS
============================================================================ */
class QRN_Deals_Pro {
  const CPT                  = 'qr_deal';
  const NONCE                = 'qrn_submit_deal';
  const ENABLE_FRONTEND_EDIT = true; // clients may edit pending/draft
  const FLAG_SHOW_DRAFT      = '_qrn_show_draft_to_client';

  public function __construct() {
    // CPT & editor
    add_action('init', [$this,'register_cpt']);
    add_action('init', [$this,'remove_editor_support'], 100);
    add_filter('use_block_editor_for_post_type', [$this,'force_classic_for_deals'], 10, 2);

    // Shortcodes
    add_shortcode('qr_submit_deal',      [$this,'sc_submit_deal']);
    add_shortcode('qr_client_dashboard', [$this,'sc_client_dashboard']);

    // Front end
    add_action('init',               [$this,'handle_form']);
    add_action('wp_enqueue_scripts', [$this,'enqueue_css']);

    // Admin list & actions
    add_filter('manage_edit-'.self::CPT.'_columns',        [$this,'admin_columns']);
    add_action('manage_'.self::CPT.'_posts_custom_column', [$this,'admin_column_content'], 10, 2);
    add_filter('post_row_actions',   [$this,'row_actions'], 10, 2);
    add_action('admin_post_qrn_approve', [$this,'admin_approve']);
    add_action('admin_post_qrn_reject',  [$this,'admin_reject']);

    // Deal Details + autofill
    add_action('add_meta_boxes', [$this,'add_deal_metabox']);
    add_action('save_post_'.self::CPT, [$this,'save_deal_metabox'], 10, 3);
    add_action('wp_ajax_qrn_author_autofill', [$this,'ajax_author_autofill']);

    // Custom Owner (Client) replaces core Author
    add_action('add_meta_boxes',     [$this,'replace_author_box'], 20);
    add_action('add_meta_boxes',     [$this,'owner_metabox']);
    add_filter('wp_insert_post_data',[$this,'apply_owner_on_save'], 99, 2);
    add_action('add_meta_boxes',     [$this,'owner_readonly_box']);

    // Optional: show specific drafts to client
    add_action('add_meta_boxes',     [$this,'client_visibility_box']);
    add_action('save_post_'.self::CPT, [$this,'save_client_visibility'], 10, 2);

    // Publish email + expire + slug
    add_action('transition_post_status', [$this,'email_on_publish'], 10, 3);
    add_action('save_post_'.self::CPT,   [$this,'save_business_slug'], 10, 3);
    add_action('init',                   [$this,'expire_now']);               // safety run
    add_action('qrn_cron_expire_deals',  [$this,'expire_now']);
    register_activation_hook(__FILE__,   [__CLASS__,'schedule_cron']);
    register_deactivation_hook(__FILE__, [__CLASS__,'clear_cron']);
  }

  /* = CPT & Editor = */
  public function register_cpt() {
    register_post_type(self::CPT, [
      'labels' => [
        'name'          => 'Deals',
        'singular_name' => 'Deal',
        'add_new_item'  => 'Add New Deal',
        'edit_item'     => 'Edit Deal',
      ],
      'public'       => false,
      'show_ui'      => true,
      'show_in_menu' => true,
      'show_in_rest' => true,
      'supports'     => ['title','editor','thumbnail','author'],
      'menu_icon'    => 'dashicons-megaphone',
      'capability_type' => 'post',
      'map_meta_cap'    => true,
    ]);
  }
  public function remove_editor_support(){ remove_post_type_support(self::CPT,'editor'); }
  public function force_classic_for_deals($use,$pt){ return $pt===self::CPT?false:$use; }

  /* = Styles = */
  public function enqueue_css(){
    $css = <<<'CSS'
:root{--qr-blue:#0b74ff;--qr-text:#2b3340;--qr-muted:#6b7280;--qr-card:#fff;--qr-ring:rgba(11,116,255,.12);--qr-radius:18px}
.qrn-wrap{display:grid;gap:20px}
.qrn-card{background:var(--qr-card);border:1px solid #eef2f7;border-radius:var(--qr-radius);padding:16px;box-shadow:0 18px 40px rgba(16,24,40,.08),0 2px 10px rgba(16,24,40,.05);display:grid;gap:10px}
.qrn-title{font-weight:900;font-size:20px;color:var(--qr-text);margin:0 0 2px}
.qrn-meta{color:var(--qr-muted);font-size:13px;margin:2px 0}
.qrn-badge{display:inline-block;background:#eef4ff;color:var(--qr-blue);font-weight:800;padding:6px 10px;border-radius:999px;font-size:12px;margin-bottom:6px;letter-spacing:.2px}
.qrn-form{background:#fafafa;border:1px solid #eef2f7;border-radius:14px;padding:16px;margin:10px 0}
.qrn-form .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.qrn-form .row-1{display:grid;grid-template-columns:1fr;gap:12px}
.qrn-form input,.qrn-form textarea,.qrn-form select{width:100%;padding:12px 14px;border:1px solid #e5e7eb;border-radius:12px;font-size:16px;outline:none;box-sizing:border-box}
.qrn-form textarea{min-height:110px}
.qrn-form input:focus,.qrn-form textarea:focus,.qrn-form select:focus{border-color:var(--qr-blue);box-shadow:0 0 0 4px var(--qr-ring)}
.qrn-form input[name='qrn_headline']{color:#6b7280}
.qrn-btn{background:var(--qr-blue);color:#fff;border:none;padding:12px 16px;border-radius:12px;font-weight:800;cursor:pointer;box-shadow:0 10px 24px rgba(11,116,255,.18)}
.qrn-ok{padding:12px 14px;border-radius:12px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;margin-bottom:10px}
.qrn-err{padding:12px 14px;border-radius:12px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;margin-bottom:10px}
@media (max-width:768px){.qrn-form .row{grid-template-columns:1fr}}
CSS;
    wp_register_style('qrn-deals-css', false);
    wp_enqueue_style('qrn-deals-css');
    wp_add_inline_style('qrn-deals-css', $css);
  }

  /* = Shortcode: Submit / Update = */
  public function sc_submit_deal($atts){
    $atts = shortcode_atts(['redirect'=>''], $atts);
    $out=''; $success=!empty($_GET['qrn_success']);
    $error = !empty($_GET['qrn_error']) ? sanitize_text_field(wp_unslash($_GET['qrn_error'])) : '';

    if($success) $out.='<div class="qrn-ok">✅ Your deal was submitted! We will review and publish it shortly.</div>';
    if($error)   $out.='<div class="qrn-err">⚠️ '.esc_html($error).'</div>';

    $u = is_user_logged_in()? wp_get_current_user():null;
    $pref=['business'=>$u && $u->display_name?$u->display_name:'','email'=>$u?$u->user_email:'','headline'=>'','item'=>'','desc'=>'','start'=>'','end'=>'','type'=>'','value'=>''];
    $edit_id=0;

    if(self::ENABLE_FRONTEND_EDIT && isset($_GET['edit_deal'])){
      $try=(int)$_GET['edit_deal']; $p=$try? get_post($try):null;
      if($p && $p->post_type===self::CPT && $p->post_status!=='publish' && (((int)$p->post_author===get_current_user_id())||current_user_can('edit_post',$try))){
        $edit_id=$try;
        $pref['business']=get_post_meta($edit_id,'_qr_business',true);
        $pref['email']   =get_post_meta($edit_id,'_qr_email',true);
        $pref['headline']=$p->post_title;
        $pref['item']    =get_post_meta($edit_id,'_qr_item',true);
        $pref['desc']    =$p->post_content;
        $pref['start']   =get_post_meta($edit_id,'_qr_start',true);
        $pref['end']     =get_post_meta($edit_id,'_qr_end',true);
        $pref['type']    =get_post_meta($edit_id,'_qr_type',true);
        $pref['value']   =get_post_meta($edit_id,'_qr_value',true);
      }
    }

    $out.='<form class="qrn-form" method="post" enctype="multipart/form-data">';
    $out.=wp_nonce_field(self::NONCE, self::NONCE, true, false);
    if($atts['redirect']) $out.='<input type="hidden" name="qrn_redirect" value="'.esc_url($atts['redirect']).'">';
    $out.='<div class="row-1"><label>Business Name<br><input type="text" name="qrn_business" value="'.esc_attr($pref['business']).'" required></label></div>';
    $out.='<div class="row-1"><label>Contact Email (for confirmations)<br><input type="email" name="qrn_email" value="'.esc_attr($pref['email']).'" required></label></div>';
    $out.='<div class="row-1"><label>Deal Headline (optional)<br><input type="text" name="qrn_headline" value="'.esc_attr($pref['headline']).'" placeholder="Leave blank to auto-generate (e.g. 20% Off Lunch)"></label></div>';
    $out.='<div class="row-1"><label>Item / Applies To<br><input type="text" name="qrn_item" value="'.esc_attr($pref['item']).'" placeholder="e.g., Lunch, Any Service, Smoothies"></label></div>';
    $out.='<div class="row-1"><label>Description (optional — add any details or fine print)<br><textarea name="qrn_desc" placeholder="Optional notes: valid days, exclusions, how to redeem…">'.esc_textarea($pref['desc']).'</textarea></label></div>';
    $out.='<div class="row"><label>Start Date<br><input type="date" name="qrn_start" value="'.esc_attr($pref['start']).'" required></label><label>End Date<br><input type="date" name="qrn_end" value="'.esc_attr($pref['end']).'" required></label></div>';
    $out.='<div class="row"><label>Discount Type<br><select name="qrn_type" required><option value="">Select…</option><option value="percent" '.selected($pref['type'],'percent',false).'>% Off</option><option value="amount" '.selected($pref['type'],'amount',false).'>$ Off</option><option value="bogo" '.selected($pref['type'],'bogo',false).'>BOGO</option></select></label><label>Value<br><input type="number" step="0.01" name="qrn_value" value="'.esc_attr($pref['value']).'" placeholder="e.g., 20" required></label></div>';
    $out.='<div class="row-1"><label>Promo Image (optional)<br><input type="file" name="qrn_image" accept="image/*"></label></div>';
    $out.='<div class="row-1" style="margin-top:6px"><label><input type="checkbox" name="qrn_consent" required> I agree to the Terms and confirm I’m authorized to post this offer.</label></div>';
    if($edit_id){ $out.='<input type="hidden" name="qrn_action" value="update_deal"><input type="hidden" name="qrn_edit_id" value="'.(int)$edit_id.'">'; $btn='Update Deal'; }
    else{ $out.='<input type="hidden" name="qrn_action" value="submit_deal">'; $btn='Submit Deal'; }
    $out.='<div style="margin-top:12px"><button class="qrn-btn" type="submit">'.esc_html($btn).'</button></div></form>';
    if($success || $error) $out.='<script>(function(){try{var u=new URL(window.location);u.searchParams.delete("qrn_success");u.searchParams.delete("qrn_error");history.replaceState({}, "", u);}catch(e){}})();</script>';
    return $out;
  }

  /* = Shortcode: Client Dashboard = */
  public function sc_client_dashboard($atts){
    if(!is_user_logged_in()){
      $login=wp_login_url(get_permalink());
      return '<div class="qrn-card"><div class="qrn-title">Client Dashboard</div><div class="qrn-meta">Please <a href="'.esc_url($login).'">log in</a> to submit and manage your deals.</div></div>';
    }
    $uid=get_current_user_id();
    $msg_ok=!empty($_GET['qrn_success']);
    $msg_err=!empty($_GET['qrn_error'])? sanitize_text_field(wp_unslash($_GET['qrn_error'])) : '';

    $pending_q=new WP_Query(['post_type'=>self::CPT,'post_status'=>'pending','author'=>$uid,'orderby'=>'date','order'=>'DESC','posts_per_page'=>-1]);
    $flagged_q=new WP_Query(['post_type'=>self::CPT,'post_status'=>'draft','author'=>$uid,'meta_key'=>self::FLAG_SHOW_DRAFT,'meta_value'=>'1','orderby'=>'date','order'=>'DESC','posts_per_page'=>-1]);
    $pending_posts=array_merge($pending_q->posts,$flagged_q->posts);

    $published_q=new WP_Query(['post_type'=>self::CPT,'post_status'=>'publish','author'=>$uid,'orderby'=>'date','order'=>'DESC','posts_per_page'=>-1]);
    $dashboard_url=get_permalink();

    ob_start();
    echo '<div class="qrn-wrap" style="max-width:900px;margin:0 auto">';
    if($msg_ok)  echo '<div class="qrn-ok">✅ Deal submitted! We’ll review and publish it shortly.</div>';
    if($msg_err) echo '<div class="qrn-err">⚠️ '.esc_html($msg_err).'</div>';

    echo '<div><div class="qrn-title" style="margin-bottom:8px">Submit a New Deal</div>'.do_shortcode('[qr_submit_deal redirect="'.esc_url($dashboard_url).'"]').'</div>';

    echo '<div><div class="qrn-title" style="margin:14px 0 8px">My Deals — Pending Approval</div>';
    if(!empty($pending_posts)){
      foreach($pending_posts as $p){
        $pid=$p->ID; $s=get_post_meta($pid,'_qr_start',true); $e=get_post_meta($pid,'_qr_end',true);
        $is_draft=(get_post_status($pid)==='draft');
        $badge=$is_draft? '<span class="qrn-badge" style="background:#e7f1ff;color:#0b5ed7;border:1px solid #cfe0ff">Draft (admin)</span>'
                        : '<span class="qrn-badge" style="background:#fff7ed;color:#b45309;border:1px solid #fde68a">Pending</span>';
        echo '<div class="qrn-card"><div>'.$badge;
        echo '<div class="qrn-title">'.esc_html(get_the_title($pid)?:'(no title)').'</div>';
        echo '<div class="qrn-meta">Valid '.($s?esc_html(date_i18n('M j',strtotime($s))):'—').'–'.($e?esc_html(date_i18n('M j',strtotime($e))):'—').'</div>';
        $content=trim(get_post_field('post_content',$pid));
        if($content!=='') echo '<div class="qrn-meta">'.wp_kses_post(wp_trim_words($content,28)).'</div>';
        if(self::ENABLE_FRONTEND_EDIT){ $link=esc_url(add_query_arg(['edit_deal'=>$pid],$dashboard_url)); echo '<div class="qrn-meta"><a href="'.$link.'">Update</a></div>'; }
        echo '</div></div>';
      }
    } else echo '<div class="qrn-meta">No pending deals.</div>';
    echo '</div>';

    echo '<div><div class="qrn-title" style="margin:14px 0 8px">My Published Deals</div>';
    if($published_q->have_posts()){
      while($published_q->have_posts()){ $published_q->the_post();
        $s=get_post_meta(get_the_ID(),'_qr_start',true); $e=get_post_meta(get_the_ID(),'_qr_end',true);
        echo '<div class="qrn-card"><div><span class="qrn-badge">Live</span>';
        echo '<div class="qrn-title">'.esc_html(get_the_title()).'</div>';
        echo '<div class="qrn-meta">Valid '.($s?esc_html(date_i18n('M j',strtotime($s))):'—').'–'.($e?esc_html(date_i18n('M j',strtotime($e))):'—').'</div>';
        $content=trim(get_the_content());
        if($content!=='') echo '<div class="qrn-meta">'.wp_kses_post(wp_trim_words($content,28)).'</div>';
        if(current_user_can('edit_post',get_the_ID())) echo '<div class="qrn-meta"><a href="'.esc_url(get_edit_post_link()).'">Edit</a></div>';
        echo '</div></div>';
      } wp_reset_postdata();
    } else echo '<div class="qrn-meta">No published deals yet.</div>';
    echo '</div></div>';
    return ob_get_clean();
  }

  /* = Form handler = */
  public function handle_form(){
    if(!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
    $action = isset($_POST['qrn_action']) ? sanitize_text_field($_POST['qrn_action']) : '';
    if($action!=='submit_deal' && $action!=='update_deal') return;

    $biz  = sanitize_text_field($_POST['qrn_business'] ?? '');
    $email= sanitize_email($_POST['qrn_email'] ?? '');
    $head = sanitize_text_field($_POST['qrn_headline'] ?? '');
    $item = sanitize_text_field($_POST['qrn_item'] ?? '');
    $desc = wp_kses_post($_POST['qrn_desc'] ?? '');
    $start= sanitize_text_field($_POST['qrn_start'] ?? '');
    $end  = sanitize_text_field($_POST['qrn_end'] ?? '');
    $type = sanitize_text_field($_POST['qrn_type'] ?? '');
    $value= sanitize_text_field($_POST['qrn_value'] ?? '');

    if(!$biz || !$start || !$end || !$email || !is_email($email)) $this->redirect_with('qrn_error','Please complete all required fields with a valid email.');
    if($type==='' || $value==='') $this->redirect_with('qrn_error','Select a discount type and enter a discount value.');
    if(empty($head)) $head = $this->compose_title($type,$value,$item) ?: 'Special Offer';

    if($action==='submit_deal'){
      $post_id = wp_insert_post(['post_type'=>self::CPT,'post_status'=>'pending','post_title'=>$head,'post_content'=>$desc,'post_author'=>get_current_user_id()]);
      if(is_wp_error($post_id) || !$post_id) $this->redirect_with('qrn_error','Could not save deal. Please try again.');
    } else {
      if(!self::ENABLE_FRONTEND_EDIT) return;
      $post_id=(int)($_POST['qrn_edit_id'] ?? 0); $post=$post_id? get_post($post_id):null;
      if(!$post || $post->post_type!==self::CPT) $this->redirect_with('qrn_error','Invalid deal.');
      if((int)$post->post_author!==get_current_user_id() && !current_user_can('edit_post',$post_id)) $this->redirect_with('qrn_error','You do not have permission to update this deal.');
      if($post->post_status==='publish' && !current_user_can('publish_posts')) $this->redirect_with('qrn_error','Published deals cannot be updated. Please submit a new deal.');
      wp_update_post(['ID'=>$post_id,'post_status'=>'pending','post_title'=>$head,'post_content'=>$desc]);
    }

    update_post_meta($post_id,'_qr_business',$biz);
    update_post_meta($post_id,'_qr_item',$item);
    update_post_meta($post_id,'_qr_start',$start);
    update_post_meta($post_id,'_qr_end',$end);
    update_post_meta($post_id,'_qr_email',$email);
    update_post_meta($post_id,'_qr_type',$type);
    update_post_meta($post_id,'_qr_value',$value);
    update_post_meta($post_id,'_qr_business_slug',$this->slugify($biz));

    if(!empty($_FILES['qrn_image']['name'])){
      require_once ABSPATH.'wp-admin/includes/file.php';
      require_once ABSPATH.'wp-admin/includes/media.php';
      require_once ABSPATH.'wp-admin/includes/image.php';
      $move=wp_handle_upload($_FILES['qrn_image'],['test_form'=>false]);
      if($move && !isset($move['error'])){
        $ft=wp_check_filetype($move['file'],null);
        $aid=wp_insert_attachment(['post_mime_type'=>$ft['type'],'post_title'=>sanitize_file_name(basename($move['file'])),'post_content'=>'','post_status'=>'inherit'],$move['file'],$post_id);
        $meta=wp_generate_attachment_metadata($aid,$move['file']); wp_update_attachment_metadata($aid,$meta); set_post_thumbnail($post_id,$aid);
      }
    }

    $admin=get_option('admin_email'); $editurl=admin_url('post.php?post='.$post_id.'&action=edit');
    if($action==='submit_deal'){
      wp_mail($admin,'New Deal Submitted (QR Neighbor)',"A new deal is pending review:\n\nBusiness: $biz\nDeal: $head\nDates: $start to $end\nClient Email: $email\n\nEdit: $editurl");
      wp_mail($email,'We received your deal: '.$head,"Thanks for submitting your promotion to QR Neighbor!\n\nBusiness: $biz\nDeal: $head\nDates: $start to $end\n\nWe’ll review and publish it shortly.\n\n".get_bloginfo('name'));
    } else {
      wp_mail($admin,'Deal Updated (Pending Review) – QR Neighbor',"A client updated a deal (pending):\n\nBusiness: $biz\nDeal: $head\nDates: $start to $end\nClient Email: $email\n\nEdit: $editurl");
    }

    $this->redirect_with('qrn_success','1');
  }

  /* = Publish email = */
  public function email_on_publish($new,$old,$post){
    if($post->post_type!==self::CPT) return;
    if($old==='publish' || $new!=='publish') return;
    $email=get_post_meta($post->ID,'_qr_email',true);
    if(!$email || !is_email($email)) return;
    $biz=get_post_meta($post->ID,'_qr_business',true);
    $start=get_post_meta($post->ID,'_qr_start',true);
    $end=get_post_meta($post->ID,'_qr_end',true);
    $link=home_url('/client-dashboard/#qrn-published');
    wp_mail($email,'Your deal is live on QR Neighbor 🎉',"Great news—your promotion has been approved and is now live!\n\nBusiness: $biz\nDeal: {$post->post_title}\nDates: $start to $end\nManage it here: $link\n\nThanks for using QR Neighbor.");
  }

  /* = Admin list & actions = */
  public function admin_columns($cols){
    $new=[]; foreach($cols as $k=>$v){ $new[$k]=$v; if($k==='title'){ $new['qrn_business']='Business'; $new['qrn_item']='Item'; $new['qrn_dates']='Dates'; $new['qrn_email']='Client Email';}}
    return $new;
  }
  public function admin_column_content($col,$post_id){
    if($col==='qrn_business') echo esc_html(get_post_meta($post_id,'_qr_business',true)?:'—');
    elseif($col==='qrn_item') echo esc_html(get_post_meta($post_id,'_qr_item',true)?:'—');
    elseif($col==='qrn_dates'){ $s=get_post_meta($post_id,'_qr_start',true); $e=get_post_meta($post_id,'_qr_end',true); echo ($s?esc_html($s):'—').' – '.($e?esc_html($e):'—'); }
    elseif($col==='qrn_email'){ $e=get_post_meta($post_id,'_qr_email',true); echo $e? '<a href="mailto:'.esc_attr($e).'">'.esc_html($e).'</a>' : '—'; }
  }
  public function row_actions($actions,$post){
    if($post->post_type!==self::CPT) return $actions;
    if(!current_user_can('publish_post',$post->ID)) return $actions;
    if($post->post_status!=='publish'){ $approve=wp_nonce_url(admin_url('admin-post.php?action=qrn_approve&post='.$post->ID),'qrn_moderate_'.$post->ID); $actions['qrn_approve']='<a href="'.$approve.'" style="color:#0b74ff;font-weight:700">Approve</a>'; }
    $reject=wp_nonce_url(admin_url('admin-post.php?action=qrn_reject&post='.$post->ID),'qrn_moderate_'.$post->ID); $actions['qrn_reject']='<a href="'.$reject.'" style="color:#b42318">Reject</a>';
    return $actions;
  }
  public function admin_approve(){ if(!current_user_can('publish_posts')) wp_die('Permission denied.'); $id=(int)($_GET['post']??0); if(!$id||!wp_verify_nonce($_GET['_wpnonce']??'','qrn_moderate_'.$id)) wp_die('Bad nonce.'); wp_update_post(['ID'=>$id,'post_status'=>'publish']); wp_safe_redirect(admin_url('edit.php?post_type='.self::CPT.'&qrn_msg=approved')); exit; }
  public function admin_reject(){ if(!current_user_can('delete_post',(int)($_GET['post']??0))) wp_die('Permission denied.'); $id=(int)($_GET['post']??0); if(!$id||!wp_verify_nonce($_GET['_wpnonce']??'','qrn_moderate_'.$id)) wp_die('Bad nonce.'); wp_trash_post($id); wp_safe_redirect(admin_url('edit.php?post_type='.self::CPT.'&qrn_msg=rejected')); exit; }

  /* = Deal Details + Auto-Fill = */
  public function add_deal_metabox(){ add_meta_box('qrn_deal_meta','Deal Details',[$this,'render_deal_metabox'],self::CPT,'normal','high'); }
  public function render_deal_metabox($post){
    $biz=get_post_meta($post->ID,'_qr_business',true); $email=get_post_meta($post->ID,'_qr_email',true);
    $item=get_post_meta($post->ID,'_qr_item',true); $start=get_post_meta($post->ID,'_qr_start',true); $end=get_post_meta($post->ID,'_qr_end',true);
    $type=get_post_meta($post->ID,'_qr_type',true); $value=get_post_meta($post->ID,'_qr_value',true);
    $nonce=wp_create_nonce('qrn_autofill_nonce'); $ajax=admin_url('admin-ajax.php'); wp_nonce_field('qrn_meta_save','qrn_meta_nonce'); ?>
    <style>.qrn-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:14px 16px;margin-top:6px;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb}.qrn-grid .full{grid-column:1/-1}.qrn-field input,.qrn-field select,.qrn-field textarea{width:100%;padding:8px 10px;border-radius:6px;border:1px solid #d1d5db;background:#ffffff;font-size:13px}.qrn-label{font-weight:600;font-size:12px;color:#111827;margin:4px 0 4px}.qrn-autofill-wrap{display:flex;justify-content:flex-end;margin:2px 0 0}.qrn-title-preview-wrap{margin:8px 0 0}.qrn-title-preview{border-radius:6px;border:1px solid #e5e7eb;background:#f3f4f6;padding:7px 10px;font-size:13px;color:#111827}
    </style>
    <div class="qrn-autofill-wrap"><button type="button" class="button" id="qrnAutofillBtn">🔄 Auto-Fill from Owner</button></div>
    <div class="qrn-grid" data-ajax="<?php echo esc_url($ajax); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
      <div class="qrn-field"><div class="qrn-label">Business</div><input type="text" name="qrn_business" value="<?php echo esc_attr($biz); ?>" id="qrn_business"></div>
      <div class="qrn-field"><div class="qrn-label">Client Email</div><input type="email" name="qrn_email" value="<?php echo esc_attr($email); ?>" id="qrn_email"></div>
      <div class="qrn-field"><div class="qrn-label">Item / Applies To</div><input type="text" name="qrn_item" value="<?php echo esc_attr($item); ?>" placeholder="e.g., Lunch, Any Service, Smoothies"></div>
      <div class="qrn-field"><div class="qrn-label">Discount Type</div><select name="qrn_type"><option value="">—</option><option value="percent" <?php selected($type,'percent'); ?>>% Off</option><option value="amount" <?php selected($type,'amount'); ?>>$ Off</option><option value="bogo" <?php selected($type,'bogo'); ?>>BOGO</option></select></div>
      <div class="qrn-field"><div class="qrn-label">Value</div><input type="number" step="0.01" name="qrn_value" value="<?php echo esc_attr($value); ?>" placeholder="e.g., 20"></div>
      <div class="qrn-field"><div class="qrn-label">Start Date</div><input type="date" name="qrn_start" value="<?php echo esc_attr($start); ?>"></div>
      <div class="qrn-field"><div class="qrn-label">End Date</div><input type="date" name="qrn_end" value="<?php echo esc_attr($end); ?>"></div>
      <div class="qrn-field full"><div class="qrn-label">Description (optional — add any details or fine print)</div><textarea name="qrn_desc" rows="3"><?php echo esc_textarea($post->post_content); ?></textarea></div>
      <div class="qrn-title-preview-wrap"><div class="qrn-label">Auto Headline Preview</div><div id="qrnTitlePreview" class="qrn-title-preview">— Enter discount type, value, and item to preview —</div></div>
      <p class="full">Use the post <strong>Title</strong> as the headline. If you leave it blank, it will auto-generate from Type/Value/Item.</p>
    </div>
    <script>(function(){var btn=document.getElementById('qrnAutofillBtn');if(!btn)return;btn.addEventListener('click',function(){var owner=document.getElementById('qrn_owner_client');var authorSel=owner||document.querySelector('select[name="post_author_override"],#post_author');if(!authorSel)return;var authorId=authorSel.value;var wrap=document.querySelector('.qrn-grid');var ajax=wrap&&wrap.getAttribute('data-ajax');var nonce=wrap&&wrap.getAttribute('data-nonce');if(!ajax||!nonce)return;var fd=new FormData();fd.append('action','qrn_author_autofill');fd.append('nonce',nonce);fd.append('author_id',authorId);fetch(ajax,{method:'POST',credentials:'same-origin',body:fd}).then(r=>r.json()).then(function(res){if(res&&res.success){var b=document.getElementById('qrn_business');var e=document.getElementById('qrn_email');if(res.data.business&&b&&!b.value)b.value=res.data.business;if(res.data.email&&e&&!e.value)e.value=res.data.email;}else{alert('Could not auto-fill from Owner.');}});});})();(function(){
    function toTitleCase(str){
        return str.replace(/\w\S*/g,function(t){return t.charAt(0).toUpperCase()+t.substr(1).toLowerCase();});
      }
      function cleanNumber(val){
        val = (val || '').trim();
        if(!val) return '';
        if(val.indexOf('.') !== -1){
          val = val.replace(/0+$/,'').replace(/\.$/,'');
        }
        return val;
      }
      function buildPreview(){
        var typeEl  = document.getElementById('qrn_type');
        var valEl   = document.getElementById('qrn_value');
        var itemEl  = document.getElementById('qrn_item');
        var box     = document.getElementById('qrnTitlePreview');
        if(!typeEl || !valEl || !itemEl || !box) return;

        var type  = typeEl.value || '';
        var value = cleanNumber(valEl.value);
        var item  = itemEl.value ? toTitleCase(itemEl.value.trim()) : '';
        var title = '';

        if(type === 'percent' && value){
          title = value + '% Off';
        } else if(type === 'amount' && value){
          title = '$' + value + ' Off';
        } else if(type === 'bogo'){
          title = 'BOGO';
        }

        if(title && item){
          title += ' ' + item;
        } else if(!title && item){
          title = 'Special on ' + item;
        }

        if(!title){
          title = '— Enter discount type, value, and item to preview —';
        }
        box.textContent = title;
      }

      function bindPreview(){
        ['qrn_type','qrn_value','qrn_item'].forEach(function(id){
          var el = document.getElementById(id);
          if(!el) return;
          el.addEventListener('input',buildPreview);
          el.addEventListener('change',buildPreview);
        });
        buildPreview();
      }

      if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded',bindPreview);
      } else {
        bindPreview();
      }
    })();</script>
    <script>(function(){function qrnToTitle(str){return str.replace(/\w\S*/g,function(t){return t.charAt(0).toUpperCase()+t.substr(1).toLowerCase();});}function qrnCleanNumber(val){val=(val||'').trim();if(!val)return'';if(val.indexOf('.')!==-1){val=val.replace(/0+$/,'').replace(/\.$/,'');}return val;}function qrnBuildPreview(){var typeEl=document.querySelector('[name="qrn_type"]');var valEl=document.querySelector('[name="qrn_value"]');var itemEl=document.querySelector('[name="qrn_item"]');var box=document.getElementById('qrnTitlePreview');if(!typeEl||!valEl||!itemEl||!box)return;var type=typeEl.value||'';var value=qrnCleanNumber(valEl.value);var item=itemEl.value?qrnToTitle(itemEl.value.trim()):'';var title='';if(type==='percent'&&value){title=value+'% Off';}else if(type==='amount'&&value){title='$'+value+' Off';}else if(type==='bogo'){title='BOGO';}if(title&&item){title+=' '+item;}else if(!title&&item){title='Special on '+item;}if(!title){title='— Enter discount type, value, and item to preview —';}box.textContent=title;}function qrnBindPreview(){['qrn_type','qrn_value','qrn_item'].forEach(function(name){var el=document.querySelector('[name="'+name+'"]');if(!el)return;el.addEventListener('input',qrnBuildPreview);el.addEventListener('change',qrnBuildPreview);});qrnBuildPreview();}if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',qrnBindPreview);}else{qrnBindPreview();}})();</script>
    <?php
  }
  public function save_deal_metabox($post_id,$post,$update){
    if(wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if(empty($_POST['qrn_meta_nonce']) || !wp_verify_nonce($_POST['qrn_meta_nonce'],'qrn_meta_save')) return;

    $biz=sanitize_text_field($_POST['qrn_business']??'');
    $email=sanitize_email($_POST['qrn_email']??'');
    $item=sanitize_text_field($_POST['qrn_item']??'');
    $start=sanitize_text_field($_POST['qrn_start']??'');
    $end=sanitize_text_field($_POST['qrn_end']??'');
    $type=sanitize_text_field($_POST['qrn_type']??'');
    $value=sanitize_text_field($_POST['qrn_value']??'');
    $desc=wp_kses_post($_POST['qrn_desc']??'');

    $author_id=(int)$post->post_author;
    if(!$biz)   $biz   = get_user_meta($author_id,'qrn_business',true) ?: (get_userdata($author_id)->display_name ?? '');
    if(!$email) $email = get_user_meta($author_id,'qrn_contact_email',true) ?: (get_userdata($author_id)->user_email ?? '');

    update_post_meta($post_id,'_qr_business',$biz);
    update_post_meta($post_id,'_qr_email',$email);
    update_post_meta($post_id,'_qr_item',$item);
    update_post_meta($post_id,'_qr_start',$start);
    update_post_meta($post_id,'_qr_end',$end);
    update_post_meta($post_id,'_qr_type',$type);
    update_post_meta($post_id,'_qr_value',$value);
    update_post_meta($post_id,'_qr_business_slug',$this->slugify($biz));

    remove_action('save_post_'.self::CPT,[$this,'save_deal_metabox'],10);
    wp_update_post(['ID'=>$post_id,'post_content'=>$desc]);
    add_action('save_post_'.self::CPT,[$this,'save_deal_metabox'],10,3);

    if(empty($post->post_title)){ $t=$this->compose_title($type,$value,$item); if($t) wp_update_post(['ID'=>$post_id,'post_title'=>$t]); }
  }
  public function ajax_author_autofill(){
    if(!current_user_can('edit_posts')) wp_send_json_error(['msg'=>'cap']);
    $nonce=isset($_POST['nonce'])? sanitize_text_field($_POST['nonce']) : '';
    if(!wp_verify_nonce($nonce,'qrn_autofill_nonce')) wp_send_json_error(['msg'=>'nonce']);
    $author_id=isset($_POST['author_id'])?(int)$_POST['author_id']:0;
    if(!$author_id) wp_send_json_error(['msg'=>'author']);
    $user=get_userdata($author_id); if(!$user) wp_send_json_error(['msg'=>'user']);
    $business=get_user_meta($author_id,'qrn_business',true) ?: $user->display_name;
    $email   =get_user_meta($author_id,'qrn_contact_email',true) ?: $user->user_email;
    wp_send_json_success(['business'=>$business,'email'=>$email]);
  }

  /* = Owner (Client) = */
  public function replace_author_box(){ remove_meta_box('authordiv',self::CPT,'normal'); }
  public function owner_metabox(){
    add_meta_box('qrn_owner_box','Owner (Client)',function($post){
      if($post->post_type!==self::CPT) return;
      wp_nonce_field('qrn_owner_save','qrn_owner_nonce');
      $current=(int)$post->post_author;
      $users=get_users(['role__in'=>['qr_client','administrator','editor','author'],'orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_email']]);
      echo '<select id="qrn_owner_client" name="qrn_owner_client" style="width:100%;">';
      foreach($users as $u) printf('<option value="%d"%s>%s (%s)</option>',$u->ID,selected($current,$u->ID,false),esc_html($u->display_name),esc_html($u->user_email));
      echo '</select><p class="description">Sets the Deal’s owner (post_author). Client Dashboard shows only deals owned by this user.</p>';
    }, self::CPT,'side','high');
  }
  public function apply_owner_on_save($data,$postarr){
    if(!is_admin() || empty($data['post_type']) || $data['post_type']!==self::CPT) return $data;
    if(!empty($_POST['qrn_owner_nonce']) && wp_verify_nonce($_POST['qrn_owner_nonce'],'qrn_owner_save')){
      if(isset($_POST['qrn_owner_client'])) $data['post_author']=(int)$_POST['qrn_owner_client'];
    }
    return $data;
  }
  public function owner_readonly_box(){
    add_meta_box('qrn_owner_readonly','Current Owner (post_author)',function($post){
      if($post->post_type!==self::CPT) return;
      $u=get_userdata((int)$post->post_author);
      echo '<p><strong>User ID:</strong> '.(int)$post->post_author.'</p><p><strong>Name:</strong> '.esc_html($u?$u->display_name:'—').'</p><p><strong>Email:</strong> '.esc_html($u?$u->user_email:'—').'</p>';
    }, self::CPT,'side','core');
  }

  /* = Optional draft visibility = */
  public function client_visibility_box(){
    add_meta_box('qrn_client_vis','Client Visibility',function($post){
      if($post->post_type!==self::CPT) return;
      wp_nonce_field('qrn_vis_save','qrn_vis_nonce');
      $flag=get_post_meta($post->ID,self::FLAG_SHOW_DRAFT,true)==='1';
      echo '<label><input type="checkbox" name="qrn_show_client" value="1" '.checked($flag,true,false).'> Show this draft in client “Pending Approval”</label>';
    }, self::CPT,'side','default');
  }
  public function save_client_visibility($post_id,$post){
    if(wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if(empty($_POST['qrn_vis_nonce']) || !wp_verify_nonce($_POST['qrn_vis_nonce'],'qrn_vis_save')) return;
    if(isset($_POST['qrn_show_client']) && $_POST['qrn_show_client']==='1') update_post_meta($post_id,self::FLAG_SHOW_DRAFT,'1');
    else delete_post_meta($post_id,self::FLAG_SHOW_DRAFT);
  }

  /* = Helpers = */
  private function redirect_with($k,$v){ $fallback=wp_get_referer()?:home_url(); $target=(!empty($_POST['qrn_redirect'])? esc_url_raw($_POST['qrn_redirect']) : $fallback); wp_safe_redirect(add_query_arg([$k=>rawurlencode($v)],$target)); exit; }
  private function slugify($s){ $s=strtolower(trim($s)); $s=preg_replace('~[^a-z0-9]+~','-',$s); return trim($s,'-'); }
  private function compose_title($type,$value,$item=''){ $item=trim($item); $label=''; $clean_value=trim((string)$value); if($clean_value!=='' && strpos($clean_value,'.')!==false){ $clean_value=rtrim(rtrim($clean_value,'0'),'.'); } switch($type){ case 'percent': if($clean_value!=='') $label=$clean_value.'% Off'; break; case 'amount': if($clean_value!=='') $label='$'.$clean_value.' Off'; break; case 'bogo': $label='BOGO'; break; } $pretty_item=$item!==''?ucwords($item):''; if($label && $pretty_item!=='') $label.=' '.$pretty_item; if($label) $label=apply_filters('qrn_compose_title',$label,$type,$clean_value,$item); if($label) return $label; if($pretty_item) return 'Special on '.$pretty_item; return ''; }



  /* = Auto-expire = */
  public function expire_now(){
    $today=current_time('Y-m-d');
    $q=new WP_Query(['post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>-1,'meta_query'=>[[ 'key'=>'_qr_end','value'=>$today,'compare'=>'<','type'=>'DATE' ]],'fields'=>'ids','suppress_filters'=>true]);
    if($q->have_posts()) foreach($q->posts as $pid){ wp_update_post(['ID'=>$pid,'post_status'=>'draft']); delete_post_meta($pid,self::FLAG_SHOW_DRAFT); }
    wp_reset_postdata();
  }
  public static function schedule_cron(){ if(!wp_next_scheduled('qrn_cron_expire_deals')) wp_schedule_event(time()+60,'daily','qrn_cron_expire_deals'); }
  public static function clear_cron(){ $ts=wp_next_scheduled('qrn_cron_expire_deals'); if($ts) wp_unschedule_event($ts,'qrn_cron_expire_deals'); }
  public function save_business_slug($post_id,$post,$update){ if($post->post_type!==self::CPT) return; $biz=get_post_meta($post_id,'_qr_business',true); if($biz) update_post_meta($post_id,'_qr_business_slug',$this->slugify($biz)); }
}

/* Instantiate Deals so Part 1 runs now */


/* ============================================================================
   HARD BLOCK: Clients cannot edit published deals
============================================================================ */
add_filter('map_meta_cap', function($caps,$cap,$user_id,$args){
  if($cap==='edit_post' && !empty($args[0])){
    $post=get_post((int)$args[0]);
    if($post && $post->post_type==='qr_deal' && $post->post_status==='publish'){
      if(!user_can($user_id,'administrator') && !user_can($user_id,'edit_others_posts')) return ['do_not_allow'];
    }
  }
  return $caps;
},10,4);
/* ============================================================================
   QR LEADS (clean, compact)
============================================================================ */

/* 1) Register CPT */
add_action('init', function(){
  register_post_type('qr_lead', [
    'label'        => 'QR Leads',
    'public'       => false,
    'show_ui'      => true,
    'menu_icon'    => 'dashicons-groups',
    'supports'     => ['title'],
    'capability_type'=>'post',
    'map_meta_cap' => true,
  ]);
});

/* 2) Detect business from referrer/subdomain/path (ignores placeholders) */
if ( ! function_exists( 'qrn_detect_business' ) ) {
  function qrn_detect_business() {

    // Do NOT trust business_name if it's our placeholder
    if ( ! empty( $_POST['business_name'] ) ) {
      $raw  = trim( wp_unslash( $_POST['business_name'] ) );
      $lc   = strtolower( $raw );
      if ( $lc !== '[qrn-business-key]' && $lc !== '[qrn_business_key]' ) {
        return sanitize_title( $raw );
      }
    }

    // Prefer explicit ref param or HTTP_REFERER
    $ref = ! empty( $_POST['qrn_ref'] )
      ? wp_unslash( $_POST['qrn_ref'] )
      : ( ! empty( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );

    if ( $ref ) {
      $h = parse_url( $ref, PHP_URL_HOST );
      if ( $h && preg_match( '/^([a-z0-9-]+)\.qrneighbor\.com$/i', $h, $m ) ) {
        return sanitize_title( $m[1] );
      }

      $p = parse_url( $ref, PHP_URL_PATH );
      if ( $p ) {
        $seg = trim( explode( '/', trim( $p, '/' ) )[0] );
        if ( $seg && ! in_array( $seg, [ 'wp-admin', 'wp-json', 'client-dashboard', 'thank-you' ], true ) ) {
          return sanitize_title( $seg );
        }
      }
    }

    // Fallback: current host
    if ( ! empty( $_SERVER['HTTP_HOST'] ) && preg_match( '/^([a-z0-9-]+)\.qrneighbor\.com$/i', $_SERVER['HTTP_HOST'], $mm ) ) {
      return sanitize_title( $mm[1] );
    }

    // Fallback: current path
    if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
      $seg = trim( explode( '/', trim( $_SERVER['REQUEST_URI'], '/' ) )[0] );
      if ( $seg && ! in_array( $seg, [ 'wp-admin', 'wp-json', 'client-dashboard', 'thank-you' ], true ) ) {
        return sanitize_title( $seg );
      }
    }

    return '';
  }
}


/* 3) Core writer (used by all save hooks) */
if ( ! function_exists( 'qrn_qr_lead_write_core' ) ) {
  function qrn_qr_lead_write_core( $post_id ) {
    if ( get_post_type( $post_id ) !== 'qr_lead' ) {
      return;
    }

    // --- BUSINESS NAME ---
    $biz = get_post_meta( $post_id, 'business_name', true );

    // Treat shortcode-ish placeholders as empty
    $is_placeholder = false;
    if ( is_string( $biz ) ) {
      $trim = trim( strtolower( $biz ) );
      if ( in_array( $trim, [ '[qrn-business-key]', '[qrn_business_key]' ], true ) ) {
        $is_placeholder = true;
      }
    }

    if ( ! $biz || $is_placeholder ) {
      // Prefer router-defined business key
      $biz = qrn_get_business_key();

      // Extra safety: fallback to old detection if needed
      if ( ! $biz && function_exists( 'qrn_detect_business' ) ) {
        $biz = qrn_detect_business();
      }

      if ( $biz ) {
        $biz = sanitize_title( $biz );
        update_post_meta( $post_id, 'business_name', $biz );
        update_post_meta( $post_id, 'business',      $biz );
      }
    }
    // --- CUSTOMER PHONE (normalize to E.164 US) ---
    $raw_phone = get_post_meta( $post_id, 'customer_phone', true );
    if ( is_string( $raw_phone ) && $raw_phone !== '' ) {
      // If the value is already in E.164 format (starts with +), keep it.
      if ( strpos( $raw_phone, '+' ) !== 0 ) {
        // Strip everything except digits like (555) 123-4567 -> 5551234567
        $digits = preg_replace( '/\D+/', '', $raw_phone );

        $normalized = '';

        // 10 digits -> assume US number, prepend +1
        if ( strlen( $digits ) === 10 ) {
          $normalized = '+1' . $digits;
        }
        // 11 digits starting with 1 -> treat as US country code already present
        elseif ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
          $normalized = '+' . $digits;
        }

        if ( $normalized ) {
          update_post_meta( $post_id, 'customer_phone', $normalized );
        }
      }
    }


    // --- CONSENT TIMESTAMP ---
    if ( ! get_post_meta( $post_id, 'consent_timestamp', true ) ) {
      update_post_meta( $post_id, 'consent_timestamp', current_time( 'mysql' ) );
    }

    // --- IP ---
    if ( ! get_post_meta( $post_id, 'opt_in_ip', true ) ) {
      foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' ] as $k ) {
        if ( ! empty( $_SERVER[ $k ] ) ) {
          $raw = trim( $_SERVER[ $k ] );
          $ip  = strpos( $raw, ',' ) !== false ? trim( explode( ',', $raw )[0] ) : $raw;
          if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            update_post_meta( $post_id, 'opt_in_ip', $ip );
            break;
          }
        }
      }
    }

    // --- SOURCE URL ---
    if ( ! get_post_meta( $post_id, 'source_url', true ) ) {
      $host = $_SERVER['HTTP_HOST'] ?? '';
      $uri  = $_SERVER['REQUEST_URI'] ?? '';
      if ( $host ) {
        $scheme = is_ssl() ? 'https://' : 'http://';
        update_post_meta( $post_id, 'source_url', esc_url_raw( $scheme . $host . $uri ) );
      }
    }

    // --- REFERRER URL ---
    if ( ! get_post_meta( $post_id, 'referer_url', true ) ) {
      $ref = ! empty( $_POST['qrn_ref'] )
        ? wp_unslash( $_POST['qrn_ref'] )
        : ( ! empty( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );

      if ( $ref ) {
        update_post_meta( $post_id, 'referer_url', esc_url_raw( $ref ) );
      }
    }
  }
}

/* 4) Hooks (JetFormBuilder + normal) */
add_action('jet-form-builder/after-insert-post', function($post_id){ qrn_qr_lead_write_core($post_id); }, 10,1);
add_action('jet-form-builder/form-handler/after-save', function($record,$handler){ if(method_exists($record,'get_main_id')){ $pid=(int)$record->get_main_id(); if($pid) qrn_qr_lead_write_core($pid);} }, 10,2);
add_action('save_post_qr_lead', function($post_id){ if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return; if(wp_is_post_revision($post_id)) return; qrn_qr_lead_write_core($post_id); }, 20);

/* 5) Compact metabox */
add_action('add_meta_boxes', function(){
  add_meta_box('qrn_lead_meta','Lead Details', function($post){
    $map=['customer_first_name'=>'First Name','customer_last_name'=>'Last Name','customer_phone'=>'Phone','business_name'=>'Business','referer_url'=>'Referer URL','source_url'=>'Source URL','consent_timestamp'=>'Consent Time','opt_in_ip'=>'Opt-in IP'];
    echo '<table class="form-table">';
    foreach($map as $key=>$label){ $val=esc_attr(get_post_meta($post->ID,$key,true)); $wide=strpos($key,'url')!==false? " style='width:100%'" : ""; echo "<tr><th><label>{$label}</label></th><td><input type='text'{$wide} name='{$key}' value='{$val}'/></td></tr>"; }
    echo '</table>';
  }, 'qr_lead','normal','default');
});
add_action('save_post_qr_lead', function($post_id){
  if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return; if(wp_is_post_revision($post_id)) return;
  foreach(['customer_first_name','customer_last_name','customer_phone','business_name','referer_url','source_url','consent_timestamp','opt_in_ip'] as $k){
    if(isset($_POST[$k])) update_post_meta($post_id,$k, sanitize_text_field($_POST[$k]));
  }
}, 30);

/* 6) Admin list columns, sorting, filtering */
add_filter('manage_qr_lead_posts_columns', function($cols){ $cols['customer_phone']='Phone'; $cols['business_name_col']='Business'; $cols['consent_timestamp']='Consent'; return $cols; });
add_action('manage_qr_lead_posts_custom_column', function($col,$post_id){
  if($col==='customer_phone') echo esc_html(get_post_meta($post_id,'customer_phone',true));
  elseif($col==='business_name_col'){ $biz=get_post_meta($post_id,'business_name',true); if($biz){ $url=add_query_arg(['post_type'=>'qr_lead','qrn_business'=>$biz], admin_url('edit.php')); echo '<a href="'.esc_url($url).'">'.esc_html($biz).'</a>'; } }
  elseif($col==='consent_timestamp') echo esc_html(get_post_meta($post_id,'consent_timestamp',true));
}, 10,2);
add_filter('manage_edit-qr_lead_sortable_columns', function($cols){ $cols['business_name_col']='business_name'; $cols['consent_timestamp']='consent_timestamp'; return $cols; });
add_action('pre_get_posts', function($q){
  if(!is_admin() || !$q->is_main_query()) return; if($q->get('post_type')!=='qr_lead') return;
  if(!empty($_GET['qrn_business'])){ $biz=sanitize_title($_GET['qrn_business']); $q->set('meta_query', [[ 'key'=>'business_name','value'=>$biz,'compare'=>'=' ]]); }
  $orderby=$q->get('orderby');
  if($orderby==='business_name'){ $q->set('meta_key','business_name'); $q->set('orderby','meta_value'); }
  elseif($orderby==='consent_timestamp'){ $q->set('meta_key','consent_timestamp'); $q->set('orderby','meta_value'); }
});
add_action('restrict_manage_posts', function(){
  global $typenow,$wpdb; if($typenow!=='qr_lead') return;
  $rows=$wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key='business_name' AND meta_value<>'' ORDER BY meta_value ASC LIMIT 200");
  $current=isset($_GET['qrn_business'])? sanitize_text_field($_GET['qrn_business']) : '';
  echo '<select name="qrn_business"><option value="">All Businesses</option>';
  foreach($rows as $r) printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($r), selected($current,$r,false));
  echo '</select>';
});

/* 7) Footer JS: fill hidden JetFormBuilder fields (business_name + qrn_ref) */
add_action( 'wp_footer', function() { ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var host = location.hostname;
  var path = location.pathname.replace(/\/+/g,'/');
  var seg  = (path.split('/')[1] || '').trim();

  var m   = host.match(/^([a-z0-9-]+)\.qrneighbor\.com$/i);
  var biz = m ? m[1] : '';

  if (!biz && seg && !['wp-admin','wp-json','client-dashboard','thank-you'].includes(seg)) {
    biz = seg;
  }

  var forms = document.querySelectorAll('form.jet-form-builder, form.jet-form-builder__form');
  forms.forEach(function(f){
    var el = f.querySelector('input[name="business_name"]');
    if (el) {
      var val = (el.value || '').trim().toLowerCase();
      // If empty OR it's one of our shortcode placeholders, override
      if (!el.value || val === '[qrn-business-key]' || val === '[qrn_business_key]') {
        el.value = biz;
      }
    }

    var ref = f.querySelector('input[name="qrn_ref"]');
    if (!ref) {
      ref = document.createElement('input');
      ref.type = 'hidden';
      ref.name = 'qrn_ref';
      f.appendChild(ref);
    }
    if (!ref.value) {
      ref.value = location.href;
    }
  });
});
</script>
<?php } );

// ==========================================================
// Initialize main plugin class (required to run QR Neighbor)
// ==========================================================
// =======================

new QRN_Deals_Pro();

