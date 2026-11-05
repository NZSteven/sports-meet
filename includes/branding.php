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
add_action('wp_enqueue_scripts',function(){
    $main=dirname(__DIR__).'/sports-meet.php';
    wp_enqueue_style('sports-meet-header',plugins_url('header-identity.css',$main),[],'2.4.12');
    wp_enqueue_script('sports-meet-header',plugins_url('header-identity.js',$main),[],'2.4.12',true);
});
