<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function race_text($v,int $max,bool $required=true):string {if(!is_scalar($v))throw new \RuntimeException('文字格式错误。');$s=trim(sanitize_text_field((string)$v));if(($required&&$s==='')||mb_strlen($s)>$max)throw new \RuntimeException('必填文字为空或超出长度。');return $s;}
function race_athlete_save(array $p):int {
    relay_manual_registration_guard((int)($p['id']??0),$p);
    $id=(int)($p['id']??0);$bib=race_text($p['registration_id']??'',40);$name=race_text($p['name']??'',80);$gender=$p['gender']??'';$group=race_text($p['age_group']??'',80,false);$team=(int)($p['team_id']??0);race_one('teams',$team);
    if(!in_array($gender,['M','F'],true))throw new \RuntimeException('性别须为M或F。');
    if($id){$old=race_one('athletes',$id);$structural=(int)$old['team_id']!==$team||$old['gender']!==$gender||$old['age_group']!==$group;
        if($structural){foreach(q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll() as $r)race_unstarted((int)$r['event_id']);if(q('SELECT id FROM results WHERE athlete_id=? LIMIT 1',[$id])->fetch()||q('SELECT result_id FROM relay_members WHERE athlete_id=? LIMIT 1',[$id])->fetch())throw new \RuntimeException('已有旧版成绩引用，不能改队伍、性别或组别。');}
        q('UPDATE athletes SET bib=?,name=?,gender=?,age_group=?,team_id=? WHERE id=?',[$bib,$name,$gender,$group,$team,$id]);
        if($structural)foreach(q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll() as $r)race_enroll((int)$r['event_id'],$id);
    }else{q('INSERT INTO athletes(bib,name,gender,age_group,team_id) VALUES(?,?,?,?,?)',[$bib,$name,$gender,$group,$team]);global $wpdb;$id=(int)$wpdb->insert_id;}
    return $id;
}
function race_import_legacy_csv(string $path,bool $update=false):int {
    $f=fopen($path,'r');if(!$f)throw new \RuntimeException('无法读取CSV。');$count=0;$seen=[];
    try{
        $head=fgetcsv($f,0,',','"','');if(!$head)throw new \RuntimeException('CSV为空。');$head[0]=preg_replace('/^\xEF\xBB\xBF/','',$head[0]);
        if($head!==['team','registration_id','name','gender','age_group','event','kind','relay_name'])throw new \RuntimeException('表头须与报名模板一致。');
        $line=1;while(($row=fgetcsv($f,0,',','"',''))!==false){$line++;if($row===[null])continue;if($line>10001)throw new \RuntimeException('每批最多10000行。');
            try{
                if(count($row)!==8)throw new \RuntimeException('列数不符。');foreach($row as $v)if(!mb_check_encoding($v,'UTF-8'))throw new \RuntimeException('请保存为UTF-8 CSV。');$d=array_map('trim',array_combine($head,$row));
                $team=race_text($d['team'],80);$event=race_text($d['event'],120);$reg=race_text($d['registration_id'],40);$kind=['个人'=>'individual','接力'=>'relay'][$d['kind']]??$d['kind'];$gender=['男'=>'M','女'=>'F'][$d['gender']]??$d['gender'];
                if(!in_array($kind,['individual','relay'],true))throw new \RuntimeException('kind须为individual或relay。');
                $signature=[$team,$d['name'],$gender,$d['age_group']];if(isset($seen[$reg])&&$seen[$reg]!==$signature)throw new \RuntimeException('同一报名编号的运动员资料不一致。');$seen[$reg]=$signature;
                q('INSERT INTO teams(name) VALUES(?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$team]);$tid=(int)q('SELECT id FROM teams WHERE name=?',[$team])->fetchColumn();
                $old=q('SELECT * FROM athletes WHERE bib=?',[$reg])->fetch();
                if($old&&!$update&&[$old['name'],$old['gender'],$old['age_group'],(int)$old['team_id']]!==[$d['name'],$gender,$d['age_group'],$tid])throw new \RuntimeException('报名编号已有不同资料；如需更新，请勾选更新已有报名资料。');
                $aid=race_athlete_save(['id'=>$old['id']??0,'registration_id'=>$reg,'name'=>$d['name'],'gender'=>$gender,'age_group'=>$d['age_group'],'team_id'=>$tid]);
                $e=q('SELECT * FROM events WHERE name=?',[$event])->fetch();if($e&&$e['kind']!==$kind)throw new \RuntimeException('同名项目的类型不一致。');
                if(!$e){q("INSERT INTO events(name,kind,gender,age_group,direction,unit) VALUES(?,?,'X','','asc','秒')",[$event,$kind]);$e=q('SELECT * FROM events WHERE name=?',[$event])->fetch();}
                race_enroll((int)$e['id'],$aid);
                if($kind==='relay'&&$d['relay_name']!==''){
                    $relayName=race_text($d['relay_name'],80);q('INSERT INTO race_relays(event_id,name) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$e['id'],$relayName]);$rid=(int)q('SELECT id FROM race_relays WHERE event_id=? AND name=?',[$e['id'],$relayName])->fetchColumn();
                    $used=q('SELECT relay_id FROM race_members WHERE event_id=? AND athlete_id=?',[$e['id'],$aid])->fetchColumn();if($used && (int)$used!==$rid)throw new \RuntimeException('该运动员在同一接力项目已属于另一个接力队。');
                    q('INSERT IGNORE INTO race_members(relay_id,event_id,athlete_id) VALUES(?,?,?)',[$rid,$e['id'],$aid]);if((int)q('SELECT COUNT(*) FROM race_members WHERE relay_id=?',[$rid])->fetchColumn()>8)throw new \RuntimeException('接力队超过8名成员。');
                }$count++;
            }catch(\Throwable $e){throw new \RuntimeException('CSV第'.$line.'行：'.($e instanceof DatabaseException?'报名编号或队伍/项目资料重复、过长或仍被引用。':$e->getMessage()));}
        }
    }finally{fclose($f);}race_audit('import',0,['rows'=>$count]);return $count;
}
function race_reopen(int $id):void {
    $r=race_one('race_rounds',$id);if($r['status']!=='finalized')throw new \RuntimeException('此轮尚未提交完成。');
    if($r['stage']!=='final'){
        $final=q("SELECT * FROM race_rounds WHERE event_id=? AND stage='final'",[$r['event_id']])->fetch();if($final&&$final['status']==='finalized')throw new \RuntimeException('决赛已完成，须先撤回决赛。');
        if($r['stage']==='heat'&&q("SELECT id FROM race_rounds WHERE event_id=? AND stage='semi' AND status='finalized' LIMIT 1",[$r['event_id']])->fetch())throw new \RuntimeException('须先撤回已完成的半决赛。');
        if($final){race_audit('reset_final',(int)$final['id'],q('SELECT * FROM race_entries WHERE round_id=?',[$final['id']])->fetchAll());q('DELETE FROM race_rounds WHERE id=?',[$final['id']]);}
        if($r['stage']==='heat')q("DELETE FROM race_rounds WHERE event_id=? AND stage='semi'",[$r['event_id']]);
    }
    race_audit('reopen',$id,q('SELECT * FROM race_entries WHERE round_id=?',[$id])->fetchAll());q("UPDATE race_rounds SET status='open',version=version+1 WHERE id=?",[$id]);if($r['stage']==='heat'&&(int)($r['eight_lanes']??0)<2)race_sync_final((int)$r['event_id']);
}
function race_mutate(array $p):void {
    $action=$p['race_action']??'';$admin=race_can_manage();
    if(race_registration_closed()&&(in_array($action,['registration','athlete','enroll','unenroll','import'],true)
        ||($action==='delete'&&($p['type']??'')==='athlete'))){
        throw new \RuntimeException('报名已截止，报名资料已锁定。');
    }
    if(!$admin&&current_user_can('sports_meet_school_registration'))$p=race_school_registration_input($p);
    elseif(!$admin&&!in_array($action,['checkin','round','relay','prepare_final','prepare_relay'],true))throw new \RuntimeException('此操作需要管理员权限。');
    switch($action){
    case 'prepare_relay':
        $event=absint($p['event_id']??0);
        if(race_event($event)['kind']!=='relay')throw new \RuntimeException('请选择接力项目。');
        race_generate($event,'heat',4,true);break;
    case 'checkin':race_checkin((int)$p['athlete_id'],(string)$p['bib'],(int)$p['version']);break;
    case 'prepare_final':race_prepare_final((int)$p['event_id'],!empty($p['confirm_direct']));break;
    case 'round':$r=race_one('race_rounds',(int)$p['round_id']);$manual=relay_manual_state((int)$r['event_id']);if($manual&&!hash_equals($manual['revision'],(string)($p['relay_manual_revision']??'')))throw new \RuntimeException('接力队伍已被管理员调整，请刷新后再录入。');$empty=($p['decision']??'')==='empty';if($empty&&($p['empty_confirm']??'')!=='DNS')throw new \RuntimeException('无人出赛请在确认框输入DNS。');race_save_round((int)$p['round_id'],(int)$p['version'],(array)($p['entries']??[]),in_array($p['decision']??'',['finalize','empty'],true),$empty);break;
    case 'team':$name=race_text($p['name']??'',80);if(!empty($p['id'])){race_one('teams',(int)$p['id']);q('UPDATE teams SET name=? WHERE id=?',[$name,(int)$p['id']]);}else q('INSERT INTO teams(name) VALUES(?)',[$name]);break;
    case 'athlete':race_athlete_save($p);break;
    case 'registration':race_registration_save($p);break;
    case 'enroll':race_enroll((int)$p['event_id'],(int)$p['athlete_id']);break;
    case 'unenroll':$e=(int)$p['event_id'];$a=(int)$p['athlete_id'];relay_manual_registration_guard($a,['remove_event'=>$e]);race_unstarted($e);q('DELETE FROM race_members WHERE event_id=? AND athlete_id=?',[$e,$a]);q('DELETE FROM race_enrollments WHERE event_id=? AND athlete_id=?',[$e,$a]);break;
    case 'event':
        $id=(int)($p['id']??0);$name=race_text($p['name']??'',120);$kind=$p['kind']??'';$gender=$p['gender']??'X';$group=race_text($p['age_group']??'',80,false);
        if(!in_array($kind,['individual','relay'],true)||!in_array($gender,['X','M','F'],true))throw new \RuntimeException('项目类型或性别无效。');if($kind==='relay')$gender='X';
        if($id){race_unstarted($id);$old=race_event($id);if($old['kind']!==$kind && q('SELECT id FROM race_enrollments WHERE event_id=?',[$id])->fetch())throw new \RuntimeException('已有报名，不能改变项目类型。');q('UPDATE events SET name=?,kind=?,gender=?,age_group=? WHERE id=?',[$name,$kind,$gender,$group,$id]);foreach(q('SELECT athlete_id FROM race_enrollments WHERE event_id=?',[$id])->fetchAll() as $a)race_enroll($id,(int)$a['athlete_id']);}
        else q("INSERT INTO events(name,kind,gender,age_group,direction,unit) VALUES(?,?,?,?,'asc','秒')",[$name,$kind,$gender,$group]);break;
    case 'relay':throw new \RuntimeException('接力现由系统自动组队，请查看各队报名及接力组队页面。');
    case 'generate':race_generate((int)$p['event_id'],(string)$p['stage'],(int)$p['capacity'],!empty($p['individual_split']));break;
    case 'prepare_sprints':
        $prepared=0;
        foreach(all('events') as $event){
            if($event['kind']!=='individual'||!race_eight_lane_event($event))continue;
            if(q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$event['id']])->fetch())continue;
            if(!q('SELECT id FROM race_enrollments WHERE event_id=? LIMIT 1',[$event['id']])->fetch())continue;
            race_generate((int)$event['id'],'heat',8,true);
            $prepared++;
        }
        race_audit('prepare_sprints',0,['events'=>$prepared]);
        break;
    case 'move':race_move((int)$p['entry_id'],(int)$p['heat_no']);break;
    case 'reopen':if(($p['confirm']??'')!=='REOPEN')throw new \RuntimeException('请输入REOPEN确认撤回。');race_reopen((int)$p['round_id']);break;
    case 'import':if(!isset($_FILES['csv'])||$_FILES['csv']['error']!==UPLOAD_ERR_OK||$_FILES['csv']['size']>2097152||!is_uploaded_file($_FILES['csv']['tmp_name']))throw new \RuntimeException('请上传不超过2MB的CSV。');race_import_csv($_FILES['csv']['tmp_name'],!empty($p['update']));break;
    case 'rules':
        $rules=['individual_part'=>(string)$p['individual_part'],'relay_part'=>(string)$p['relay_part'],'individual_places'=>[(string)$p['individual_1'],(string)$p['individual_2'],(string)$p['individual_3']],'relay_places'=>[(string)$p['relay_1'],(string)$p['relay_2'],(string)$p['relay_3']]];
        foreach([$rules['individual_part'],$rules['relay_part'],...$rules['individual_places'],...$rules['relay_places']] as $v)race_cents($v);
        foreach(['individual_places','relay_places'] as $key)if(race_cents($rules[$key][0])<race_cents($rules[$key][1])||race_cents($rules[$key][1])<race_cents($rules[$key][2]))throw new \RuntimeException('名次分须依名次递减或相同。');
        $male=(int)$p['relay_males'];if($male<1||$male>7)throw new \RuntimeException('混合接力男性人数须为1至7。');$old=(int)q('SELECT relay_males FROM settings WHERE id=1')->fetchColumn();
        if($male!==$old && q("SELECT r.id FROM race_rounds r JOIN events e ON e.id=r.event_id WHERE e.kind='relay' LIMIT 1")->fetch())throw new \RuntimeException('已生成接力比赛名单，不能再修改男女比例。');
        q('UPDATE settings SET title=?,relay_males=? WHERE id=1',[race_text($p['title'],120),$male]);update_option('sports_meet_race_rules',$rules,false);break;
    case 'delete':
        if(($p['confirm']??'')!=='DELETE')throw new \RuntimeException('请输入DELETE确认。');$id=(int)$p['id'];$type=$p['type']??'';
        if($type==='athlete'){relay_manual_registration_guard($id,['delete_athlete'=>true]);race_one('athletes',$id);foreach(q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll() as $r)race_unstarted((int)$r['event_id']);q('DELETE FROM race_members WHERE athlete_id=?',[$id]);q('DELETE FROM race_enrollments WHERE athlete_id=?',[$id]);q('DELETE FROM checkins WHERE athlete_id=?',[$id]);q('DELETE FROM athletes WHERE id=?',[$id]);}
        elseif($type==='event'){if(race_registration_closed()&&q('SELECT id FROM race_enrollments WHERE event_id=? LIMIT 1',[$id])->fetch())throw new \RuntimeException('报名已截止，不能删除已有报名的项目。');race_unstarted($id);q('DELETE FROM race_members WHERE event_id=?',[$id]);q('DELETE FROM race_relays WHERE event_id=?',[$id]);q('DELETE FROM race_enrollments WHERE event_id=?',[$id]);q('DELETE FROM events WHERE id=?',[$id]);}
        elseif($type==='relay'){$r=race_one('race_relays',$id);race_unstarted((int)$r['event_id']);q('DELETE FROM race_relays WHERE id=?',[$id]);}
        elseif($type==='team')q('DELETE FROM teams WHERE id=?',[$id]);else throw new \RuntimeException('删除类型无效。');break;
    default:throw new \RuntimeException('未知操作。');
    }
    if(!in_array($action,['checkin','round','import'],true))race_audit($action,(int)($p['id']??$p['event_id']??$p['round_id']??0));
}
function race_handler():void {
    if(!race_can_judge()&&!current_user_can('sports_meet_school_registration'))wp_die('没有操作权限。','',['response'=>403]);check_admin_referer('sports_meet_race','race_nonce');
    $p=wp_unslash($_POST);$message='已保存。';$error=false;
    try{race_transaction(fn()=>race_mutate($p));}catch(\Throwable $e){$error=true;error_log('Sports Meet race: '.$e->getMessage());$message=$e instanceof DatabaseException?'保存失败：号码重复、资料仍被引用，或数据已变化。请刷新后核对。':$e->getMessage();}
    set_transient(race_notice_key(),[$error,$message],120);
    $tab=sanitize_key($p['race_tab']??'registration');$params=['race_tab'=>$tab];if($tab==='registration')$params=array_merge($params,race_roster_params($p));if(!empty($p['round_id']))$params['round_id']=(int)$p['round_id'];
    if(!$error&&($p['race_action']??'')==='prepare_relay'){
        $params['event_id']=absint($p['event_id']);
        $params['round_id']=(int)q("SELECT id FROM race_rounds WHERE event_id=? ORDER BY FIELD(stage,'heat','semi','final'),heat_no LIMIT 1",[$params['event_id']])->fetchColumn();
    }
    if($tab==='checkin')$params=array_merge($params,race_checkin_params($p));
    $day=is_scalar($p['day_page']??null)?(string)$p['day_page']:'';
    if($day==='new_registration'&&!empty($p['frontend'])&&($p['race_action']??'')==='registration'){
        $url=page_url('new_registration');
        if($error&&!empty($p['id']))$url=add_query_arg('edit_athlete',absint($p['id']),$url);
        wp_safe_redirect($url);exit;
    }
    if($day==='chief'&&!empty($p['frontend'])){wp_safe_redirect(chief_page_url($params));exit;}
    if(!isset(race_day_definitions()[$day]))$day='judge';
    if(($p['race_action']??'')==='prepare_final'&&!empty($p['event_id'])){$params['event_id']=(int)$p['event_id'];if(!empty($p['frontend']))$day='heats';}
    if(!empty($p['frontend']))$url=add_query_arg($params,page_url($day));else{$url=add_query_arg($params,admin_url('admin.php?page=sports-meet&tab=races'));}
    wp_safe_redirect($url);exit;
}
add_action('admin_post_sports_meet_race',__NAMESPACE__.'\\race_handler');
function race_guard_legacy(array $p):void {
    if(!empty($p['sm_action']) && $p['sm_action']!=='logout')throw new \RuntimeException('新版请使用报名、检录及比赛工作台；旧版成绩仅保留供查阅。');
}
