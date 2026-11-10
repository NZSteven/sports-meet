<?php
/**
 * Plugin Name: 运动会报名与赛事管理 Sports Meet
 * Description: CSV报名、并发检录、裁判确认、预赛晋级、决赛名次与参赛积分、混合接力及会员照片相册。
 * Version: 2.5.87
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Sports Meet
 * License: GPL-2.0-or-later
 * Text Domain: sports-meet
 * Update URI: https://github.com/NZSteven/sports-meet
 */
namespace SportsMeetWP;
defined('ABSPATH') || exit;
require_once __DIR__.'/includes/updates.php';
require_once __DIR__.'/includes/database.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/branding.php';
require_once __DIR__.'/includes/song.php';
require_once __DIR__.'/includes/core.php';
require_once __DIR__.'/includes/actions.php';
require_once __DIR__.'/includes/export.php';
require_once __DIR__.'/includes/view.php';
require_once __DIR__.'/includes/photo-files.php';
require_once __DIR__.'/includes/photo-media.php';
require_once __DIR__.'/includes/photos.php';
require_once __DIR__.'/includes/disclaimer.php';
require_once __DIR__.'/includes/waiver-name-correction.php';
require_once __DIR__.'/includes/member-signup.php';
require_once __DIR__.'/includes/athlete-account-page.php';
require_once __DIR__.'/includes/community.php';
require_once __DIR__.'/includes/race-core.php';
require_once __DIR__.'/includes/bib-ranges.php';
require_once __DIR__.'/includes/bib-ranges-view.php';
require_once __DIR__.'/includes/registration.php';
require_once __DIR__.'/includes/roster-filter.php';
require_once __DIR__.'/includes/day-pages.php';
require_once __DIR__.'/includes/chief-page.php';
require_once __DIR__.'/includes/event-pdf.php';
require_once __DIR__.'/includes/final-projects.php';
require_once __DIR__.'/includes/distance-results.php';
require_once __DIR__.'/includes/race-actions.php';
require_once __DIR__.'/includes/race-view.php';
require_once __DIR__.'/includes/awards.php';
require_once __DIR__.'/includes/team-portal.php';
require_once __DIR__.'/includes/lane-arrangements.php';
require_once __DIR__.'/includes/relay-portal.php';
require_once __DIR__.'/includes/relay-auto.php';
require_once __DIR__.'/includes/relay-manual.php';

function activate(bool $network_wide=false): void {
    if($network_wide) wp_die('本插件请在各子站单独启用，不支持网络统一启用。');
    if(!extension_loaded('mbstring')) wp_die('请先在主机启用 PHP mbstring 扩展。');
    global $wpdb;
    if(!preg_match('/^[a-zA-Z0-9_]+$/',$wpdb->prefix) || strlen($wpdb->prefix)>35) wp_die('数据库表前缀不符合本插件要求。');
    try {
        foreach(explode(';',file_get_contents(__DIR__.'/includes/schema.sql')) as $sql) if(trim($sql)!=='') q($sql);
        q('INSERT IGNORE INTO settings(id,title,points,relay_multiplier,relay_males) VALUES(1,?,?,2,4)',['运动会成绩统计','9,7,6,5,4,3,2,1']);
        if(!q('SELECT id FROM events LIMIT 1')->fetch()) q("INSERT INTO events(name,kind,gender,age_group,direction,unit) VALUES(?,'relay','X','','asc','秒')",['8×50米混合接力']);
        install_photos();
        install_races();
        install_distances();
        install_registration_extras();
        race_ensure_day_pages();
        install_disclaimer();
        install_community();
        update_option('sports_meet_db_version','2.5.87',false);
    } catch(\Throwable $e) { wp_die(esc_html('建表失败。请检查数据库CREATE、ALTER权限和InnoDB支持，排除问题后重新启用。'.$e->getMessage())); }
}
register_activation_hook(__FILE__,__NAMESPACE__.'\\activate');
function current_tab(): string { $v=sanitize_key($_REQUEST['tab']??'board');return in_array($v,['board','results','races','photos','messages','disclaimer','legacy'],true)?$v:'board'; }
function view_url(string $tab): string { return add_query_arg(['page'=>'sports-meet','tab'=>$tab],admin_url('admin.php')); }
function export_url(string $type): string { return add_query_arg(['action'=>'sports_meet_export','type'=>$type,'_wpnonce'=>wp_create_nonce('sports_meet_export')],admin_url('admin-post.php')); }
function save_handler(): void {
    if(!current_user_can('manage_options')) wp_die('没有管理权限。','',['response'=>403]);
    $transaction=false;
    try {
        csrf();$p=wp_unslash($_POST);race_guard_legacy($p);foreach($p as $value) if(!is_scalar($value)) throw new \RuntimeException('提交资料格式错误');
        q('START TRANSACTION');$transaction=true;q('SELECT id FROM settings WHERE id=1 FOR UPDATE')->fetch();
        mutate($p);q('COMMIT');$transaction=false;$msg=['success','操作完成。'];
    } catch(\Throwable $e) {
        if($transaction) q('ROLLBACK');error_log('Sports Meet: '.$e->getMessage());
        $msg=['error',$e instanceof DatabaseException?'无法保存：资料重复、超出长度或仍被其他资料引用。':$e->getMessage()];
    }
    set_transient('sports_meet_notice_'.get_current_user_id(),$msg,120);
    wp_safe_redirect(view_url(current_tab()));exit;
}
add_action('admin_post_sports_meet_save',__NAMESPACE__.'\\save_handler');
add_action('admin_post_sports_meet_export',__NAMESPACE__.'\\export_data');
add_action('admin_menu',function(){add_menu_page('运动会成绩统计','运动会计分','sports_meet_plugin_access','sports-meet',__NAMESPACE__.'\\admin_page','dashicons-awards',30);});
function admin_page(): void {
    if(!current_user_can('sports_meet_plugin_access')||!race_can_manage()) wp_die('没有管理权限。','',['response'=>403]);
    if(!current_user_can('manage_options')&&!in_array(current_tab(),['board','results','races'],true))wp_die('此栏目仅供网站管理员使用。','',['response'=>403]);
    echo '<div class="wrap sports-meet"><h1>运动会成绩统计</h1><nav>';
    foreach((current_user_can('manage_options')?['board'=>'排行榜','results'=>'成绩榜','races'=>'报名及比赛工作台','photos'=>'照片管理','messages'=>'留言管理','disclaimer'=>'免责声明','legacy'=>'旧版成绩（只读）']:['board'=>'排行榜','results'=>'成绩榜','races'=>'报名及比赛工作台']) as $tab=>$label) echo '<a href="'.esc_url(view_url($tab)).'">'.esc_html($label).'</a>';
    echo '</nav><p>2.0流程：报名 → 检录发号 → 生成分组 → 裁判确认与名次 → 提交完成 → 晋级及积分。旧版计时成绩保留，只读查阅，不自动当作新版赛果。</p>';
    $notice=get_transient('sports_meet_notice_'.get_current_user_id());if($notice){delete_transient('sports_meet_notice_'.get_current_user_id());echo '<p class="'.esc_attr($notice[0]==='error'?'error':'notice').'">'.esc_html($notice[1]).'</p>';}
    try {switch(current_tab()){case 'photos':photo_admin();break;case 'messages':guestbook_admin();break;case 'disclaimer':disclaimer_admin();break;case 'races':echo race_workspace();break;case 'board':echo '<p><a href="'.esc_url(race_export_url('scores')).'">导出队伍积分CSV</a></p>'.race_admin_board();break;case 'results':echo race_public_results(false);break;case 'legacy':echo '<p>旧版资料仅供查阅，未录入预赛、决赛及确认参赛资料，故不计入2.0积分。</p>'.render('results');break;}}catch(\Throwable $e){error_log('Sports Meet: '.$e->getMessage());echo '<p>暂时无法读取运动会资料，请检查插件是否成功启用。</p>';}
    echo '</div>';
}
function shortcode_view(string $view): string {
    try{return race_public_navigation().race_public_board().race_public_results();}catch(\Throwable $e){error_log('Sports Meet: '.$e->getMessage());return '<p>运动会成绩暂时无法读取，请联系管理员。</p>';}
}
add_shortcode('sports_meet_board',fn()=>shortcode_view('board'));
add_shortcode('sports_meet_results',fn()=>shortcode_view('results'));
function enqueue_style(): void {wp_enqueue_style('sports-meet',plugins_url('style.css',__FILE__),[],'2.5.87');wp_enqueue_style('sports-meet-photos',plugins_url('photos.css',__FILE__),[],'2.5.87');wp_enqueue_style('sports-meet-races',plugins_url('races.css',__FILE__),[],'2.5.87');wp_enqueue_script('sports-meet-distance',plugins_url('distance-results.js',__FILE__),[],'2.5.87',true);wp_enqueue_script('sports-meet-registration-filter',plugins_url('registration-filter.js',__FILE__),[],'2.5.87',true);}
add_action('wp_enqueue_scripts',__NAMESPACE__.'\\enqueue_style');
function enqueue_photo_tools(): void {
    wp_enqueue_script('sports-meet-race-display',plugins_url('race-display.js',__FILE__),[],'2.5.87',true);
    wp_enqueue_script('sports-meet-photo-tools', plugins_url('photo-tools.js', __FILE__), [], '2.5.87', true);
}
add_action('wp_enqueue_scripts',__NAMESPACE__.'\\enqueue_photo_tools');
add_action('admin_enqueue_scripts',function($hook){if($hook==='toplevel_page_sports-meet')enqueue_photo_tools();});
add_action('admin_enqueue_scripts',function($hook){if($hook==='toplevel_page_sports-meet')enqueue_style();});
