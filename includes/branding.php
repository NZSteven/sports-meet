<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

const SITE_NAME='新西兰中国高校校友 奔Fun运动会';
const SITE_SLOGAN='为Fun而奔，奔而得分';

// Apply this approved identity once, including after unattended plugin updates.
function apply_site_identity():void {
    if(get_option('sports_meet_identity_version')==='1')return;
    try {
        q('UPDATE settings SET title=? WHERE id=1',[SITE_NAME]);
        update_option('blogname',SITE_NAME);
        update_option('blogdescription',SITE_SLOGAN);
        update_option('sports_meet_identity_version','1',false);
    } catch(\Throwable $e) {error_log('Sports Meet identity: '.$e->getMessage());}
}
add_action('init',__NAMESPACE__.'\\apply_site_identity',30);
add_filter('body_class',function(array $classes):array {
    if(is_front_page())$classes[]='sm-brand-home';
    return $classes;
});
add_action('wp_enqueue_scripts',function(){
    $main=dirname(__DIR__).'/sports-meet.php';
    wp_enqueue_style('sports-meet-header',plugins_url('header-identity.css',$main),[],'2.4.18');
    wp_enqueue_script('sports-meet-header',plugins_url('header-identity.js',$main),[],'2.4.18',true);
    if(is_front_page())wp_enqueue_style('sports-meet-home-corners',plugins_url('home-corners.css',$main),['sports-meet-photos'],'2.4.18');
});
