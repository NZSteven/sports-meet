<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function relay_reserved_schools(int $event,int $except=0):array {
    $reserved=[];
    foreach(q('SELECT id,school_id FROM race_relays WHERE event_id=? AND id<>? AND school_id IS NOT NULL',[$event,$except])->fetchAll() as $row)
        $reserved[(int)$row['school_id']]=(int)$row['id'];
    return $reserved;
}
function relay_reservation_guard(int $event,int $relay,array $ids):void {
    $schools=[];foreach($ids as $id){$a=race_one('athletes',(int)$id);$schools[(int)$a['team_id']]=true;}
    $reserved=relay_reserved_schools($event,$relay);
    foreach($schools as $school=>$yes)if(isset($reserved[$school]))throw new \RuntimeException('所选队员所属高校已独立成队，该校全部接力报名者（包括候补）均不能被其他队选择。');
    if(count($schools)===1){
        $school=(int)array_key_first($schools);
        foreach(q('SELECT DISTINCT m.relay_id FROM race_members m JOIN athletes a ON a.id=m.athlete_id WHERE m.event_id=? AND a.team_id=? AND m.relay_id<>?',[$event,$school,$relay])->fetchAll(7) as $other)
            throw new \RuntimeException('该校已有队员加入其他接力队，请先移出，再独立成队。');
    }
}
function relay_portal_rows(int $event,int $school=0,int $editing=0):array {
    $reserved=relay_reserved_schools($event,$editing);
    $rows=q('SELECT a.id,a.name,a.team_id,t.name AS school,m.relay_id FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id
        LEFT JOIN race_members m ON m.athlete_id=a.id AND m.event_id=n.event_id WHERE n.event_id=? ORDER BY t.name,a.name,a.id',[$event])->fetchAll();
    return array_values(array_filter($rows,fn($a)=>($school?(int)$a['team_id']===$school:!isset($reserved[(int)$a['team_id']]))&&(!$a['relay_id']||(int)$a['relay_id']===$editing)));
}
function relay_portal_owns(int $relay,int $school):bool {
    if((int)race_one('race_relays',$relay)['school_id']===$school)return true;
    $members=race_members($relay);
    $schools=array_unique(array_map('intval',array_column($members,'team_id')));
    if(count($members)===8&&$schools===[$school])return true;
    return (int)get_option('sports_meet_relay_owner_'.$relay,0)===$school;
}
function relay_portal_save(array $p,int $school):int {
    if(race_registration_closed())throw new \RuntimeException('报名及接力组队已截止。');
    race_one('teams',$school);$event=absint($p['relay_event']??0);$relay=absint($p['relay_id']??0);
    if($relay&&!relay_portal_owns($relay,$school))throw new \RuntimeException('只能管理本校负责的接力队。');
    if($relay&&(int)race_one('race_relays',$relay)['event_id']!==$event)throw new \RuntimeException('接力项目不符。');
    race_unstarted($event);
    if(($p['relay_operation']??'')==='delete'){
        if(!$relay||empty($p['relay_confirm']))throw new \RuntimeException('请确认解散接力队。');
        q('DELETE FROM race_relays WHERE id=?',[$relay]);delete_option('sports_meet_relay_owner_'.$relay);return $relay;
    }
    $kind=$p['relay_kind']??'';if(!in_array($kind,['school','mixed'],true))throw new \RuntimeException('请选择独立校队或混合队。');
    $rows=relay_portal_rows($event,$kind==='school'?$school:0,$relay);$allowed=array_column($rows,null,'id');
    $raw=$p['relay_members']??[];if(!is_array($raw))throw new \RuntimeException('队员格式错误。');
    if($kind==='school'&&!$raw&&count($rows)===8)$raw=array_column($rows,'id');
    $ids=[];$schools=[];
    foreach($raw as $value){
        if(!is_scalar($value)||!ctype_digit((string)$value)||!isset($allowed[(int)$value]))throw new \RuntimeException('队员已被其他队保留、未报名接力，或不属于本校，请刷新后核对。');
        $ids[(int)$value]=(int)$value;$schools[(int)$allowed[(int)$value]['team_id']]=true;
    }
    if(count($ids)!==8)throw new \RuntimeException('请选8名上场队员，其余本校报名者保留为候补。');
    if($kind==='mixed'&&count($schools)<2)throw new \RuntimeException('混合队须有至少两所高校的队员。');
    $relay=race_set_relay($event,$relay,race_text($p['relay_name']??'',80),array_values($ids));
    update_option('sports_meet_relay_owner_'.$relay,$school,false);return $relay;
}
function relay_portal_form(string $operation):void {
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_relay_portal"><input type="hidden" name="relay_operation" value="'.esc_attr($operation).'">'
        .wp_nonce_field('sports_meet_relay_portal'.team_portal_token(),'relay_nonce',false,false);
}
function relay_portal_ui(int $school):void {
    echo '<details class="sm-relay-portal"><summary>接力组队：8人上场，其余候补</summary><p>接力报名不限人数。独立校队保留该校全部接力报名者，正式队员及候补均不能被混合队选择。恰好8人时可自动组队；超过8人须选择8人上场，并符合混合接力男女比例。组队后生成赛程才会锁定名单。</p>';
    $editing=absint($_GET['relay_edit']??0);$closed=race_registration_closed();
    foreach(all('events') as $event){
        if($event['kind']!=='relay')continue;$eventId=(int)$event['id'];$edit=null;
        echo '<h3>'.esc_html($event['name']).'</h3>';
        $locked=$closed||(bool)q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$eventId])->fetch();
        foreach(q('SELECT * FROM race_relays WHERE event_id=?',[$eventId])->fetchAll() as $r){
            $id=(int)$r['id'];if(!relay_portal_owns($id,$school))continue;
            $members=race_members($id);if($editing===$id)$edit=$r;
            echo '<p><strong>'.esc_html($r['name']).'</strong>：'.esc_html(implode('、',array_map(fn($a)=>$a['name'].'（'.$a['team_name'].'）',$members))).'</p>';
            if(isset(relay_reserved_schools($eventId)[$school])){
                $reserves=relay_portal_rows($eventId,$school);
                echo '<p>本校候补：'.esc_html($reserves?implode('、',array_column($reserves,'name')):'暂无').'。全部保留给本校。</p>';
            }
            if(!$locked){
                echo '<a href="'.esc_url(add_query_arg('relay_edit',$id,page_url('new_registration'))).'">编辑上场名单</a>';
                relay_portal_form('delete');echo '<input type="hidden" name="relay_event" value="'.$eventId.'"><input type="hidden" name="relay_id" value="'.$id.'"><label><input type="checkbox" name="relay_confirm" value="1" required> 确认解散（保留个人报名）</label><button>解散接力队</button></form>';
            }
        }
        if($locked){echo '<p>本项目组队名单已锁定。</p>';continue;}
        $id=(int)($edit['id']??0);$selected=$id?array_map('intval',array_column(race_members($id),'id')):[];
        relay_portal_form('save');echo '<input type="hidden" name="relay_event" value="'.$eventId.'"><input type="hidden" name="relay_id" value="'.$id.'">';
        race_options('relay_kind','组队方式',[['id'=>'school','name'=>'本校独立成队'],['id'=>'mixed','name'=>'跨校混合队']],$id&&count(array_unique(array_column(race_members($id),'team_id')))>1?'mixed':'school');
        field('relay_name','接力队名称','text',$edit['name']??'');
        // School candidates remain visible for editing an existing independent team.
        $rows=relay_portal_rows($eventId,0,$id);$own=relay_portal_rows($eventId,$school,$id);
        $choices=[];foreach(array_merge($own,$rows) as $row)$choices[(int)$row['id']]=$row;
        echo '<fieldset class="race-activities-picker"><legend>选择8名上场队员（本校恰有8人时可留空自动选择）</legend><div class="race-event-grid">';
        foreach($choices as $row)echo '<label><input type="checkbox" name="relay_members[]" value="'.(int)$row['id'].'" '.checked(in_array((int)$row['id'],$selected,true),true,false).'> '.esc_html($row['school'].' · '.$row['name']).'</label>';
        echo '</div></fieldset><button>'.($id?'更新接力队':'建立接力队').'</button></form>';
    }
    echo '</details>';
}
function relay_portal_handler():void {
    $school=(int)(team_portal_grant()['school']??0);if(!$school)wp_die('请先验证本校报名密码。','',['response'=>403]);
    check_admin_referer('sports_meet_relay_portal'.team_portal_token(),'relay_nonce');
    try{race_transaction(fn()=>relay_portal_save(wp_unslash($_POST),$school));}
    catch(\Throwable $e){wp_die(esc_html($e instanceof DatabaseException?'无法保存，请刷新核对。':$e->getMessage()),'接力组队',['response'=>400,'back_link'=>true]);}
    wp_safe_redirect(page_url('new_registration'));exit;
}
add_action('admin_post_sports_meet_relay_portal',__NAMESPACE__.'\\relay_portal_handler');
add_action('admin_post_nopriv_sports_meet_relay_portal',__NAMESPACE__.'\\relay_portal_handler');
