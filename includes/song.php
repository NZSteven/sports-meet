<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function song_lyrics_markup(): string {
    $lines=file(dirname(__DIR__).'/media/song.lrc', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    $html='';
    foreach($lines ?: [] as $line){
        if(!preg_match('/^\[(\d+):(\d{2}(?:\.\d+)?)\](.+)$/u',$line,$match))continue;
        $time=60*(int)$match[1]+(float)$match[2];
        $html.='<div class="sm-lyric-line" data-time="'.esc_attr((string)$time).'">'.esc_html($match[3]).'</div>';
    }
    return $html ?: nl2br(esc_html(file_get_contents(dirname(__DIR__).'/media/lyrics.txt')));
}

function song_section():string {
    $base=plugins_url('media/',dirname(__DIR__).'/sports-meet.php');
    $poster=(string)get_option('sports_meet_song_poster','');
    if($poster==='')$poster=$base.'song-logo.jpg';
    $cover='<a href="'.esc_url($poster).'" target="_blank" rel="noopener"><img src="'.esc_url($poster).'" alt="《奔FUN赛道》主题曲吉祥物封面" loading="lazy"></a>';
    $lyrics=song_lyrics_markup();
    return '<section class="sm-song" aria-label="运动会主题曲"><div class="sm-song-cover">'.$cover.'</div><div class="sm-song-body"><p class="sm-eyebrow">运动会主题曲</p><h2>《奔Fun赛道》</h2><p>青春不散场，梦想写满，奔Fun人生</p><audio controls preload="none" aria-label="播放主题曲奔Fun赛道"><source src="'.esc_url($base.'ben-fun-track.mp3').'" type="audio/mpeg">你的浏览器不支持音频播放。<a href="'.esc_url($base.'ben-fun-track.mp3').'">打开歌曲</a></audio><p class="sm-song-hint">点击播放，一起为Fun而奔。</p><p class="sm-song-current" hidden></p><details><summary>展开完整歌词</summary><label class="sm-song-follow"><input type="checkbox" data-sm-follow-lyrics checked> 跟随歌曲滚动</label><div class="sm-song-lyrics" tabindex="0" role="region" aria-label="完整歌词，可上下滚动">'.$lyrics.'</div></details></div></section>';
}
add_shortcode('sports_meet_song',__NAMESPACE__.'\\song_section');
add_action('wp_enqueue_scripts',function(){
    $main=dirname(__DIR__).'/sports-meet.php';
    wp_enqueue_style('sports-meet-song',plugins_url('song.css',$main),[],'2.4.19');
    wp_enqueue_script('sports-meet-song-sync',plugins_url('song-sync.js',$main),[],'2.4.19',true);
});
add_filter('the_content',function($content){
    if(is_admin()||!is_front_page()||!is_main_query()||!in_the_loop()||has_shortcode($content,'sports_meet_home')||has_shortcode($content,'sports_meet_song')||str_contains($content,'class="sm-song"'))return $content;
    return $content.song_section();
},20);

function song_admin():void {
    echo '<h3>主题曲封面</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_song_options">'.wp_nonce_field('sports_meet_song_options','sm_nonce',false,false).'<label>海报图片地址（从媒体库复制文件URL）<input type="url" name="poster" value="'.esc_attr(get_option('sports_meet_song_poster','')).'" placeholder="https://…"></label><p>海报完整展示，不裁切。留空使用《奔FUN赛道》吉祥物封面。</p><button>保存主题曲封面</button></form><p>主题曲短代码：[sports_meet_song]</p>';
}
add_action('admin_post_sports_meet_song_options',function(){
    if(!current_user_can('manage_options'))wp_die('没有管理权限。','',['response'=>403]);
    check_admin_referer('sports_meet_song_options','sm_nonce');
    $value=wp_unslash($_POST['poster']??'');if(!is_string($value))wp_die('图片地址格式错误。');
    update_option('sports_meet_song_poster',esc_url_raw($value,['http','https']));
    wp_safe_redirect(view_url('photos'));exit;
});
