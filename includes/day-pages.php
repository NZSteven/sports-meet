<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_day_definitions():array {return ['checkin'=>['检录发号','sports-checkin','sports_meet_checkin'],'heats'=>['比赛录入','sports-prelim','sports_meet_prelim'],'finals'=>['比赛录入','sports-finals','sports_meet_finals']];}
function race_ensure_day_pages():void {
    $pages=get_option('sports_meet_pages',[]);
    foreach(race_day_definitions() as $key=>$def){if(!empty($pages[$key])&&get_post_status($pages[$key])&&get_post_status($pages[$key])!=='trash')continue;
        $id=wp_insert_post(['post_title'=>$def[0],'post_name'=>$def[1],'post_type'=>'page','post_status'=>'publish','post_content'=>'<!-- wp:shortcode -->['.$def[2].']<!-- /wp:shortcode -->','comment_status'=>'closed'],true);if(is_wp_error($id))throw new \RuntimeException('裁判页面创建失败：'.$id->get_error_message());$pages[$key]=$id;update_option('sports_meet_pages',$pages,false);
    }
    if(empty($pages['officials'])||!get_post_status($pages['officials'])||get_post_status($pages['officials'])==='trash'){
        $id=wp_insert_post(['post_title'=>'裁判专区','post_name'=>'sports-officials','post_type'=>'page','post_status'=>'publish','post_content'=>'<!-- wp:shortcode -->[sports_meet_official_portal]<!-- /wp:shortcode -->','comment_status'=>'closed'],true);if(is_wp_error($id))throw new \RuntimeException('裁判入口创建失败。');$pages['officials']=$id;update_option('sports_meet_pages',$pages,false);
    }
}
function race_day_context():string {return $GLOBALS['sports_meet_day_context']??'';}
function race_day_links():string {
    if(!race_can_judge())return '';
    $GLOBALS['sports_meet_official_links_rendered']=true;
    return '<section class="sm-site race-day-links">'.race_judge_navigation().'</section>';
}
function race_judge_navigation(string $active=''):string {
    if($active==='finals'||$active==='heats')$active='rounds';
    if(!empty($_GET['number_ranges']))$active='bib_ranges';
    $links=['checkin'=>['检录发号','搜索选手、筛选高校及项目、发放号码',chief_page_url(['race_tab'=>'checkin'])],'rounds'=>['比赛录入','确认参赛、录入成绩及名次',chief_page_url(['race_tab'=>'rounds'])],'bib_ranges'=>['各校号码区间表','查看实际预留区间、已发及剩余号码',chief_page_url(['race_tab'=>'bib_ranges'])]];
    if(race_can_manage())$links['relay']=['接力组队','裁判长调整校队及混合队',chief_page_url(['race_tab'=>'relay'])];
    return race_section_cards($links,$active,'裁判专区');
}
function home_section():string {return home_section_original();}
function race_checkin_params(?array $source=null):array {
    $source=$source??wp_unslash($_REQUEST);$out=[];foreach(['find','checkin_school','checkin_event','checkin_page'] as $key){$v=$source[$key]??'';if(!is_scalar($v))continue;$v=trim((string)$v);if($key==='find')$v=mb_substr(sanitize_text_field($v),0,120);else $v=ctype_digit($v)&&(int)$v>0?(string)(int)$v:'';if($v!=='')$out[$key]=$v;}return $out;
}
function race_day_page(string $key):string {
    $defs=race_day_definitions();if(!isset($defs[$key]))return '';
    if(!is_user_logged_in())return '<section class="sports-meet race-day-login"><h2>'.esc_html($defs[$key][0]).'</h2><p>请输入裁判账号及密码登录后操作。</p>'.wp_login_form(['echo'=>false,'redirect'=>page_url($key),'label_username'=>'裁判用户名或邮箱','label_password'=>'密码','label_remember'=>'记住登录','label_log_in'=>'登录进入']).'<p>没有裁判账号？请联系管理员创建，角色选择“运动会裁判”。</p></section>';
    if(!race_can_judge())return '<section class="sports-meet"><p class="error">此页面仅供管理员或运动会裁判使用。当前账号没有裁判权限，请联系管理员。</p></section>';
    $previous=race_day_context();$GLOBALS['sports_meet_day_context']=$key;ob_start();
    try{echo '<section class="sports-meet race-workspace race-day-page">'.race_judge_navigation($key);race_notice();if(!empty($_GET['number_ranges']))bib_range_readonly();elseif($key==='checkin')race_ui_checkin();else race_ui_rounds();echo '</section>';return ob_get_clean();}
    catch(\Throwable $e){ob_end_clean();error_log('Sports Meet day: '.$e->getMessage());return '<p>无法显示赛事工作页面，请刷新或联系管理员。</p>';}
    finally{$GLOBALS['sports_meet_day_context']=$previous;}
}
function race_ui_checkin():void {
    bib_range_ui();
    $params=race_checkin_params();$rows=race_roster_rows();$needle=mb_strtolower($params['find']??'');$matched=[];
    $enrolled=isset($params['checkin_event'])
        ?array_fill_keys(q('SELECT athlete_id FROM race_enrollments WHERE event_id=?',[(int)$params['checkin_event']])->fetchAll(7),true)
        :null;
    foreach($rows as $a){if($enrolled!==null&&!isset($enrolled[$a['id']]))continue;if(isset($params['checkin_school'])&&(int)$a['team_id']!==(int)$params['checkin_school'])continue;if($needle!==''&&!str_contains(mb_strtolower($a['name'].' '.$a['team_name'].' '.($a['race_bib']??'')),$needle))continue;$matched[]=$a;}
    $page=max(1,(int)($params['checkin_page']??1));$pages=max(1,(int)ceil(count($matched)/100));$page=min($page,$pages);$visible=array_slice($matched,($page-1)*100,100);$base=is_admin()?admin_url('admin.php'):page_url(race_day_context()?:'judge');
    echo '<h2>检录发号</h2><p>按姓名、比赛号码或高校搜索，也可按所属高校和比赛项目筛选。全场号码唯一，001与1视为同号；多裁判重复或过期提交不会覆盖已发号码。</p><form method="get" class="race-checkin-filter" action="'.esc_url($base).'">';
    if(is_admin())echo '<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races">';elseif(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])[race_day_context()?:'judge']??0).'">';
    echo '<input type="hidden" name="race_tab" value="checkin"><label>搜索<input type="search" name="find" value="'.esc_attr($params['find']??'').'" placeholder="姓名、比赛号码或高校"></label>';race_roster_select('checkin_school','所属高校',all('teams'),$params['checkin_school']??'');race_roster_select('checkin_event','比赛项目',all('events'),$params['checkin_event']??'');echo '<button>搜索／筛选</button><a href="'.esc_url(is_admin()?race_url('checkin'):$base).'">重置</a></form><p class="race-roster-count">匹配 '.count($matched).' 人，共 '.count($rows).' 人；当前第'.$page.'／'.$pages.'页，每页最多100人。</p><div class="scroll"><table class="race-checkin-table"><thead><tr><th>所属高校</th><th>免责声明</th><th>姓名</th><th>组别</th><th>比赛号码／发号</th></tr></thead><tbody>';
    $accepted=disclaimer_acceptances();
    foreach($visible as $a){echo '<tr><td>'.esc_html($a['team_name']).'</td><td>'.disclaimer_dot($a,$accepted).'</td><td>'.esc_html($a['name']).'</td><td>'.esc_html(race_category($a)).'</td><td>';if(disclaimer_dinner_only($a)){/* Dinner-only guests do not require check-in or a bib. */}elseif(!disclaimer_agreed($a,$accepted)){if($a['race_bib'])echo '<b>'.esc_html($a['race_bib']).'</b> · ';echo '<span class="sm-checkin-blocked">未同意，暂不能发号</span>';}elseif($a['race_bib']&&!race_can_manage())echo '<b>'.esc_html($a['race_bib']).'</b> · 已发号';else{race_form('checkin','checkin');echo '<input type="hidden" name="athlete_id" value="'.(int)$a['id'].'"><input type="hidden" name="version" value="'.(int)($a['check_version']??0).'"><label class="race-number-label"><span class="screen-reader-text">'.esc_html($a['name'].'的比赛号码').'</span><input name="bib" value="'.esc_attr($a['race_bib']??'').'" inputmode="numeric" pattern="[0-9]{1,8}" placeholder="比赛号码" required aria-label="'.esc_attr($a['name'].'的比赛号码').'"></label>';if($a['race_bib'])echo '<button type="submit" class="sm-checkin-change">管理员改号</button></form>';else finish('确认发号');}echo '</td></tr>';}
    if(!$visible)echo '<tr><td colspan="5">没有符合条件的选手，请调整搜索或筛选条件。</td></tr>';echo '</tbody></table></div><nav class="race-checkin-pagination">';$args=array_merge(['race_tab'=>'checkin'],$params);if(is_admin())$args=array_merge($args,['page'=>'sports-meet','tab'=>'races']);if($page>1)echo '<a href="'.esc_url(add_query_arg(array_merge($args,['checkin_page'=>$page-1]),$base)).'">← 上一页</a>';if($page<$pages)echo '<a href="'.esc_url(add_query_arg(array_merge($args,['checkin_page'=>$page+1]),$base)).'">下一页 →</a>';echo '</nav>';
}
add_shortcode('sports_meet_checkin',fn()=>race_day_page('checkin'));
add_shortcode('sports_meet_prelim',fn()=>race_day_page('heats'));
add_shortcode('sports_meet_finals',fn()=>race_day_page('heats'));
add_shortcode('sports_meet_race_entry',fn()=>race_day_page('heats'));
add_action('init',function(){
    $id=(int)(get_option('sports_meet_pages',[])['heats']??0);
    if($id&&in_array(get_post_field('post_title',$id),['预赛录入','决赛录入'],true))wp_update_post(['ID'=>$id,'post_title'=>'比赛录入']);
},27);
add_action('template_redirect',function(){
    $pages=get_option('sports_meet_pages',[]);$old=(int)($pages['finals']??0);$new=(int)($pages['heats']??0);
    if(!$old||!$new||$old===$new||!is_page($old))return;
    $args=[];foreach(['round_id','event_id'] as $key)if(!empty($_GET[$key]))$args[$key]=absint($_GET[$key]);
    wp_safe_redirect(add_query_arg($args,page_url('heats')),302);exit;
});
// The previous homepage shortcode is intentionally retired: entries now live in the footer.
add_shortcode('sports_meet_official_links',fn()=>'');
function race_official_portal():string {return chief_page();}
add_shortcode('sports_meet_official_portal',__NAMESPACE__.'\\race_official_portal');
function race_footer_officials():void {
    if(is_admin())return;
    echo '<footer id="sports-meet-official-footer" class="race-official-footer"><nav aria-label="裁判入口">';
    echo '<a class="race-footer-login" href="'.esc_url(chief_page_url()).'">裁判专区</a>';
    echo '</nav></footer>';
}
add_action('wp_footer',__NAMESPACE__.'\\race_footer_officials',5);
add_action('template_redirect',function(){
    $private=is_user_logged_in();$post=is_singular()?get_queried_object():null;
    if($post){$tags=array_column(race_day_definitions(),2);$tags[]='sports_meet_official_portal';foreach($tags as $tag)if(has_shortcode($post->post_content??'',$tag)){$private=true;break;}}
    if($private){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}
});
