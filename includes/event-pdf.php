<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function chief_pdf_projects():array {
    $projects=[];foreach(all('events') as $event)$projects[]=['id'=>'event:'.$event['id'],'name'=>$event['name']];
    foreach(['three_legged'=>'2人3足比赛（不计积分）','tug_of_war'=>'拔河比赛（不计积分）'] as $key=>$name)$projects[]=['id'=>'activity:'.$key,'name'=>$name];return $projects;
}
function chief_pdf_link(string $project):string {return add_query_arg(['action'=>'sports_meet_event_pdf','project'=>$project,'_wpnonce'=>wp_create_nonce('sports_meet_event_pdf')],admin_url('admin-post.php'));}
function chief_pdf_ui():void {
    echo '<h3>项目表格PDF</h3><p>每个项目单独下载，A4横向。已生成赛程的项目按预赛、半决赛、决赛及小组分别列出；没有赛程时输出报名名单。导出不会生成或修改分组。</p><div class="scroll"><table><thead><tr><th>项目</th><th>输出</th></tr></thead><tbody>';
    foreach(chief_pdf_projects() as $p)echo '<tr><td>'.esc_html($p['name']).'</td><td><a class="sm-pdf-download" href="'.esc_url(chief_pdf_link($p['id'])).'">下载PDF</a></td></tr>';
    echo '</tbody></table></div>';
}
function chief_pdf_person(array $a,array $accepted):string {return disclaimer_dinner_only($a)?'无需签署':(disclaimer_agreed($a,$accepted)?'已签署':'未签署');}
function chief_pdf_people(array $ids):array {
    $rows=[];foreach($ids as $id){$a=q('SELECT a.*,t.name AS team_name,c.bib AS race_bib FROM athletes a JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE a.id=?',[(int)$id])->fetch();if($a)$rows[]=$a;}return $rows;
}
function chief_pdf_row(int $n,array $a,array $accepted,$lane='',string $confirm='',string $performance='',string $place=''):array {
    return [(string)$n,(string)$lane,$a['team_name'],$a['name'],$a['race_bib']??'待分配',chief_pdf_person($a,$accepted),$confirm,$performance,$place];
}
function chief_pdf_sections(string $project):array {
    $accepted=disclaimer_acceptances();$sections=[];
    if(preg_match('/^activity:(three_legged|tug_of_war)$/',$project,$m)){
        $title=$m[1]==='three_legged'?'2人3足比赛（不计积分）':'拔河比赛（不计积分）';$rows=[];
        foreach(q('SELECT a.*,t.name AS team_name,c.bib AS race_bib FROM registration_extras x JOIN athletes a ON a.id=x.athlete_id JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE x.activity=? ORDER BY t.name,a.name,a.id',[$m[1]])->fetchAll() as $i=>$a)$rows[]=chief_pdf_row($i+1,$a,$accepted);
        return ['title'=>$title,'sections'=>[['title'=>'报名名单 - 未安排系统分组','rows'=>$rows,'members'=>[]]]];
    }
    if(!preg_match('/^event:([1-9][0-9]*)$/',$project,$m))throw new \RuntimeException('请选择有效比赛项目。');
    $event=race_event((int)$m[1]);$id=(int)$event['id'];
    $rounds=q("SELECT * FROM race_rounds WHERE event_id=? ORDER BY FIELD(stage,'heat','semi','final'),heat_no,id",[$id])->fetchAll();
    foreach($rounds as $round){
        $completed=$round['status']==='finalized';$rows=[];$memberGroups=[];
        foreach(q('SELECT * FROM race_entries WHERE round_id=? ORDER BY COALESCE(lane,999),id',[$round['id']])->fetchAll() as $i=>$entry){
            $mark=$completed?(!empty($entry['confirmed'])?'已确认':'未参赛'):(!empty($entry['confirmed'])?'已勾选（暂存）':'');$performance='';
            if(race_is_distance($event)&&(!empty($entry['distance'])||!empty($entry['no_mark'])))$performance=!empty($entry['no_mark'])?'无有效成绩':race_distance_text($entry['distance']);
            $place=$entry['place']===null?'':(string)$entry['place'];
            if($entry['athlete_id']){
                $people=chief_pdf_people([(int)$entry['athlete_id']]);if($people)$rows[]=chief_pdf_row($i+1,$people[0],$accepted,$entry['lane']??'',$mark,$performance,$place);
            }else{
                $team=race_one('race_relays',(int)$entry['relay_id']);$members=race_members((int)$entry['relay_id']);$signed=0;foreach($members as $a)if(disclaimer_agreed($a,$accepted))$signed++;
                $rows[]=[(string)($i+1),(string)($entry['lane']??''),implode(' + ',array_unique(array_column($members,'team_name'))),$team['name'],implode(' / ',array_map(fn($a)=>$a['race_bib']??'待分配',$members)),'已签'.$signed.'/'.count($members),$mark,'',$place];
                $memberGroups[]=['name'=>$team['name'],'people'=>$members];
            }
        }
        $sections[]=['title'=>race_round_label($round).' - '.($completed?'已完成':($round['status']==='open'?'待比赛／暂存':'比赛中／暂存')),'rows'=>$rows,'members'=>$memberGroups];
    }
    if(!$rounds&&$event['kind']==='relay'){
        $plan=relay_auto_draw_plan($id);
        foreach($plan['draw_groups'] as $g=>$teams){$rows=[];$memberGroups=[];foreach($teams as $i=>$team){$members=chief_pdf_people($team['members']);$signed=0;foreach($members as $a)if(disclaimer_agreed($a,$accepted))$signed++;
            $rows[]=[(string)($i+1),(string)[1,3,5,7][$i],implode(' + ',array_unique(array_column($members,'team_name'))),$team['name'],implode(' / ',array_map(fn($a)=>$a['race_bib']??'待分配',$members)),'已签'.$signed.'/'.count($members),'','',''];$memberGroups[]=['name'=>$team['name'],'people'=>$members];}
            $sections[]=['title'=>'第'.($g+1).'组预编排（未生成正式赛程）','rows'=>$rows,'members'=>$memberGroups];
        }
    }
    if(!$sections){$rows=[];foreach(q('SELECT a.*,t.name AS team_name,c.bib AS race_bib FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE n.event_id=? ORDER BY t.name,a.name,a.id',[$id])->fetchAll() as $i=>$a)$rows[]=chief_pdf_row($i+1,$a,$accepted);$sections[]=['title'=>'报名名单（尚未生成分组及赛道）','rows'=>$rows,'members'=>[]];}
    return ['title'=>$event['name'],'sections'=>$sections];
}
function chief_event_pdf(string $project):string {
    $report=chief_pdf_sections($project);require_once __DIR__.'/pdf-renderer.php';$pdf=new MeetEventPdf($report['title']);
    $headers=['序号','赛道','所属高校','姓名／接力队','比赛号码','免责声明','确认参赛','成绩／备注','名次'];$widths=[12,14,50,60,36,24,26,32,23];
    foreach($report['sections'] as $section){$pdf->section($section['title'],$headers,$widths);if(!$section['rows'])$pdf->row(['','','','暂无报名／参赛名单','','','','','']);foreach($section['rows'] as $i=>$row)$pdf->row($row,$i%2===1);
        foreach($section['members'] as $group){$pdf->section($section['title'].' - '.$group['name'].'队员明细',['序号','所属高校','姓名','比赛号码','免责声明'],[14,68,90,40,65]);foreach($group['people'] as $i=>$a)$pdf->row([(string)($i+1),$a['team_name'],$a['name'],$a['race_bib']??'待分配',chief_pdf_person($a,disclaimer_acceptances())],$i%2===1);}
    }
    return $pdf->Output('S');
}
add_action('admin_post_sports_meet_event_pdf',function(){
    if(!race_can_manage())wp_die('仅管理员及运动会裁判长可导出项目PDF。','',['response'=>403]);check_admin_referer('sports_meet_event_pdf');
    $project=is_string($_GET['project']??null)?sanitize_text_field(wp_unslash($_GET['project'])):'';
    try{$bytes=chief_event_pdf($project);}catch(\Throwable $e){error_log('Sports Meet PDF: '.$e->getMessage());wp_die('PDF生成失败，请联系管理员检查字体和临时目录。','',['response'=>500,'back_link'=>true]);}
    nocache_headers();header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="sports-event-'.sanitize_file_name(str_replace(':','-',$project)).'.pdf"');header('X-Content-Type-Options: nosniff');echo $bytes;exit;
});
