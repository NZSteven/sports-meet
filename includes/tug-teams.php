<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function tug_teams():array {
    // Read the current value after acquiring the shared registration lock.
    global $wpdb;
    $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'sports_meet_tug_teams'));
    $teams=$value===null?[]:maybe_unserialize($value);
    return is_array($teams)?$teams:[];
}
function tug_store(array $teams):void {
    global $wpdb;
    $result=$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",'sports_meet_tug_teams',maybe_serialize($teams)));
    if($result===false)throw new \RuntimeException('拔河组队保存失败，请重试。');
    wp_cache_delete('sports_meet_tug_teams','options');wp_cache_delete('notoptions','options');
}
function tug_candidates(array $teams, string $editing=''):array {
    $reserved=[];$used=[];
    foreach($teams as $id=>$team){
        if($team['kind']==='school')$reserved[(int)$team['school']]=true;
        elseif($id!==$editing)foreach($team['members'] as $athlete)$used[(int)$athlete]=true;
    }
    $rows=q("SELECT a.id,a.name,a.team_id,t.name AS school FROM athletes a JOIN teams t ON t.id=a.team_id
        JOIN registration_extras x ON x.athlete_id=a.id AND x.activity='tug_of_war' ORDER BY t.name,a.name,a.id")->fetchAll();
    return array_values(array_filter($rows,fn($row)=>!isset($reserved[(int)$row['team_id']])&&!isset($used[(int)$row['id']])));
}
function tug_mutate(array $input,int $scope=0):void {
    if(race_registration_closed())throw new \RuntimeException('报名已截止，拔河组队资料已锁定。');
    $teams=tug_teams();$id=is_string($input['tug_id']??null)?$input['tug_id']:'';
    $old=$id?($teams[$id]??null):null;
    if($id&&!$old)throw new \RuntimeException('该队伍已不存在，请刷新。');
    if($scope&&$old&&(int)$old['school']!==$scope)throw new \RuntimeException('只能管理本校负责的拔河队。');
    if(($input['tug_operation']??'')==='delete'){
        if(!$old||empty($input['tug_confirm']))throw new \RuntimeException('请确认解散所选拔河队。');
        unset($teams[$id]);tug_store($teams);race_audit('tug_delete',0,['team'=>$id]);return;
    }
    $school=$scope?:absint($input['tug_school']??0);race_one('teams',$school);
    $kind=$input['tug_kind']??'';if(!in_array($kind,['school','mixed'],true))throw new \RuntimeException('请选择独立校队或混合队。');
    $name=race_text($input['tug_name']??'',80);
    $members=[];
    if($kind==='school'){
        foreach($teams as $otherId=>$team){
            if($otherId===$id)continue;
            if($team['kind']==='school'&&(int)$team['school']===$school)throw new \RuntimeException('该高校已经独立成队。');
            if($team['kind']==='mixed'&&$team['members']){
                foreach($team['members'] as $athlete){
                    if((int)q('SELECT team_id FROM athletes WHERE id=?',[$athlete])->fetchColumn()===$school)throw new \RuntimeException('本校已有队员在混合队，请先从混合队移出，再独立成队。');
                }
            }
        }
    }else{
        $raw=$input['tug_members']??[];if(!is_array($raw))throw new \RuntimeException('队员选项无效。');
        $allowed=[];foreach(tug_candidates($teams,$id) as $row)$allowed[(int)$row['id']]=$row;
        $schools=[];
        foreach($raw as $value){
            if(!is_scalar($value)||!ctype_digit((string)$value)||!isset($allowed[(int)$value]))throw new \RuntimeException('所选队员已被独立校队保留、已加入其他队，或未报名拔河，请刷新后重新选择。');
            $athlete=(int)$value;$members[$athlete]=$athlete;$schools[(int)$allowed[$athlete]['team_id']]=true;
        }
        if(count($schools)<2)throw new \RuntimeException('混合队须由至少两所高校的拔河报名者组成；同一高校请选独立校队。');
        // No member limit: tug-of-war registration is independent of relay rules.
        $members=array_values($members);
    }
    if(!$id)$id=wp_generate_uuid4();
    $teams[$id]=['name'=>$name,'school'=>$school,'kind'=>$kind,'members'=>$members];
    tug_store($teams);
    race_audit('tug_save',0,['team'=>$id,'school'=>$school,'kind'=>$kind]);
}
function tug_team_members(array $team):array {
    $rows=q("SELECT a.id,a.name,a.team_id,t.name AS school FROM athletes a JOIN teams t ON t.id=a.team_id
        JOIN registration_extras x ON x.athlete_id=a.id AND x.activity='tug_of_war' ORDER BY t.name,a.name,a.id")->fetchAll();
    return array_values(array_filter($rows,fn($row)=>$team['kind']==='school'?(int)$row['team_id']===(int)$team['school']:in_array((int)$row['id'],$team['members'],true)));
}
function tug_registration_guard(int $id,array $input):void {
    if(!$id)return;
    foreach(tug_teams() as $team)if($team['kind']==='mixed'&&in_array($id,$team['members'],true)){
        $athlete=race_one('athletes',$id);
        if(!empty($input['delete_athlete'])||(isset($input['extras_present'])&&!in_array('tug_of_war',(array)($input['activities']??[]),true))
            ||(isset($input['team_id'])&&(int)$input['team_id']!==(int)$athlete['team_id']))throw new \RuntimeException('该报名者已加入拔河混合队，请先从混合队移出，再取消拔河、换校或删除报名。');
    }
}
function tug_form(string $operation,int $scope=0):void {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_tug_team"><input type="hidden" name="tug_operation" value="'.esc_attr($operation).'">'
        .'<input type="hidden" name="tug_portal" value="'.($scope?'1':'0').'">'.wp_nonce_field('sports_meet_tug_team'.($scope?team_portal_token():''),'tug_nonce',false,false);
}
function tug_team_ui(int $scope=0):void {
    $teams=tug_teams();$closed=race_registration_closed();$editing=is_string($_GET['tug_edit']??null)?sanitize_text_field(wp_unslash($_GET['tug_edit'])):'';
    $edit=$teams[$editing]??null;if($scope&&$edit&&(int)$edit['school']!==$scope)$edit=null;
    echo '<details class="sm-tug-teams"><summary>拔河组队（报名不限人数）</summary><p>独立校队保留本校全部拔河报名者，之后新增报名也自动归入本校队；这些人不能加入其他混合队。混合队只能选择未被独立校队保留、未加入其他队伍的拔河报名者。组队仅作活动管理，不纳入计分比赛。</p>';
    foreach($teams as $id=>$team){
        if($scope&&(int)$team['school']!==$scope)continue;
        $members=tug_team_members($team);
        echo '<p><strong>'.esc_html($team['name']).'</strong> · '.($team['kind']==='school'?'独立校队':'混合队').' · '.count($members).'人：'.esc_html(implode('、',array_map(fn($row)=>$row['name'].'（'.$row['school'].'）',$members))).'</p>';
        if(!$closed){
            $base=$scope?page_url('new_registration'):race_url('relay');echo '<a href="'.esc_url(add_query_arg('tug_edit',$id,$base)).'">编辑</a>';
            tug_form('delete',$scope);echo '<input type="hidden" name="tug_id" value="'.esc_attr($id).'"><label><input type="checkbox" name="tug_confirm" value="1" required> 确认解散此队（保留报名）</label><button>解散队伍</button></form>';
        }
    }
    if(!$closed){
        tug_form('save',$scope);echo '<input type="hidden" name="tug_id" value="'.esc_attr($edit?$editing:'').'">';
        if(!$scope)race_options('tug_school','负责高校',all('teams'),(string)($edit['school']??''));
        race_options('tug_kind','组队方式',[['id'=>'school','name'=>'本校独立成队（全部拔河报名者）'],['id'=>'mixed','name'=>'跨校混合队（下方选择队员）']],$edit['kind']??'school');
        field('tug_name','拔河队名称','text',$edit['name']??'');
        echo '<fieldset class="race-activities-picker"><legend>混合队队员（独立校队无需选择）</legend><div class="race-event-grid">';
        foreach(tug_candidates($teams,$edit?$editing:'') as $row)echo '<label><input type="checkbox" name="tug_members[]" value="'.(int)$row['id'].'" '.checked($edit&&in_array((int)$row['id'],$edit['members'],true),true,false).'> '.esc_html($row['school'].' · '.$row['name']).'</label>';
        echo '</div></fieldset><button>'.($edit?'更新拔河队':'建立拔河队').'</button></form>';
    }
    echo '</details>';
}
function tug_handler():void {
    $portal=!empty($_POST['tug_portal']);
    $scope=$portal?(int)(team_portal_grant()['school']??0):0;
    if($portal&&!$scope)wp_die('请先验证本校报名密码。','',['response'=>403]);
    if(!$scope&&!current_user_can('manage_options'))wp_die('请先验证本校报名密码。','',['response'=>403]);
    check_admin_referer('sports_meet_tug_team'.($scope?team_portal_token():''),'tug_nonce');
    try{race_transaction(fn()=>tug_mutate(wp_unslash($_POST),$scope));}
    catch(\Throwable $e){wp_die(esc_html($e instanceof DatabaseException?'保存失败，请刷新核对。':$e->getMessage()),'拔河组队',['response'=>400,'back_link'=>true]);}
    wp_safe_redirect($scope?page_url('new_registration'):race_url('relay'));exit;
}
add_action('admin_post_sports_meet_tug_team',__NAMESPACE__.'\\tug_handler');
add_action('admin_post_nopriv_sports_meet_tug_team',__NAMESPACE__.'\\tug_handler');
