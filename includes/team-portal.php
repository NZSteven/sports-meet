<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function install_registration_extras():void {
    q('CREATE TABLE IF NOT EXISTS registration_extras (athlete_id INT NOT NULL, activity VARCHAR(24) NOT NULL, PRIMARY KEY(athlete_id,activity), FOREIGN KEY(athlete_id) REFERENCES athletes(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function registration_extra_options():array {return ['three_legged'=>'2人3足比赛','tug_of_war'=>'拔河比赛','dinner'=>'晚宴'];}
function registration_extra_names(int $id,bool $competitions=false):string {
    $labels=registration_extra_options();
    $keys=q('SELECT activity FROM registration_extras WHERE athlete_id=? ORDER BY activity',[$id])->fetchAll(7);
    $keys=array_filter($keys,fn($key)=>$competitions?$key!=='dinner':$key==='dinner');
    return implode('、',array_map(fn($key)=>($labels[$key]??'').($competitions?'（不计积分）':''),$keys));
}
function registration_unscored_fields(int $id=0):void {
    $selected=$id?q('SELECT activity FROM registration_extras WHERE athlete_id=?',[$id])->fetchAll(7):[];
    foreach(registration_extra_options() as $key=>$label){
        if($key==='dinner')continue;
        echo '<label class="race-event-choice" data-race-categories="'.esc_attr(wp_json_encode(array_keys(race_categories()))).'" data-race-locked="0"><input type="checkbox" name="activities[]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'><span>'.esc_html($label).'<small>比赛项目 · 不计积分</small></span></label>';
    }
}
function registration_extras_fields(int $id=0):void {
    $selected=$id?q('SELECT activity FROM registration_extras WHERE athlete_id=?',[$id])->fetchAll(7):[];
    echo '<fieldset class="race-activities-picker"><legend>活动报名</legend><input type="hidden" name="extras_present" value="1"><div class="race-event-grid">';
    echo '<label class="race-event-choice"><input type="checkbox" name="activities[]" value="dinner" '.checked(in_array('dinner',$selected,true),true,false).'><span>晚宴<small>活动报名 · 统计人数</small></span></label>';
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
        relay_manual_registration_guard($id,['delete_athlete'=>true]);
        
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
    ob_start();echo '<section class="sports-meet race-workspace sm-team-portal">';
    $grant=team_portal_grant();$school=(int)($grant['school']??0);$closed=race_registration_closed();
    if(!$school||!empty($_GET['registration_entry'])){
        echo race_registration_navigation();
        if($closed)echo '<p>报名已截止，输入本校密码可查看资料，不能修改或删除。</p>';
        echo '<div class="sm-registration-entry-cards">';
        echo '<section class="sm-registration-entry-card"><h2>首次进入</h2><p>为本校创建报名密码</p>';
        if($closed)echo '<p>报名已截止，首次创建已关闭。</p>';
        else{
            team_portal_form('unlock');echo '<input type="hidden" name="create_password" value="1">';
            race_options('school_id','所属高校／队伍',all('teams'));
            echo '<label>设置新密码（可选）<input type="password" name="school_password" minlength="10" maxlength="128" autocomplete="new-password"></label>'
                .'<label>再次输入新密码<input type="password" name="password_confirm" maxlength="128" autocomplete="new-password"></label>'
                .'<p>自设密码至少10个字符；两栏都留空，系统会生成密码并显示一次，请保存。</p>'
                .'<label class="sm-entry-confirm"><input type="checkbox" name="school_representative" value="1" required> 我是该高校授权的报名负责人</label>'
                .'<button>创建密码并进入报名</button></form>';
        }
        echo '</section><section class="sm-registration-entry-card"><h2>已有密码，再次进入</h2><p>使用本校已经设置的密码</p>';
        team_portal_form('unlock');race_options('school_id','所属高校／队伍',all('teams'));
        echo '<label>高校报名密码<input type="password" name="school_password" maxlength="128" autocomplete="current-password" required></label>'
            .'<button>登录本校报名</button><p>忘记密码？请联系组委会重置。</p></form></section></div>';
        if($school)echo '<p><a href="'.esc_url(page_url('new_registration')).'">返回本校报名</a></p>';
        relay_auto_ui(0);echo '</section>';
        return ob_get_clean();
    }
    echo '<p>报名及修改截止：'.esc_html(race_registration_deadline_label()).'。本页仅显示已验证高校的报名资料。</p>';
    echo '<h2>'.esc_html(q('SELECT name FROM teams WHERE id=?',[$school])->fetchColumn()).' · 本校报名</h2>';
    $key='sm_portal_password_'.hash('sha256',team_portal_token());$password=get_transient($key);
    if(is_string($password)){delete_transient($key);echo '<p role="status">本校报名密码（仅显示一次，请保存）：<code>'.esc_html($password).'</code></p>';}
    echo '<div class="sm-school-roster-actions">';
    if(!$closed)echo '<a class="sm-add-athlete" href="'.esc_url(add_query_arg('add_athlete','1',page_url('new_registration'))).'">＋ 添加队员</a>';
    team_portal_form('logout');echo '<button>退出／切换高校</button></form></div>';
    if($closed)echo '<p>报名已截止，以下资料只供查看，不能修改或删除。</p>';
    elseif(!empty($_GET['add_athlete'])||!empty($_GET['edit_athlete'])){
        $id=absint($_GET['edit_athlete']??0);$edit=$id?q('SELECT * FROM athletes WHERE id=? AND team_id=?',[$id,$school])->fetch():[];
        if($id&&!$edit)echo '<p>未找到本校的这条报名。</p>';
        else{echo '<h3>'.($edit?'编辑队员报名':'添加队员').'</h3>';race_registration_form([],all('events'),$edit?:[],true,$school,true);}
        echo '<p><a href="'.esc_url(page_url('new_registration')).'">返回本校报名名单</a></p></section>';return ob_get_clean();
    }
    $rows=q('SELECT * FROM athletes WHERE team_id=? ORDER BY name,id',[$school])->fetchAll();
    $totals=array_fill_keys(array_keys(registration_extra_options()),0);
    foreach(q('SELECT x.activity,COUNT(*) AS n FROM registration_extras x JOIN athletes a ON a.id=x.athlete_id WHERE a.team_id=? GROUP BY x.activity',[$school])->fetchAll() as $count)$totals[$count['activity']]=(int)$count['n'];
    echo '<p>本校报名 '.count($rows).' 人 · 晚宴 '.$totals['dinner'].' 人</p>';
    $params=race_roster_params(wp_unslash($_GET));$params['roster_school']=(string)$school;
    $result=race_filter_roster($params);$rows=$result['rows'];
    echo '<form class="race-roster-filter sm-school-roster-filter" method="get" action="'.esc_url(page_url('new_registration')).'">';
    if(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])['new_registration']??0).'">';
    echo '<label>姓名／比赛号码搜索<input type="search" name="roster_q" maxlength="120" placeholder="输入姓名或比赛号码" value="'.esc_attr($params['roster_q']??'').'"></label>';
    race_roster_select('roster_category','组别',array_map(fn($label)=>['id'=>$label,'name'=>$label],array_keys(race_categories())),$params['roster_category']??'');
    race_roster_select('roster_event','比赛及活动项目',race_roster_project_options(),$params['roster_event']??'');
    echo '<div class="race-roster-filter-actions"><button>筛选</button><a href="'.esc_url(page_url('new_registration')).'">重置</a></div></form><p role="status">当前显示 '.count($rows).' 人。</p>';
    $accepted=disclaimer_acceptances();
    echo '<div class="scroll"><table class="sm-team-roster sm-athlete-roster"><thead><tr><th class="sm-consent-cell">免责声明</th><th>姓名</th><th>比赛号码</th><th>组别</th><th>比赛项目</th><th>晚宴</th><th>操作</th></tr></thead><tbody>';
    foreach($rows as $row){
        $id=(int)$row['id'];echo '<tr><td class="sm-consent-cell">'.disclaimer_roster_mark($row,$accepted).'</td><td>'.esc_html($row['name']).'</td><td>'.esc_html(race_roster_bib($row)).'</td><td>'.esc_html(race_category($row)).'</td><td>'.esc_html(race_event_names($id)).'</td><td>'.esc_html(registration_extra_names($id)).'</td><td>';
        if(!$closed){
            echo '<a href="'.esc_url(add_query_arg('edit_athlete',$id,page_url('new_registration'))).'">编辑</a>';
            team_portal_form('delete');echo '<input type="hidden" name="id" value="'.$id.'"><label><input type="checkbox" name="delete_confirm" value="1" required> 确认删除</label><button>删除</button></form>';
        }else echo '已锁定';
        echo '</td></tr>';
    }
    if(!$rows)echo '<tr><td colspan="7">没有符合条件的本校报名。可调整筛选，或点击“添加队员”。</td></tr>';
    echo '</tbody></table></div></section>';return ob_get_clean();
}
add_shortcode('sports_meet_team_registration',__NAMESPACE__.'\\team_portal_page');

add_action('init',function(){
    $pages=get_option('sports_meet_pages',[]);$id=(int)($pages['new_registration']??0);
    if($id&&get_post_status($id)==='publish'){
        if(in_array(get_post_field('post_title',$id),['新增报名','各队报名'],true))wp_update_post(['ID'=>$id,'post_title'=>'各队报名及接力组队']);
        return;
    }
    $page=get_page_by_path('sports-new-registration');
    if($page&&$page->post_status==='publish')$id=$page->ID;
    else $id=wp_insert_post(['post_title'=>'各队报名及接力组队','post_name'=>'sports-new-registration','post_type'=>'page','post_status'=>'publish','post_content'=>'[sports_meet_team_registration]','comment_status'=>'closed'],true);
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
