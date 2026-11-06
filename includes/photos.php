<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function install_photos(): void {
    q("CREATE TABLE IF NOT EXISTS photos (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, owner BIGINT UNSIGNED NOT NULL, title VARCHAR(180) NOT NULL, image LONGBLOB NOT NULL, width SMALLINT UNSIGNED NOT NULL, height SMALLINT UNSIGNED NOT NULL, bytes INT UNSIGNED NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', created DATETIME NOT NULL, PRIMARY KEY(id), KEY gallery(status,id), KEY user_date(owner,created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
add_action('admin_init',function(){if(current_user_can('manage_options') && get_option('sports_meet_db_version')!=='2.5.6')activate();});
function page_url(string $key): string {
    $pages=get_option('sports_meet_pages',[]);
    $id=(int)($pages[$key]??0);
    if($id && get_post_status($id)==='publish')return get_permalink($id);
    $fallbacks=[
        'upload'=>['sports_meet_upload',['上传照片','sports-upload']],
        'gallery'=>['sports_meet_gallery',['精彩相册','sports-gallery']],
        'board'=>['sports_meet_board',['排行榜','积分榜','sports-ranking']],
        'roster'=>['sports_meet_roster',['报名名单','sports-registration']],
        'results'=>['sports_meet_results',['成绩榜','项目成绩','sports-results']],
    ];
    if(isset($fallbacks[$key])){
        [$shortcode,$slugs]=$fallbacks[$key];
        foreach($slugs as $slug){
            $page=get_page_by_path($slug);
            if($page && $page->post_status==='publish' && has_shortcode($page->post_content,$shortcode))return get_permalink($page);
        }
    }
    return home_url('/');
}
function photo_url(int $id): string {return add_query_arg(['action'=>'sports_meet_photo','id'=>$id],admin_url('admin-post.php'));}
function photo_upload_enabled(): bool {return (bool)get_option('sports_meet_photo_upload_enabled',1);}
function photo_gallery_enabled(): bool {return (bool)get_option('sports_meet_photo_gallery_enabled',1);}
function hero_photo_ids(): array {return array_values(array_unique(array_map('intval',(array)get_option('sports_meet_hero_photos',[]))));}

add_filter('wp_nav_menu_objects',function(array $items): array {
    if(photo_gallery_enabled() && photo_upload_enabled())return $items;
    $pages=get_option('sports_meet_pages',[]);
    return array_filter($items,function($item) use ($pages){
        $id=(int)$item->object_id;
        $content=$item->object==='page'?(string)get_post_field('post_content',$id):'';
        if(!photo_gallery_enabled() && (($item->object==='page' && $id===(int)($pages['gallery']??0))||has_shortcode($content,'sports_meet_gallery')))return false;
        if(!photo_upload_enabled() && (($item->object==='page' && $id===(int)($pages['upload']??0))||has_shortcode($content,'sports_meet_upload')))return false;
        return true;
    });
});
function photo_require_login(): void {if(!is_user_logged_in() || !current_user_can('read'))wp_die('请先登录后再上传照片。','',['response'=>403]);}
function photo_notice(string $text): void {set_transient('sports_meet_photo_notice_'.get_current_user_id(),$text,120);}
function photo_notice_html(): string {$text=get_transient('sports_meet_photo_notice_'.get_current_user_id());if(!$text)return '';delete_transient('sports_meet_photo_notice_'.get_current_user_id());return '<p role="status" class="sm-message">'.esc_html($text).'</p>';}

/** Decode, auto-orient, fit within 1920x1080, flatten and re-encode: never retain originals. */
function normalize_photo(string $path): array {
    if(!extension_loaded('gd'))throw new \RuntimeException('服务器需要启用GD图片扩展。');
    $info=@getimagesize($path);$mime=$info['mime']??'';
    if(!$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new \RuntimeException('只支持真实的JPG、PNG或WebP图片。');
    [$w,$h]=$info;
    if($w<1 || $h<1 || $w*$h>40000000)throw new \RuntimeException('图片超过4000万像素，请先在手机选择较小版本。');
    wp_raise_memory_limit('image');$limit=wp_convert_hr_to_bytes(ini_get('memory_limit'));
    if($limit>0 && $w*$h*8+33554432>$limit-memory_get_usage(true))throw new \RuntimeException('图片超出服务器可处理范围，请先缩小图片或提高PHP内存上限。');
    $raw=@file_get_contents($path);$image=$raw===false?false:@imagecreatefromstring($raw);unset($raw);
    if(!$image)throw new \RuntimeException('无法读取图片。');
    $canvas=null;
    try {
        if($mime==='image/jpeg') {
            if(!function_exists('exif_read_data'))throw new \RuntimeException('服务器需要启用exif扩展，以正确处理手机照片方向。');
            $exif=@exif_read_data($path,'IFD0',true);$orientation=(int)($exif['IFD0']['Orientation']??1);
            if(in_array($orientation,[3,5,6,7,8],true)) {
                $angle=[3=>180,5=>-90,6=>-90,7=>90,8=>90][$orientation];$rotated=imagerotate($image,$angle,0);
                if(!$rotated)throw new \RuntimeException('无法校正照片方向。');imagedestroy($image);$image=$rotated;
            }
            if(in_array($orientation,[2,5,7],true))imageflip($image,IMG_FLIP_HORIZONTAL);
            if($orientation===4)imageflip($image,IMG_FLIP_VERTICAL);
        }
        $w=imagesx($image);$h=imagesy($image);$scale=min(1,1920/$w,1080/$h);$nw=max(1,(int)floor($w*$scale));$nh=max(1,(int)floor($h*$scale));
        $canvas=imagecreatetruecolor($nw,$nh);if(!$canvas)throw new \RuntimeException('无法创建缩小图片。');
        imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));imagealphablending($canvas,true);
        if(!imagecopyresampled($canvas,$image,0,0,0,0,$nw,$nh,$w,$h))throw new \RuntimeException('缩小图片失败。');
        ob_start();try{$saved=imagejpeg($canvas,null,82);$bytes=ob_get_contents();}finally{ob_end_clean();}
        if(!$saved || !$bytes || strlen($bytes)>3145728)throw new \RuntimeException('压缩结果异常，请换一张图片。');
        return ['image'=>$bytes,'width'=>$nw,'height'=>$nh,'bytes'=>strlen($bytes)];
    } finally {imagedestroy($image);if($canvas)imagedestroy($canvas);}
}
function store_photo(string $path,string $title,int $owner): int {
    global $wpdb;
    $data=normalize_photo($path);$transaction=false;$reference=null;$committing=false;
    try {
        q('START TRANSACTION');$transaction=true;q('SELECT id FROM settings WHERE id=1 FOR UPDATE')->fetch();
        $count=(int)q('SELECT COUNT(*) FROM photos WHERE owner=? AND created>=?',[$owner,current_time('Y-m-d').' 00:00:00'])->fetchColumn();
        if($count>=30)throw new \RuntimeException('每人每天最多上传30张照片。');
        $reference=photo_write_file($data['image']);
        $data['image']=$reference;
        $data+=['owner'=>$owner,'title'=>mb_substr(sanitize_text_field($title),0,180),'status'=>'published','created'=>current_time('mysql')];
        if($wpdb->insert($wpdb->prefix.'sm_photos',$data,['%s','%d','%d','%d','%d','%s','%s','%s'])===false)throw new \RuntimeException('照片资料保存失败。');
        $id=(int)$wpdb->insert_id;$committing=true;q('COMMIT');
        if($data['status']==='published')photo_sync_media_safely($id);
        return $id;
    }catch(\Throwable $e){try{if($transaction)q('ROLLBACK');}finally{if($reference!==null&&!$committing)photo_remove_file($reference);}throw $e;}
}
function upload_photos(): void {
    $async=($_GET['sm_async']??'')==='1';
    if($async){
        if(!is_user_logged_in()||!current_user_can('read'))wp_send_json_error(['message'=>'登录已失效，请重新登录。'],403);
        if(community_banned(get_current_user_id(),'photos'))wp_send_json_error(['message'=>'该账号的照片上传功能已暂停。'],403);
        if(!photo_upload_enabled())wp_send_json_error(['message'=>'照片上传已暂停。'],403);
        if(!$_POST && !$_FILES && !empty($_SERVER['CONTENT_LENGTH']))wp_send_json_error(['message'=>'服务器未收到照片，文件可能超过主机的上传限制。'],413);
        $nonce=$_POST['sm_nonce']??'';
        if(!is_string($nonce)||!wp_verify_nonce(wp_unslash($nonce),'sports_meet_upload'))wp_send_json_error(['message'=>'上传页面已过期，请刷新后重试。'],403);
    }else{
        photo_require_login();check_admin_referer('sports_meet_upload','sm_nonce');
        if(community_banned(get_current_user_id(),'photos'))wp_die('该账号的照片上传功能已暂停。','',['response'=>403]);
        if(!photo_upload_enabled())wp_die('照片上传已暂停。','',['response'=>403]);
    }
    $success=0;$mediaPending=0;$errors=[];$files=$_FILES['photos']??null;
    if(!$files || !is_array($files['name']??null) || !$files['name'] || count($files['name'])>($async?1:5)){
        if($async)wp_send_json_error(['message'=>'服务器未收到所选照片。'],400);
        photo_notice('请每次选择1至5张照片。');wp_safe_redirect(page_url('upload'));exit;
    }
    foreach($files['name'] as $i=>$name) {
        try {
            if(($files['error'][$i]??-1)!==UPLOAD_ERR_OK)throw new \RuntimeException('上传未完成，可能超过主机文件大小限制。');
            if(($files['size'][$i]??0)>min(12582912,wp_max_upload_size()))throw new \RuntimeException('单张照片超过允许大小。');
            $path=$files['tmp_name'][$i]??'';if(!is_uploaded_file($path))throw new \RuntimeException('无效上传。');
            $title=sanitize_text_field(wp_unslash($_POST['photo_title']??''));if($title==='')$title=pathinfo(sanitize_file_name($name),PATHINFO_FILENAME);
            $id=store_photo($path,$title,get_current_user_id());$success++;
            try{if(!photo_media_attachment($id))$mediaPending++;}catch(\Throwable $e){$mediaPending++;}
        }catch(\Throwable $e){$errors[]='第'.($i+1).'张：'.$e->getMessage();}
    }
    if($async){
        if(!$success)wp_send_json_error(['message'=>$errors[0]??'照片上传失败。'],422);
        wp_send_json_success(['media_ready'=>$mediaPending===0]);
    }
    photo_notice(($success?'成功上传'.$success.'张照片。':'').($mediaPending?'其中 '.$mediaPending.' 张已保存，媒体库登记待完成。':'').implode(' ',$errors));
    wp_safe_redirect(page_url('upload'));exit;
}
add_action('admin_post_sports_meet_upload',__NAMESPACE__.'\\upload_photos');
add_action('admin_post_nopriv_sports_meet_upload',__NAMESPACE__.'\\upload_photos');
function serve_photo(): void {
    $id=absint($_GET['id']??0);$row=q('SELECT * FROM photos WHERE id=?',[$id])->fetch();
    if(!$row || ($row['status']!=='published' && !current_user_can('manage_options') && (!is_user_logged_in() || (int)$row['owner']!==get_current_user_id()))){status_header(404);exit;}
    try{$jpeg=photo_read_bytes($row['image'],(int)$row['bytes']);}
    catch(\Throwable $e){error_log('Sports Meet photo: '.$e->getMessage());status_header(404);exit;}
    nocache_headers();header('Content-Type: image/jpeg');header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="sports-photo-'.$id.'.jpg"');header('Content-Length: '.strlen($jpeg));echo $jpeg;exit;
}
add_action('admin_post_sports_meet_photo',__NAMESPACE__.'\\serve_photo');
add_action('admin_post_nopriv_sports_meet_photo',__NAMESPACE__.'\\serve_photo');
function photo_cards(array $rows,bool $manage=false,bool $batch=false): string {
    if(!$rows)return '<div class="sm-empty"><span aria-hidden="true">◎</span><h3>精彩，等你来记录。</h3><p>上传运动场上的瞬间，让每一份热爱都有回忆。</p></div>';
    $admin=$manage&&current_user_can('manage_options');
    $hero=$admin?hero_photo_ids():[];
    $html='<div class="sm-photo-grid'.($manage?'':' sm-public-gallery').'">';foreach($rows as $r){$id=(int)$r['id'];$html.='<figure><a href="'.esc_url(photo_url($id)).'" target="_blank" rel="noopener"><img loading="lazy" src="'.esc_url(photo_url($id)).'" alt="'.esc_attr($r['title']).'" width="'.(int)$r['width'].'" height="'.(int)$r['height'].'"></a><figcaption>';
        $uploader=!empty($r['owner'])?get_userdata((int)$r['owner']):false;
        $name=$uploader?trim((string)$uploader->display_name):'';
        if($name===''||is_email($name))$name='会员上传';
        $title=$r['title']?:'运动会瞬间';
        $details='来源：'.$name.' · '.substr($r['created'],0,10).' · '.(int)$r['width'].'×'.(int)$r['height'];
        $html.='<div class="sm-photo-details" title="'.esc_attr($title.' · '.$details).'"><strong>'.esc_html($title).'</strong> · '.esc_html($details).'</div>';
        if($batch && $r['status']==='pending')$html.='<label class="sm-review-choice"><input type="checkbox" name="photo_ids[]" form="sm-batch-review" value="'.$id.'"> 选中审核</label>';
        if($manage){
            $status=['pending'=>'待审核','hidden'=>'已隐藏','published'=>'已公开'][$r['status']]??'未公开';
            $selected=in_array($id,$hero,true);
            $html.='<span>'.$status.($selected?' · 版头照片':'').'</span><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
                .'<input type="hidden" name="action" value="sports_meet_moderate"><input type="hidden" name="id" value="'.$id.'">'.wp_nonce_field('sports_meet_photo_'.$id,'sm_nonce',false,false);
            if($admin){
                $html.=$r['status']==='published'?'<button name="decision" value="hide">隐藏照片</button>':'<button name="decision" value="publish">公开照片</button>';
                if($selected||$r['status']==='published')$html.='<button name="decision" value="'.($selected?'hero_off':'hero_on').'">'.($selected?'移出版头':'设为版头照片').'</button>';
            }
            $html.='<button name="decision" value="delete">删除照片</button></form>';
        }
        $html.='</figcaption></figure>';}
    return $html.'</div>';
}
function photo_gallery($attrs=[]): string {
    if(!photo_gallery_enabled())return '';
    $attrs=shortcode_atts(['limit'=>12],(array)$attrs);$limit=min(24,max(1,(int)$attrs['limit']));$page=max(1,absint($_GET['sm_gallery_page']??1));
    $total=(int)q("SELECT COUNT(*) FROM photos WHERE status='published'")->fetchColumn();$offset=($page-1)*$limit;
    $rows=q("SELECT id,owner,title,width,height,status,created FROM photos WHERE status='published' ORDER BY id DESC LIMIT $limit OFFSET $offset")->fetchAll();
    $html='<section class="sm-site"><div class="sm-section-head"><div><p class="sm-eyebrow">OUR MOMENTS / 运动场上的我们</p><h2>精彩相册</h2></div>'.(photo_upload_enabled()?'<a class="sm-btn" href="'.esc_url(page_url('upload')).'">分享我的照片 ↗</a>':'').'</div>'.photo_cards($rows);
    if($total>$limit){$html.='<nav class="sm-pagination" aria-label="相册分页">';if($page>1)$html.='<a href="'.esc_url(add_query_arg('sm_gallery_page',$page-1)).'">← 上一页</a>';$html.='<span>第'.$page.'页 / 共'.(int)ceil($total/$limit).'页</span>';if($page*$limit<$total)$html.='<a href="'.esc_url(add_query_arg('sm_gallery_page',$page+1)).'">下一页 →</a>';$html.='</nav>';}
    return $html.'</section>';
}
add_shortcode('sports_meet_gallery',__NAMESPACE__.'\\photo_gallery');
function photo_upload_form(): string {
    if(!photo_upload_enabled())return '<section class="sm-site sm-upload"><p>照片上传已暂停。</p></section>';
    $html='<section class="sm-site sm-upload"><p class="sm-eyebrow">SHARE THE ENERGY / 分享热爱</p><h2>把精彩，留在这里。</h2><p>大照片会先在浏览器保持原比例缩小到不超过1920×1080，再逐张上传。</p>';
    if(!is_user_logged_in())return $html.'<div class="sm-login"><h3>登录后分享照片</h3>'.wp_login_form(['echo'=>false,'redirect'=>page_url('upload'),'label_username'=>'用户名或邮箱','label_password'=>'密码','label_remember'=>'记住我','label_log_in'=>'登录并上传']).'<p><a href="'.esc_url(wp_lostpassword_url(page_url('upload'))).'">忘记密码？</a></p><p><a href="'.esc_url(page_url('disclaimer')).'#sports-meet-signup">参赛者自助注册</a></p></div></section>';
    if(community_banned(get_current_user_id(),'photos'))return $html.'<p>该账号的照片上传功能已暂停。</p></section>';
    $html.=photo_notice_html().'<form class="sm-upload-form" method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php','relative')).'"><input type="hidden" name="action" value="sports_meet_upload">'.wp_nonce_field('sports_meet_upload','sm_nonce',false,false).'<label>照片说明（可选）<input name="photo_title" maxlength="180" placeholder="例如：最后一棒，向终点冲刺"></label><label class="sm-drop sm-photo-picker"><strong class="sm-picker-label">① 选择照片</strong><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required><span>每批最多5张 · JPG / PNG / WebP · 每张不超过'.esc_html(size_format(min(12582912,wp_max_upload_size()))).'</span></label><p>照片上传后会立即公开。 请只上传你有权分享的活动照片。</p><button class="sm-btn" type="submit">② 上传已选照片</button></form>';
    $preview='<p class="sm-preview-status" role="status"></p><div class="sm-photo-previews" aria-label="所选照片预览"></div><p class="sm-upload-progress" role="status"></p><ul class="sm-upload-results" aria-label="各张照片上传结果"></ul>';
    $html=str_replace('</span></label>','</span></label>'.$preview,$html);
    $uid=get_current_user_id();$rows=q('SELECT id,owner,title,width,height,status,created FROM photos WHERE owner=? ORDER BY id DESC LIMIT 12',[$uid])->fetchAll();
    return $html.'<h3>我的最近照片</h3>'.photo_cards($rows,true).'</section>';
}
add_shortcode('sports_meet_upload',__NAMESPACE__.'\\photo_upload_form');
function photo_delete_record(int $id): void {
    q('START TRANSACTION');
    try{
        $row=q('SELECT image FROM photos WHERE id=? FOR UPDATE',[$id])->fetch();
        q('DELETE FROM photos WHERE id=?',[$id]);
        q('COMMIT');
    }catch(\Throwable $e){q('ROLLBACK');throw $e;}
    if($row)photo_remove_file($row['image']);
    $attachment=photo_media_attachment($id);
    if($attachment)wp_delete_attachment($attachment,true);
    update_option('sports_meet_hero_photos',array_values(array_diff(hero_photo_ids(),[$id])),false);
}

function moderate_photo(): void {
    photo_require_login();$id=absint($_POST['id']??0);check_admin_referer('sports_meet_photo_'.$id,'sm_nonce');
    $r=q('SELECT id,owner,status FROM photos WHERE id=?',[$id])->fetch();$admin=current_user_can('manage_options');
    if(!$r || (!$admin && (int)$r['owner']!==get_current_user_id()))wp_die('没有操作权限。','',['response'=>403]);
    $decision=sanitize_key($_POST['decision']??'');
    if($decision==='delete'){
        photo_delete_record($id);
    }elseif($decision==='publish' && $admin){
        q("UPDATE photos SET status='published' WHERE id=?",[$id]);
        photo_sync_media_safely($id);
    }elseif($decision==='hide'&&$admin){
        q("UPDATE photos SET status='hidden' WHERE id=?",[$id]);
    }elseif($admin&&($decision==='hero_off'||($decision==='hero_on'&&$r['status']==='published'))){
        $ids=array_values(array_diff(hero_photo_ids(),[$id]));
        if($decision==='hero_on'){
            if(count($ids)>=12)wp_die('版头最多选择 12 张照片，请先移除一张。','',['response'=>400,'back_link'=>true]);
            $ids[]=$id;
        }
        update_option('sports_meet_hero_photos',$ids,false);
    }else wp_die('无效操作。','',['response'=>403]);
    wp_safe_redirect($admin?view_url('photos'):page_url('upload'));exit;
}
add_action('admin_post_sports_meet_moderate',__NAMESPACE__.'\\moderate_photo');

function batch_publish_photos(): void {
    if(!current_user_can('manage_options'))wp_die('没有操作权限。','',['response'=>403]);
    check_admin_referer('sports_meet_batch_publish','sm_nonce');
    $selection=$_POST['photo_ids']??[];
    if(!is_array($selection)||count($selection)>12)wp_die('请在当前页选择照片。','',['response'=>400]);
    $ids=[];
    foreach($selection as $value){
        if(!is_string($value)||!ctype_digit($value)||(int)$value<1)wp_die('照片编号无效。','',['response'=>400]);
        $ids[]=(int)$value;
    }
    $published=[];
    if($ids){
        $ids=array_values(array_unique($ids));
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        q('START TRANSACTION');
        try{
            $published=q("SELECT id FROM photos WHERE id IN ($placeholders) AND status='pending' ORDER BY id FOR UPDATE",$ids)->fetchAll(7);
            q("UPDATE photos SET status='published' WHERE id IN ($placeholders) AND status='pending'",$ids);
            q('COMMIT');
        }catch(\Throwable $error){q('ROLLBACK');throw $error;}
        foreach($published as $id)photo_sync_media_safely((int)$id);
    }
    photo_notice($published?'已审核通过并公开 '.count($published).' 张照片。':'没有选中待审核的照片。');
    wp_safe_redirect(view_url('photos'));exit;
}
add_action('admin_post_sports_meet_batch_publish',__NAMESPACE__.'\\batch_publish_photos');
function home_section_original(): string {
    $showGallery=photo_gallery_enabled();
    $rows=$showGallery?q("SELECT id,owner,title,width,height,status,created FROM photos WHERE status='published' ORDER BY id DESC LIMIT 8")->fetchAll():[];
    $media=plugins_url('media/',dirname(__DIR__).'/sports-meet.php');
    $slides=[];
    $heroIds=$showGallery?hero_photo_ids():[];
    if($heroIds){
        $slots=implode(',',array_fill(0,count($heroIds),'?'));
        $selected=array_column(q("SELECT id,title FROM photos WHERE status='published' AND id IN ($slots)",$heroIds)->fetchAll(),null,'id');
        foreach($heroIds as $id)if(isset($selected[$id]))$slides[]=[photo_url($id),$selected[$id]['title']?:'运动会精彩瞬间'];
    }
    if(!$slides)$slides=[[$media.'sports-day-brand.jpg','奔Fun运动会吉祥物'],[$media.'poster.png','第五届奔Fun运动会海报']];

    $counts=[
        [count(all('teams')),'参赛高校'],
        [count(all('athletes')),'运动员'],
        [(int)q("SELECT COUNT(*) FROM photos WHERE status='published'")->fetchColumn(),'照片'],
    ];
    $html='<div class="sm-site"><section class="sm-hero sm-hero-showcase">'
        .'<div class="sm-hero-copy"><div class="sm-hero-mascot" aria-hidden="true"></div>'
        .'<h1>为Fun而奔<br><em>奔而得分</em></h1><dl class="sm-hero-counts">';
    foreach($counts as [$count,$label])$html.='<div><dt>'.esc_html($label).'</dt><dd>'.(int)$count.'</dd></div>';
    $html.='</dl>'.race_public_board(true).'</div><div class="sm-hero-right">'
        .'<section class="sm-hero-slides" aria-label="版头照片轮播">';
    foreach($slides as $index=>[$url,$alt]){
        $html.='<div class="sm-hero-slide'.($index===0?' is-active':'').'" aria-hidden="'.($index===0?'false':'true').'">'
            .'<img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" decoding="async"></div>';
    }
    $html.='<div class="sm-slide-controls" hidden><button type="button" data-sm-prev aria-label="上一张">‹</button>'
        .'<button type="button" data-sm-play>暂停</button><button type="button" data-sm-next aria-label="下一张">›</button></div></section>';
    $html.=song_section().'</div></section>';
    if($showGallery){
        $links=[['查看全部相册',page_url('gallery')]];
        if(photo_upload_enabled())$links[]=['分享你的照片',page_url('upload')];
        $html.='<section class="sm-gallery-preview sm-photo-carousel"><div class="sm-section-head"><div><p class="sm-eyebrow">THE HIGHLIGHTS</p>'
            .'<h2>不止终点，还有沿途的精彩...</h2></div><div class="sm-gallery-actions">'
            .'<div class="sm-strip-controls" hidden><button type="button" data-sm-strip-prev aria-label="上一张照片">‹</button>'
            .'<button type="button" data-sm-strip-play>暂停</button>'
            .'<button type="button" data-sm-strip-next aria-label="下一张照片">›</button></div>'
            .community_buttons($links).'</div></div>'.photo_cards($rows).'</section>';
    }
    return $html.guestbook_home().'</div>';
}
add_shortcode('sports_meet_home',__NAMESPACE__.'\\home_section');
function setup_site(): void {
    if(!current_user_can('manage_options'))wp_die('没有权限。','',['response'=>403]);check_admin_referer('sports_meet_setup','sm_nonce');
    $pages=get_option('sports_meet_pages',[]);
    foreach(['home'=>['运动会首页','sports-home','sports_meet_home'],'gallery'=>['精彩相册','sports-gallery','sports_meet_gallery'],'upload'=>['上传照片','sports-upload','sports_meet_upload'],'board'=>['队伍排行榜','sports-ranking','sports_meet_board'],'results'=>['项目成绩','sports-results','sports_meet_results'],'roster'=>['高校及报名名单','sports-registration','sports_meet_roster'],'judge'=>['裁判工作台','sports-judge','sports_meet_judge']] as $key=>$p) {
        if(!empty($pages[$key]) && get_post_status($pages[$key]) && get_post_status($pages[$key])!=='trash')continue;
        $id=wp_insert_post(['post_title'=>$p[0],'post_name'=>$p[1],'post_status'=>'publish','post_type'=>'page','post_content'=>'<!-- wp:shortcode -->['.$p[2].']<!-- /wp:shortcode -->','comment_status'=>'closed'],true);
        if(is_wp_error($id))wp_die(esc_html($id->get_error_message()));$pages[$key]=$id;update_option('sports_meet_pages',$pages,false);
    }
    race_ensure_day_pages();
    if(!empty($_POST['set_home'])){update_option('show_on_front','page');update_option('page_on_front',$pages['home']);}
    wp_safe_redirect(view_url('photos'));exit;
}
add_action('admin_post_sports_meet_setup',__NAMESPACE__.'\\setup_site');
function photo_options(): void {
    if(!current_user_can('manage_options'))wp_die('没有权限。','',['response'=>403]);check_admin_referer('sports_meet_photo_options','sm_nonce');
    update_option('sports_meet_photo_upload_enabled',empty($_POST['upload_enabled'])?0:1,false);
    update_option('sports_meet_photo_gallery_enabled',empty($_POST['gallery_enabled'])?0:1,false);
    wp_safe_redirect(view_url('photos'));exit;
}
add_action('admin_post_sports_meet_photo_options',__NAMESPACE__.'\\photo_options');
function photo_admin(): void {
    song_admin();
    echo '<p>压缩后的照片保存在上传目录。备份时请同时备份数据库及上传目录。</p>';
    echo '<p>已公开的照片也可在 WordPress 媒体库查看。已有照片会分批登记，待审核照片在此管理。</p>';
    $migrationError=get_option('sports_meet_photo_files_error');
    if($migrationError)echo '<p class="error">照片迁移将重试：'.esc_html($migrationError).'</p>';
    elseif(!get_option('sports_meet_photo_files_done'))echo '<p>旧照片正在分批迁移，迁移期间可以继续查看和上传。</p>';
    echo '<div class="sm-site"><h2>网站页面与相册</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_setup">'.wp_nonce_field('sports_meet_setup','sm_nonce',false,false).'<p>建立首页、相册、上传照片、排行榜、项目成绩、报名名单及裁判工作台七个页面。已有的本插件页面不会被覆盖；从旧版升级后请再点击一次，补齐新增页面。</p><label><input type="checkbox" name="set_home" value="1" checked> 将运动会首页设为网站首页</label><button>创建／补齐网站页面</button></form><p>普通参与者角色选订阅者可上传照片；裁判账号角色选“运动会裁判”，可在前台裁判工作台检录及录入比赛。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_photo_options">'.wp_nonce_field('sports_meet_photo_options','sm_nonce',false,false).'<label><input type="checkbox" name="upload_enabled" value="1" '.checked(photo_upload_enabled(),true,false).'> 允许会员上传照片</label><label><input type="checkbox" name="gallery_enabled" value="1" '.checked(photo_gallery_enabled(),true,false).'> 显示前台相册</label><p>新上传的照片会直接公开，管理员可在下方删除。</p><button>保存相册设置</button></form>';
    if(!extension_loaded('gd') || !function_exists('exif_read_data'))echo '<p class="error">请在主机PHP设置中启用GD及exif扩展。</p>';
    $page=max(1,absint($_GET['sm_photo_page']??1));$offset=($page-1)*12;$total=(int)q('SELECT COUNT(*) FROM photos')->fetchColumn();
    $rows=q("SELECT id,owner,title,width,height,status,created FROM photos ORDER BY FIELD(status,'pending','published'),id DESC LIMIT 12 OFFSET $offset")->fetchAll();
    echo '<h3>管理照片</h3><p>可隐藏或删除照片，也可从已公开照片中选择最多 12 张版头照片。</p>'.photo_notice_html();
    if(in_array('pending',array_column($rows,'status'),true)){
        echo '<form id="sm-batch-review" class="sm-batch-review" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_batch_publish">'.wp_nonce_field('sports_meet_batch_publish','sm_nonce',false,false).'<label hidden data-sm-select-label><input type="checkbox" data-sm-select-all> 全选本页待审核照片</label><button type="submit">批量通过并公开</button> <span role="status" data-sm-selected></span></form>';
    }
    echo photo_cards($rows,true,true);
    echo '<nav class="sm-pagination">';if($page>1)echo '<a href="'.esc_url(add_query_arg('sm_photo_page',$page-1,view_url('photos'))).'">← 上一页</a>';echo '<span>共'.$total.'张</span>';if($page*12<$total)echo '<a href="'.esc_url(add_query_arg('sm_photo_page',$page+1,view_url('photos'))).'">下一页 →</a>';echo '</nav>';
    community_author_admin('photos');echo '</div>';
}
