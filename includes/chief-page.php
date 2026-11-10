<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function chief_page_url(array $args=[]):string {
    $id=(int)(get_option('sports_meet_pages',[])['officials']??0);
    $url=$id&&get_post_status($id)==='publish'?get_permalink($id):home_url('/sports-officials/');
    return $args?add_query_arg($args,$url):$url;
}
function chief_page():string {
    if(!is_user_logged_in())return '<section class="sports-meet race-day-login"><h2>裁判专区</h2><p>请使用运动会裁判、裁判长或管理员账号登录。</p>'.wp_login_form(['echo'=>false,'redirect'=>chief_page_url(),'label_log_in'=>'登录裁判专区']).'</section>';
    if(!race_can_judge())return '<section class="sports-meet"><p>此页面仅供运动会裁判、裁判长及管理员使用。请联系管理员设置账号角色。</p></section>';
    $tabs=['checkin'=>'检录发号','bib_ranges'=>'各校号码区间表','rounds'=>'比赛录入／更正','relay'=>'接力组队','registration'=>'报名名单','pdf'=>'项目表格PDF','events'=>'项目与积分设置','scores'=>'积分榜','results'=>'成绩榜'];
    if(!race_can_manage())$tabs=array_intersect_key($tabs,array_flip(['checkin','bib_ranges','rounds','scores','results']));
    $tab=sanitize_key($_GET['race_tab']??'checkin');if($tab==='rules')$tab='events';if(!isset($tabs[$tab]))$tab='checkin';
    $previous=race_day_context();$GLOBALS['sports_meet_day_context']='chief';ob_start();
    try{
        echo '<section class="sports-meet sm-chief"><header><h2>裁判专区</h2><p>当前登录：'.esc_html(wp_get_current_user()->display_name).'（'.(race_can_manage()?'裁判长／管理员':'裁判').'） · <a href="'.esc_url(wp_logout_url(chief_page_url())).'">退出登录</a></p></header><nav class="sm-chief-nav" aria-label="裁判专区功能">';
        foreach($tabs as $key=>$label)echo '<a '.($key===$tab?'aria-current="page" ':'').'href="'.esc_url(chief_page_url(['race_tab'=>$key])).'">'.esc_html($label).'</a>';
        echo '</nav>';race_notice();
        switch($tab){
            case 'checkin':race_ui_checkin();break;
            case 'bib_ranges':bib_range_readonly();break;
            case 'rounds':race_ui_rounds();break;
            case 'relay':race_ui_relay();break;
            case 'registration':race_ui_registration();break;
            case 'pdf':chief_pdf_ui();break;
            case 'events':race_ui_events();race_ui_rules();break;
            case 'scores':echo race_can_manage()?'<p><a href="'.esc_url(race_export_url('scores')).'">导出积分CSV</a></p>'.race_admin_board():race_public_board();break;
            case 'results':echo race_public_results(false);break;

        }
        echo '</section>';return ob_get_clean();
    }catch(\Throwable $e){ob_end_clean();error_log('Sports Meet chief: '.$e->getMessage());return '<p>工作台暂时无法显示，请刷新或联系管理员。</p>';}
    finally{$GLOBALS['sports_meet_day_context']=$previous;}
}
add_shortcode('sports_meet_head_referee',__NAMESPACE__.'\\chief_page');
add_action('template_redirect',function(){if(is_singular()&&has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'sports_meet_head_referee')){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}});

add_action('template_redirect',function(){
    if(!is_singular())return;$post=get_queried_object();if(!$post instanceof \WP_Post)return;
    $map=['sports_meet_head_referee'=>'checkin','sports_meet_judge'=>'checkin','sports_meet_checkin'=>'checkin','sports_meet_prelim'=>'rounds','sports_meet_finals'=>'rounds'];
    foreach($map as $tag=>$default){if(!has_shortcode($post->post_content,$tag))continue;
        $args=[];foreach(['race_tab','event_id','round_id','relay_heat','manual_relay_event','roster_q','roster_school','roster_category','roster_event','find','checkin_school','checkin_event','checkin_page','edit_athlete'] as $key)if(isset($_GET[$key])&&is_scalar($_GET[$key]))$args[$key]=sanitize_text_field(wp_unslash($_GET[$key]));
        $args['race_tab']=!empty($_GET['number_ranges'])?'bib_ranges':($args['race_tab']??$default);
        wp_safe_redirect(chief_page_url($args),302);exit;
    }
},8);
