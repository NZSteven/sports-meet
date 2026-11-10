<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function relay_manual_state(int $event):?array {
    global $wpdb;
    $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'sports_meet_relay_manual_'.$event));
    $value=$raw===null?null:maybe_unserialize($raw);return is_array($value)?$value:null;
}
function relay_manual_store(int $event,array $value):void {
    global $wpdb;$key='sports_meet_relay_manual_'.$event;
    if($wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",$key,maybe_serialize($value)))===false)throw new \RuntimeException('手动编排未能保存。');
    wp_cache_delete($key,'options');wp_cache_delete('notoptions','options');delete_transient('sports_meet_relay_entrance_preview');
}
function relay_manual_editable(int $event):void {
    if(race_event($event)['kind']!=='relay')throw new \RuntimeException('请选择接力项目。');
    if(q("SELECT id FROM race_rounds WHERE event_id=? AND (status<>'open' OR version>1) LIMIT 1",[$event])->fetch()
        ||q('SELECT en.id FROM race_entries en JOIN race_rounds r ON r.id=en.round_id WHERE r.event_id=? AND (en.confirmed=1 OR en.place IS NOT NULL OR en.elapsed IS NOT NULL OR en.distance IS NOT NULL OR en.no_mark=1) LIMIT 1',[$event])->fetch())throw new \RuntimeException('本项目已有暂存或比赛记录，不能调整接力队；请先核对比赛记录。');
}
function relay_manual_registration_guard(int $athlete,array $input):void {
    if(!$athlete)return;
    $old=race_one('athletes',$athlete);
    foreach(q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$athlete])->fetchAll(7) as $event){
        $state=relay_manual_state((int)$event);if(!$state)continue;
        $used=false;foreach($state['teams'] as $team)if(in_array($athlete,$team['members'],true)){$used=true;break;}
        if(!$used)continue;
        if(!empty($input['delete_athlete'])||(int)($input['remove_event']??0)===(int)$event
            ||(isset($input['registration_token'])&&!in_array((int)$event,array_map('intval',(array)($input['event_ids']??[])),true))
            ||(isset($input['team_id'])&&(int)$input['team_id']!==(int)$old['team_id'])
            ||(isset($input['gender'])&&$input['gender']!==$old['gender'])
            ||(isset($input['category'])&&isset(race_categories()[$input['category']])&&race_categories()[$input['category']][0]!==$old['gender']))
            throw new \RuntimeException('该队员已列入组委会锁定的接力名单，请先在手动调整中换出，再取消报名、改性别或换校。');
    }
}
function relay_manual_source(int $event):array {
    $schools=relay_auto_school_rows($event);$rows=q('SELECT * FROM race_relays WHERE event_id=? ORDER BY id',[$event])->fetchAll();
    if(q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$event])->fetch()){
        $teams=[];foreach($rows as $r){$parts=[];$members=race_members((int)$r['id']);foreach($members as $m){$sid=(int)$m['team_id'];$parts[$sid]??=[0,0];$parts[$sid][$m['gender']==='M'?0:1]++;}$teams[]=['name'=>$r['name'],'school'=>$r['school_id']?(int)$r['school_id']:null,'members'=>array_map('intval',array_column($members,'id')),'parts'=>$parts,'relay_id'=>(int)$r['id']];}
        return ['schools'=>$schools,'teams'=>$teams];
    }
    $state=relay_manual_state($event);
    return $state?['schools'=>$schools,'teams'=>$state['teams']]:relay_auto_plan($schools);
}
function relay_manual_revision(int $event):string {
    return hash('sha256',wp_json_encode([relay_manual_source($event),q('SELECT id,status,version FROM race_rounds WHERE event_id=? ORDER BY id',[$event])->fetchAll(),relay_manual_state($event)]));
}
function relay_manual_validate(int $event,array $teams):array {
    $schools=relay_auto_school_rows($event);$people=[];$reserved=[];
    foreach($schools as $sid=>$school){foreach(['M','F'] as $sex)foreach($school[$sex] as $id)$people[$id]=['school'=>$sid,'gender'=>$sex];if(count($school['M'])>=4&&count($school['F'])>=4)$reserved[$sid]=true;}
    $state=relay_manual_state($event);
    if($state){$reserved=[];foreach($state['teams'] as $saved)if($saved['school'])$reserved[(int)$saved['school']]=true;}
    $seen=[];$names=[];$out=[];$independent=[];
    foreach($teams as $team){
        $name=race_text($team['name']??'',80);if(isset($names[$name]))throw new \RuntimeException('队名不能重复。');$names[$name]=true;
        $raw=$team['members']??null;if(!is_array($raw)||count($raw)!==8)throw new \RuntimeException($name.'须选8名上场队员。');
        $ids=[];$parts=[];$men=0;$school=isset($team['school'])?(int)$team['school']:0;
        foreach($raw as $rawId){
            if(!is_scalar($rawId)||!ctype_digit((string)$rawId)||!isset($people[(int)$rawId]))throw new \RuntimeException('队员已取消接力报名或不存在，请刷新核对。');
            $id=(int)$rawId;$person=$people[$id];if(isset($seen[$id]))throw new \RuntimeException('同一队员不能在两个位置或队伍重复出现。');$seen[$id]=true;$ids[]=$id;
            $sid=$person['school'];if($school&&$sid!==$school)throw new \RuntimeException('独立校队只能选择本校队员。');
            if(!$school&&isset($reserved[$sid]))throw new \RuntimeException('已独立成队高校的正式队员及候补，均不能加入混合队。');
            $parts[$sid]??=[0,0];$parts[$sid][$person['gender']==='M'?0:1]++;$men+=($person['gender']==='M');
        }
        if($men!==4)throw new \RuntimeException($name.'须为4男4女。');
        if(!$school&&(count($parts)<2||count($parts)>3))throw new \RuntimeException('混合队须由两至三所高校组成。');
        if($school){if(isset($independent[$school]))throw new \RuntimeException('同一高校不能建立两个独立接力队。');$independent[$school]=true;}
        $out[]=['name'=>$name,'school'=>$school?:null,'members'=>$ids,'parts'=>$parts,'relay_id'=>(int)($team['relay_id']??0)];
    }
    return $out;
}
function relay_manual_effective_plan(int $event):array {
    $state=relay_manual_state($event);$schools=relay_auto_school_rows($event);if(!$state)return relay_auto_plan($schools);
    $teams=relay_manual_validate($event,$state['teams']);$independent=[];$used=[];
    foreach($teams as $team){if($team['school'])$independent[$team['school']]=true;foreach($team['members'] as $id)$used[$id]=true;}
    $remaining=[];foreach($schools as $sid=>$row){if(isset($independent[$sid]))continue;foreach(['M','F'] as $sex)$row[$sex]=array_values(array_filter($row[$sex],fn($id)=>!isset($used[$id])));$remaining[$sid]=$row;}
    return ['schools'=>$schools,'teams'=>$teams,'independent'=>$independent,'remaining'=>$remaining,'manual'=>true];
}
function relay_manual_save(int $event,array $input):void {
    if(!race_can_manage())throw new \RuntimeException('仅管理员可调整接力队。');
    relay_manual_editable($event);
    if(!hash_equals(relay_manual_revision($event),(string)($input['manual_revision']??'')))throw new \RuntimeException('报名、编排或其他管理员的调整已变化，请刷新后再保存。');
    $source=relay_manual_source($event);$submitted=$input['manual_teams']??[];
    if(!is_array($submitted)||count($submitted)!==count($source['teams'])||!$submitted)throw new \RuntimeException('队伍数已变化，请刷新。');
    $teams=[];foreach($source['teams'] as $i=>$team){if(!isset($submitted[$i])||!is_array($submitted[$i]))throw new \RuntimeException('队伍格式错误。');$team['name']=$submitted[$i]['name']??'';$team['members']=$submitted[$i]['members']??[];$teams[]=$team;}
    $teams=relay_manual_validate($event,$teams);
    if(q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$event])->fetch()){
        q('DELETE FROM race_members WHERE event_id=?',[$event]);
        foreach($teams as $team){$id=$team['relay_id'];if(!$id)throw new \RuntimeException('正式队伍标识缺失。');q('UPDATE race_relays SET name=?,school_id=? WHERE id=? AND event_id=?',[$team['name'],$team['school'],$id,$event]);foreach($team['members'] as $athlete)q('INSERT INTO race_members(relay_id,event_id,athlete_id) VALUES(?,?,?)',[$id,$event,$athlete]);}
    }
    relay_manual_store($event,['teams'=>$teams,'revision'=>wp_generate_uuid4()]);
    race_audit('manual_relay',$event,['teams'=>$teams]);
}
function relay_manual_ui():void {
    if(!race_can_manage())return;
    echo '<section class="sm-relay-manual"><h2>手动调整接力队（组委会）</h2><p>可改队名、换队员或在混合队之间交换队员。每队4男4女；同一人不可重复；独立校队人员不能加入混合队。保存后锁定整份手动编排，系统不再自动覆盖。已有暂存或赛果的项目不能改队。</p>';
    $events=array_values(array_filter(all('events'),fn($e)=>$e['kind']==='relay'));$event=absint($_GET['manual_relay_event']??($events[0]['id']??0));
    echo '<form method="get"><input type="hidden" name="race_tab" value="relay">';if(is_admin())echo '<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races">';elseif(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])[race_day_context()==='chief'?'chief':'judge']??0).'">';race_options('manual_relay_event','接力项目',$events,(string)$event);echo '<button>打开手动调整</button></form>';
    if(!$event){echo '</section>';return;}
    try{relay_manual_editable($event);$plan=relay_manual_source($event);$revision=relay_manual_revision($event);}catch(\Throwable $e){echo '<p>'.esc_html($e->getMessage()).'</p></section>';return;}
    if(!$plan['teams']){echo '<p>尚无满足4男4女的队伍，请先完成报名。</p></section>';return;}
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_manual_relay"><input type="hidden" name="event_id" value="'.$event.'"><input type="hidden" name="manual_revision" value="'.esc_attr($revision).'">'.wp_nonce_field('sports_meet_manual_relay','manual_nonce',false,false);
    if(race_day_context()==='chief')echo '<input type="hidden" name="return_chief" value="1">';
    $accepted=disclaimer_acceptances();
    $people=q('SELECT a.*,t.name AS school,c.bib AS race_bib FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE n.event_id=? ORDER BY t.name,a.name,a.id',[$event])->fetchAll();
    $reserved=[];foreach($plan['teams'] as $team)if($team['school'])$reserved[(int)$team['school']]=true;
    foreach($plan['teams'] as $i=>$team){
        echo '<details class="race-activities-picker"><summary>'.esc_html($team['name']).' · 4男4女</summary><label>队名<input name="manual_teams['.$i.'][name]" maxlength="80" value="'.esc_attr($team['name']).'" required></label><div class="race-event-grid">';
        foreach(['M'=>'男','F'=>'女'] as $sex=>$label){$selected=array_values(array_filter($team['members'],fn($id)=>in_array($id,array_map('intval',array_column(array_filter($people,fn($a)=>$a['gender']===$sex),'id')),true)));
            for($slot=0;$slot<4;$slot++){echo '<label>'.$label.'队员'.($slot+1).'<select name="manual_teams['.$i.'][members][]" required><option value="">请选择</option>';foreach($people as $a)if($a['gender']===$sex&&($team['school']?(int)$a['team_id']===(int)$team['school']:!isset($reserved[(int)$a['team_id']])))echo '<option value="'.(int)$a['id'].'" '.selected((int)$a['id'],(int)($selected[$slot]??0),false).'>'.esc_html($a['school'].' · '.$a['name']).'</option>';echo '</select></label>';}
        }
        echo '</div><h4>当前上场队员及免责状态</h4>';disclaimer_members_table(array_values(array_filter($people,fn($a)=>in_array((int)$a['id'],$team['members'],true))),$accepted);echo '</details>';
    }
    echo '<button>保存并锁定手动编排</button></form></section>';
}
add_action('admin_post_sports_meet_manual_relay',function(){
    if(!race_can_manage())wp_die('仅管理员可调整接力队。','',['response'=>403]);check_admin_referer('sports_meet_manual_relay','manual_nonce');
    $event=absint($_POST['event_id']??0);
    try{race_transaction(fn()=>relay_manual_save($event,wp_unslash($_POST)));}
    catch(\Throwable $e){wp_die(esc_html($e instanceof DatabaseException?'保存失败，请刷新后核对队名、队员及比赛状态。':$e->getMessage()),'接力手动调整',['response'=>400,'back_link'=>true]);}
    wp_safe_redirect(!empty($_POST['return_chief'])?chief_page_url(['race_tab'=>'relay','manual_relay_event'=>$event]):add_query_arg('manual_relay_event',$event,race_url('relay')));exit;
});
