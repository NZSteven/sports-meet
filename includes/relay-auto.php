<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function relay_auto_school_rows(int $event):array {
    $schools=[];
    foreach(q('SELECT a.id,a.name,a.gender,a.team_id,t.name AS school FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id WHERE n.event_id=? ORDER BY t.id,n.id,a.id',[$event])->fetchAll() as $row){
        $id=(int)$row['team_id'];
        if(!isset($schools[$id]))$schools[$id]=['id'=>$id,'name'=>$row['school'],'M'=>[],'F'=>[]];
        $schools[$id][$row['gender']][]=(int)$row['id'];
    }
    return $schools;
}
function relay_auto_combinations(array $ids,int $size,int $start=0,array $chosen=[]):array {
    if(count($chosen)===$size)return [$chosen];$out=[];
    for($i=$start;$i<count($ids);$i++)foreach(relay_auto_combinations($ids,$size,$i+1,[...$chosen,$ids[$i]]) as $group)$out[]=$group;
    return $out;
}
function relay_auto_allocation(array $group,array $pool):?array {
    $best=null;$bestScore=null;
    $walk=function(int $i,int $men,int $women,array $allocation)use(&$walk,&$best,&$bestScore,$group,$pool){
        if($i===count($group)){
            if($men||$women)return;
            $remaining=0;$balance=0;
            foreach($allocation as $id=>$counts){$remaining+=count($pool[$id]['M'])+count($pool[$id]['F'])-$counts[0]-$counts[1];$balance+=abs($counts[0]+$counts[1]-8/count($group));}
            $score=[$remaining,$balance];if($bestScore===null||$score<$bestScore){$best=$allocation;$bestScore=$score;}return;
        }
        $id=$group[$i];
        for($m=0;$m<=min($men,count($pool[$id]['M']));$m++)for($f=0;$f<=min($women,count($pool[$id]['F']));$f++){
            if(!$m&&!$f)continue;
            $walk($i+1,$men-$m,$women-$f,$allocation+[$id=>[$m,$f]]);
        }
    };
    $walk(0,4,4,[]);return $best;
}
function relay_auto_plan(array $schools):array {
    $teams=[];$pool=[];$independent=[];
    foreach($schools as $id=>$school){
        if(count($school['M'])>=4&&count($school['F'])>=4){
            $teams[]=['name'=>$school['name'],'school'=>(int)$id,'members'=>array_merge(array_slice($school['M'],0,4),array_slice($school['F'],0,4)),'parts'=>[$id=>[4,4]]];
            $independent[$id]=true;
        }else $pool[$id]=$school;
    }
    $number=1;
    while(true){
        $active=array_keys(array_filter($pool,fn($s)=>count($s['M'])+count($s['F'])>0));
        $best=null;$score=null;
        foreach([2,3] as $size){
            foreach(relay_auto_combinations($active,$size) as $group){
                $allocation=relay_auto_allocation($group,$pool);if(!$allocation)continue;
                $remaining=0;foreach($allocation as $id=>$counts)$remaining+=count($pool[$id]['M'])+count($pool[$id]['F'])-$counts[0]-$counts[1];
                if($score===null||$remaining<$score){$best=$allocation;$score=$remaining;}
            }
            if($best)break; // Prefer two-school teams whenever feasible.
        }
        if(!$best)break;
        $members=[];$names=[];
        foreach($best as $id=>$counts){
            $members=array_merge($members,array_splice($pool[$id]['M'],0,$counts[0]),array_splice($pool[$id]['F'],0,$counts[1]));
            $names[]=$pool[$id]['name'];
        }
        $teams[]=['name'=>'混合队'.$number++,'school'=>null,'members'=>$members,'parts'=>$best,'schools'=>$names];
    }
    return ['schools'=>$schools,'independent'=>$independent,'teams'=>$teams,'remaining'=>$pool];
}
function relay_auto_materialize(int $event):void {
    race_unstarted($event);
    $plan=relay_auto_plan(relay_auto_school_rows($event));
    // No results exist yet; the draw must use exactly the teams shown in preview.
    q('DELETE FROM race_relays WHERE event_id=?',[$event]);
    foreach($plan['teams'] as $team){
        $name=mb_substr($team['name'],0,80);
        $id=race_set_relay($event,0,$name,$team['members']);
        if($team['school'])q('UPDATE race_relays SET school_id=? WHERE id=?',[$team['school'],$id]);
    }
}
function relay_auto_table(array $rows):void {
    echo '<div class="scroll"><table class="sm-relay-team-table"><thead><tr><th>队名</th><th>组队状态</th><th>男</th><th>女</th><th>组成／候补</th></tr></thead><tbody>';
    foreach($rows as $row){echo '<tr>';foreach($row as $value)echo '<td>'.esc_html((string)$value).'</td>';echo '</tr>';}
    if(!$rows)echo '<tr><td colspan="5">暂无满足4男4女的组合</td></tr>';
    echo '</tbody></table></div>';
}
function relay_auto_ui(int $school):void {
    wp_enqueue_script('sports-meet-relay-auto',plugins_url('relay-auto.js',dirname(__DIR__).'/sports-meet.php'),[],'2.5.44',true);
    $url=add_query_arg('action','sports_meet_relay_preview',admin_url('admin-ajax.php'));
    echo '<section class="sm-relay-auto" data-relay-auto-url="'.esc_url($url).'"><h2>接力自动组队</h2><p>每队4男4女。高校达到至少4男4女时自动独立成队，队名为高校名，其他报名者作本校候补。其余高校优先两校组合，必要时三校组合。报名变动后自动重新计算；生成赛程后锁定。</p>';
    foreach(all('events') as $event){
        if($event['kind']!=='relay')continue;$id=(int)$event['id'];
        echo '<h3>'.esc_html($event['name']).'</h3>';
        if(q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$id])->fetch()){
            echo '<p>已生成赛程，组队名单已锁定。</p>';$rows=[];
            foreach(q('SELECT * FROM race_relays WHERE event_id=? ORDER BY id',[$id])->fetchAll() as $team){$members=race_members((int)$team['id']);$names=array_unique(array_column($members,'team_name'));$m=count(array_filter($members,fn($a)=>$a['gender']==='M'));$rows[]=[$team['name'],'已锁定',$m,count($members)-$m,implode('＋',$names)];}
            relay_auto_table($rows);continue;
        }
        $plan=relay_auto_plan(relay_auto_school_rows($id));
        echo '<div class="sm-relay-auto-columns"><div><h3>待组合高校</h3><table><thead><tr><th>高校</th><th>男</th><th>女</th></tr></thead><tbody>';
        $waiting=0;
        foreach($plan['schools'] as $sid=>$row){if(isset($plan['independent'][$sid]))continue;$waiting++;
            echo '<tr><td>'.esc_html($row['name']).'</td><td>'.count($row['M']).'</td><td>'.count($row['F']).'</td></tr>';
        }
        if(!$waiting)echo '<tr><td colspan="3">暂无待组合高校</td></tr>';
        echo '</tbody></table></div><div><h3>自动组队结果</h3>';
        $teamRows=[];
        foreach($plan['teams'] as $team){
            $description='';
            if($team['school']){
                $row=$plan['schools'][$team['school']];$description='已报'.count($row['M']).'男'.count($row['F']).'女；候补'.(count($row['M'])+count($row['F'])-8).'人';
            }else{
                $parts=[];foreach($team['parts'] as $sid=>$counts)$parts[]=$plan['schools'][$sid]['name'].' '.$counts[0].'男'.$counts[1].'女';$description=implode('＋',$parts);
            }
            $teamRows[]=[$team['name'],$team['school']?'自成组队':'混合队',4,4,$description];
        }
        relay_auto_table($teamRows);
        foreach($plan['remaining'] as $row){$m=count($row['M']);$f=count($row['F']);if($m+$f)echo '<p class="sm-relay-auto-pending">'.esc_html($row['name']).'：仍有'.$m.'男'.$f.'女待组合。</p>';}
        echo '<p>以上为动态预编排；不足4男4女或需要超过三校的剩余人员继续待组合。同一人不会重复入队，独立校队候补不参与混合组队。</p></div></div>';
    }
    echo '</section>';
}

function relay_auto_preview_handler():void {
    // Public entrance shows school totals and team composition, never personal names.
    nocache_headers();$html=get_transient('sports_meet_relay_entrance_preview');
    if(!is_string($html)){ob_start();relay_auto_ui(0);$html=ob_get_clean();set_transient('sports_meet_relay_entrance_preview',$html,5);}
    wp_send_json_success(['html'=>$html]);
}
add_action('wp_ajax_sports_meet_relay_preview',__NAMESPACE__.'\\relay_auto_preview_handler');
add_action('wp_ajax_nopriv_sports_meet_relay_preview',__NAMESPACE__.'\\relay_auto_preview_handler');
