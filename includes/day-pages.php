<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_day_definitions():array {return ['checkin'=>['检录发号','sports-checkin','sports_meet_checkin'],'heats'=>['预赛录入','sports-prelim','sports_meet_prelim'],'finals'=>['决赛录入','sports-finals','sports_meet_finals']];}
function race_ensure_day_pages():void {
    $pages=get_option('sports_meet_pages',[]);
    foreach(race_day_definitions() as $key=>$def){if(!empty($pages[$key])&&get_post_status($pages[$key])&&get_post_status($pages[$key])!=='trash')continue;
        $id=wp_insert_post(['post_title'=>$def[0],'post_name'=>$def[1],'post_type'=>'page','post_status'=>'publish','post_content'=>'<!-- wp:shortcode -->['.$def[2].']<!-- /wp:shortcode -->','comment_status'=>'closed'],true);if(is_wp_error($id))throw new \RuntimeException('裁判页面创建失败：'.$id->get_error_message());$pages[$key]=$id;update_option('sports_meet_pages',$pages,false);
    }
    if(empty($pages['officials'])||!get_post_status($pages['officials'])||get_post_status($pages['officials'])==='trash'){
        $id=wp_insert_post(['post_title'=>'裁判入口','post_name'=>'sports-officials','post_type'=>'page','post_status'=>'publish','post_content'=>'<!-- wp:shortcode -->[sports_meet_official_portal]<!-- /wp:shortcode -->','comment_status'=>'closed'],true);if(is_wp_error($id))throw new \RuntimeException('裁判入口创建失败。');$pages['officials']=$id;update_option('sports_meet_pages',$pages,false);
    }
}
function race_day_context():string {return $GLOBALS['sports_meet_day_context']??'';}
function race_day_links():string {
    if(!race_can_judge())return '';
    $GLOBALS['sports_meet_official_links_rendered']=true;
    $html='<section class="sm-site race-day-links"><div class="sm-section-head"><div><p class="sm-eyebrow">OFFICIALS / 赛事工作入口</p><h2>比赛日工作台</h2></div><span>裁判账号及密码登录后使用</span></div><div class="race-day-grid">';
    foreach(race_day_definitions() as $key=>$def)$html.='<a href="'.esc_url(page_url($key)).'"><strong>'.esc_html($def[0]).' ↗</strong><span>'.esc_html(['checkin'=>'搜索选手 · 按高校筛选 · 发放号码','heats'=>'确认参赛 · 录入名次 · 自动晋级','finals'=>'确认决赛 · 录入名次 · 汇总积分'][$key]).'</span><small>需裁判账号密码</small></a>';
    return $html.'</div></section>';
}
function home_section():string {return home_section_original();}
function race_checkin_params(?array $source=null):array {
    $source=$source??wp_unslash($_REQUEST);$out=[];foreach(['find','checkin_school','checkin_page'] as $key){$v=$source[$key]??'';if(!is_scalar($v))continue;$v=trim((string)$v);if($key==='find')$v=mb_substr(sanitize_text_field($v),0,120);else $v=ctype_digit($v)&&(int)$v>0?(string)(int)$v:'';if($v!=='')$out[$key]=$v;}return $out;
}
function race_day_page(string $key):string {
    $defs=race_day_definitions();if(!isset($defs[$key]))return '';
    if(!is_user_logged_in())return '<section class="sports-meet race-day-login"><h2>'.esc_html($defs[$key][0]).'</h2><p>请输入裁判账号及密码登录后操作。</p>'.wp_login_form(['echo'=>false,'redirect'=>page_url($key),'label_username'=>'裁判用户名或邮箱','label_password'=>'密码','label_remember'=>'记住登录','label_log_in'=>'登录进入']).'<p>没有裁判账号？请联系管理员创建，角色选择“运动会裁判”。</p></section>';
    if(!race_can_judge())return '<section class="sports-meet"><p class="error">此页面仅供管理员或运动会裁判使用。当前账号没有裁判权限，请联系管理员。</p></section>';
    $previous=race_day_context();$GLOBALS['sports_meet_day_context']=$key;ob_start();
    try{echo '<section class="sports-meet race-workspace race-day-page"><nav class="race-tabs">';foreach($defs as $k=>$d)echo '<a href="'.esc_url(page_url($k)).'">'.esc_html($d[0]).'</a>';echo '</nav>';race_notice();if($key==='checkin')race_ui_checkin();else race_ui_rounds($key==='heats'?'heat':'final');echo '</section>';return ob_get_clean();}
    catch(\Throwable $e){ob_end_clean();error_log('Sports Meet day: '.$e->getMessage());return '<p>无法显示赛事工作页面，请刷新或联系管理员。</p>';}
    finally{$GLOBALS['sports_meet_day_context']=$previous;}
}
function race_ui_checkin():void {
    $params=race_checkin_params();$rows=race_roster_rows();$needle=mb_strtolower($params['find']??'');$matched=[];
    foreach($rows as $a){if(isset($params['checkin_school'])&&(int)$a['team_id']!==(int)$params['checkin_school'])continue;if($needle!==''&&!str_contains(mb_strtolower($a['name'].' '.$a['team_name'].' '.($a['race_bib']??'')),$needle))continue;$matched[]=$a;}
    $page=max(1,(int)($params['checkin_page']??1));$pages=max(1,(int)ceil(count($matched)/100));$page=min($page,$pages);$visible=array_slice($matched,($page-1)*100,100);$base=is_admin()?admin_url('admin.php'):page_url(race_day_context()?:'judge');
    echo '<h2>检录发号</h2><p>按姓名、比赛号码或高校搜索，也可先筛选所属高校。全场号码唯一，001与1视为同号；多裁判重复或过期提交不会覆盖已发号码。</p><form method="get" class="race-checkin-filter" action="'.esc_url($base).'">';
    if(is_admin())echo '<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races">';elseif(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])[race_day_context()?:'judge']??0).'">';
    echo '<input type="hidden" name="race_tab" value="checkin"><label>搜索<input type="search" name="find" value="'.esc_attr($params['find']??'').'" placeholder="姓名、比赛号码或高校"></label>';race_roster_select('checkin_school','所属高校',all('teams'),$params['checkin_school']??'');echo '<button>搜索／筛选</button><a href="'.esc_url(is_admin()?race_url('checkin'):$base).'">重置</a></form><p class="race-roster-count">匹配 '.count($matched).' 人，共 '.count($rows).' 人；当前第'.$page.'／'.$pages.'页，每页最多100人。</p><div class="scroll"><table class="race-checkin-table"><thead><tr><th>所属高校</th><th>免责声明</th><th>姓名</th><th>组别</th><th>比赛号码／发号</th></tr></thead><tbody>';
    $accepted=disclaimer_acceptances();
    foreach($visible as $a){echo '<tr><td>'.esc_html($a['team_name']).'</td><td>'.disclaimer_dot($a,$accepted).'</td><td>'.esc_html($a['name']).'</td><td>'.esc_html(race_category($a)).'</td><td>';if(!disclaimer_agreed($a,$accepted)){if($a['race_bib'])echo '<b>'.esc_html($a['race_bib']).'</b> · ';echo '<span class="sm-checkin-blocked">未同意，暂不能发号</span>';}elseif($a['race_bib']&&!current_user_can('manage_options'))echo '<b>'.esc_html($a['race_bib']).'</b> · 已发号';else{race_form('checkin','checkin');echo '<input type="hidden" name="athlete_id" value="'.(int)$a['id'].'"><input type="hidden" name="version" value="'.(int)($a['check_version']??0).'"><label class="race-number-label"><span class="screen-reader-text">'.esc_html($a['name'].'的比赛号码').'</span><input name="bib" value="'.esc_attr($a['race_bib']??'').'" inputmode="numeric" pattern="[0-9]{1,8}" placeholder="比赛号码" required aria-label="'.esc_attr($a['name'].'的比赛号码').'"></label>';if($a['race_bib'])echo '<button type="submit" class="sm-checkin-change">管理员改号</button></form>';else finish('确认发号');}echo '</td></tr>';}
    if(!$visible)echo '<tr><td colspan="5">没有符合条件的选手，请调整搜索或高校筛选。</td></tr>';echo '</tbody></table></div><nav class="race-checkin-pagination">';$args=array_merge(['race_tab'=>'checkin'],$params);if(is_admin())$args=array_merge($args,['page'=>'sports-meet','tab'=>'races']);if($page>1)echo '<a href="'.esc_url(add_query_arg(array_merge($args,['checkin_page'=>$page-1]),$base)).'">← 上一页</a>';if($page<$pages)echo '<a href="'.esc_url(add_query_arg(array_merge($args,['checkin_page'=>$page+1]),$base)).'">下一页 →</a>';echo '</nav>';
}
add_shortcode('sports_meet_checkin',fn()=>race_day_page('checkin'));
add_shortcode('sports_meet_prelim',fn()=>race_day_page('heats'));
add_shortcode('sports_meet_finals',fn()=>race_day_page('finals'));
// The previous homepage shortcode is intentionally retired: entries now live in the footer.
add_shortcode('sports_meet_official_links',fn()=>'');
function race_official_portal():string {
    if(!is_user_logged_in())return '<section class="sports-meet race-day-login"><h2>裁判登录</h2><p>请输入裁判账号和密码。</p>'.wp_login_form(['echo'=>false,'redirect'=>page_url('officials'),'label_username'=>'裁判用户名或邮箱','label_password'=>'密码','label_remember'=>'记住登录','label_log_in'=>'登录']).'</section>';
    if(!race_can_judge())return '<section class="sports-meet"><p class="error">当前账号没有裁判权限，请联系管理员分配“运动会裁判”角色。</p><a href="'.esc_url(wp_logout_url(page_url('officials'))).'">退出并更换账号</a></section>';
    return race_day_links();
}
add_shortcode('sports_meet_official_portal',__NAMESPACE__.'\\race_official_portal');
function race_footer_officials():void {
    if(is_admin())return;
    echo '<footer id="sports-meet-official-footer" class="race-official-footer"><nav aria-label="裁判入口">';
    if(race_can_judge()){echo '<span>裁判工作：</span>';foreach(race_day_definitions() as $key=>$def)echo '<a href="'.esc_url(page_url($key)).'">'.esc_html($def[0]).'</a>';}
    else echo '<a class="race-footer-login" href="'.esc_url(wp_login_url(page_url('officials'))).'">裁判登录</a>';
    echo '</nav></footer>';
}
add_action('wp_footer',__NAMESPACE__.'\\race_footer_officials',5);
add_action('template_redirect',function(){
    $private=is_user_logged_in();$post=is_singular()?get_queried_object():null;
    if($post){$tags=array_column(race_day_definitions(),2);$tags[]='sports_meet_official_portal';foreach($tags as $tag)if(has_shortcode($post->post_content??'',$tag)){$private=true;break;}}
    if($private){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}
});
