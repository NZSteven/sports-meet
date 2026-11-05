<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function update_manifest():array {
    $cached=get_transient('sports_meet_update_manifest');
    if(is_array($cached))return $cached;
    $response=wp_remote_get('https://raw.githubusercontent.com/NZSteven/sports-meet/main/update.json',['timeout'=>10,'limit_response_size'=>16384]);
    if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return [];
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if(!is_array($data)||!is_string($data['version']??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$data['version'])||!is_string($data['commit']??null)||!preg_match('/^[a-f0-9]{40}$/D',$data['commit']))return [];
    set_transient('sports_meet_update_manifest',$data,15*MINUTE_IN_SECONDS);
    return $data;
}
add_filter('update_plugins_github.com',function($update,$pluginData,$pluginFile){
    if($pluginFile!==plugin_basename(dirname(__DIR__).'/sports-meet.php'))return $update;
    $data=update_manifest();if(!$data)return false;
    return ['id'=>'https://github.com/NZSteven/sports-meet','slug'=>'sports-meet','version'=>$data['version'],'url'=>'https://github.com/NZSteven/sports-meet','package'=>'https://github.com/NZSteven/sports-meet/archive/'.$data['commit'].'.zip','requires'=>'6.4','requires_php'=>'8.1'];
},10,3);

// GitHub archives have a commit suffix; retain the installed WordPress folder.
add_filter('upgrader_source_selection',function($source,$remoteSource,$upgrader,$extra){
    if(is_wp_error($source)||($extra['plugin']??'')!==plugin_basename(dirname(__DIR__).'/sports-meet.php'))return $source;
    global $wp_filesystem;
    if(!$wp_filesystem->exists(trailingslashit($source).'sports-meet.php'))return new \WP_Error('sports_meet_invalid_package','更新包缺少运动会插件主文件。');
    $target=trailingslashit($remoteSource).dirname(plugin_basename(dirname(__DIR__).'/sports-meet.php'));
    if(untrailingslashit($source)===$target)return $source;
    if($wp_filesystem->exists($target)||!$wp_filesystem->move($source,$target))return new \WP_Error('sports_meet_update_folder','无法准备运动会插件更新目录。');
    return trailingslashit($target);
},20,4);

// Opt in once; subsequent administrator choices remain authoritative.
add_action('admin_init',function(){
    if(!current_user_can('manage_options')||get_option('sports_meet_auto_update_initialized'))return;
    $plugins=(array)get_site_option('auto_update_plugins',[]);
    $plugins[]=plugin_basename(dirname(__DIR__).'/sports-meet.php');
    update_site_option('auto_update_plugins',array_values(array_unique($plugins)));
    update_option('sports_meet_auto_update_initialized',1,false);
    delete_site_transient('update_plugins');
});
