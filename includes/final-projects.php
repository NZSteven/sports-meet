<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_prepare_final(int $event,bool $direct):int {
    race_event($event);$final=q("SELECT id FROM race_rounds WHERE event_id=? AND stage='final'",[$event])->fetchColumn();if($final)return (int)$final;
    $heats=q("SELECT * FROM race_rounds WHERE event_id=? AND stage IN ('heat','semi')",[$event])->fetchAll();
    if($heats){foreach($heats as $heat)if($heat['status']!=='finalized')throw new \RuntimeException('此项目预赛尚未全部完成，须等待晋级名单，不能把报名名单直接当作决赛名单。');if(!race_qualifiers($event))throw new \RuntimeException('预赛已结束，但没有晋级选手。');race_sync_final($event);}
    else{if(!$direct)throw new \RuntimeException('请确认此项目不设预赛，并使用报名名单直接决赛。');race_generate($event,'final',8);}
    $id=(int)q("SELECT id FROM race_rounds WHERE event_id=? AND stage='final'",[$event])->fetchColumn();race_audit('prepare_final',$event,['round_id'=>$id,'direct'=>!$heats]);return $id;
}
function race_finals_url(int $event):string {
    $base=is_admin()?race_url('rounds'):page_url('finals');return add_query_arg(['event_id'=>$event],$base);
}
function race_final_projects(bool $showSelect=true):int {
    $events=all('events');$rounds=q('SELECT * FROM race_rounds ORDER BY id')->fetchAll();$byEvent=[];foreach($rounds as $r)$byEvent[$r['event_id']][]=$r;
    $counts=[];foreach(q('SELECT event_id,COUNT(*) AS n FROM race_enrollments GROUP BY event_id')->fetchAll() as $r)$counts[$r['event_id']]=(int)$r['n'];
    $selected=absint($_GET['event_id']??0);if(!$selected&&!empty($_GET['round_id'])){$r=race_one('race_rounds',(int)$_GET['round_id']);if($r['stage']!=='final'){echo '<p class="error">此轮不属于当前页面，请从对应预赛／决赛入口选择。</p>';return 0;}$selected=(int)$r['event_id'];}
    if($showSelect){
    echo '<h2>选择决赛项目</h2><form method="get" class="race-final-select" action="'.esc_url(is_admin()?admin_url('admin.php'):page_url('finals')).'">';if(is_admin())echo '<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races"><input type="hidden" name="race_tab" value="rounds">';elseif(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])['finals']??0).'">';echo '<label>比赛项目<select name="event_id" data-final-project-select required><option value="">请选择决赛项目</option>';
    $options=['open'=>[],'finalized'=>[]];
    foreach($events as $e){$final=null;$hasHeat=false;$pending=false;foreach($byEvent[$e['id']]??[] as $r){if($r['stage']==='final')$final=$r;else{$hasHeat=true;$pending=$pending||$r['status']!=='finalized';}}
        $status=$final?($final['status']==='finalized'?'决赛已完成':($pending?'等待预赛完成':'决赛待录入')):($hasHeat?($pending?'等待预赛晋级':'预赛已结束'):(!empty($counts[$e['id']])?'可生成直接决赛':'尚无报名'));
        $options[$final&&$final['status']==='finalized'?'finalized':'open'][]='<option value="'.(int)$e['id'].'" '.selected((string)$e['id'],(string)$selected,false).'>'.esc_html($e['name'].' · '.$status.' · 报名'.(int)($counts[$e['id']]??0).'人').'</option>';
    }
    foreach(['open'=>'待比赛／待提交','finalized'=>'已比赛／已完成'] as $status=>$heading){
        if(!$options[$status])continue;
        echo '<optgroup label="'.esc_attr($heading).'">'.implode('',$options[$status]).'</optgroup>';
    }
    echo '</select></label><button type="submit">打开项目</button></form>';
    }
if(!$events){echo '<p>尚无比赛项目，请管理员先添加项目和报名。</p>';return 0;}if(!$selected){echo '<p>'.($showSelect?'请从下拉菜单选择要录入决赛的项目。':'请从左侧选择决赛项目。').'</p>';return 0;}
    $event=race_event($selected);$final=null;$heats=[];foreach($byEvent[$selected]??[] as $r){if($r['stage']==='final')$final=$r;else$heats[]=$r;}if($final)return (int)$final['id'];
    echo '<h3>'.esc_html($event['name']).'</h3>';
    if($heats){$pending=array_filter($heats,fn($r)=>$r['status']!=='finalized');if($pending){echo '<p class="notice">等待预赛完成及晋级名单。请先在预赛页面提交所有组的结果。</p>';return 0;}if(!race_qualifiers($selected)){echo '<p>预赛已完成，没有符合晋级条件的参赛者。</p>';return 0;}race_form('prepare_final','rounds');echo '<input type="hidden" name="event_id" value="'.$selected.'"><button>生成晋级选手决赛名单</button></form>';return 0;}
    if(empty($counts[$selected])){echo '<p>此项目尚无报名，请先添加参赛者。</p>';return 0;}
    echo '<p>此项目尚未安排预赛或决赛。如本项目直接决赛，可在此生成报名名单后录入确认与名次。</p>';race_form('prepare_final','rounds');echo '<input type="hidden" name="event_id" value="'.$selected.'"><label class="sm-inline-confirm"><input type="checkbox" name="confirm_direct" value="1" required> 确认本项目不设预赛，并锁定报名名单</label><button>生成决赛名单并开始录入</button></form>';return 0;
}
