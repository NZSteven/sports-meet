<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function token(): string { return wp_create_nonce("sports_meet_save"); }
function csrf(): void { if (!wp_verify_nonce((string)($_POST["csrf"]??""), "sports_meet_save")) throw new \RuntimeException("页面已过期，请刷新后重试。"); }
function all(string $table): array { if (!in_array($table,['teams','athletes','events'],true)) throw new \RuntimeException('错误数据表'); return q("SELECT * FROM $table ORDER BY id")->fetchAll(); }
function field(string $name,string $label,string $type='text',string $value='',string $extra=''): void { echo '<label>'.h($label).'<input name="'.h($name).'" type="'.h($type).'" value="'.h($value).'" '.$extra.' required></label>'; }
function selectBox(string $name,string $label,array $items,string $key='name'): void { echo '<label>'.h($label).'<select required name="'.h($name).'"><option value="">请选择</option>'; foreach($items as $i) echo '<option value="'.h($i['id']).'">'.h($i[$key]).'</option>'; echo '</select></label>'; }
function formStart(string $action): void { echo '<form method="post" action="'.h(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_save"><input type="hidden" name="tab" value="'.h(current_tab()).'"><input type="hidden" name="csrf" value="'.h(token()).'"><input type="hidden" name="sm_action" value="'.h($action).'">'; }
function finish(string $label='保存'): void { echo '<button>'.h($label).'</button></form>'; }
function csvCell($v): string { $v=(string)$v; return preg_match('/^[\s]*[=+@\-]/u',$v) ? "'".$v : $v; }
function downloadCsv(string $name,array $headers,array $rows): void { nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$name.'.csv"'); $f=fopen('php://output','w'); fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,$headers,',','"',''); foreach($rows as $r) fputcsv($f,array_map(__NAMESPACE__.'\\csvCell',$r),',','"',''); fclose($f); exit; }
function scoreRows(array $rows,array $points,int $multiplier=1): array {
    // Competition ranking: 1,1,3. Ties receive the mean of occupied place points.
    usort($rows,fn($a,$b)=>($a['sort_value']<=>$b['sort_value']) ?: ($a['id']<=>$b['id']));
    $n=count($rows); for($i=0;$i<$n;) { $j=$i+1; while($j<$n && (float)$rows[$j]['sort_value']===(float)$rows[$i]['sort_value']) $j++;
        $sum=0; for($k=$i;$k<$j;$k++) $sum+=(float)($points[$k]??0);
        for($k=$i;$k<$j;$k++) { $rows[$k]['rank']=$i+1; $rows[$k]['points']=$sum/($j-$i)*$multiplier; } $i=$j;
    } return $rows;
}
function results(?int $eventId=null): array {
    $settings=q('SELECT * FROM settings WHERE id=1')->fetch(); $points=array_map('floatval',explode(',',$settings['points'])); $out=[];
    $events=$eventId ? q('SELECT * FROM events WHERE id=?',[$eventId])->fetchAll() : all('events');
    foreach($events as $e) {
        $rows=q('SELECT r.*,t.name AS team_name,a.name AS athlete_name,a.bib FROM results r JOIN teams t ON t.id=r.team_id LEFT JOIN athletes a ON a.id=r.athlete_id WHERE r.event_id=?',[$e['id']])->fetchAll();
        $valid=[]; $other=[]; foreach($rows as $r) { $r['event_name']=$e['name']; $r['unit']=$e['unit']; $r['kind']=$e['kind']; $r['display_name']=$r['athlete_name']??$r['entry_name']; if($r['status']==='OK') { $r['sort_value']=$e['direction']==='asc'?(float)$r['performance']:-(float)$r['performance']; $valid[]=$r; } else { $r['rank']='—';$r['points']=0;$other[]=$r; } }
        $out=array_merge($out,scoreRows($valid,$points,$e['kind']==='relay'?(int)$settings['relay_multiplier']:1),$other);
    } return $out;
}
function validateResult(array $p): array {
    $event=q('SELECT * FROM events WHERE id=?',[(int)($p['event_id']??0)])->fetch(); if(!$event) throw new \RuntimeException('项目不存在');
    $status=$p['status']??'OK'; if(!in_array($status,['OK','DNS','DNF','DQ'],true)) throw new \RuntimeException('成绩状态无效');
    $performance=$status==='OK'?trim((string)($p['performance']??'')):null;
    if($status==='OK' && (!is_numeric($performance) || !is_finite((float)$performance) || (float)$performance<=0 || (float)$performance>99999999 || !preg_match('/^\d+(\.\d{1,4})?$/',$performance))) throw new \RuntimeException('成绩须为大于0的数字，最多4位小数；时间请填秒数');
    $athleteId=null;$teamId=0;$entry='';$members=[];
    if($event['kind']==='individual') {
        $a=q('SELECT * FROM athletes WHERE id=?',[(int)($p['athlete_id']??0)])->fetch(); if(!$a) throw new \RuntimeException('运动员不存在');
        if($event['gender']!=='X' && $a['gender']!==$event['gender']) throw new \RuntimeException('运动员性别不符合项目要求');
        if($event['age_group']!=='' && $a['age_group']!==$event['age_group']) throw new \RuntimeException('运动员组别不符合项目要求');
        $athleteId=(int)$a['id'];$teamId=(int)$a['team_id'];$entry='A'.$athleteId;
    } else {
        $teamId=(int)($p['team_id']??0); if(!q('SELECT id FROM teams WHERE id=?',[$teamId])->fetch()) throw new \RuntimeException('队伍不存在');
        $entry=trim((string)($p['entry_name']??'')); if($entry==='' || mb_strlen($entry)>80) throw new \RuntimeException('接力分队名称须为1至80字');
        $ids=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($p['members']??''))))));
        if(count($ids)!==8) throw new \RuntimeException('接力必须填写8名不同运动员的ID，以英文逗号分隔');
        $men=0;$women=0; foreach($ids as $id) { $a=q('SELECT * FROM athletes WHERE id=?',[$id])->fetch(); if(!$a || (int)$a['team_id']!==$teamId) throw new \RuntimeException('接力8人必须全部属于所选队伍');
            if($event['age_group']!=='' && $a['age_group']!==$event['age_group']) throw new \RuntimeException('接力成员组别不符合要求');
            $men+=($a['gender']==='M');$women+=($a['gender']==='F'); $members[]=$id;
        }
        $male=(int)q('SELECT relay_males FROM settings WHERE id=1')->fetchColumn(); if($men!==$male || $women!==8-$male) throw new \RuntimeException('接力需'.$male.'男'.(8-$male).'女');
    }
    return [$event,$athleteId,$teamId,$entry,$performance,$status,$members];
}
function saveResult(array $p): void {
    [$e,$a,$t,$entry,$value,$status,$members]=validateResult($p);
    if($e['kind']==='relay') foreach($members as $id) {
        $used=q('SELECT r.id FROM relay_members m JOIN results r ON r.id=m.result_id WHERE r.event_id=? AND m.athlete_id=? AND NOT(r.team_id=? AND r.entry_name=?)',[$e['id'],$id,$t,$entry])->fetch();
        if($used) throw new \RuntimeException('同一运动员不能在同一接力项目代表两个分队');
    }
    q('INSERT INTO results(event_id,athlete_id,team_id,entry_name,performance,status) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE performance=VALUES(performance),status=VALUES(status)',[$e['id'],$a,$t,$entry,$value,$status]);
    $id=q('SELECT id FROM results WHERE event_id=? AND team_id=? AND entry_name=?',[$e['id'],$t,$entry])->fetchColumn(); q('DELETE FROM relay_members WHERE result_id=?',[$id]); foreach($members as $member) q('INSERT INTO relay_members(result_id,athlete_id) VALUES(?,?)',[$id,$member]);
}
