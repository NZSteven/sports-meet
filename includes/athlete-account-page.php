<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function athlete_account_url():string {
    $id=(int)(get_option('sports_meet_pages',[])['athlete_account']??0);
    return $id&&get_post_status($id)==='publish'?get_permalink($id):home_url('/sports-athlete-login/');
}
function athlete_account_page():string {
    $html='<section class="sports-meet sm-athlete-account"><h2>运动员登录／注册</h2>';
    if(is_user_logged_in()){
        $user=wp_get_current_user();$athlete=q('SELECT a.*,t.name AS team_name FROM athlete_accounts aa JOIN athletes a ON a.id=aa.athlete_id JOIN teams t ON t.id=a.team_id WHERE aa.user_id=?',[$user->ID])->fetch();
        $html.='<p>你好，'.esc_html($user->display_name).'，你已登录。</p>';
        if($athlete){$html.='<p>报名身份：'.esc_html($athlete['team_name'].' · '.$athlete['name']).'</p>';if(disclaimer_dinner_only($athlete))$html.='<p>仅报名晚宴，无需签署参赛免责声明。</p>';else $html.='<p>免责声明：'.disclaimer_dot($athlete,disclaimer_acceptances((int)$athlete['id'])).'</p>';}
        return $html.community_buttons([['上传运动会照片',page_url('upload')],['说出你的感言',page_url('messages').'#sm-message-form'],['查看／签署免责声明',page_url('disclaimer')],['退出登录',wp_logout_url(athlete_account_url())]]).'</section>';
    }
    $html.='<p>登录后，可以在本网站上传运动会照片及感言。注册账号与参赛免责声明签署分别办理。</p><div class="sm-athlete-auth-grid"><section class="sm-athlete-card"><h3>已有账号？登录</h3>'
        .wp_login_form(['echo'=>false,'redirect'=>athlete_account_url(),'label_username'=>'用户名或邮箱','label_password'=>'密码','label_remember'=>'记住登录','label_log_in'=>'登录'])
        .'<p><a href="'.esc_url(wp_lostpassword_url(athlete_account_url())).'">忘记密码？</a></p></section><section class="sm-athlete-card"><h3>首次使用？注册</h3><p>选择所属高校及本人报名姓名，填写邮箱并设置密码。</p><div class="sm-waiver-account-options">'.athlete_signup_form(true).'</div></section></div></section>';
    return $html;
}
add_shortcode('sports_meet_athlete_account',__NAMESPACE__.'\\athlete_account_page');
add_action('admin_init',function(){
    if(!current_user_can('manage_options'))return;$pages=get_option('sports_meet_pages',[]);$id=(int)($pages['athlete_account']??0);
    if($id&&get_post_status($id)==='publish')return;
    $existing=get_page_by_path('sports-athlete-login');
    if($existing){if(!has_shortcode($existing->post_content,'sports_meet_athlete_account'))return;$id=$existing->ID;}
    else{$id=wp_insert_post(['post_title'=>'运动员登录／注册','post_name'=>'sports-athlete-login','post_type'=>'page','post_status'=>'publish','post_content'=>'[sports_meet_athlete_account]','comment_status'=>'closed'],true);if(is_wp_error($id)||!$id)return;}
    $pages['athlete_account']=$id;update_option('sports_meet_pages',$pages,false);
});
add_filter('wp_nav_menu_items',function(string $items,$args):string {
    if(($args->theme_location??'')!=='primary')return $items;
    $url=esc_url(athlete_account_url());
    if(str_contains($items,'href="'.$url.'"')||str_contains($items,"href='".$url."'"))return $items;
    return $items.'<li class="menu-item sm-athlete-menu"><a href="'.$url.'">'.(is_user_logged_in()?'运动员中心':'运动员登录／注册').'</a></li>';
},30,2);
add_action('template_redirect',function(){if(is_singular()&&has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'sports_meet_athlete_account')){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}});
