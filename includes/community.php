<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function community_sections(): array {
    return [
        'intro'=>['奔Fun简介','sports-about'], 'race_hub'=>['赛场直通车','sports-race-hub'],
        'committee'=>['组委会','sports-committee'], 'sponsors'=>['赞助伙伴','sports-sponsors'],
        'media_hub'=>['图文现场','sports-media-hub'], 'messages'=>['留言板','sports-messages'],
    ];
}

function install_community(): void {
    q('CREATE TABLE IF NOT EXISTS guest_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner BIGINT UNSIGNED NOT NULL,
        message TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT \'published\', created DATETIME NOT NULL,
        pinned TINYINT NOT NULL DEFAULT 0, KEY visible (status,id), KEY author (owner,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if(!q("SHOW COLUMNS FROM guest_messages LIKE 'pinned'")->fetch()){
        q('ALTER TABLE guest_messages ADD COLUMN pinned TINYINT NOT NULL DEFAULT 0');
    }
    update_option('sports_meet_guestbook_pins_ready',1,false);
    $pages=get_option('sports_meet_pages',[]);
    foreach(community_sections() as $key=>[$title,$slug]){
        if(!empty($pages[$key])&&get_post_status($pages[$key])==='publish')continue;
        $page=get_page_by_path($slug);
        $id=$page&&$page->post_status==='publish'?$page->ID:wp_insert_post([
            'post_title'=>$title,'post_name'=>$slug,'post_status'=>'publish','post_type'=>'page',
            'post_content'=>'<!-- wp:shortcode -->[sports_meet_hub kind="'.$key.'"]<!-- /wp:shortcode -->',
        ],true);
        if(is_wp_error($id)||!$id)throw new \RuntimeException('无法创建'.$title.'页面。');
        $pages[$key]=$id;
    }
    update_option('sports_meet_pages',$pages,false);
    update_option('sports_meet_community_ready',1,false);
}

function community_banned(int $user,string $scope): bool {
    $key='sports_meet_community_banned_'.$scope;
    if(metadata_exists('user',$user,$key))return (bool)get_user_meta($user,$key,true);
    return (bool)get_user_meta($user,'sports_meet_community_banned',true);
}

function community_buttons(array $links): string {
    $html='<div class="sm-community-actions">';
    foreach($links as [$label,$url])$html.='<a class="sm-btn" href="'.esc_url($url).'">'.esc_html($label).'</a>';
    return $html.'</div>';
}

function community_menu(): string {
    $menu='';
    foreach(['intro'=>'奔Fun简介','board'=>'积分榜','race_hub'=>'赛场直通车','officials'=>'裁判工作台',
        'committee'=>'组委会','sponsors'=>'赞助伙伴','media_hub'=>'图文现场'] as $key=>$label){
        $menu.='<li class="menu-item"><a href="'.esc_url(page_url($key)).'">'.esc_html($label).'</a></li>';
    }
    return $menu;
}
add_filter('wp_nav_menu_args',static function(array $args):array {
    if(($args['theme_location']??'')==='primary'&&get_option('sports_meet_community_ready')&&empty($args['menu'])){
        $args['fallback_cb']=has_nav_menu('primary')
            ?false
            :static function(){echo '<ul>'.community_menu().'</ul>';};
    }
    return $args;
});

function community_hub($attrs=[]): string {
    $key=shortcode_atts(['kind'=>'intro'],(array)$attrs)['kind'];
    if($key==='messages')return guestbook_page();
    if($key==='media_hub')return '<section class="sm-site sm-media-hub">'.photo_carousel().guestbook_home(8).'</section>';
    $html='<section class="sm-site sm-community">';
    if($key==='intro')$html.='<p>'.esc_html(SITE_NAME).' · '.esc_html(SITE_SLOGAN).'</p>'
        .'<p>第五届奔Fun运动会于 2026 年 11 月 15 日举行。校友和家人一起参赛、观赛，分享运动场上的精彩瞬间。</p>'
        .community_buttons([['回到首页',home_url('/')],['进入赛场',page_url('race_hub')]]);
    elseif($key==='race_hub'){
        $html.='<nav class="race-day-grid" aria-label="赛场直通车">';
        foreach([
            'roster'=>['报名名单','按高校、组别或项目查看报名名单'],
            'results'=>['成绩榜','查看决赛前三名及预赛晋级名单'],
            'board'=>['积分榜','查看实时高校积分排名'],
        ] as $page=>[$title,$description]){
            $html.='<a href="'.esc_url(page_url($page)).'"><strong>'.esc_html($title).' ↗</strong><span>'.esc_html($description).'</span></a>';
        }
        $html.='</nav>';
    }
    elseif($key==='committee')$html.=current_user_can('manage_options')
        ?community_buttons([['赛事管理',view_url('races')],['照片管理',view_url('photos')],['留言管理',view_url('messages')],['免责声明设置',view_url('disclaimer')]])
        :'<p>组委会负责赛事和网站管理。组委会成员请用管理员账号登录。</p>'.(!is_user_logged_in()?wp_login_form(['echo'=>false,'redirect'=>page_url('committee')]):'');
    elseif($key==='sponsors')$html.='<p>感谢支持奔Fun运动会的伙伴。</p><p>赞助伙伴资料待公布。</p>';
    return $html.'</section>';
}
add_shortcode('sports_meet_hub',__NAMESPACE__.'\\community_hub');

function community_author_schools(array $owners): array {
    if(!$owners||!get_option('sports_meet_disclaimer_ready'))return [];
    $ids=array_values(array_unique(array_map('intval',$owners)));
    $slots=implode(',',array_fill(0,count($ids),'?'));
    return array_column(q("SELECT aa.user_id,t.name FROM athlete_accounts aa
        JOIN athletes a ON a.id=aa.athlete_id JOIN teams t ON t.id=a.team_id
        WHERE aa.user_id IN ($slots)",$ids)->fetchAll(),'name','user_id');
}

function community_author_name($user,array $schools,string $fallback='会员'): string {
    $name=$user?trim((string)$user->display_name):'';
    if($name===''||is_email($name))$name=$fallback;
    $school=$user?($schools[$user->ID]??''):'';
    return $school!==''?$name.'（'.$school.'）':$name;
}

function guestbook_cards(array $rows,bool $short=false): string {
    if(!$rows)return '<p>欢迎留下第一条留言。</p>';
    $schools=community_author_schools(array_column($rows,'owner'));
    $html='<div class="sm-message-grid'.($short?' sm-message-excerpts':'').'">';
    foreach($rows as $row){
        $user=get_userdata((int)$row['owner']);
        $name=community_author_name($user,$schools);
        $message=$row['message'];
        if($short&&mb_strlen($message)>82)$message=mb_substr($message,0,82).'…';
        $id=(int)$row['id'];
        $html.='<article'.($short?'':' id="sm-message-'.$id.'"').'><div class="sm-message-byline"><strong>'.esc_html($name).'</strong><time>'.esc_html(substr($row['created'],0,10)).'</time></div><p>'.nl2br(esc_html($message)).'</p>';
        if($short)$html.='<a href="'.esc_url(add_query_arg('sm_message',$id,page_url('messages'))).'">查看留言</a>';
        $html.='</article>';
    }
    return $html.'</div>';
}

function guestbook_home(int $limit=3): string {
    if(!get_option('sports_meet_community_ready'))return '';
    $order=get_option('sports_meet_guestbook_pins_ready')?'pinned DESC,id DESC':'id DESC';
    $rows=q("SELECT * FROM guest_messages WHERE status='published' ORDER BY $order LIMIT $limit")->fetchAll();
    return '<section class="sm-gallery-preview sm-guestbook-preview"><div class="sm-section-head"><h2>除了精彩瞬间，更有心里的感言...</h2>'
        .community_buttons([['查看全部留言',page_url('messages')],['说出你的感言',page_url('messages').'#sm-message-form']]).'</div>'.guestbook_cards($rows,true).'</section>';
}

function guestbook_page(): string {
    if(!get_option('sports_meet_community_ready'))return '<p>留言板即将开放。</p>';
    if(isset($_GET['sm_message'])){
        $id=absint($_GET['sm_message']);
        $row=q("SELECT * FROM guest_messages WHERE id=? AND status='published'",[$id])->fetch();
        return '<section class="sm-site sm-community sm-single-message"><p><a href="'.esc_url(page_url('messages')).'">← 返回全部留言</a></p>'
            .($row?guestbook_cards([$row]):'<p>这篇留言暂不可查看。</p>').'</section>';
    }
    $page=min(100000,max(1,absint($_GET['message_page']??1)));
    $offset=($page-1)*24;
    $order=get_option('sports_meet_guestbook_pins_ready')?'pinned DESC,id DESC':'id DESC';
    $rows=q("SELECT * FROM guest_messages WHERE status='published' ORDER BY $order LIMIT 25 OFFSET $offset")->fetchAll();
    $html='<section class="sm-site sm-community sm-guestbook-list">'.photo_notice_html();
    if(!is_user_logged_in())$html.='<p>登录后可以留言。</p>'.community_buttons([['登录或注册',page_url('disclaimer')]]);
    elseif(community_banned(get_current_user_id(),'messages'))$html.='<p>该账号的留言功能已暂停。</p>';
    else $html.='<form id="sm-message-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="sports_meet_guest_message">'.wp_nonce_field('sports_meet_guest_message','sm_nonce',false,false)
        .'<label>说出你的感言<textarea name="message" rows="4" maxlength="1000" required></textarea></label><button class="sm-btn" type="submit">发布留言</button></form>';
    $html.=guestbook_cards(array_slice($rows,0,24),true);
    if($page>1)$html.='<a href="'.esc_url(add_query_arg('message_page',$page-1,page_url('messages'))).'">上一页</a> ';
    if(count($rows)>24)$html.='<a href="'.esc_url(add_query_arg('message_page',$page+1,page_url('messages'))).'">下一页</a>';
    return $html.'</section>';
}

add_action('admin_post_sports_meet_guest_message',function(){
    photo_require_login();check_admin_referer('sports_meet_guest_message','sm_nonce');
    $user=get_current_user_id();
    if(community_banned($user,'messages'))wp_die('该账号的留言功能已暂停。','',['response'=>403]);
    $value=wp_unslash($_POST['message']??'');
    $message=is_string($value)?trim(sanitize_textarea_field($value)):'';
    if($message===''||mb_strlen($message)>1000)wp_die('请输入 1 到 1000 字留言。','',['response'=>400,'back_link'=>true]);
    q("INSERT INTO guest_messages(owner,message,status,created) VALUES(?,?,'published',?)",[$user,$message,current_time('mysql')]);
    photo_notice('留言已发布。');wp_safe_redirect(page_url('messages'));exit;
});

function moderation_filters(array $input): array {
    $owner=absint($input['sm_author']??0);
    $pin=$input['sm_pin']??'';
    return array_filter(['sm_author'=>$owner,'sm_pin'=>in_array($pin,['pinned','unpinned'],true)?$pin:'']);
}

function moderation_filter_form(string $tab,array $filters): void {
    echo '<form class="sm-moderation-filters" method="get" action="'.esc_url(admin_url('admin.php')).'">'
        .'<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="'.esc_attr($tab).'">'
        .'<label>作者 <select name="sm_author" data-sm-author-select><option value="0">全部作者</option>';
    $table=$tab==='photos'?'photos':'guest_messages';
    $owners=q("SELECT DISTINCT owner FROM $table ORDER BY owner")->fetchAll(7);
    $schools=community_author_schools($owners);
    if($owners)foreach(get_users(['include'=>$owners,'orderby'=>'display_name','fields'=>['ID','display_name']]) as $user){
        echo '<option value="'.(int)$user->ID.'" '.selected($filters['sm_author']??0,$user->ID,false).'>'.esc_html(community_author_name($user,$schools)).'</option>';
    }
    echo '</select></label><label>置顶状态 <select name="sm_pin"><option value="">全部</option>';
    foreach(['pinned'=>'已置顶','unpinned'=>'未置顶'] as $value=>$label){
        echo '<option value="'.$value.'" '.selected($filters['sm_pin']??'',$value,false).'>'.$label.'</option>';
    }
    echo '</select></label><div class="sm-moderation-actions"><button type="submit">筛选</button> <a href="'.esc_url(view_url($tab)).'">重置</a></div></form>';
}

function moderation_pin_button(bool $pinned,string $pin,string $unpin): string {
    return '<button class="sm-moderation-pin'.($pinned?' is-pinned':'').'" type="submit" name="decision" value="'
        .esc_attr($pinned?$unpin:$pin).'" aria-pressed="'.($pinned?'true':'false').'">'.($pinned?'已置顶':'置顶').'</button>';
}

function guestbook_admin(): void {
    echo '<div class="sm-message-admin" data-sm-photo-admin>';
    echo '<h2>留言管理</h2>'.photo_notice_html();
    $filters=moderation_filters($_GET);
    moderation_filter_form('messages',$filters);
    $base=add_query_arg($filters,view_url('messages'));
    $clauses=[];$args=[];
    if(isset($filters['sm_author'])){$clauses[]='owner=?';$args[]=$filters['sm_author'];}
    if(isset($filters['sm_pin'])){$clauses[]='pinned=?';$args[]=(int)($filters['sm_pin']==='pinned');}
    $where=$clauses?' WHERE '.implode(' AND ',$clauses):'';
    $total=(int)q("SELECT COUNT(*) FROM guest_messages$where",$args)->fetchColumn();
    $page=min(max(1,(int)ceil($total/30)),max(1,absint($_GET['admin_message_page']??1)));
    $offset=($page-1)*30;
    $rows=q("SELECT * FROM guest_messages$where ORDER BY pinned DESC,id DESC LIMIT 31 OFFSET $offset",$args)->fetchAll();
    if(!$rows)echo '<p>暂无留言。</p>';
    foreach(array_slice($rows,0,30) as $row){
        $id=(int)$row['id'];
        $hidden=$row['status']==='hidden';
        echo guestbook_cards([$row]).'<p>'.($hidden?'已隐藏':'已公开').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
            .'<input type="hidden" name="action" value="sports_meet_community_moderate"><input type="hidden" name="message_id" value="'.$id.'">'
            .wp_nonce_field('sports_meet_community_moderate','sm_nonce',false,false)
            .moderation_pin_button((bool)$row['pinned'],'pin_message','unpin_message')
            .'<button name="decision" value="'.($hidden?'restore_message':'hide_message').'">'.($hidden?'恢复留言':'隐藏留言').'</button>'
            .'<label><input type="checkbox" name="confirm_delete" value="1"> 确认删除</label><button name="decision" value="delete_message">删除留言</button></form>';
    }
    if($page>1)echo '<a href="'.esc_url(add_query_arg('admin_message_page',$page-1,$base)).'">上一页留言</a> ';
    if(count($rows)>30)echo '<a href="'.esc_url(add_query_arg('admin_message_page',$page+1,$base)).'">下一页留言</a>';
    community_author_admin('messages');echo '</div>';
}

function community_author_admin(string $scope): void {
    $label=$scope==='photos'?'照片':'留言';
    echo '<h4>按作者管理</h4><form class="sm-author-controls" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="sports_meet_community_moderate">'.wp_nonce_field('sports_meet_community_moderate','sm_nonce',false,false)
        .'<input type="hidden" name="scope" value="'.esc_attr($scope).'">'
        .'<label>作者<select name="author" data-sm-author-select required><option value="">请选择作者</option>';
    $users=get_users(['fields'=>['ID','display_name'],'orderby'=>'display_name']);
    $schools=community_author_schools(array_column($users,'ID'));
    foreach($users as $user){
        echo '<option value="'.(int)$user->ID.'">'.esc_html(community_author_name($user,$schools).(community_banned((int)$user->ID,$scope)?'（已禁止发布）':'')).'</option>';
    }
    echo '</select></label><div class="sm-moderation-actions"><button name="decision" value="ban_author">'.($scope==='photos'?'禁止上传照片':'禁止发布留言').'</button>'
        .'<button name="decision" value="unban_author">恢复发布权限</button></div>'
        .'<div class="sm-moderation-actions sm-author-delete"><label><input type="checkbox" name="confirm_delete" value="1"> 确认删除该作者的'.$label.'</label>'
        .'<button name="decision" value="delete_author_content">删除作者的'.$label.'</button></div></form>';
}

function community_delete_author_photos(int $author,int $lastId): bool {
    $ids=q('SELECT id FROM photos WHERE owner=? AND id<=? ORDER BY id LIMIT 26',[$author,$lastId])->fetchAll(7);
    foreach(array_slice($ids,0,25) as $id)photo_delete_record((int)$id);
    if(count($ids)<=25)return true;
    $args=[$author,$lastId];
    if(!wp_next_scheduled('sports_meet_delete_author_photos',$args)
        && !wp_schedule_single_event(time()+30,'sports_meet_delete_author_photos',$args)){
        throw new \RuntimeException('未能安排后续照片删除，请在后台再次提交。');
    }
    return false;
}
add_action('sports_meet_delete_author_photos',function(int $author,int $lastId){
    try{community_delete_author_photos($author,$lastId);}
    catch(\Throwable $error){error_log('Sports Meet author photos: '.$error->getMessage());}
},10,2);

add_action('admin_post_sports_meet_community_moderate',function(){
    if(!current_user_can('manage_options'))wp_die('没有管理权限。','',['response'=>403]);
    check_admin_referer('sports_meet_community_moderate','sm_nonce');
    $decision=sanitize_key($_POST['decision']??'');
    $delete=($_POST['confirm_delete']??'')==='1';
    $message=absint($_POST['message_id']??0);
    $author=absint($_POST['author']??0);
    $scope=$_POST['scope']??'';
    $returnTab='messages';
    if($message&&in_array($decision,['hide_message','restore_message','delete_message','pin_message','unpin_message'],true)){
        if($decision==='delete_message'){
            if(!$delete)wp_die('请先勾选确认删除。','',['response'=>400,'back_link'=>true]);
            q('DELETE FROM guest_messages WHERE id=?',[$message]);
        }elseif(in_array($decision,['pin_message','unpin_message'],true)){
            q('UPDATE guest_messages SET pinned=? WHERE id=?',[(int)($decision==='pin_message'),$message]);
        }else q('UPDATE guest_messages SET status=? WHERE id=?',[$decision==='hide_message'?'hidden':'published',$message]);
        photo_notice('留言已更新。');
    }elseif($author&&get_userdata($author)&&in_array($scope,['photos','messages'],true)&&in_array($decision,['ban_author','unban_author','delete_author_content'],true)){
        $returnTab=$scope;
        if($decision==='ban_author'||$decision==='unban_author')update_user_meta($author,'sports_meet_community_banned_'.$scope,$decision==='ban_author'?1:0);
        else{
            if(!$delete)wp_die('请先勾选确认删除。','',['response'=>400,'back_link'=>true]);
            if($scope==='messages'){
                q('DELETE FROM guest_messages WHERE owner=?',[$author]);
                photo_notice('已删除该作者的留言。');
                wp_safe_redirect(view_url($returnTab));exit;
            }
            $lastId=(int)q('SELECT MAX(id) FROM photos WHERE owner=?',[$author])->fetchColumn();
            try{$done=community_delete_author_photos($author,$lastId);}
            catch(\Throwable $error){photo_notice($error->getMessage());wp_safe_redirect(photo_admin_return_url());exit;}
            if(!$done){
                photo_notice('该作者的照片将分批删除。');
                wp_safe_redirect(photo_admin_return_url());exit;
            }
        }
        photo_notice('作者内容设置已更新。');
    }else wp_die('无效操作。','',['response'=>400]);
    $return=add_query_arg(moderation_filters($_POST),view_url('messages'));
    $page=min(100000,absint($_POST['admin_message_page']??0));
    if($page>1)$return=add_query_arg('admin_message_page',$page,$return);
    wp_safe_redirect($returnTab==='photos'?photo_admin_return_url():$return);exit;
});
