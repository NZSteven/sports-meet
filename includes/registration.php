<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_registration_closed(?int $now=null): bool {
    $deadline=new \DateTimeImmutable('2026-11-12 00:00:00',new \DateTimeZone('Pacific/Auckland'));
    return ($now??time()) >= $deadline->getTimestamp();
}

function race_registration_test_mode(): bool {
    $end=new \DateTimeImmutable('2026-11-09 00:00:00',new \DateTimeZone('Pacific/Auckland'));
    return time()<$end->getTimestamp()&&(bool)get_option('sports_meet_registration_test_mode',true);
}

function race_roster_is_public(): bool {
    return true;
}

add_action('admin_post_sports_meet_registration_test_mode',function(){
    if(!current_user_can('manage_options'))wp_die('需要管理员权限。','',['response'=>403]);
    check_admin_referer('sports_meet_registration_test_mode');
    update_option('sports_meet_registration_test_mode',!empty($_POST['enabled']),false);
    wp_safe_redirect(race_url('registration'));exit;
});

function race_registration_deadline_label(): string {return '新西兰时间 2026 年 11 月 12 日 00:00';}

function race_school_session_key(): string {
    $token=wp_get_session_token();
    return $token!==''?'sm_school_access_'.hash('sha256',$token):'';
}

function race_school_access(): int {
    if(race_registration_closed()||!current_user_can('sports_meet_school_registration'))return 0;
    $key=race_school_session_key();
    if($key==='')return 0;
    $grant=get_transient($key);
    if(!is_array($grant)||empty($grant['school'])||empty($grant['version']))return 0;
    $id=(int)$grant['school'];
    $hash=(string)get_option('sports_meet_school_password_'.$id,'');
    return $hash!==''&&hash_equals(hash('sha256',$hash),$grant['version'])?$id:0;
}

function race_school_admin(array $teams): void {
    echo '<details><summary>高校报名密码</summary><p>在 WordPress 用户管理中创建一个「高校报名共用账号」。在此为每所高校生成独立密码，生成后请私下转交学校负责人。重新生成会使该校旧密码及已解锁会话失效。</p>'
        .'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_issue_school_password">'
        .wp_nonce_field('sports_meet_issue_school_password','sm_nonce',false,false);
    race_options('school_id','所属高校',$teams);
    echo '<button>生成／重置所选高校密码</button></form></details>';
}

add_action('admin_post_sports_meet_issue_school_password',function(){
    if(!current_user_can('manage_options'))wp_die('需要管理员权限。','',['response'=>403]);
    check_admin_referer('sports_meet_issue_school_password','sm_nonce');
    if(race_registration_closed())wp_die('报名已截止。','',['response'=>403]);
    $id=absint($_POST['school_id']??0);
    $school=q('SELECT name FROM teams WHERE id=?',[$id])->fetchColumn();
    if(!$school)wp_die('请选择有效高校。','',['response'=>400,'back_link'=>true]);
    $password=wp_generate_password(24,false);
    $hash=wp_hash_password($password);
    if(!update_option('sports_meet_school_password_'.$id,$hash,false))wp_die('密码保存失败，请重试。','',['response'=>500]);
    nocache_headers();
    header('Referrer-Policy: no-referrer');
    wp_die('<p>'.esc_html($school).'的报名密码，仅在此显示一次。</p><p><code>'.esc_html($password).'</code></p>'
        .'<p>报名地址 <a href="'.esc_url(page_url('new_registration')).'">'.esc_html(page_url('new_registration')).'</a></p>'
        .'<p><a href="'.esc_url(race_url('registration')).'">返回报名管理</a></p>','高校报名密码',['response'=>200]);
});

add_action('admin_post_sports_meet_unlock_school',function(){
    if(!current_user_can('sports_meet_school_registration')||race_registration_closed())wp_die('高校报名暂不可用。','',['response'=>403]);
    check_admin_referer('sports_meet_unlock_school','sm_nonce');
    $key=race_school_session_key();
    if($key==='')wp_die('请重新登录。','',['response'=>403]);
    delete_transient($key);
    $failed=$key.'_failed';
    if(($_POST['school_decision']??'')!=='lock'){
        $id=absint($_POST['school_id']??0);
        $password=wp_unslash($_POST['school_password']??'');
        $hash=(string)get_option('sports_meet_school_password_'.$id,'');
        $attempts=(int)get_transient($failed);
        if($attempts>=8)wp_die('尝试次数过多，请 15 分钟后重试。','',['response'=>429,'back_link'=>true]);
        if(!$id||$hash===''||!is_string($password)||strlen($password)>128||!wp_check_password($password,$hash)){
            set_transient($failed,$attempts+1,15*MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('school_error','1',page_url('new_registration')));exit;
        }
        delete_transient($failed);
        set_transient($key,['school'=>$id,'version'=>hash('sha256',$hash)],8*HOUR_IN_SECONDS);
    }
    wp_safe_redirect(page_url('new_registration'));exit;
});

function race_school_registration_input(array $input): array {
    $school=race_school_access();
    if(!$school||($input['race_action']??'')!=='registration'||($input['day_page']??'')!=='new_registration'
        ||($input['frontend']??'')!=='1'){
        throw new \RuntimeException('请先在报名页面验证本校密码。');
    }
    $id=absint($input['id']??0);
    if($id&&!q('SELECT id FROM athletes WHERE id=? AND team_id=?',[$id,$school])->fetch()){
        throw new \RuntimeException('只能修改本校报名。');
    }
    $input['team_id']=$school;
    $input['id']=$id;
    return $input;
}

function race_school_registration_view(): void {
    $school=race_school_access();
    $form='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="sports_meet_unlock_school">'
        .wp_nonce_field('sports_meet_unlock_school','sm_nonce',false,false);
    if(!$school){
        if(isset($_GET['school_error']))echo '<p role="alert">高校或报名密码不正确。</p>';
        echo $form;
        race_options('school_id','所属高校',all('teams'));
        echo '<label>高校报名密码<input type="password" name="school_password" maxlength="128" required></label><button>进入本校报名</button></form>';
        return;
    }
    $team=q('SELECT name FROM teams WHERE id=?',[$school])->fetchColumn();
    if(!$team){echo '<p>高校已不存在，请联系组委会。</p>';return;}
    echo '<p>当前所属高校 <strong>'.esc_html($team).'</strong></p>'.$form
        .'<button name="school_decision" value="lock">锁定本校报名</button></form>';
    $id=absint($_GET['edit_athlete']??0);
    $edit=$id?q('SELECT * FROM athletes WHERE id=? AND team_id=?',[$id,$school])->fetch():[];
    if($id&&!$edit)echo '<p role="alert">未找到本校的这条报名。</p>';
    else race_registration_form([],all('events'),$edit,true,$school);
    echo '<h2>本校报名名单</h2>';
    $rows=q('SELECT * FROM athletes WHERE team_id=? ORDER BY name,id',[$school])->fetchAll();
    if(!$rows){echo '<p>本校暂无报名。</p>';return;}
    $accepted=disclaimer_acceptances();echo '<div class="scroll"><table><thead><tr><th class="sm-consent-cell">免责声明</th><th>姓名</th><th>组别</th><th>项目</th><th>操作</th></tr></thead><tbody>';
    foreach($rows as $row){
        $url=add_query_arg('edit_athlete',(int)$row['id'],page_url('new_registration'));
        echo '<tr><td class="sm-consent-cell">'.disclaimer_dot($row,$accepted).'</td><td>'.esc_html($row['name']).'</td><td>'.esc_html(race_category($row)).'</td><td>'
            .esc_html(race_event_names((int)$row['id'])).'</td><td><a href="'.esc_url($url).'">编辑</a></td></tr>';
    }
    echo '</tbody></table></div>';
}

add_action('template_redirect',function(){
    if(!is_singular())return;
    $page=get_queried_object();
    if(!$page instanceof \WP_Post)return;
    foreach(['sports_meet_roster','sports_meet_disclaimer','sports_meet_hub','sports_meet_results','sports_meet_board'] as $shortcode){
        if(!has_shortcode($page->post_content,$shortcode))continue;
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        nocache_headers();
        break;
    }
});

function race_categories():array {return ['成年男子'=>['M','成年'],'成年女子'=>['F','成年'],'少年男子'=>['M','少年'],'少年女子'=>['F','少年']];}
function race_category(array $a):string {$young=preg_match('/少年|青少年|小学|中学/u',$a['age_group']??'');return ($young?'少年':'成年').(($a['gender']??'M')==='F'?'女子':'男子');}
function race_group_matches(string $required,string $actual,string $gender):bool {
    if($required===''||$required===$actual)return true;
    $base=function($g){if(preg_match('/^(成年|成人)(组|男子|女子)?$/u',$g))return '成年';if(preg_match('/^(少年|青少年)(组|男子|女子)?$/u',$g))return '少年';return $g;};
    if(str_contains($required,'男子')&&$gender!=='M')return false;if(str_contains($required,'女子')&&$gender!=='F')return false;
    return $base($required)===$base($actual);
}
function race_event_categories(array $event):array {
    $allowed=[];$gender=$event['gender']??'X';$age=$event['age_group']??'';$name=$event['name']??'';$namedGender='X';$namedAge='';
    if(($event['kind']??'individual')!=='relay') {if(str_contains($name,'女子')&&!str_contains($name,'男子'))$namedGender='F';elseif(str_contains($name,'男子')&&!str_contains($name,'女子'))$namedGender='M';}
    $young=(bool)preg_match('/少年|青少年/u',$name);$adult=(bool)preg_match('/成年|成人/u',$name);if($young&&!$adult)$namedAge='少年';elseif($adult&&!$young)$namedAge='成年';
    foreach(race_categories() as $category=>$spec)if(($gender==='X'||$gender===$spec[0])&&($namedGender==='X'||$namedGender===$spec[0])&&race_group_matches($age,$spec[1],$spec[0])&&race_group_matches($namedAge,$spec[1],$spec[0]))$allowed[]=$category;
    return $allowed;
}
function race_registration_save(array $p):int {
    relay_manual_registration_guard((int)($p['id']??0),$p);
    $category=race_text($p['category']??'',20);$spec=race_categories()[$category]??null;if(!$spec)throw new \RuntimeException('请选择成年男子、成年女子、少年男子或少年女子。');
    $activities=$p['activities']??[];
    if(!is_array($activities)||count($activities)>3)throw new \RuntimeException('活动报名选项无效。');
    foreach($activities as $activity)if(!is_string($activity)||!isset(registration_extra_options()[$activity]))throw new \RuntimeException('活动报名选项无效。');
    if(!isset($p['event_ids']))$p['event_ids']=[];
    if(!is_array($p['event_ids']))throw new \RuntimeException('参赛项目格式错误。');
    $selected=[];foreach($p['event_ids'] as $v){if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<1)throw new \RuntimeException('参赛项目格式错误。');$e=race_event((int)$v);if(!in_array($category,race_event_categories($e),true))throw new \RuntimeException($e['name'].'不适用于'.$category.'，请重新选择项目。');$selected[(int)$e['id']]=true;}if(!$selected&&!$activities)throw new \RuntimeException('请至少勾选一个比赛项目或晚宴。');
    $id=(int)($p['id']??0);$old=$id?race_one('athletes',$id):null;$group=$spec[1];
    if($old && $old['age_group']!=='' && race_category($old)===$category)$group=$old['age_group'];
    if($old)$key=$old['bib'];else{$token=(string)($p['registration_token']??'');if(!preg_match('/^[a-f0-9-]{36}$/',$token))throw new \RuntimeException('报名页面已过期，请刷新重试。');$key='AUTO-'.str_replace('-','',$token);if(q('SELECT id FROM athletes WHERE bib=?',[$key])->fetch())throw new \RuntimeException('此报名已提交，请从报名名单点击编辑。');}
    $current=$old?q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll(7):[];$existing=array_fill_keys(array_map('intval',$current),true);
    foreach($existing as $event=>$yes)if(!isset($selected[$event])){race_unstarted((int)$event);q('DELETE FROM race_members WHERE event_id=? AND athlete_id=?',[$event,$id]);q('DELETE FROM race_enrollments WHERE event_id=? AND athlete_id=?',[$event,$id]);}
    $id=race_athlete_save(['id'=>$id,'registration_id'=>$key,'name'=>$p['name']??'','team_id'=>$p['team_id']??0,'gender'=>$spec[0],'age_group'=>$group]);
    foreach($selected as $event=>$yes)if(!isset($existing[$event]))race_enroll((int)$event,$id);
    if(isset($p['extras_present'])){
        q('DELETE FROM registration_extras WHERE athlete_id=?',[$id]);
        foreach(array_unique($activities) as $activity)q('INSERT INTO registration_extras(athlete_id,activity) VALUES(?,?)',[$id,$activity]);
    }
    race_audit('registration',$id,['events'=>array_keys($selected),'category'=>$category,'activities'=>$activities]);return $id;
}
function race_import_csv(string $path,bool $update=false):int {
    $f=fopen($path,'r');if(!$f)throw new \RuntimeException('无法读取CSV。');$temp=null;
    try{$head=fgetcsv($f,0,',','"','');if(!$head)throw new \RuntimeException('CSV为空。');$head[0]=preg_replace('/^\xEF\xBB\xBF/','',$head[0]);
        if($head===['team','registration_id','name','gender','age_group','event','kind','relay_name'])return race_import_legacy_csv($path,$update);
        if($head!==['university','name','category','event','kind','relay_name'])throw new \RuntimeException('请使用新版6列报名模板。');
        $temp=tmpfile();if(!$temp)throw new \RuntimeException('无法建立导入临时文件。');fputcsv($temp,['team','registration_id','name','gender','age_group','event','kind','relay_name'],',','"','');$line=1;
        while(($r=fgetcsv($f,0,',','"',''))!==false){$line++;if($r===[null])continue;if($line>10001)throw new \RuntimeException('每批最多10000行。');if(count($r)!==6)throw new \RuntimeException('CSV第'.$line.'行列数不符。');foreach($r as $v)if(!mb_check_encoding($v,'UTF-8'))throw new \RuntimeException('请保存为UTF-8 CSV。');$d=array_map('trim',array_combine($head,$r));$spec=race_categories()[$d['category']]??null;if(!$spec)throw new \RuntimeException('CSV第'.$line.'行组别无效。');
            $matches=array_values(array_filter(q('SELECT a.* FROM athletes a JOIN teams t ON t.id=a.team_id WHERE t.name=? AND a.name=? AND a.gender=?',[$d['university'],$d['name'],$spec[0]])->fetchAll(),fn($a)=>race_category($a)===$d['category']));
            if(count($matches)>1)throw new \RuntimeException('CSV第'.$line.'行有同高校、同姓名、同组别的多人，请先在后台核对，未自动合并。');
            $key=$matches?$matches[0]['bib']:'U-'.substr(hash('sha256',wp_json_encode([mb_strtolower($d['university']),mb_strtolower($d['name']),$d['category']])),0,32);$group=$matches?$matches[0]['age_group']:$spec[1];
            fputcsv($temp,[$d['university'],$key,$d['name'],$spec[0],$group,$d['event'],$d['kind'],$d['relay_name']],',','"','');
        }fflush($temp);$meta=stream_get_meta_data($temp);return race_import_legacy_csv($meta['uri'],$update);
    }finally{fclose($f);if($temp)fclose($temp);}
}
function race_registration_export(string $type):void {
    $head=['university','name','category','event','kind','relay_name'];
    if($type==='template')downloadCsv('registration-template',$head,[['中山大学','Steven Lee','成年男子','男子50米','individual',''],['中山大学','Steven Lee','成年男子','8×50米混合接力','relay','']]);
    $rows=[];foreach(q('SELECT a.*,t.name AS university,e.name AS event_name,e.kind,COALESCE(r.name,?) AS relay_name FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id JOIN events e ON e.id=n.event_id LEFT JOIN race_members m ON m.athlete_id=a.id AND m.event_id=e.id LEFT JOIN race_relays r ON r.id=m.relay_id ORDER BY a.id,e.id',[''])->fetchAll() as $r)$rows[]=[$r['university'],$r['name'],race_category($r),$r['event_name'],$r['kind'],$r['relay_name']];downloadCsv('registrations',$head,$rows);
}
