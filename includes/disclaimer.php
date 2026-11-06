<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function install_disclaimer(): void {
    q('CREATE TABLE IF NOT EXISTS athlete_accounts (
        athlete_id INT NOT NULL PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL UNIQUE,
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    q('CREATE TABLE IF NOT EXISTS disclaimer_acceptances (
        athlete_id INT NOT NULL, statement_hash CHAR(64) NOT NULL, identity_hash CHAR(64) NOT NULL,
        actor BIGINT UNSIGNED NOT NULL, accepted_at DATETIME NOT NULL,
        PRIMARY KEY (athlete_id,statement_hash,identity_hash),
        FOREIGN KEY (athlete_id) REFERENCES athletes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pages=get_option('sports_meet_pages',[]);
    if(empty($pages['disclaimer']) || get_post_status($pages['disclaimer'])!=='publish'){
        $page=get_page_by_path('sports-disclaimer');
        if($page && $page->post_status==='publish' && has_shortcode($page->post_content,'sports_meet_disclaimer')){
            $pages['disclaimer']=$page->ID;
        }else{
            $id=wp_insert_post(['post_title'=>'免责声明','post_name'=>'sports-disclaimer','post_type'=>'page',
                'post_status'=>'publish','post_content'=>'<!-- wp:shortcode -->[sports_meet_disclaimer]<!-- /wp:shortcode -->'],true);
            if(is_wp_error($id)||!$id)throw new \RuntimeException('无法创建免责声明页面。');
            $pages['disclaimer']=$id;
        }
        update_option('sports_meet_pages',$pages,false);
    }
    $default=trim(file_get_contents(dirname(__DIR__).'/media/disclaimer.txt'));
    add_option('sports_meet_disclaimer_text',$default,'',false);
    add_option('sports_meet_disclaimer_'.hash('sha256',$default),$default,'',false);
    update_option('sports_meet_disclaimer_ready',1,false);
}

function disclaimer_text(): string {return (string)get_option('sports_meet_disclaimer_text','');}

function disclaimer_identity(array $athlete): string {
    return hash('sha256',(int)$athlete['team_id'].':'.$athlete['name']);
}

function disclaimer_acceptances(?int $athleteId=null): array {
    $text=disclaimer_text();
    if($text===''||!get_option('sports_meet_disclaimer_ready'))return [];
    $sql='SELECT athlete_id,identity_hash FROM disclaimer_acceptances WHERE statement_hash=?';
    $args=[hash('sha256',$text)];
    if($athleteId!==null){$sql.=' AND athlete_id=?';$args[]=$athleteId;}
    $rows=q($sql,$args)->fetchAll();
    $accepted=[];
    foreach($rows as $row)$accepted[(int)$row['athlete_id']][$row['identity_hash']]=true;
    return $accepted;
}

function disclaimer_agreed(array $athlete,array $accepted): bool {
    return isset($accepted[(int)$athlete['id']][disclaimer_identity($athlete)]);
}

function disclaimer_dot(array $athlete,array $accepted): string {
    $yes=disclaimer_agreed($athlete,$accepted);
    $label=$yes?'已同意免责声明':'未同意免责声明';
    return '<span class="sm-consent-dot'.($yes?' is-agreed':'').'" role="img" aria-label="'.$label.'" title="'.$label.'"></span>';
}

function disclaimer_page(): string {
    $text=disclaimer_text();
    $html='<section class="sports-meet sm-disclaimer">';
    if($text===''||!get_option('sports_meet_disclaimer_ready'))return $html.'<p>免责声明尚未发布。</p></section>';
    if(!get_option('sports_meet_community_ready'))return $html.'<p>会员注册与签署正在更新，请稍后再试。</p></section>';
    $html.='<div class="sm-disclaimer-text">'.wpautop(esc_html($text)).'</div>';
    if(!is_user_logged_in())return $html.'<p>有账号请登录。已报名的参赛者也可以在这里注册。</p>'
        .wp_login_form(['echo'=>false,'redirect'=>page_url('disclaimer'),'label_log_in'=>'登录']).athlete_signup_form().'</section>';

    $athlete=q('SELECT a.id,a.name,a.team_id,t.name AS team_name FROM athlete_accounts aa
        JOIN athletes a ON a.id=aa.athlete_id JOIN teams t ON t.id=a.team_id WHERE aa.user_id=?',[get_current_user_id()])->fetch();
    $accepted=$athlete?disclaimer_acceptances((int)$athlete['id']):[];
    if($athlete&&disclaimer_agreed($athlete,$accepted)){
        return $html.'<p role="status" class="sm-message">'.disclaimer_dot($athlete,$accepted)
            .' 已同意免责声明，无需再次提交。<br>'.esc_html($athlete['team_name'].' · '.$athlete['name']).'</p></section>';
    }
    $html.=photo_notice_html().'<form class="sm-disclaimer-form" method="post" action="'.esc_url(admin_url('admin-post.php','relative')).'">'
        .'<input type="hidden" name="action" value="sports_meet_accept_disclaimer">'
        .'<input type="hidden" name="statement_hash" value="'.hash('sha256',$text).'">'
        .wp_nonce_field('sports_meet_accept_disclaimer','sm_nonce',false,false)
        .athlete_account_fields(get_current_user_id());
    return $html.'<label><input type="checkbox" name="agree" value="1" required> 本人是所选运动员，并同意以上的免责声明</label>'
        .'<button type="submit">提交同意</button></form></section>';
}
add_shortcode('sports_meet_disclaimer',__NAMESPACE__.'\\disclaimer_page');

function record_disclaimer_acceptance(array $athlete,string $hash,int $user): void {
    q('INSERT INTO disclaimer_acceptances (athlete_id,statement_hash,identity_hash,actor,accepted_at) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE accepted_at=accepted_at',[(int)$athlete['id'],$hash,disclaimer_identity($athlete),$user,current_time('mysql')]);
}

function disclaimer_accept(): void {
    if(!is_user_logged_in()||!current_user_can('read'))wp_die('请先登录。','',['response'=>403]);
    check_admin_referer('sports_meet_accept_disclaimer','sm_nonce');
    $text=disclaimer_text();
    $hash=hash('sha256',$text);
    if($text===''||!get_option('sports_meet_disclaimer_ready')||($_POST['statement_hash']??'')!==$hash){
        photo_notice('免责声明已更新，请阅读后重新提交。');
    }elseif(($_POST['agree']??'')!=='1'){
        photo_notice('请先确认本人同意免责声明。');
    }else{
        $school=$_POST['school_id']??'';
        $id=$_POST['athlete_id']??'';
        $athlete=is_string($id)&&ctype_digit($id)&&is_string($school)&&ctype_digit($school)
            ?q('SELECT id,name,team_id FROM athletes WHERE id=? AND team_id=?',[(int)$id,(int)$school])->fetch():false;
        if(!$athlete){
            photo_notice('请选择相符的高校和运动员姓名。');
        }else{
            try {
                race_transaction(function() use($athlete,$hash){
                    $user=get_current_user_id();
                    bind_athlete_account((int)$athlete['id'],$user);
                    record_disclaimer_acceptance($athlete,$hash,$user);
                });
            } catch(\Throwable $error) {photo_notice($error->getMessage());}
        }
    }
    wp_safe_redirect(page_url('disclaimer'));exit;
}
add_action('admin_post_sports_meet_accept_disclaimer',__NAMESPACE__.'\\disclaimer_accept');
add_action('admin_post_nopriv_sports_meet_accept_disclaimer',__NAMESPACE__.'\\disclaimer_accept');

function disclaimer_admin(): void {
    echo '<h2>免责声明设置</h2>'.photo_notice_html()
        .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="sports_meet_save_disclaimer">'
        .wp_nonce_field('sports_meet_save_disclaimer','sm_nonce',false,false)
        .'<label>免责声明正文<textarea name="statement" rows="12" maxlength="20000" class="large-text">'.esc_textarea(disclaimer_text()).'</textarea></label>'
        .'<p>保存新正文后，参赛者需同意当前版本。留空时暂停收集同意。</p><button type="submit">保存正文</button></form>';
}

add_action('admin_post_sports_meet_save_disclaimer',function(){
    if(!current_user_can('manage_options'))wp_die('没有管理权限。','',['response'=>403]);
    check_admin_referer('sports_meet_save_disclaimer','sm_nonce');
    $value=$_POST['statement']??'';
    if(!is_string($value))wp_die('正文格式无效。','',['response'=>400]);
    $text=mb_substr(sanitize_textarea_field(wp_unslash($value)),0,20000);
    if($text!=='')add_option('sports_meet_disclaimer_'.hash('sha256',$text),$text,'',false);
    update_option('sports_meet_disclaimer_text',$text,false);
    photo_notice('免责声明正文已保存。');
    wp_safe_redirect(view_url('disclaimer'));exit;
});

add_filter('wp_nav_menu_items',function(string $items,$args): string {
    if(($args->theme_location??'')!=='primary')return $items;
    $pageId=(int)(get_option('sports_meet_pages',[])['disclaimer']??0);
    if(!$pageId||get_post_status($pageId)!=='publish')return $items;
    $url=esc_url(get_permalink($pageId));
    if(str_contains($items,'href="'.$url.'"')||str_contains($items,"href='".$url."'"))return $items;
    return $items.'<li class="menu-item"><a href="'.$url.'">免责声明</a></li>';
},10,2);

add_action('wp_enqueue_scripts',function(){
    wp_enqueue_script('sports-meet-disclaimer',plugins_url('disclaimer.js',dirname(__DIR__).'/sports-meet.php'),[],'2.5.18',true);
});
