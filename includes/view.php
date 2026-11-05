<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function table(array $head,array $rows):void { echo '<div class="scroll"><table><thead><tr>';foreach($head as $v) echo '<th>'.h($v).'</th>';echo '</tr></thead><tbody>';foreach($rows as $r){echo '<tr>';foreach($r as $v)echo '<td>'.h($v).'</td>';echo '</tr>';}echo '</tbody></table></div>'; }

function render(string $page='board',bool $public=false): string {
 $admin=!$public && current_user_can('manage_options');
 if(!$admin && !in_array($page,['board','results'],true)) return '';
 $settings=q('SELECT * FROM settings WHERE id=1')->fetch();$teams=all('teams');$athletes=all('athletes');$events=all('events');$scored=results();
 ob_start();echo '<div class="sports-meet"><h2>'.h($settings['title']).'</h2>';
if($page==='board') {
    if($admin) echo '<p><a href="'.h(export_url('standings')).'">导出队伍排行榜 CSV</a> · <a href="'.h(export_url('scores')).'">导出各项目名次与积分 CSV</a></p>';
    echo '<h2>队伍总积分</h2><p>个人与接力积分相加；总分相同并列。规则：'.h($settings['points']).'；接力 ×'.h($settings['relay_multiplier']).'。成绩更新后刷新页面即可查看。</p>';
    $totals=[];foreach($teams as $t)$totals[$t['id']]=['name'=>$t['name'],'individual'=>0,'relay'=>0,'total'=>0];foreach($scored as $r){$totals[$r['team_id']][$r['kind']]+=$r['points'];$totals[$r['team_id']]['total']+=$r['points'];}
    usort($totals,fn($a,$b)=>($b['total']<=>$a['total'])?:strcmp($a['name'],$b['name']));$rows=[];$prev=null;$rank=0;foreach($totals as $i=>$t){if($prev===null || $prev!=$t['total'])$rank=$i+1;$prev=$t['total'];$rows[]=[$rank,$t['name'],$t['individual'],$t['relay'],$t['total']];}table(['名次','队伍','个人积分','接力积分','总积分'],$rows);
    echo '<h2>运动员个人积分</h2><p>只累计个人项目，接力积分计入队伍。</p>';$ar=[];foreach($athletes as $a)$ar[$a['id']]=['name'=>$a['name'],'bib'=>$a['bib'],'points'=>0];foreach($scored as $r)if($r['athlete_id'])$ar[$r['athlete_id']]['points']+=$r['points'];usort($ar,fn($a,$b)=>$b['points']<=>$a['points']);$rows=[];$prev=null;foreach($ar as $i=>$a){if($prev===null || $prev!=$a['points'])$rank=$i+1;$prev=$a['points'];$rows[]=[$rank,$a['bib'],$a['name'],$a['points']];}table(['名次','号码','姓名','积分'],$rows);
} elseif($page==='results') {
    echo '<h2>项目成绩</h2>';
    $rows=[];foreach($scored as $r)if(true)$rows[]=[$r['id'],$r['event_name'],$r['rank'],$r['display_name'],$r['team_name'],$r['status']==='OK'?rtrim(rtrim($r['performance'],'0'),'.').' '.$r['unit']:$r['status'],$r['points']];table(['成绩ID','项目','名次','运动员/接力分队','队伍','成绩','积分'],$rows);
} elseif($page==='manage') {
    echo '<h2>队伍管理</h2>';formStart('team');field('id','队伍ID（新增填0，改名填现有ID）','number','0','min="0"');field('name','队伍名称');finish();table(['ID','队伍'],array_map(fn($t)=>[$t['id'],$t['name']],$teams));
    echo '<h2>运动员管理</h2><p>新增填ID 0；修改填现有ID。已有成绩时可修改姓名及号码，变更队伍、性别或组别前须删除相关成绩。</p>';formStart('athlete');field('id','运动员ID','number','0','min="0"');field('bib','号码（唯一）');field('name','姓名');selectBox('gender','性别',[['id'=>'M','name'=>'男'],['id'=>'F','name'=>'女']]);echo '<label>组别（可空）<input name="age_group"></label>';selectBox('team_id','队伍',$teams);finish();
    $tn=array_column($teams,'name','id');table(['ID','号码','姓名','性别','组别','队伍'],array_map(fn($a)=>[$a['id'],$a['bib'],$a['name'],$a['gender'],$a['age_group'],$tn[$a['team_id']]??''],$athletes));
} elseif($page==='events') {
    echo '<h2>新增比赛项目</h2><p>每个性别/年龄组分别建立一个项目，例如“小学组男子50米”。8×50米接力已预建，可按组别另建。计时统一输入秒，例如1分12秒填72。</p>';formStart('event');field('name','项目名称');selectBox('kind','类型',[['id'=>'individual','name'=>'个人项目'],['id'=>'relay','name'=>'8×50米混合接力']]);selectBox('gender','性别限制（接力忽略）',[['id'=>'X','name'=>'不限'],['id'=>'M','name'=>'男'],['id'=>'F','name'=>'女']]);echo '<label>组别限制（留空为不限）<input name="age_group"></label>';selectBox('direction','排名方向（接力固定越小越好）',[['id'=>'asc','name'=>'越小越好（跑步）'],['id'=>'desc','name'=>'越大越好（跳远等）']]);field('unit','单位','text','秒');finish();table(['ID','名称','类型','性别','组别','排名方向','单位'],array_map('array_values',$events));
} elseif($page==='entry') {
    echo '<h2>录入或更新成绩</h2><p>同一个人同一项目再次保存即更新成绩；接力以“项目＋队伍＋分队名称”识别。输入最终成绩，无预赛/决赛分轮。个人与接力分别填写对应栏，其余栏留空。</p>';
    formStart('result');selectBox('event_id','项目',$events);selectBox('status','状态',[['id'=>'OK','name'=>'有效成绩'],['id'=>'DNS','name'=>'未出赛 DNS'],['id'=>'DNF','name'=>'未完成 DNF'],['id'=>'DQ','name'=>'取消资格 DQ']]);echo '<label>成绩（有效成绩必填，数字，时间单位秒）<input name="performance" inputmode="decimal"></label><label>个人项目：运动员<select name="athlete_id"><option value="">不适用</option>';foreach($athletes as $a)echo '<option value="'.h($a['id']).'">'.h($a['bib'].' '.$a['name'].' / ID '.$a['id']).'</option>';echo '</select></label><label>接力：队伍<select name="team_id"><option value="">不适用</option>';foreach($teams as $t)echo '<option value="'.h($t['id']).'">'.h($t['name']).'</option>';echo '</select></label><label>接力：分队名称（如A队）<input name="entry_name"></label><label>接力：8名运动员ID（英文逗号分隔，非号码）<input name="members" placeholder="1,2,3,4,5,6,7,8"></label>';finish();echo '<p>接力要求同队8人，'.h($settings['relay_males']).'男'.h(8-$settings['relay_males']).'女；同项目不能跨分队重复出赛。</p>';
} elseif($page==='csv') {
    echo '<h2>CSV 导入与导出</h2><p>使用 UTF-8 CSV，英文逗号分隔。先建立项目，再导入运动员，最后导入成绩。导入整批在一个事务中执行，一行有误整批回滚。运动员号码重复将拒绝；成绩重复将更新。</p><p><a href="'.h(export_url('template-athletes')).'">运动员导入模板</a> · <a href="'.h(export_url('template-results')).'">成绩导入模板</a> · <a href="'.h(export_url('athletes')).'">导出运动员</a> · <a href="'.h(export_url('teams')).'">导出队伍</a> · <a href="'.h(export_url('events')).'">导出项目</a> · <a href="'.h(export_url('results')).'">导出成绩</a></p><form method="post" action="'.h(admin_url('admin-post.php')).'" enctype="multipart/form-data"><input type="hidden" name="action" value="sports_meet_save"><input type="hidden" name="tab" value="csv"><input type="hidden" name="csrf" value="'.h(token()).'"><input type="hidden" name="sm_action" value="import">';selectBox('type','导入类型',[['id'=>'athletes','name'=>'运动员'],['id'=>'results','name'=>'成绩']]);echo '<label>CSV文件（≤2MB）<input type="file" name="csv" accept=".csv" required></label>';finish('导入');echo '<p>成绩表头：event,bib,team,entry_name,performance,status,members。接力的bib留空；members填8个号码，用 | 分隔；个人的entry_name及members留空。完整示例见安装包 examples。</p>';
} elseif($page==='settings') {
    echo '<h2>比赛设置</h2><p>并列时平均分配所占名次积分，例如9、7分并列第一各8分，下一名第三；超出积分范围的名次为0分。更改积分会即时重算全部排行榜。多个接力分队分别按名次得分并累计到所属队伍。</p>';formStart('settings');field('title','比赛名称','text',$settings['title']);field('points','第1名起积分（英文逗号）','text',$settings['points']);field('relay_multiplier','接力积分倍数','number',(string)$settings['relay_multiplier'],'min="1" max="10"');field('relay_males','接力8人中男性人数','number',(string)$settings['relay_males'],'min="1" max="7"');finish();
}
if($admin && in_array($page,['manage','events','entry'],true)) {
    echo '<details><summary>删除错误资料</summary><p>先删除相关成绩，再删除运动员、项目或队伍。输入ID并填写 DELETE 确认。</p>';formStart('delete');selectBox('table','资料类型',[['id'=>'results','name'=>'成绩'],['id'=>'athletes','name'=>'运动员'],['id'=>'teams','name'=>'队伍'],['id'=>'events','name'=>'项目']]);field('id','资料ID','number','','min="1"');field('confirm','输入 DELETE 确认');finish('删除');echo '</details>';
}

 echo '</div>';return ob_get_clean();
}
