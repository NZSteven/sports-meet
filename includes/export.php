<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function export_data(): void {
 if(!current_user_can('manage_options')) wp_die('没有导出权限', '', ['response'=>403]);
 check_admin_referer('sports_meet_export');
 $type=sanitize_key($_GET['type']??'');
    if($type==='template-athletes') downloadCsv('athletes-template',['bib','name','gender','age_group','team'],[['001','王小明','M','小学组','红队'],['002','李小美','F','小学组','红队']]);
    if($type==='template-results') downloadCsv('results-template',['event','bib','team','entry_name','performance','status','members'],[]);
    if($type==='athletes') downloadCsv('athletes',['bib','name','gender','age_group','team'],q('SELECT a.bib,a.name,a.gender,a.age_group,t.name FROM athletes a JOIN teams t ON t.id=a.team_id ORDER BY a.id')->fetchAll(3));
    if($type==='teams') downloadCsv('teams',['id','name'],q('SELECT id,name FROM teams ORDER BY id')->fetchAll(3));
    if($type==='events') downloadCsv('events',['id','name','kind','gender','age_group','direction','unit'],q('SELECT * FROM events ORDER BY id')->fetchAll(3));
    if($type==='scores') { $rows=[];foreach(results() as $r)$rows[]=[$r['event_name'],$r['rank'],$r['display_name'],$r['team_name'],$r['performance'],$r['unit'],$r['status'],$r['points']];downloadCsv('scores',['项目','名次','姓名或分队','队伍','成绩','单位','状态','积分'],$rows); }
    if($type==='standings') {
        $tot=[];foreach(all('teams') as $t)$tot[$t['id']]=['name'=>$t['name'],'individual'=>0,'relay'=>0,'total'=>0];
        foreach(results() as $r){$tot[$r['team_id']][$r['kind']]+=$r['points'];$tot[$r['team_id']]['total']+=$r['points'];}
        usort($tot,fn($a,$b)=>$b['total']<=>$a['total']);$rows=[];$prev=null;$rank=0;foreach($tot as $i=>$t){if($prev===null || $prev!=$t['total'])$rank=$i+1;$prev=$t['total'];$rows[]=[$rank,$t['name'],$t['individual'],$t['relay'],$t['total']];}downloadCsv('standings',['名次','队伍','个人积分','接力积分','总积分'],$rows);
    }
    if($type==='results') { $rows=[];foreach(results() as $r) { $members=q('SELECT a.bib FROM relay_members m JOIN athletes a ON a.id=m.athlete_id WHERE m.result_id=? ORDER BY a.id',[$r['id']])->fetchAll(7);$rows[]=[$r['event_name'],$r['bib']??'',$r['team_name'],$r['kind']==='relay'?$r['entry_name']:'',$r['performance'],$r['status'],implode('|',$members)]; }downloadCsv('results',['event','bib','team','entry_name','performance','status','members'],$rows); }

 wp_die('未知导出类型');
}
