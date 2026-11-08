<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function install_registration_extras():void {
    q('CREATE TABLE IF NOT EXISTS registration_extras (athlete_id INT NOT NULL, activity VARCHAR(24) NOT NULL, PRIMARY KEY(athlete_id,activity), FOREIGN KEY(athlete_id) REFERENCES athletes(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function registration_extra_options():array {return ['three_legged'=>'2人3足比赛','tug_of_war'=>'拔河比赛','dinner'=>'晚宴'];}
function registration_extra_names(int $id):string {
    $labels=registration_extra_options();
    return implode('、',array_map(fn($key)=>$labels[$key]??'',q('SELECT activity FROM registration_extras WHERE athlete_id=? ORDER BY activity',[$id])->fetchAll(7)));
}
function registration_extras_fields(int $id=0):void {
    $selected=$id?q('SELECT activity FROM registration_extras WHERE athlete_id=?',[$id])->fetchAll(7):[];
    echo '<fieldset class="race-activities-picker"><legend>活动报名（仅统计人数，不计分、不安排比赛分组）</legend><input type="hidden" name="extras_present" value="1"><div class="race-event-grid">';
    foreach(registration_extra_options() as $key=>$label)echo '<label class="race-event-choice"><input type="checkbox" name="activities[]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'><span>'.esc_html($label).'</span></label>';
    echo '</div></fieldset>';
}
function team_portal_token():string {
    $token=$_COOKIE['sports_meet_school_portal']??'';
    return is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)?$token:'';
}
function team_portal_grant():array {
    $token=team_portal_token();if($token==='')return [];
    $grant=get_transient('sm_portal_'.hash('sha256',$token));
    if(!is_array($grant)||empty($grant['school'])||empty($grant['version']))return [];
    $hash=(string)get_option('sports_meet_school_password_'.(int)$grant['school'],'');
    return $hash!==''&&hash_equals(hash('sha256',$hash),$grant['version'])?$grant:[];
}
function team_portal_start(int $school,string $hash):void {
    $token=bin2hex(random_bytes(32));
    set_transient('sm_portal_'.hash('sha256',$token),['school'=>$school,'version'=>hash('sha256',$hash)],8*HOUR_IN_SECONDS);
    setcookie('sports_meet_school_portal',$token,['expires'=>time()+8*HOUR_IN_SECONDS,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
    $_COOKIE['sports_meet_school_portal']=$token;
}
function team_portal_form(string $operation):void {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_team_portal"><input type="hidden" name="operation" value="'.esc_attr($operation).'">'
        .wp_nonce_field('sm_team_portal_'.$operation.($operation==='unlock'?'':team_portal_token()),'portal_nonce',false,false);
}
function team_portal_password(int $school,string $password,bool $create):string {
    race_one('teams',$school);
    if(race_registration_closed()&&$create)throw new \RuntimeException('报名已截止，无法创建报名密码。');
    $option='sports_meet_school_password_'.$school;
    $hash=(string)get_option($option,'');
    if($hash===''){
        if(!$create)throw new \RuntimeException('本校尚未设置密码，请选择首次创建。');
        if(strlen($password)<10||strlen($password)>128)throw new \RuntimeException('密码须为10至128个字符。');
        $hash=wp_hash_password($password);
        if(!add_option($option,$hash,'',false))throw new \RuntimeException('其他负责人刚设置了密码，请用已设置的密码登录。');
    }elseif($create)throw new \RuntimeException('本校已有报名密码，请向本校负责人索取，或联系组委会重置。');
    elseif(!wp_check_password($password,$hash))throw new \RuntimeException('高校或报名密码不正确。');
    return $hash;
}
function team_portal_save(array $input,int $school):int {
    if(race_registration_closed())throw new \RuntimeException('报名已截止，不能修改或删除。');
    race_one('teams',$school);
    $id=absint($input['id']??0);
    if($id&&!q('SELECT id FROM athletes WHERE id=? AND team_id=?',[$id,$school])->fetch())throw new \RuntimeException('只能操作本校报名。');
    if(($input['operation']??'')==='delete'){
        if(!$id||empty($input['delete_confirm']))throw new \RuntimeException('请确认删除本条报名。');
        foreach(q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll(7) as $event)race_unstarted((int)$event);
        if(q('SELECT id FROM race_entries WHERE athlete_id=? LIMIT 1',[$id])->fetch()||q('SELECT id FROM results WHERE athlete_id=? LIMIT 1',[$id])->fetch())throw new \RuntimeException('该报名已有比赛记录，请联系管理员。');
        q('DELETE FROM race_members WHERE athlete_id=?',[$id]);q('DELETE FROM race_enrollments WHERE athlete_id=?',[$id]);q('DELETE FROM checkins WHERE athlete_id=?',[$id]);
        q('DELETE FROM athletes WHERE id=? AND team_id=?',[$id,$school]);race_audit('school_delete',$id,['school'=>$school]);return $id;
    }
    $input['team_id']=$school;
    $input['extras_present']=1;
    return race_registration_save($input);
}
function team_portal_handler():void {
    nocache_headers();
    $input=wp_unslash($_POST);$op=is_string($input['operation']??null)?$input['operation']:'';
    if(!in_array($op,['unlock','save','delete','logout'],true))wp_die('无效操作。','',['response'=>400]);
    check_admin_referer('sm_team_portal_'.$op.($op==='unlock'?'':team_portal_token()),'portal_nonce');
    try{
        if($op==='unlock'){
            $school=absint($input['school_id']??0);
            $rate='sm_portal_attempt_'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').':'.$school);
            $attempts=(int)get_transient($rate);
            if($attempts>=8)throw new \RuntimeException('尝试次数过多，请15分钟后重试。');
            set_transient($rate,$attempts+1,15*MINUTE_IN_SECONDS);
            $create=!empty($input['create_password']);
            if($create&&empty($input['school_representative']))throw new \RuntimeException('请确认你是该校授权的报名负责人。');
            $password=$input['school_password']??'';
            if(!is_string($password)||strlen($password)>128)throw new \RuntimeException('密码格式错误。');
            if($create&&$password==='')$password=wp_generate_password(18,false);
            if($create&&($input['school_password']??'')!==''&&$password!==($input['password_confirm']??''))throw new \RuntimeException('两次密码不一致。');
            $hash=team_portal_password($school,$password,$create);
            delete_transient($rate);team_portal_start($school,$hash);
            if($create)set_transient('sm_portal_password_'.hash('sha256',team_portal_token()),$password,120);
        }elseif($op==='logout'){
            delete_transient('sm_portal_'.hash('sha256',team_portal_token()));
            setcookie('sports_meet_school_portal','',['expires'=>time()-3600,'path'=>COOKIEPATH?:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        }else{
            $grant=team_portal_grant();if(!$grant)throw new \RuntimeException('请先输入本校密码。');
            race_transaction(fn()=>team_portal_save($input,(int)$grant['school']));
        }
    }catch(\Throwable $e){
        $message=$e instanceof DatabaseException?'未能保存，请核对报名是否已有比赛记录或资料重复。':$e->getMessage();
        wp_die(esc_html($message).'<p><a href="'.esc_url(page_url('new_registration')).'">返回各队报名</a></p>','报名提示',['response'=>400]);
    }
    wp_safe_redirect(add_query_arg('saved','1',page_url('new_registration')));exit;
}
add_action('admin_post_sports_meet_team_portal',__NAMESPACE__.'\\team_portal_handler');
add_action('admin_post_nopriv_sports_meet_team_portal',__NAMESPACE__.'\\team_portal_handler');

function team_portal_page():string {
    if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
    ob_start();echo '<section class="sports-meet race-workspace sm-team-portal"><p>报名及修改截止：'.esc_html(race_registration_deadline_label()).'。本页仅显示已验证高校的报名资料。</p>';
    $grant=team_portal_grant();$school=(int)($grant['school']??0);$closed=race_registration_closed();
    if(!$school){
        if($closed)echo '<p>报名已截止，输入本校密码可查看资料，不能修改或删除。</p>';
        team_portal_form('unlock');race_options('school_id','先选择你的高校／队伍',all('teams'));
        echo '<label>高校报名密码<input type="password" name="school_password" maxlength="128" autocomplete="current-password"></label><label>确认新密码（首次自设时填写）<input type="password" name="password_confirm" maxlength="128" autocomplete="new-password"></label>'
            .'<label><input type="checkbox" name="create_password" value="1"> 首次为本校创建密码（至少10个字符；留空由系统生成）</label>'
            .'<label><input type="checkbox" name="school_representative" value="1"> 我确认是该高校授权的报名负责人</label><p>已设置密码的高校请直接输入密码进入。密码由本校负责人保管；忘记密码请联系组委会重置。</p><button>进入本校报名</button></form></section>';
        return ob_get_clean();
    }
    echo '<h2>'.esc_html(q('SELECT name FROM teams WHERE id=?',[$school])->fetchColumn()).' · 本校报名</h2>';
    $key='sm_portal_password_'.hash('sha256',team_portal_token());$password=get_transient($key);
    if(is_string($password)){delete_transient($key);echo '<p role="status">本校报名密码（仅显示一次，请保存）：<code>'.esc_html($password).'</code></p>';}
    if(isset($_GET['saved']))echo '<p role="status">操作已完成。</p>';
    team_portal_form('logout');echo '<button>退出／切换高校</button></form>';
    if($closed)echo '<p>报名已截止，以下资料只供查看，不能修改或删除。</p>';
    else{
        $id=absint($_GET['edit_athlete']??0);$edit=$id?q('SELECT * FROM athletes WHERE id=? AND team_id=?',[$id,$school])->fetch():[];
        if($id&&!$edit)echo '<p>未找到本校的这条报名。</p>';
        else race_registration_form([],all('events'),$edit?:[],true,$school,true);
    }
    $rows=q('SELECT * FROM athletes WHERE team_id=? ORDER BY name,id',[$school])->fetchAll();
    $totals=array_fill_keys(array_keys(registration_extra_options()),0);
    foreach(q('SELECT x.activity,COUNT(*) AS n FROM registration_extras x JOIN athletes a ON a.id=x.athlete_id WHERE a.team_id=? GROUP BY x.activity',[$school])->fetchAll() as $count)$totals[$count['activity']]=(int)$count['n'];
    echo '<p>本校报名 '.count($rows).' 人 · '.implode(' · ',array_map(fn($key)=>esc_html(registration_extra_options()[$key]).' '.$totals[$key].' 人',array_keys(registration_extra_options()))).'</p>';
    echo '<div class="scroll"><table><thead><tr><th>姓名</th><th>组别</th><th>比赛项目</th><th>活动统计</th><th>操作</th></tr></thead><tbody>';
    foreach($rows as $row){
        $id=(int)$row['id'];echo '<tr><td>'.esc_html($row['name']).'</td><td>'.esc_html(race_category($row)).'</td><td>'.esc_html(race_event_names($id)).'</td><td>'.esc_html(registration_extra_names($id)).'</td><td>';
        if(!$closed){
            echo '<a href="'.esc_url(add_query_arg('edit_athlete',$id,page_url('new_registration'))).'">编辑</a>';
            team_portal_form('delete');echo '<input type="hidden" name="id" value="'.$id.'"><label><input type="checkbox" name="delete_confirm" value="1" required> 确认删除</label><button>删除</button></form>';
        }else echo '已锁定';
        echo '</td></tr>';
    }
    echo '</tbody></table></div></section>';return ob_get_clean();
}
add_shortcode('sports_meet_team_registration',__NAMESPACE__.'\\team_portal_page');

add_action('init',function(){
    $pages=get_option('sports_meet_pages',[]);$id=(int)($pages['new_registration']??0);
    if($id&&get_post_status($id)==='publish'){
        if(get_post_field('post_title',$id)==='新增报名')wp_update_post(['ID'=>$id,'post_title'=>'各队报名']);
        return;
    }
    $page=get_page_by_path('sports-new-registration');
    if($page&&$page->post_status==='publish')$id=$page->ID;
    else $id=wp_insert_post(['post_title'=>'各队报名','post_name'=>'sports-new-registration','post_type'=>'page','post_status'=>'publish','post_content'=>'[sports_meet_team_registration]','comment_status'=>'closed'],true);
    if(!is_wp_error($id)&&$id){$pages['new_registration']=(int)$id;update_option('sports_meet_pages',$pages,false);}
},25);

function registration_extras_summary():void {
    echo '<details><summary>2人3足／拔河／晚宴报名人数统计</summary>';$rows=[];$totals=[0,0,0];
    foreach(all('teams') as $team){
        $counts=[];foreach(registration_extra_options() as $key=>$label)$counts[]=(int)q('SELECT COUNT(*) FROM registration_extras x JOIN athletes a ON a.id=x.athlete_id WHERE a.team_id=? AND x.activity=?',[$team['id'],$key])->fetchColumn();
        foreach($counts as $i=>$count)$totals[$i]+=$count;
        $rows[]=[$team['name'],...$counts];
    }
    $rows[]=['合计',...$totals];table(['所属高校',...array_values(registration_extra_options())],$rows);echo '</details>';
}

add_action('template_redirect',function(){
    if(!is_singular())return;$post=get_queried_object();
    if($post instanceof \WP_Post&&(has_shortcode($post->post_content,'sports_meet_team_registration')||str_contains($post->post_content,'new_registration'))){
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();
    }
});
