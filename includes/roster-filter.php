<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_roster_params(?array $source=null):array {
    $source=$source??wp_unslash($_REQUEST);$out=[];
    foreach(['roster_q','roster_school','roster_category','roster_event'] as $key){$v=$source[$key]??'';if(!is_scalar($v))continue;$v=trim((string)$v);
        if($key==='roster_q')$v=mb_substr(sanitize_text_field($v),0,120);
        elseif($key==='roster_category'){if(!isset(race_categories()[$v]))$v='';}
        elseif($key==='roster_event'&&str_starts_with($v,'activity:')){if(!isset(registration_extra_options()[substr($v,9)]))$v='';}
        else $v=ctype_digit($v)&&(int)$v>0?(string)(int)$v:'';
        if($v!=='')$out[$key]=$v;
    }return $out;
}
function race_filter_roster(array $params):array {
    $params=race_roster_params($params);$all=race_roster_rows();$entries=[];
    foreach(q('SELECT n.athlete_id,n.event_id,e.name FROM race_enrollments n JOIN events e ON e.id=n.event_id ORDER BY e.id')->fetchAll() as $r){$entries[$r['athlete_id']]['ids'][]=(int)$r['event_id'];$entries[$r['athlete_id']]['names'][]=$r['name'];}
    $activities=[];$labels=registration_extra_options();
    foreach(q('SELECT athlete_id,activity FROM registration_extras ORDER BY activity')->fetchAll() as $r)$activities[$r['athlete_id']][]=$r['activity'];
    $matched=[];$needle=mb_strtolower($params['roster_q']??'');
    foreach($all as $a){$a['entry_ids']=$entries[$a['id']]['ids']??[];$a['entry_names']=implode('、',$entries[$a['id']]['names']??[]);$category=race_category($a);
        $a['activity_ids']=$activities[$a['id']]??[];
        $nonScoring=array_filter($a['activity_ids'],fn($key)=>$key!=='dinner');
        $extraNames=array_map(fn($key)=>($labels[$key]??'').'（不计积分）',$nonScoring);
        $a['entry_names']=implode('、',array_filter([$a['entry_names'],...$extraNames]));
        $a['activity_names']=in_array('dinner',$a['activity_ids'],true)?$labels['dinner']:'';
        if(isset($params['roster_school'])&&(int)$a['team_id']!==(int)$params['roster_school'])continue;
        if(isset($params['roster_category'])&&$category!==$params['roster_category'])continue;
        if(isset($params['roster_event'])){
            $value=$params['roster_event'];
            if(str_starts_with($value,'activity:')){if(!in_array(substr($value,9),$a['activity_ids'],true))continue;}
            elseif(!in_array((int)$value,$a['entry_ids'],true))continue;
        }
        if($needle!==''&&!str_contains(mb_strtolower($a['name'].' '.$a['team_name'].' '.($a['race_bib']??'').' '.$category.' '.$a['entry_names'].' '.$a['activity_names']),$needle))continue;
        $matched[]=$a;
    }
    // Keep athletes from the same school together after any combination of filters.
    usort($matched,static function(array $a,array $b):int {
        return strnatcasecmp($a['team_name'],$b['team_name'])
            ?: ((int)$a['team_id']<=>(int)$b['team_id'])
            ?: strnatcasecmp($a['name'],$b['name'])
            ?: ((int)$a['id']<=>(int)$b['id']);
    });
    return ['rows'=>$matched,'total'=>count($all)];
}
function race_roster_project_options(?array $events=null):array {
    $items=$events??all('events');
    foreach(registration_extra_options() as $key=>$label)$items[]=['id'=>'activity:'.$key,'name'=>$label.($key==='dinner'?'':'（不计积分）')];
    return $items;
}
function race_roster_bib(array $athlete):string {
    if(empty($athlete['entry_ids'])&&($athlete['activity_ids']??[])===['dinner'])return '';
    return (string)($athlete['race_bib']??'待分配');
}
function race_roster_select(string $name,string $label,array $items,string $value):void {
    echo '<label>'.esc_html($label).'<select name="'.esc_attr($name).'"><option value="">全部'.esc_html($label).'</option>';
    foreach($items as $item)echo '<option value="'.esc_attr($item['id']).'" '.selected((string)$item['id'],$value,false).'>'.esc_html($item['name']).'</option>';echo '</select></label>';
}
function race_render_roster(array $teams,array $events):void {
    $params=race_roster_params();$result=race_filter_roster($params);$accepted=disclaimer_acceptances();
    echo '<section id="race-roster"><h2>报名名单</h2><form class="race-roster-filter" method="get" action="'.esc_url(admin_url('admin.php')).'#race-roster"><input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races"><input type="hidden" name="race_tab" value="registration"><label>搜索<input type="search" name="roster_q" value="'.esc_attr($params['roster_q']??'').'" placeholder="姓名、比赛号码、高校或项目" maxlength="120"></label>';
    race_roster_select('roster_school','所属高校',$teams,$params['roster_school']??'');race_roster_select('roster_category','组别',array_map(fn($c)=>['id'=>$c,'name'=>$c],array_keys(race_categories())),$params['roster_category']??'');race_roster_select('roster_event','比赛及活动项目',race_roster_project_options($events),$params['roster_event']??'');
    echo '<div class="race-roster-filter-actions"><button type="submit">搜索／筛选</button><a href="'.esc_url(race_url('registration')).'#race-roster">重置</a></div></form><p role="status" class="race-roster-count">显示 '.count($result['rows']).' 人，共 '.$result['total'].' 人。搜索和筛选条件同时生效。</p><div class="scroll"><table class="race-compact-roster sm-athlete-roster"><thead><tr><th>免责声明</th><th>所属高校</th><th>姓名</th><th>组别</th><th>比赛号码</th><th>参赛项目</th><th>晚宴</th><th>操作</th></tr></thead><tbody>';
    foreach($result['rows'] as $a){echo '<tr><td>'.disclaimer_roster_mark($a,$accepted).'</td><td>'.esc_html($a['team_name']).'</td><td>'.esc_html($a['name']).'</td><td>'.esc_html(race_category($a)).'</td><td>'.esc_html(race_roster_bib($a)).'</td><td>'.esc_html($a['entry_names']).'</td><td>'.esc_html($a['activity_names']).'</td><td><a href="'.esc_url(race_url('registration',array_merge($params,['edit_athlete'=>$a['id']]))).'">编辑</a>';race_form('delete','registration');echo '<input type="hidden" name="type" value="athlete"><input type="hidden" name="id" value="'.(int)$a['id'].'"><label><input type="checkbox" name="confirm" value="DELETE" required> 确认删除此报名</label><button>删除</button></form></td></tr>';}
    if(!$result['rows'])echo '<tr><td colspan="8" class="race-roster-empty">没有符合条件的报名记录，请调整搜索或筛选条件。</td></tr>';
    echo '</tbody></table></div></section>';
}
