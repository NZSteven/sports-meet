<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function install_races():void {
    foreach(explode(';',file_get_contents(__DIR__.'/race-schema.sql')) as $sql)if(trim($sql)!=='')q($sql);
    add_option('sports_meet_race_rules',['individual_part'=>'1','individual_places'=>['5','3','2'],'relay_part'=>'1','relay_places'=>['32','24','16']], '',false);
    add_role('sports_meet_referee','运动会裁判',['read'=>true,'sports_meet_judge'=>true]);$role=get_role('administrator');if($role)$role->add_cap('sports_meet_judge');
    add_role('sports_meet_school_registrar','高校报名共用账号',['read'=>true,'sports_meet_school_registration'=>true]);
}
function race_can_judge():bool {return current_user_can('manage_options')||current_user_can('sports_meet_judge');}
function race_one(string $table,int $id):array {if(!in_array($table,['athletes','teams','events','race_relays','race_rounds'],true))throw new \RuntimeException('资料类型无效');$r=q("SELECT * FROM $table WHERE id=?",[$id])->fetch();if(!$r)throw new \RuntimeException('资料不存在，请刷新页面。');return $r;}
function race_audit(string $action,int $id,array $payload=[]):void {q('INSERT INTO race_audit(actor,action,record_id,payload,created) VALUES(?,?,?,?,?)',[get_current_user_id(),$action,$id,wp_json_encode($payload),current_time('mysql')]);}
function race_transaction(callable $fn){$started=false;try{q('START TRANSACTION');$started=true;q('SELECT id FROM settings WHERE id=1 FOR UPDATE')->fetch();$r=$fn();q('COMMIT');return $r;}catch(\Throwable $e){if($started)q('ROLLBACK');throw $e;}}
function race_unstarted(int $event):void {if(q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$event])->fetch())throw new \RuntimeException('此项目已生成比赛名单，不能再修改报名或接力队员。');}
function race_actor():array {return [get_current_user_id(),current_time('mysql')];}
function race_event(int $id):array {return race_one('events',$id);}
function race_enroll(int $event,int $athlete):void {
    race_unstarted($event);$e=race_event($event);$a=race_one('athletes',$athlete);
    if(!in_array(race_category($a),race_event_categories($e),true))throw new \RuntimeException('运动员组别不符合项目要求。');
    if($e['gender']!=='X' && $a['gender']!==$e['gender'])throw new \RuntimeException('运动员性别不符合项目要求。');
    if(!race_group_matches($e['age_group'],$a['age_group'],$a['gender']))throw new \RuntimeException('运动员组别不符合项目要求。');
    q('INSERT IGNORE INTO race_enrollments(event_id,athlete_id) VALUES(?,?)',[$event,$athlete]);
}
function race_checkin(int $athlete,string $bib,int $version):void {
    $person=race_one('athletes',$athlete);
    if(!disclaimer_agreed($person,disclaimer_acceptances($athlete))){
        throw new \RuntimeException('该运动员尚未同意当前免责声明，不能发号或改号。');
    }
    $bib=trim($bib);
    if(!preg_match('/^\d{1,8}$/',$bib)||(int)$bib===0)throw new \RuntimeException('比赛号码须为1至99999999的数字。');$bib=(string)(int)$bib;
    $old=q('SELECT * FROM checkins WHERE athlete_id=?',[$athlete])->fetch();
    if(($old?(int)$old['version']:0)!==$version)throw new \RuntimeException('其他裁判已更新该运动员，请刷新后核对。');
    if($old && !current_user_can('manage_options'))throw new \RuntimeException('该运动员已发号；改号请由管理员操作。');
    $used=q('SELECT athlete_id FROM checkins WHERE bib=? AND athlete_id<>?',[$bib,$athlete])->fetch();if($used)throw new \RuntimeException('此比赛号码已被使用，请换一个号码。');
    [$actor,$now]=race_actor();if($old)q('UPDATE checkins SET bib=?,version=version+1,actor=?,updated=? WHERE athlete_id=?',[$bib,$actor,$now,$athlete]);else q('INSERT INTO checkins(athlete_id,bib,actor,updated) VALUES(?,?,?,?)',[$athlete,$bib,$actor,$now]);
    race_audit('checkin',$athlete,['old'=>$old['bib']??null,'bib'=>$bib]);
}
function race_members(int $relay):array {return q('SELECT a.*,t.name AS team_name,c.bib AS race_bib FROM race_members m JOIN athletes a ON a.id=m.athlete_id JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE m.relay_id=? ORDER BY a.id',[$relay])->fetchAll();}
function race_relay_validate(int $relay,bool $checked=false):array {
    $r=race_one('race_relays',$relay);$e=race_event((int)$r['event_id']);$members=race_members($relay);if(count($members)!==8)throw new \RuntimeException($r['name'].'须有8名不同队员。');
    $male=(int)q('SELECT relay_males FROM settings WHERE id=1')->fetchColumn();$men=0;
    foreach($members as $a){$men+=($a['gender']==='M');if(!race_group_matches($e['age_group'],$a['age_group'],$a['gender']))throw new \RuntimeException('接力组别不符合要求。');if($checked && !$a['race_bib'])throw new \RuntimeException('接力成员'.$a['name'].'尚未检录发号。');}
    if($men!==$male)throw new \RuntimeException('接力要求'.$male.'男'.(8-$male).'女，可由管理员在设置调整。');return $members;
}
function race_set_relay(int $event,int $relay,string $name,array $athleteIds):int {
    race_unstarted($event);$e=race_event($event);if($e['kind']!=='relay')throw new \RuntimeException('请选择接力项目。');$name=trim($name);
    if($name===''||mb_strlen($name)>80)throw new \RuntimeException('接力队名称须为1至80字。');
    $athleteIds=array_values(array_unique(array_map('intval',$athleteIds)));if(count($athleteIds)!==8)throw new \RuntimeException('须填写8名不同队员。');
    if($relay){$old=race_one('race_relays',$relay);if((int)$old['event_id']!==$event)throw new \RuntimeException('接力项目不符。');q('UPDATE race_relays SET name=? WHERE id=?',[$name,$relay]);q('DELETE FROM race_members WHERE relay_id=?',[$relay]);}
    else{q('INSERT INTO race_relays(event_id,name) VALUES(?,?)',[$event,$name]);global $wpdb;$relay=(int)$wpdb->insert_id;}
    foreach($athleteIds as $id){
        if(!race_registration_closed())race_enroll($event,$id);
        elseif(!q('SELECT id FROM race_enrollments WHERE event_id=? AND athlete_id=?',[$event,$id])->fetch())throw new \RuntimeException('报名已截止，只能使用已报名此项目的队员组队。');
        if(q('SELECT relay_id FROM race_members WHERE event_id=? AND athlete_id=?',[$event,$id])->fetch())throw new \RuntimeException('同一队员不能在本项目加入两个接力队。');
        q('INSERT INTO race_members(relay_id,event_id,athlete_id) VALUES(?,?,?)',[$relay,$event,$id]);
    }
    race_relay_validate($relay);race_audit('relay',$relay,['members'=>$athleteIds]);return $relay;
}
function race_create_round(int $event,string $stage,int $heat):int {[$actor,$now]=race_actor();q('INSERT INTO race_rounds(event_id,stage,heat_no,actor,updated) VALUES(?,?,?,?,?)',[$event,$stage,$heat,$actor,$now]);global $wpdb;return (int)$wpdb->insert_id;}
function race_add_entry(int $round,string $key):void {
    $kind=$key[0];$id=(int)substr($key,1);q('INSERT IGNORE INTO race_entries(round_id,participant,athlete_id,relay_id) VALUES(?,?,?,?)',[$round,$key,$kind==='A'?$id:null,$kind==='R'?$id:null]);
}
function race_eight_lane_event(array $event): bool {
    return $event['kind']==='relay'||(bool)preg_match('/(?<![0-9])100\s*(?:米|公尺|m)(?![a-z])/iu',$event['name']);
}

function race_lane_groups(array $keys): array {
    $count=(int)ceil(count($keys)/8);
    $groups=array_fill(0,$count,[]);
    foreach($keys as $i=>$key)$groups[$i%$count][]=$key;
    return $groups;
}

function race_lane_quota(int $groups): int {return $groups>0?intdiv(8,$groups):0;}

function race_placing_plan(int $groups): array {
    if(in_array($groups,[1,2,4,8],true))return [intdiv(8,$groups),0];
    $semis=$groups<=8?2:8;
    return [intdiv($semis*8,$groups),$semis];
}

function race_placing_quota(array $round): int {
    $groups=(int)q('SELECT COUNT(*) FROM race_rounds WHERE event_id=? AND stage=?',[$round['event_id'],$round['stage']])->fetchColumn();
    return $round['stage']==='semi'?intdiv(8,$groups):race_placing_plan($groups)[0];
}

function race_placing_stage(int $event,string $stage,int $quota): array {
    if(q("SELECT id FROM race_rounds WHERE event_id=? AND stage=? AND status<>'finalized' LIMIT 1",[$event,$stage])->fetch())return [];
    return q("SELECT en.*,r.heat_no FROM race_entries en JOIN race_rounds r ON r.id=en.round_id
        WHERE r.event_id=? AND r.stage=? AND r.status='finalized' AND en.confirmed=1 AND en.place<=?
        ORDER BY en.place,r.heat_no",[$event,$stage,$quota])->fetchAll();
}

function race_generate(int $event,string $stage,int $capacity,bool $individualSplit=false):void {
    race_unstarted($event);$e=race_event($event);if(!in_array($stage,['heat','final'],true)||$capacity<1||$capacity>100)throw new \RuntimeException('分组人数须为1至100。');
    $keys=[];
    if($e['kind']==='individual'){foreach(q('SELECT athlete_id FROM race_enrollments WHERE event_id=? ORDER BY id',[$event])->fetchAll() as $r)$keys[]='A'.$r['athlete_id'];}
    else{
        $relays=q('SELECT id FROM race_relays WHERE event_id=? ORDER BY id',[$event])->fetchAll();foreach($relays as $r){race_relay_validate((int)$r['id']);$keys[]='R'.$r['id'];}
        $unassigned=(int)q('SELECT COUNT(*) FROM race_enrollments n LEFT JOIN race_members m ON m.event_id=n.event_id AND m.athlete_id=n.athlete_id WHERE n.event_id=? AND m.athlete_id IS NULL',[$event])->fetchColumn();if($unassigned)throw new \RuntimeException('还有接力报名者未编入接力队，请先组队或取消其接力报名。');
    }
    if(!$keys)throw new \RuntimeException('此项目没有参赛者。');
    if(race_eight_lane_event($e)){
        if(count($keys)>512)throw new \RuntimeException('不计时赛制最多编排512人／队，请先拆分项目。');
        if($stage==='final'&&count($keys)>8)throw new \RuntimeException('超过8人／队，请先生成预赛。');
        $stage=count($keys)<=8?'final':'heat';
        shuffle($keys);
        foreach(race_lane_groups($keys) as $i=>$group){
            $round=race_create_round($event,$stage,$i+1);
            q('UPDATE race_rounds SET eight_lanes=2 WHERE id=?',[$round]);
            foreach($group as $key)race_add_entry($round,$key);
        }
        race_audit('generate',$event,['stage'=>$stage,'capacity'=>8]);
        return;
    }
    if($e['kind']==='individual' && !$individualSplit)$capacity=count($keys);
    $estimate=$stage==='final'?count($keys):(int)ceil(count($keys)/$capacity)*min($capacity,$e['kind']==='relay'?2:3);
    if($e['kind']==='individual'&&!$individualSplit&&count($keys)>200)throw new \RuntimeException('个人单组最多200人，请启用分组或拆分项目。');
    if($estimate>200)throw new \RuntimeException('预计决赛人数超过200，请增加预赛每组人数或按年龄／性别拆分项目。');
    foreach(($stage==='final'?[$keys]:array_chunk($keys,$capacity)) as $i=>$group){$round=race_create_round($event,$stage,$i+1);foreach($group as $key)race_add_entry($round,$key);}
    race_audit('generate',$event,['stage'=>$stage,'capacity'=>$capacity]);
}
function race_qualifiers(int $event):array {
    $heats=q("SELECT * FROM race_rounds WHERE event_id=? AND stage='heat' ORDER BY heat_no",[$event])->fetchAll();
    $format=(int)($heats[0]['eight_lanes']??0);
    if($format===2){
        [$quota,$semis]=race_placing_plan(count($heats));
        $qualified=race_placing_stage($event,'heat',$quota);
        if(!$semis||count($qualified)<=8)return $qualified;
        $semiCount=(int)q("SELECT COUNT(*) FROM race_rounds WHERE event_id=? AND stage='semi'",[$event])->fetchColumn();
        return $semiCount?race_placing_stage($event,'semi',intdiv(8,$semiCount)):[];
    }
    if($format===1){
        foreach($heats as $heat)if($heat['status']!=='finalized')return [];
        $quota=race_lane_quota(count($heats));
        $rows=q("SELECT en.* FROM race_entries en JOIN race_rounds r ON r.id=en.round_id
            WHERE r.event_id=? AND r.stage='heat' AND en.confirmed=1 AND en.elapsed IS NOT NULL
            ORDER BY en.elapsed,COALESCE(en.tie_order,2147483647),r.heat_no,en.place",[$event])->fetchAll();
        $direct=[];$remaining=[];
        foreach($rows as $row){
            if((int)$row['place']<=$quota)$direct[]=$row;
            else $remaining[]=$row;
        }
        $slots=8-count($direct);
        if($slots>0&&isset($remaining[$slots])&&$remaining[$slots-1]['elapsed']===$remaining[$slots]['elapsed']){
            $time=$remaining[$slots]['elapsed'];
            $orders=array_column(array_filter($remaining,static fn($r)=>$r['elapsed']===$time),'tie_order');
            if(in_array(null,$orders,true)||count(array_unique($orders))!==count($orders)){
                throw new \RuntimeException('最后一个晋级名额出现同时间，请裁判给同时间选手填写不同的同时间顺序，再提交。');
            }
        }
        return array_merge($direct,array_slice($remaining,0,$slots));
    }
    $e=race_event($event);$limit=$e['kind']==='relay'?2:3;
    return q("SELECT en.* FROM race_entries en JOIN race_rounds r ON r.id=en.round_id WHERE r.event_id=? AND r.stage='heat' AND r.status='finalized' AND en.confirmed=1 AND en.place<=? ORDER BY r.heat_no,en.place",[$event,$limit])->fetchAll();
}
function race_sync_final(int $event):void {
    $qualifiers=race_qualifiers($event);if(!$qualifiers)return;
    $final=q("SELECT * FROM race_rounds WHERE event_id=? AND stage='final'",[$event])->fetch();
    if($final && $final['status']==='finalized')throw new \RuntimeException('决赛已提交，不能更改晋级名单。');
    $id=$final?(int)$final['id']:race_create_round($event,'final',1);
    foreach($qualifiers as $r)race_add_entry($id,$r['participant']);
    q('UPDATE race_rounds SET version=version+1 WHERE id=?',[$id]);
}
function race_advance_round(array $round): void {
    $event=(int)$round['event_id'];
    if($round['stage']==='heat'&&(int)($round['eight_lanes']??0)===2){
        $groups=(int)q("SELECT COUNT(*) FROM race_rounds WHERE event_id=? AND stage='heat'",[$event])->fetchColumn();
        [$quota,$semis]=race_placing_plan($groups);
        $qualified=race_placing_stage($event,'heat',$quota);
        if($semis&&count($qualified)>8){
            if(q("SELECT id FROM race_rounds WHERE event_id=? AND stage='semi' LIMIT 1",[$event])->fetch())return;
            while(count($qualified)<=($semis/2)*8)$semis=intdiv($semis,2);
            $rounds=[];
            for($i=1;$i<=$semis;$i++){
                $id=race_create_round($event,'semi',$i);
                q('UPDATE race_rounds SET eight_lanes=2 WHERE id=?',[$id]);
                $rounds[]=$id;
            }
            $tiers=[];
            foreach($qualified as $row)$tiers[$row['place']][]=$row['participant'];
            $slot=0;
            foreach($tiers as $place=>$tier){
                if($place%2===0)$tier=array_reverse($tier);
                foreach($tier as $participant)race_add_entry($rounds[$slot++%$semis],$participant);
            }
            return;
        }
    }
    race_sync_final($event);
}
function race_round_ready(array $r):void {
    if($r['stage']==='final' && q("SELECT id FROM race_rounds WHERE event_id=? AND stage IN ('heat','semi') AND status<>'finalized' LIMIT 1",[$r['event_id']])->fetch())throw new \RuntimeException('须等本项目所有预赛及半决赛提交完成后才能录入决赛。');
}
function race_timed_heat_results(array $rows,array $submitted,bool $finalize): array {
    $ranked=[];$times=[];
    foreach($rows as $row){
        $id=$row['id'];
        $entry=$submitted[$id];
        $raw=trim((string)($entry['elapsed']??''));
        $tie=trim((string)($entry['tie_order']??''));
        $confirmed=!empty($entry['confirmed']);
        if($raw!==''&&!preg_match('/^\d{1,6}(?:\.\d{1,3})?$/',$raw))throw new \RuntimeException('用时须填秒数，最多3位小数；无有效成绩填0。');
        if($tie!==''&&(!ctype_digit($tie)||(int)$tie<1||(int)$tie>999))throw new \RuntimeException('同时间顺序须为1至999的整数。');
        if($finalize&&$confirmed&&$raw==='')throw new \RuntimeException('请填写每位已确认参赛者的用时；无有效成绩填0。');
        $elapsed=$confirmed&&$raw!==''&&(float)$raw>0?$raw:null;
        $submitted[$id]=['confirmed'=>$confirmed,'place'=>'','elapsed'=>$elapsed,'no_mark'=>$confirmed&&$raw!==''&&$elapsed===null,'tie_order'=>$elapsed!==null&&$tie!==''?(int)$tie:null];
        if($elapsed!==null){
            $units=(int)round((float)$elapsed*1000);
            $ranked[]=['id'=>$id,'time'=>$units,'tie'=>$submitted[$id]['tie_order']];
            $times[$units][]=$submitted[$id]['tie_order'];
        }
    }
    if($finalize)foreach($times as $ties){
        if(count($ties)>1&&(in_array(null,$ties,true)||count(array_unique($ties))!==count($ties))){
            throw new \RuntimeException('同组有相同用时，请按实际冲线顺序填写不同的同时间顺序。');
        }
    }
    usort($ranked,static fn($a,$b)=>($a['time']<=>$b['time'])?:(($a['tie']??1000)<=>($b['tie']??1000))?:($a['id']<=>$b['id']));
    foreach($ranked as $i=>$item)$submitted[$item['id']]['place']=(string)($i+1);
    return $submitted;
}

function race_save_round(int $id,int $version,array $submitted,bool $finalize,bool $empty=false):void {
    $round=race_one('race_rounds',$id);race_round_ready($round);
    if($round['status']==='finalized')throw new \RuntimeException('此轮已提交完成，不能再修改。');
    if((int)$round['version']!==$version)throw new \RuntimeException('其他裁判已保存或晋级名单已变化，请刷新后重新核对，未覆盖他人资料。');
    $rows=q('SELECT * FROM race_entries WHERE round_id=? ORDER BY id',[$id])->fetchAll();$expected=array_map('intval',array_column($rows,'id'));$keys=array_map('intval',array_keys($submitted));sort($expected,SORT_NUMERIC);sort($keys,SORT_NUMERIC);if($expected!==$keys)throw new \RuntimeException('参赛名单已变化，请刷新后重新录入。');
    $format=(int)($round['eight_lanes']??0);
    $timedHeat=$round['stage']==='heat'&&$format===1;
    $placingRound=$round['stage']!=='final'&&$format===2;
    $maxPlace=$placingRound?race_placing_quota($round):3;
    $distanceEvent=race_is_distance(race_event((int)$round['event_id']));if($distanceEvent)$submitted=race_rank_distances($rows,$submitted,$finalize);
    if($timedHeat)$submitted=race_timed_heat_results($rows,$submitted,$finalize);
    $places=[];$confirmed=0;$clean=[];
    foreach($rows as $r){$d=$submitted[$r['id']];if(!is_array($d))throw new \RuntimeException('提交格式错误。');$yes=!empty($d['confirmed']);$place=trim((string)($d['place']??''));
        $noFinish=$placingRound&&!empty($d['no_mark']);
        if($place!=='' && ((!$timedHeat&&(!ctype_digit($place)||(int)$place<1||(int)$place>$maxPlace||(string)(int)$place!==$place))||!$yes||$noFinish))throw new \RuntimeException('请填写有效名次，并确认该选手参赛且完赛。');
        if($place!=='' && isset($places[$place]))throw new \RuntimeException('同一轮不能出现重复名次。');if($place!=='')$places[$place]=true;
        if($yes){$confirmed++;if($r['athlete_id']){if(!q('SELECT bib FROM checkins WHERE athlete_id=?',[$r['athlete_id']])->fetch())throw new \RuntimeException('有参赛者尚未检录发号。');}else race_relay_validate((int)$r['relay_id'],true);}
        $clean[]=['id'=>$r['id'],'confirmed'=>$yes?1:0,'place'=>$place===''?null:(int)$place,'distance'=>$distanceEvent?$d['distance']:null,'no_mark'=>($distanceEvent||$timedHeat)?(int)$d['no_mark']:(int)($yes&&$noFinish),'tie_order'=>($distanceEvent||$timedHeat)?$d['tie_order']:null,'elapsed'=>$timedHeat?$d['elapsed']:null];
    }
    if($empty&&$confirmed)throw new \RuntimeException('仍有已确认参赛者，不能提交为无人出赛。');
    $ranked=$distanceEvent?count(array_filter($clean,fn($r)=>$r['confirmed']&&$r['distance']!==null&&!$r['no_mark'])):$confirmed;
    if($placingRound)$ranked-=array_sum(array_column($clean,'no_mark'));
    if($finalize){if(!$confirmed&&!$empty)throw new \RuntimeException('无人出赛请使用专门的无人出赛确认，避免误提交。');if(!$timedHeat)for($i=1;$i<=min($maxPlace,$ranked);$i++)if(!isset($places[$i]))throw new \RuntimeException('提交完成前须依次填写前'.min($maxPlace,$ranked).'名。');}
    q('UPDATE race_entries SET place=NULL WHERE round_id=?',[$id]);foreach($clean as $r)q('UPDATE race_entries SET confirmed=?,place=?,distance=?,no_mark=?,tie_order=?,elapsed=? WHERE id=?',[$r['confirmed'],$r['place'],$r['distance'],$r['no_mark'],$r['tie_order'],$r['elapsed'],$r['id']]);
    [$actor,$now]=race_actor();q('UPDATE race_rounds SET status=?,version=version+1,actor=?,updated=? WHERE id=?',[$finalize?'finalized':'open',$actor,$now,$id]);
    if($finalize && $round['stage']!=='final')race_advance_round($round);
    race_audit($finalize?'finalize_round':'save_round',$id,['entries'=>$clean]);
}
function race_move(int $entry,int $heat):void {
    if($heat<1||$heat>100)throw new \RuntimeException('组号须为1至100。');$row=q('SELECT e.*,r.event_id,r.stage FROM race_entries e JOIN race_rounds r ON r.id=e.round_id WHERE e.id=?',[$entry])->fetch();if(!$row||$row['stage']!=='heat')throw new \RuntimeException('只能调整预赛分组。');
    if(q("SELECT id FROM race_rounds WHERE event_id=? AND (status='finalized' OR stage='final') LIMIT 1",[$row['event_id']])->fetch())throw new \RuntimeException('预赛已开始提交，不能调整分组。');
    $source=race_one('race_rounds',(int)$row['round_id']);
    if(!empty($source['eight_lanes']))throw new \RuntimeException('8道项目已随机均分，不能单独移动参赛者。');
    $target=q("SELECT id FROM race_rounds WHERE event_id=? AND stage='heat' AND heat_no=?",[$row['event_id'],$heat])->fetchColumn();if(!$target)$target=race_create_round((int)$row['event_id'],'heat',$heat);
    if((int)$target===(int)$row['round_id'])return;
    q('UPDATE race_entries SET round_id=?,confirmed=0,place=NULL WHERE id=?',[$target,$entry]);q('UPDATE race_rounds SET version=version+1 WHERE id=? OR id=?',[$target,$row['round_id']]);
    $quota=race_event((int)$row['event_id'])['kind']==='relay'?2:3;$estimate=0;foreach(q("SELECT COUNT(*) AS n FROM race_entries e JOIN race_rounds r ON r.id=e.round_id WHERE r.event_id=? AND r.stage='heat' GROUP BY r.id",[$row['event_id']])->fetchAll() as $count)$estimate+=min($quota,(int)$count['n']);if($estimate>200)throw new \RuntimeException('调整后预计决赛人数超过200，请合并分组。');
    if(!(int)q('SELECT COUNT(*) FROM race_entries WHERE round_id=?',[$row['round_id']])->fetchColumn())q('DELETE FROM race_rounds WHERE id=?',[$row['round_id']]);race_audit('move_entry',$entry,['heat'=>$heat]);
}
function race_cents($value):int {$s=trim((string)$value);if(!preg_match('/^\d{1,5}(?:\.\d{1,2})?$/',$s))throw new \RuntimeException('分值须为0至99999、最多两位小数。');return (int)round((float)$s*100);}
function race_fmt(int $units):string {return rtrim(rtrim(number_format($units/800,5,'.',''),'0'),'.');}
function race_scoreboard():array {
    $rules=get_option('sports_meet_race_rules');$tot=[];foreach(all('teams') as $t)$tot[$t['id']]=['id'=>$t['id'],'name'=>$t['name'],'part'=>0,'place'=>0,'total'=>0];$seen=[];
    $rows=q("SELECT e.*,r.event_id,r.stage FROM race_entries e JOIN race_rounds r ON r.id=e.round_id WHERE r.status='finalized' AND e.confirmed=1 ORDER BY r.id,e.id")->fetchAll();
    foreach($rows as $r){$relay=!empty($r['relay_id']);$members=$relay?race_members((int)$r['relay_id']):[race_one('athletes',(int)$r['athlete_id'])];foreach($members as $a){$team=$a['team_id'];$key=$r['event_id'].':'.$a['id'];if(!isset($seen[$key])){$tot[$team]['part']+=race_cents($rules[$relay?'relay_part':'individual_part'])*8;$seen[$key]=true;}
            if($r['stage']==='final' && $r['place']!==null){$cents=race_cents($rules[$relay?'relay_places':'individual_places'][(int)$r['place']-1]);$tot[$team]['place']+=$relay?$cents:$cents*8;}
        }
    }
    foreach($tot as &$t)$t['total']=$t['part']+$t['place'];unset($t);usort($tot,fn($a,$b)=>($b['total']<=>$a['total'])?:strcmp($a['name'],$b['name']));$rank=0;$last=null;foreach($tot as $i=>&$t){if($last===null||$last!==$t['total'])$rank=$i+1;$last=$t['total'];$t['rank']=$rank;}unset($t);return $tot;
}
function race_participant(array $entry):array {
    if($entry['athlete_id']){$a=q('SELECT a.*,t.name AS team_name,c.bib AS race_bib FROM athletes a JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE a.id=?',[$entry['athlete_id']])->fetch();return ['name'=>$a['name'],'team'=>$a['team_name'],'bib'=>$a['race_bib']??'未检录','members'=>$a['name'],'key'=>'A'.$a['id']];}
    $r=race_one('race_relays',(int)$entry['relay_id']);$m=race_members((int)$r['id']);return ['name'=>$r['name'],'team'=>implode('、',array_unique(array_column($m,'team_name'))),'bib'=>implode(' / ',array_map(fn($a)=>$a['race_bib']??'未检录',$m)),'members'=>implode('、',array_column($m,'name')),'key'=>'R'.$r['id']];
}
