<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

const SITE_NAME='新西兰中国高校校友 奔Fun运动会';
const SITE_SLOGAN='为Fun而奔，奔而得分';

function race_schedule_columns(string $content):string {
    if(str_contains($content,'race-schedule-columns'))return $content;
    preg_match_all('/<h([1-6])\b[^>]*>.*?<\/h\1>/us',$content,$headings,PREG_OFFSET_CAPTURE);
    $position=null;
    foreach($headings[0] as [$heading,$offset]){
        if(str_contains(wp_strip_all_tags($heading),'赛制')){$position=$offset;break;}
    }
    if($position===null)return $content;
    $schedule=trim(substr($content,0,$position));
    if($schedule==='')return $content;
    $rules=substr($content,$position);
    return '<section class="race-schedule-columns"><div class="race-schedule-column"><h2>赛程</h2>'.$schedule
        .'</div><div class="race-rules-column">'.$rules.'</div></section>';
}

add_filter('the_content',function(string $content):string {
    if(is_admin()||!is_page()||!is_main_query()||!in_the_loop())return $content;
    $title=trim(get_the_title(get_queried_object_id()));
    return in_array($title,['赛程与赛制','赛程赛制'],true)?race_schedule_columns($content):$content;
},40);

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
    $classes[]='sm-brand-home';
    return $classes;
});

add_filter('the_content',function(string $content):string {
    // The page template already prints the primary title above the content.
    if(is_admin()||!is_page()||is_front_page()||!is_main_query()||!in_the_loop())return $content;
    $normalize=static fn(string $value):string=>trim(html_entity_decode(wp_strip_all_tags($value),ENT_QUOTES,'UTF-8'));
    $title=$normalize(get_the_title(get_queried_object_id()));
    if($title==='')return $content;
    $aliases=[
        '报名名单'=>['高校及报名名单'],
    ];
    $duplicates=array_merge([$title],$aliases[$title]??[]);
    return preg_replace_callback('/<h([12])\b[^>]*>(.*?)<\/h\1\s*>/is',
        static fn(array $match):string=>in_array($normalize($match[2]),$duplicates,true)?'':$match[0],$content)??$content;
},30);
add_action('wp_enqueue_scripts',function(){
    $main=dirname(__DIR__).'/sports-meet.php';
    wp_enqueue_style('sports-meet-header',plugins_url('header-identity.css',$main),[],'2.5.89');
    wp_enqueue_script('sports-meet-header',plugins_url('header-identity.js',$main),[],'2.5.89',true);
    wp_enqueue_style('sports-meet-home-corners',plugins_url('home-corners.css',$main),['sports-meet-photos'],'2.5.89');
    wp_enqueue_script('sports-meet-slides',plugins_url('photo-slides.js',$main),[],'2.5.89',true);
    wp_enqueue_script('sports-meet-rankings',plugins_url('live-ranking.js',$main),[],'2.5.89',true);
});
