<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function chief_page_url(array $args=[]):string {
    $id=(int)(get_option('sports_meet_pages',[])['chief']??0);
    $url=$id&&get_post_status($id)==='publish'?get_permalink($id):home_url('/sports-chief-referee/');
    return $args?add_query_arg($args,$url):$url;
}
function chief_page():string {
    if(!is_user_logged_in())return '<section class="sports-meet race-day-login"><h2>裁判长工作台</h2><p>请使用管理员或运动会裁判长账号登录。</p>'.wp_login_form(['echo'=>false,'redirect'=>chief_page_url(),'label_log_in'=>'登录裁判长工作台']).'</section>';
    if(!race_can_manage())return '<section class="sports-meet"><p>此页面仅供运动会裁判长及管理员使用。请联系管理员设置账号角色。</p></section>';
    $tabs=['checkin'=>'检录发号','rounds'=>'比赛录入／更正','relay'=>'接力组队','registration'=>'报名名单','pdf'=>'项目表格PDF','events'=>'比赛项目','scores'=>'积分榜','results'=>'成绩榜','rules'=>'积分设置','audit'=>'操作记录'];
    $tab=sanitize_key($_GET['race_tab']??'checkin');if(!isset($tabs[$tab]))$tab='checkin';
    $previous=race_day_context();$GLOBALS['sports_meet_day_context']='chief';ob_start();
    try{
        echo '<section class="sports-meet sm-chief"><header><h2>裁判长工作台</h2><p>当前登录：'.esc_html(wp_get_current_user()->display_name).' · <a href="'.esc_url(wp_logout_url(chief_page_url())).'">退出登录</a></p></header><nav class="sm-chief-nav" aria-label="裁判长功能">';
        foreach($tabs as $key=>$label)echo '<a '.($key===$tab?'aria-current="page" ':'').'href="'.esc_url(chief_page_url(['race_tab'=>$key])).'">'.esc_html($label).'</a>';
        echo '</nav>';race_notice();
        switch($tab){
            case 'checkin':race_ui_checkin();break;
            case 'rounds':race_ui_rounds();break;
            case 'relay':race_ui_relay();break;
            case 'registration':race_ui_registration();break;
            case 'pdf':chief_pdf_ui();break;
            case 'events':race_ui_events();break;
            case 'scores':echo '<p><a href="'.esc_url(race_export_url('scores')).'">导出积分CSV</a></p>'.race_admin_board();break;
            case 'results':echo race_public_results(false);break;
            case 'rules':race_ui_rules();break;
            case 'audit':$rows=[];foreach(q('SELECT * FROM race_audit ORDER BY id DESC LIMIT 100')->fetchAll() as $r){$actor=get_userdata((int)$r['actor']);$rows[]=[$r['created'],$actor?$actor->display_name:'系统／游客',$r['action'],$r['record_id'],$r['payload']];}table(['时间','操作人','动作','记录ID','变更记录'],$rows);break;
        }
        echo '</section>';return ob_get_clean();
    }catch(\Throwable $e){ob_end_clean();error_log('Sports Meet chief: '.$e->getMessage());return '<p>工作台暂时无法显示，请刷新或联系管理员。</p>';}
    finally{$GLOBALS['sports_meet_day_context']=$previous;}
}
add_shortcode('sports_meet_head_referee',__NAMESPACE__.'\\chief_page');
add_action('admin_init',function(){
    if(!current_user_can('manage_options'))return;$pages=get_option('sports_meet_pages',[]);$id=(int)($pages['chief']??0);
    if($id&&get_post_status($id)==='publish')return;
    $existing=get_page_by_path('sports-chief-referee');
    if($existing){if(!has_shortcode($existing->post_content,'sports_meet_head_referee'))return;$id=$existing->ID;}
    else{$id=wp_insert_post(['post_title'=>'裁判长工作台','post_name'=>'sports-chief-referee','post_type'=>'page','post_status'=>'publish','post_content'=>'[sports_meet_head_referee]','comment_status'=>'closed'],true);if(is_wp_error($id))return;}
    $pages['chief']=$id;update_option('sports_meet_pages',$pages,false);
});
add_action('template_redirect',function(){if(is_singular()&&has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'sports_meet_head_referee')){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}});
