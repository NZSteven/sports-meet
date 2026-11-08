<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function race_assign_lanes(int $roundId):void {
    $round=race_one('race_rounds',$roundId);
    $event=race_event((int)$round['event_id']);
    if(!race_eight_lane_event($event))return;
    $capacity=$event['kind']==='relay'?((int)$round['eight_lanes']===3?5:4):8;
    $rows=q('SELECT id,lane FROM race_entries WHERE round_id=? ORDER BY id',[$roundId])->fetchAll();
    $available=array_values(array_diff(range(1,$capacity),array_filter(array_map('intval',array_column($rows,'lane')))));
    shuffle($available);
    foreach($rows as $row)if(!$row['lane']){
        if(!$available)throw new \RuntimeException('本组人数超过可用赛道，请重新分组。');
        q('UPDATE race_entries SET lane=? WHERE id=?',[array_pop($available),$row['id']]);
    }
}

// Only untouched draws can be rebuilt; saved or completed results remain intact.
function race_upgrade_relay_draws():void {
    foreach(q("SELECT DISTINCT r.event_id FROM race_rounds r JOIN events e ON e.id=r.event_id WHERE e.kind='relay' AND r.eight_lanes=3")->fetchAll(7) as $event){
        if(q("SELECT id FROM race_rounds WHERE event_id=? AND (status<>'open' OR version>1) LIMIT 1",[$event])->fetch())continue;
        if(q('SELECT en.id FROM race_entries en JOIN race_rounds r ON r.id=en.round_id WHERE r.event_id=? AND (en.confirmed=1 OR en.place IS NOT NULL) LIMIT 1',[$event])->fetch())continue;
        race_transaction(function()use($event){
            if(!q('SELECT id FROM race_rounds WHERE event_id=? AND eight_lanes=3 LIMIT 1',[$event])->fetch())return;
            if(q("SELECT id FROM race_rounds WHERE event_id=? AND (status<>'open' OR version>1) LIMIT 1",[$event])->fetch())return;
            q('DELETE FROM race_rounds WHERE event_id=?',[$event]);
            race_generate((int)$event,'heat',4,true);
        });
    }
}

add_action('init',function(){
    if(get_option('sports_meet_portal_schema')==='2.5.41')return;
    try{
        install_distances();install_registration_extras();
        race_upgrade_relay_draws();
        foreach(q("SELECT id FROM race_rounds WHERE status='open' ORDER BY id")->fetchAll(7) as $id)race_transaction(function()use($id){
            $round=q('SELECT * FROM race_rounds WHERE id=?',[$id])->fetch();
            if($round&&$round['status']==='open')race_assign_lanes((int)$id);
        });
        update_option('sports_meet_portal_schema','2.5.41',false);
    }catch(\Throwable $e){error_log('Sports Meet draw upgrade: '.$e->getMessage());}
},24);

function race_relay_rules_html():string {
    ob_start();
    echo '<section class="race-current-relay-rules"><h2>8×50米混合接力赛制</h2><p>不计时，按组内冲线名次晋级。每队8人，每所高校在同一接力项目最多报名8人；跨校队每名队员的积分计回所属高校。预赛随机均分，每组及决赛最多4队，各轮固定赛道号，未完赛不晋级。</p>';
    table(['报名队数','预赛安排','晋级及决赛'],[
        ['1–4队','直接决赛','按实际队数参赛'],
        ['5–8队','2组，均衡分组','各组前2名晋级，决赛最多4队'],
        ['9–12队','3组；10队为4＋3＋3','各组第1名晋级，决赛3队'],
        ['13–16队','4组','各组第1名晋级，决赛4队'],
        ['17–64队','每组最多4队','各组第1名进入半决赛；半决赛每组最多4队，2组各取前2名、3–4组各取第1名进入决赛'],
    ]);
    echo '<p>晋级队数不足时按实际队数安排；不超过4队时直接进入决赛。超过64队需拆分项目。参赛分每人每项目1分，只计一次；决赛前三名总分32／24／16分，由8名队员均分计回各自高校。</p></section>';
    return ob_get_clean();
}

add_shortcode('sports_meet_relay_rules',__NAMESPACE__.'\\race_relay_rules_html');

add_filter('the_content',function(string $content):string {
    if(is_admin()||!is_page()||!is_main_query()||!in_the_loop())return $content;
    if(!in_array(trim(get_the_title(get_queried_object_id())),['赛程与赛制','赛程赛制'],true))return $content;
    $content=preg_replace('/<h2\b[^>]*>报名与检录<\/h2>.*?(?=<h[12]\b|$)/us',
        '<h2>报名与检录</h2><p>各校负责人在「各队报名」页面选择所属高校，首次创建或由系统生成本校报名密码。以后凭同一高校和密码查看、修改或删除本校资料。报名修改截止为新西兰时间2026年11月12日00:00，截止后资料锁定，名单公开。</p><p>2人3足比赛、拔河比赛、晚宴只统计报名人数，不纳入本系统比赛分组、成绩或积分。100米及8×50米接力的预赛、半决赛和决赛均保存固定赛道号，按公布赛道参赛。参赛者须先同意当前免责声明，再检录发号。</p>',$content);
    if(str_contains($content,'race-current-relay-rules'))return $content;
    // Replace the complete old subsection, including any smaller subheadings.
    preg_match_all('/<h([2-6])\b[^>]*>.*?<\/h\1>/us',$content,$headings,PREG_OFFSET_CAPTURE);
    foreach($headings[0] as $i=>[$heading,$start]){
        if(!str_contains(wp_strip_all_tags($heading),'接力'))continue;
        $end=strlen($content);$level=(int)$headings[1][$i][0];
        for($j=$i+1;$j<count($headings[0]);$j++)if((int)$headings[1][$j][0]<=$level){$end=$headings[0][$j][1];break;}
        return substr($content,0,$start).race_relay_rules_html().substr($content,$end);
    }
    return $content.race_relay_rules_html();
},39);
