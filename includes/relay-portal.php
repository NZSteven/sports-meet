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
function relay_portal_save(array $p,int $school):int {throw new \RuntimeException('接力现由系统自动组队，请返回报名页面查看。');}
