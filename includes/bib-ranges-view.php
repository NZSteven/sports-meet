<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function bib_range_readonly():void {
    if(!race_can_judge()){echo '<p>请使用裁判、裁判长或管理员账号登录查看。</p>';return;}
    $plan=bib_range_plan();echo '<section class="sm-bib-range-view"><h2>各校号码区间表</h2>';
    if(!$plan){echo '<p>系统尚未保存号码区间，请联系管理员或裁判长初始化。</p></section>';return;}
    echo '<p>以下为系统当前实际保存的号码区间。'.(($plan['policy']??'')==='per-school-five-v1'?'已采用每校参赛人数＋5个备用号方案。':'当前仍为旧方案，尚未完成每校5个备用号的重新分配。').'</p>';
    if(!empty($plan['created']))echo '<p>分配时间：'.esc_html($plan['created']).'（网站时间）</p>';
    $rows=[];
    foreach(bib_range_counts() as $school){
        $range=$plan['ranges'][(int)$school['id']]??null;$issued=q('SELECT c.bib FROM checkins c JOIN athletes a ON a.id=c.athlete_id WHERE a.team_id=?',[$school['id']])->fetchAll(7);$inside=0;
        if($range)foreach($issued as $number)if((int)$number>=$range['start']&&(int)$number<=$range['end'])$inside++;
        $capacity=$range?$range['end']-$range['start']+1:0;$outside=count($issued)-$inside;
        $note=$outside?'有'.$outside.'个号码不在本校区间，请联系裁判长核对':($range?'—':'尚未预留');
        $rows[]=[$school['name'],$school['competitors'],$range?$range['start'].'–'.$range['end']:'未预留',$capacity,count($issued),max(0,$capacity-$inside),$note];
    }
    table(['高校','参赛人数','当前号码区间','容量','已发号','区间剩余','说明'],$rows);
    if(!empty($plan['shared_spare']))echo '<p><strong>公共备用：'.(int)$plan['shared_spare']['start'].'–'.(int)$plan['shared_spare']['end'].'，共'.(int)$plan['shared_spare']['count'].'个。</strong></p>';
    echo '<p>只报晚宴不发比赛号码；实际发号以已签署当前免责声明的参赛者为准。</p></section>';
}
