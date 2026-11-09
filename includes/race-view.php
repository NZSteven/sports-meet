<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function race_tab():string {$v=sanitize_key($_REQUEST['race_tab']??'registration');return in_array($v,['registration','events','relay','checkin','rounds','rules','audit'],true)?$v:'registration';}
function race_url(string $tab,array $args=[]):string {if(!is_admin()&&in_array(race_day_context(),['heats','finals'],true)&&$tab==='rounds')return add_query_arg($args,page_url(race_day_context()));return add_query_arg(array_merge(['race_tab'=>$tab],$args),is_admin()?admin_url('admin.php?page=sports-meet&tab=races'):page_url('judge'));}
function race_form(string $action,string $tab):void {echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_race"><input type="hidden" name="race_action" value="'.esc_attr($action).'"><input type="hidden" name="race_tab" value="'.esc_attr($tab).'"><input type="hidden" name="frontend" value="'.(is_admin()?'':'1').'">'.wp_nonce_field('sports_meet_race','race_nonce',false,false);if(!is_admin()&&race_day_context())echo '<input type="hidden" name="day_page" value="'.esc_attr(race_day_context()).'">';if($tab==='checkin')foreach(race_checkin_params() as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';if($tab==='registration')foreach(race_roster_params() as $key=>$value)echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';}
function race_export_url(string $type):string {return add_query_arg(['action'=>'sports_meet_race_export','type'=>$type,'_wpnonce'=>wp_create_nonce('sports_meet_race_export')],admin_url('admin-post.php'));}
function race_notice_key():string {
    $session=current_user_can('sports_meet_school_registration')?race_school_session_key():'';
    return 'sports_meet_race_notice_'.($session?:get_current_user_id());
}
function race_notice():void {$key=race_notice_key();$n=get_transient($key);if($n){delete_transient($key);echo '<p role="status" class="'.($n[0]?'error':'notice').'">'.esc_html($n[1]).'</p>';}}
function race_options(string $name,string $label,array $items,string $selected='',bool $required=true):void {echo '<label>'.esc_html($label).'<select name="'.esc_attr($name).'" '.($required?'required':'').'><option value="">请选择</option>';foreach($items as $i)echo '<option value="'.esc_attr($i['id']).'" '.selected((string)$i['id'],$selected,false).'>'.esc_html($i['name']).'</option>';echo '</select></label>';}
function race_roster_rows():array {return q('SELECT a.*,t.name AS team_name,c.bib AS race_bib,c.version AS check_version FROM athletes a JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id ORDER BY t.name,a.name,a.id')->fetchAll();}
function race_event_names(int $athlete):string {$names=q('SELECT e.name FROM race_enrollments n JOIN events e ON e.id=n.event_id WHERE n.athlete_id=? ORDER BY e.id',[$athlete])->fetchAll(7);$extra=registration_extra_names($athlete,true);if($extra!=='')$names[]=$extra;return implode('、',$names);}
function race_public_roster():string {
    if(!race_roster_is_public()&&!current_user_can('manage_options')){
        return race_registration_navigation('roster').'<p>报名名单将于'.esc_html(race_registration_deadline_label()).'公开。</p>';
    }
    $params=race_roster_params(wp_unslash($_GET));unset($params['roster_q']);$result=race_filter_roster($params);
    $pageId=get_queried_object_id();$url=get_permalink($pageId)?:home_url('/');
    ob_start();echo race_registration_navigation('roster').'<section id="sports-public-roster" class="sports-meet race-public-roster"><h2>高校及报名名单</h2><form class="race-roster-filter" method="get" action="'.esc_url($url).'#sports-public-roster">';
    if(!get_option('permalink_structure')&&$pageId)echo '<input type="hidden" name="page_id" value="'.(int)$pageId.'">';
    race_roster_select('roster_school','所属高校',all('teams'),$params['roster_school']??'');
    race_roster_select('roster_category','组别',array_map(fn($c)=>['id'=>$c,'name'=>$c],array_keys(race_categories())),$params['roster_category']??'');
    race_roster_select('roster_event','比赛及活动项目',race_roster_project_options(),$params['roster_event']??'');
    echo '<div class="race-roster-filter-actions"><button type="submit">筛选</button><a href="'.esc_url($url).'#sports-public-roster">重置</a></div></form><p role="status">显示 '.count($result['rows']).' 人，共 '.$result['total'].' 人。按所属高校排序，筛选条件同时生效。</p>';
    if(!$result['rows'])echo '<p>没有符合条件的报名记录，请调整筛选条件。</p>';
    else{
        $accepted=disclaimer_acceptances();
        echo '<div class="scroll"><table class="sm-athlete-roster sm-public-athlete-roster"><thead><tr><th>免责声明</th><th>所属高校</th><th>姓名</th><th>组别</th><th>比赛号码</th><th>参赛项目</th><th>晚宴</th></tr></thead><tbody>';
        foreach($result['rows'] as $a){
            echo '<tr><td>'.disclaimer_roster_mark($a,$accepted).'</td>';
            foreach([$a['team_name'],$a['name'],race_category($a),race_roster_bib($a),$a['entry_names'],$a['activity_names']] as $value)echo '<td>'.esc_html((string)$value).'</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';return ob_get_clean();
}
function race_admin_board():string {
    ob_start();echo '<section class="sports-meet"><h2>高校总积分</h2><p>每人每项目参赛分只计一次；决赛另加名次分。接力名次分由8名队员均分，计回各自所属高校。仅统计已提交完成的轮次。</p>';$rows=[];foreach(race_scoreboard() as $t)$rows[]=[$t['rank'],$t['name'],race_fmt($t['part']),race_fmt($t['place']),race_fmt($t['total'])];table(['名次','所属高校','参赛分','名次分','总积分'],$rows);echo '</section>';return ob_get_clean();
}

function race_public_teams(): array {
    $cached=get_transient('sports_meet_public_team_cups');
    if(is_array($cached))return $cached;
    $teams=[];
    foreach(race_scoreboard() as $index=>$team){
        $teams[]=['id'=>(int)$team['id'],'name'=>$team['name'],'trophy'=>$index<6];
    }
    usort($teams,static fn(array $a,array $b)=>strcmp($a['name'],$b['name'])?:($a['id']<=>$b['id']));
    set_transient('sports_meet_public_team_cups',$teams,10);
    return $teams;
}

function race_public_board(bool $topOnly=false): string {
    $html='<section class="sm-live-board" data-sm-rank-mode="'.($topOnly?'home':'all').'" data-sm-rank-url="'.esc_url(rest_url('sports-meet/v1/ranking')).'">'
        .'<h2>实时积分排名榜</h2><p class="sm-ranking-note">排名不分先后 · 🏆 表示积分前 6</p>'
        .'<ol class="sm-ranking-list">';
    foreach(race_public_teams() as $team){
        if($topOnly&&!$team['trophy'])continue;
        $html.='<li data-team-id="'.$team['id'].'"><span class="sm-ranking-trophy"'.($team['trophy']?'':' hidden').' role="img" aria-label="积分前六">🏆</span>'
            .'<strong class="sm-ranking-name">'.esc_html($team['name']).'</strong>'
            .'<span class="sm-ranking-score" role="img" aria-label="积分暂不公开">•••</span></li>';
    }
    $link=$topOnly?'<p class="sm-ranking-link"><a class="sm-btn" href="'.esc_url(page_url('board')).'">查看完整排名榜</a></p>':'';
    return $html.'</ol><p class="sm-ranking-empty">暂无参赛队伍。</p>'.$link.'</section>';
}

add_action('rest_api_init',function(){
    register_rest_route('sports-meet/v1','/ranking',[
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>static function(){
            $response=new \WP_REST_Response(race_public_teams());
            $response->header('Cache-Control','no-store, max-age=0');
            return $response;
        },
    ]);
});
function race_public_results(bool $includeQualifiers=true):string {
    if($includeQualifiers&&!race_roster_is_public()&&!current_user_can('manage_options'))return '<p>比赛成绩榜将于报名名单公开后开放。</p>';
    ob_start();echo '<section class="sports-meet"><h2>'.($includeQualifiers?'比赛成绩榜':'项目决赛前三名').'</h2>';$groups=[];
    foreach(q("SELECT e.*,r.event_id FROM race_entries e JOIN race_rounds r ON r.id=e.round_id WHERE r.stage='final' AND r.status='finalized' AND e.place BETWEEN 1 AND 3 ORDER BY r.event_id,e.place")->fetchAll() as $r)$groups[$r['event_id']][]=$r;
    if(!$groups)echo '<p>暂无已完成决赛的前三名成绩。</p>';
    $accepted=disclaimer_acceptances();echo '<div class="race-results-grid">';
    foreach($groups as $eventId=>$entries){
        $event=race_event((int)$eventId);
        echo '<article class="race-event-result" data-result-event="'.(int)$eventId.'"><h3>'.esc_html($event['name']).'</h3><ol class="race-podium">';
        foreach($entries as $entry){
            $person=race_participant($entry);
            echo '<li><strong>'.(int)$entry['place'].' · '.esc_html($person['name']).'</strong><span class="sm-result-consent">免责声明 '.disclaimer_entry_mark($entry,$accepted).'</span><span>'.esc_html($person['team']).'</span>';
            if(race_is_distance($event))echo '<span>'.esc_html(race_distance_text($entry['distance']??null)).'</span>';
            if($event['kind']==='relay'){echo '<details><summary>接力队员</summary>';disclaimer_members_table(race_members((int)$entry['relay_id']),$accepted);echo '</details>';}
            else echo '<small>号码 '.esc_html($person['bib']).'</small>';
            echo '</li>';
        }
        echo '</ol></article>';
    }
    echo '</div>';
    echo '</section>';return ob_get_clean();
}
function race_registration_form(array $teams,array $events,array $edit=[],bool $standalone=false,int $school=0,bool $portal=false):void {
    $chosen=$edit?array_map('intval',q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$edit['id']])->fetchAll(7)):[];
    if(!$standalone)echo '<h2>'.($edit?'编辑报名':'新增报名').'</h2>';
    echo '<p>填写姓名、组别和所属高校，勾选参赛项目后一次提交。比赛号码将在检录时发放。</p>';
    if($portal)team_portal_form('save');else race_form('registration','registration');echo '<input type="hidden" name="id" value="'.(int)($edit['id']??0).'"><input type="hidden" name="registration_token" value="'.esc_attr(wp_generate_uuid4()).'">';
    if($standalone)echo '<input type="hidden" name="day_page" value="new_registration">';
    field('name','姓名','text',$edit['name']??'');race_options('category','组别',array_map(fn($label)=>['id'=>$label,'name'=>$label],array_keys(race_categories())),$edit?race_category($edit):'');
    if($school)echo '<input type="hidden" name="team_id" value="'.$school.'">';
    else race_options('team_id','所属高校',$teams,(string)($edit['team_id']??''));
    echo '<fieldset class="race-event-picker"><legend>比赛项目（可多选）</legend><p class="race-filter-status" role="status" aria-live="polite">项目会随所选组别筛选，不适用项目会隐藏。</p><div class="race-event-grid">';
    foreach($events as $e){$selected=in_array((int)$e['id'],$chosen,true);$locked=$selected && q('SELECT id FROM race_rounds WHERE event_id=? LIMIT 1',[$e['id']])->fetch();
        if($locked)echo '<input type="hidden" name="event_ids[]" value="'.(int)$e['id'].'">';
        echo '<label class="race-event-choice" data-race-categories="'.esc_attr(wp_json_encode(race_event_categories($e))).'" data-race-locked="'.($locked?'1':'0').'"><input type="checkbox" name="event_ids[]" value="'.(int)$e['id'].'" '.checked($selected,true,false).' '.($locked?'disabled':'').'><span>'.esc_html($e['name']).'<small>'.esc_html(($e['kind']==='relay'?'接力':'个人').($e['age_group']!==''?' / '.$e['age_group']:'').($e['gender']==='M'?' / 男':($e['gender']==='F'?' / 女':'')).($locked?' / 已生成比赛名单，报名已锁定':'')).'</small></span></label>';
    }
    registration_unscored_fields((int)($edit['id']??0));echo '</div></fieldset>';registration_extras_fields((int)($edit['id']??0));echo '<p class="race-registration-help">至少选择一个比赛项目或晚宴。2人3足、拔河属于不计积分的比赛；其他比赛按原赛制计分。编辑时取消勾选即取消该项报名；已生成赛程的计分项目会锁定。</p><button>'.($edit?'更新报名':'提交报名').'</button>';if($edit)echo '<a href="'.esc_url($standalone?page_url('new_registration'):race_url('registration')).'">取消编辑／新增报名</a>';echo '</form>';
}

function race_new_registration_page():string {return team_portal_page();}

function race_ui_registration():void {
    registration_extras_summary();
    $teams=all('teams');$events=all('events');
    $edit=!race_registration_closed()&&!empty($_GET['edit_athlete'])?race_one('athletes',(int)$_GET['edit_athlete']):[];
    $page=(int)(get_option('sports_meet_pages',[])['new_registration']??0);
    if(race_registration_closed())echo '<p>报名已截止，报名资料已锁定。</p>';
    elseif($edit||!$page||get_post_status($page)!=='publish')race_registration_form($teams,$events,$edit);
    else echo '<p><a href="'.esc_url(page_url('new_registration')).'">打开新增报名页面 ↗</a></p>';
    race_render_roster($teams,$events);
    if(current_user_can('manage_options'))echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'
        .'<input type="hidden" name="action" value="sports_meet_registration_test_mode">'.wp_nonce_field('sports_meet_registration_test_mode','_wpnonce',false,false)
        .'<label><input type="checkbox" name="enabled" value="1" '.checked(race_registration_test_mode(),true,false).'> 报名测试模式（名单立即公开，11 月 9 日 00:00 自动关闭）</label><button>保存测试设置</button></form>';
    if(!race_registration_closed()&&current_user_can('manage_options'))race_school_admin($teams);
    echo '<details><summary>CSV批量导入／导出</summary><p>使用新版6列模板，无需填写报名编号。同一高校、姓名和组别识别已有报名；若有同名多人，系统会要求先核对，不会自动合并。</p><p><a href="'.esc_url(race_export_url('template')).'">下载报名模板</a> · <a href="'.esc_url(race_export_url('roster')).'">导出报名资料</a></p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_race"><input type="hidden" name="race_action" value="import"><input type="hidden" name="race_tab" value="registration">'.wp_nonce_field('sports_meet_race','race_nonce',false,false).'<label>UTF-8 CSV，最多2MB<input type="file" name="csv" accept=".csv" required></label><button>导入报名</button></form></details>';
    echo '<details><summary>所属高校管理</summary>';race_form('team','registration');field('id','高校ID：新增填0','number','0','min="0"');field('name','高校名称');finish();table(['高校ID','高校名称'],array_map(fn($t)=>[$t['id'],$t['name']],$teams));echo '</details>';
}

function race_delete_ui():void {if(!current_user_can('manage_options'))return;echo '<details><summary>删除报名资料</summary><p>生成比赛名单后，相关报名资料会锁定。删除队伍前须先移走或删除运动员。</p>';race_form('delete',race_tab());race_options('type','资料类型',[['id'=>'athlete','name'=>'运动员及其报名'],['id'=>'team','name'=>'空高校'],['id'=>'event','name'=>'项目及报名'],['id'=>'relay','name'=>'接力队（保留队员报名）']]);field('id','资料ID','number','','min="1"');field('confirm','输入DELETE');finish('删除');echo '</details>';}
function race_ui_events():void {
    echo '<h2>比赛项目</h2><p>不同性别、年龄组分别建立项目。比赛名次直接由裁判填写，不按计时自动排名。</p>';race_form('event','events');field('id','项目ID：新增填0','number','0','min="0"');field('name','项目名称');race_options('kind','项目类型',[['id'=>'individual','name'=>'个人项目'],['id'=>'relay','name'=>'8×50米混合接力']]);race_options('gender','个人项目性别',[['id'=>'X','name'=>'不限'],['id'=>'M','name'=>'男'],['id'=>'F','name'=>'女']],'X');echo '<label>年龄组（可空）<input name="age_group"></label>';finish();$rows=[];foreach(all('events') as $e)$rows[]=[$e['id'],$e['name'],$e['kind']==='relay'?'接力':'个人',$e['gender'],$e['age_group'],q('SELECT COUNT(*) FROM race_enrollments WHERE event_id=?',[$e['id']])->fetchColumn()];table(['ID','名称','类型','性别','组别','报名人数'],$rows);race_delete_ui();
}
function race_ui_relay():void {relay_auto_ui(0);relay_manual_ui();}

function race_ui_checkin_legacy():void {$accepted=disclaimer_acceptances();
    echo '<h2>检录及发号</h2><p>按队伍或姓名查找运动员。比赛号码全场唯一：输入001与1视为同一号码。多裁判同时操作时，后提交的重复号码会被拒绝；同一运动员的旧页面也不能覆盖新号码。</p>';
    echo '<form method="get">';if(is_admin())echo '<input type="hidden" name="page" value="sports-meet"><input type="hidden" name="tab" value="races">';else if(!get_option('permalink_structure'))echo '<input type="hidden" name="page_id" value="'.(int)(get_option('sports_meet_pages',[])['judge']??0).'">';echo '<input type="hidden" name="race_tab" value="checkin"><label>高校、姓名或比赛号码<input name="find" value="'.esc_attr(wp_unslash($_GET['find']??'')).'"></label><button>查找</button></form>';
    $find=mb_strtolower(trim(wp_unslash($_GET['find']??'')));$shown=0;
    foreach(race_roster_rows() as $a){if($find!==''&&!str_contains(mb_strtolower($a['team_name'].' '.$a['name'].' '.$a['bib'].' '.($a['race_bib']??'')),$find))continue;if($shown++>=100)break;
        echo '<div class="race-check-card"><strong>'.esc_html($a['team_name'].' · '.$a['name']).'</strong><span>免责声明 '.disclaimer_dot($a,$accepted).'</span><span>'.esc_html(race_category($a)).' · '.esc_html(race_event_names((int)$a['id'])).'</span>';
        if($a['race_bib'] && !current_user_can('manage_options'))echo '<p>已发比赛号码：<b>'.esc_html($a['race_bib']).'</b></p>';
        else{race_form('checkin','checkin');echo '<input type="hidden" name="athlete_id" value="'.(int)$a['id'].'"><input type="hidden" name="version" value="'.(int)($a['check_version']??0).'">';field('bib',$a['race_bib']?'管理员改号':'输入比赛号码','text',$a['race_bib']??'','inputmode="numeric" pattern="[0-9]{1,8}"');finish($a['race_bib']?'修改号码':'确认发号');}echo '</div>';
    }if($shown>100)echo '<p>仅显示前100名，请按队伍或姓名缩小查询范围。</p>';
}
function race_round_label(array $round): string {
    if($round['stage']==='final')return '决赛';
    return ($round['stage']==='semi'?'半决赛':'预赛').'第'.$round['heat_no'].'组';
}

function race_public_lineups():string {
    if(!race_roster_is_public()&&!current_user_can('manage_options'))return race_public_navigation('lineups').'<p>分组名单将在报名名单公开后显示。</p>';
    $accepted=disclaimer_acceptances();ob_start();
    echo race_public_navigation('lineups').'<section class="sports-meet race-workspace race-public-lineups"><div class="race-judge-layout">';
    race_judge_round_links(null,true);
    echo '<div class="race-judge-detail">';
    try{
        $roundId=absint($_GET['round_id']??0);
        $eventId=absint($_GET['event_id']??0);
        if($roundId){
            $round=race_one('race_rounds',$roundId);
            $event=race_event((int)$round['event_id']);
            $done=$round['status']==='finalized';
            $entries=q('SELECT * FROM race_entries WHERE round_id=? ORDER BY COALESCE(lane,999),id',[$roundId])->fetchAll();
            if($done)$entries=race_completed_order($entries);
            echo '<h2>'.esc_html($event['name'].' · '.race_round_label($round)).'</h2>';
            echo '<p>'.($done?'已完成 · 全组成绩':'比赛名单 · 结果待公布').'</p>';
            $distance=race_is_distance($event);
            $headers=['免责声明','所属高校','姓名／接力队','比赛号码','确认参赛','名次'];
            if(race_eight_lane_event($event))array_unshift($headers,'赛道');if($distance)$headers[]='距离成绩';
            $rows=[];
            foreach($entries as $entry){
                $person=race_participant($entry);
                $row=[new DisclaimerCell(disclaimer_entry_mark($entry,$accepted)),$person['team'],$person['name'],$person['bib'],$done?($entry['confirmed']?'✓':'未参赛'):'—',
                    $done?(!empty($entry['no_mark'])?'未完赛':($entry['place']??'—')):'—'];
                if(race_eight_lane_event($event))array_unshift($row,$entry['lane']??'—');if($distance)$row[]=$done?race_distance_text($entry['distance']??null):'—';
                $rows[]=$row;
            }
            table($headers,$rows);
        }elseif($eventId){
            $event=race_event($eventId);
            if($event['kind']==='relay'){
                echo '<h2>'.esc_html($event['name']).'</h2>';
                relay_auto_lineups($eventId,absint($_GET['relay_heat']??0));
            }else{
            echo '<h2>'.esc_html($event['name']).'</h2><p>比赛分组尚未生成，以下为报名名单。</p>';
            $entries=q('SELECT a.id,a.name,a.team_id,t.name AS school,c.bib FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id
                JOIN teams t ON t.id=a.team_id LEFT JOIN checkins c ON c.athlete_id=a.id WHERE n.event_id=? ORDER BY t.name,a.name,a.id',[$eventId])->fetchAll();
            $rows=[];
            foreach($entries as $entry)$rows[]=[disclaimer_cell($entry,$accepted),$entry['school'],$entry['name'],$entry['bib']??'待分配'];
            table(['免责声明','所属高校','姓名','比赛号码'],$rows);
            }
        }else echo '<p>请从左侧选择项目及比赛分组。</p>';
    }catch(\Throwable $error){echo '<p>暂时无法显示这组名单，请重新选择项目。</p>';}
    echo '</div></div></section>';
    return ob_get_clean();
}

function race_judge_round_links(?string $stage,bool $public=false):void {
    $rounds=q("SELECT r.*,
        EXISTS(SELECT 1 FROM race_entries e WHERE e.round_id=r.id AND
            (e.confirmed=1 OR e.place IS NOT NULL OR e.distance IS NOT NULL OR e.elapsed IS NOT NULL OR e.no_mark=1)) AS has_draft
        FROM race_rounds r ORDER BY event_id,FIELD(stage,'heat','semi','final'),heat_no")->fetchAll();
    $byEvent=[];
    $eventProgress=[];
    foreach($rounds as $round){
        $displayStatus=$round['status']==='finalized'?'finalized':($round['status']==='running'||$round['has_draft']?'running':'open');
        $eventProgress[$round['event_id']][]=['stage'=>$round['stage'],'status'=>$displayStatus];
        if($stage==='heat'&&$round['stage']==='final')continue;
        if($stage==='final'&&$round['stage']!=='final')continue;
        $round['display_status']=$displayStatus;
        $byEvent[$round['event_id']][]=$round;
    }
    $selectedRound=absint($_GET['round_id']??0);
    $selectedEvent=absint($_GET['event_id']??0);
    echo '<aside class="race-judge-sidebar" aria-label="比赛项目和分组"><div class="race-judge-legend">'
        .'<span class="is-open">待比赛</span><span class="is-running">比赛中</span><span class="is-finalized">已完成</span></div>';
    foreach(all('events') as $event){
        $rows=$byEvent[$event['id']]??[];
        $progress=$eventProgress[$event['id']]??[];
        $statuses=array_column($progress,'status');
        $hasFinal=in_array('final',array_column($progress,'stage'),true);
        $completed=$hasFinal&&count(array_unique($statuses))===1&&$statuses[0]==='finalized';
        $started=in_array('running',$statuses,true)||in_array('finalized',$statuses,true);
        $status=$completed?'finalized':($started?'running':'open');
        $active=$selectedEvent===(int)$event['id']||in_array($selectedRound,array_map('intval',array_column($rows,'id')),true);
        echo '<details class="race-judge-event is-'.$status.'" '.($active?'open':'').'><summary>'.esc_html($event['name']).'</summary><div class="race-round-links">';
        foreach($rows as $round){
            $args=['event_id'=>$event['id'],'round_id'=>$round['id']];
            $url=$public?add_query_arg($args,page_url('lineups')):race_url('rounds',$args);
            $label=['open'=>'待比赛','running'=>'比赛中','finalized'=>'已完成'][$round['display_status']];
            echo '<a class="is-'.$round['display_status'].'" href="'.esc_url($url).'" '.($selectedRound===(int)$round['id']?'aria-current="page"':'').'>'
                .esc_html(race_round_label($round).' · '.$label).'</a>';
        }
        if(!$rows){
            if($event['kind']==='relay'){
                $plan=relay_auto_draw_plan((int)$event['id']);
                foreach($plan['draw_groups'] as $i=>$group){
                    $args=['event_id'=>$event['id'],'relay_heat'=>$i+1];
                    $url=$public?add_query_arg($args,page_url('lineups')):race_url('rounds',$args);
                    echo '<a class="is-open" href="'.esc_url($url).'">'.esc_html((count($plan['draw_groups'])===1?'决赛':'预赛第'.($i+1).'组').' · 预编排').'</a>';
                }
                if($plan['draw_groups']){echo '</div></details>';continue;}
            }
            $url=$public?add_query_arg('event_id',$event['id'],page_url('lineups')):race_url('rounds',['event_id'=>$event['id']]);
            echo '<a class="is-open" href="'.esc_url($url).'">名单待生成</a>';
        }
        echo '</div></details>';
    }
    echo '</aside>';
}
function race_ui_rounds(?string $stage=null):void {
    echo '<div class="race-judge-layout">';
    race_judge_round_links($stage);
    echo '<div class="race-judge-detail">';
    try{race_round_detail($stage);}
    finally{echo '</div></div>';}
}
function race_round_detail(?string $stage=null):void {
    $admin=current_user_can('manage_options');
    if(empty($_GET['round_id'])&&!empty($_GET['event_id'])){
        $event=race_event(absint($_GET['event_id']));
        if($event['kind']==='relay'){
            $existing=q("SELECT id FROM race_rounds WHERE event_id=? ORDER BY FIELD(stage,'heat','semi','final'),heat_no LIMIT 1",[$event['id']])->fetchColumn();
            if($existing)$_GET['round_id']=(int)$existing;
            else{
                echo '<h2>'.esc_html($event['name']).' · 系统自动组队</h2>';
                relay_auto_lineups((int)$event['id'],absint($_GET['relay_heat']??0));
                $plan=relay_auto_draw_plan((int)$event['id']);
                if($plan['teams']){
                    echo '<p>采用后固定本项目的8人上场名单、分组和赛道，并锁定接力报名；已生成的赛程不会被覆盖。</p>';
                    race_form('prepare_relay','rounds');echo '<input type="hidden" name="event_id" value="'.(int)$event['id'].'"><button>采用系统组队并生成正式赛程</button></form>';
                }
                return;
            }
        }
    }
    echo '<details class="race-sprint-draw"><summary>100米预先随机分组</summary><p>报名名单核对完成后，由管理员生成各组名单。超过8人时随机均分预赛，每组最多8人；8人以内直接决赛。已生成赛程的项目会跳过，生成后该项目报名锁定。</p>';
    if($admin){race_form('prepare_sprints','rounds');finish('生成全部100米项目分组');}
    else echo '<p>请联系管理员使用管理员账号登录此页生成分组。裁判可在下方录入已生成的比赛。</p>';
    echo '</details>';
    if($stage==='final'||(!$stage&&empty($_GET['round_id'])&&!empty($_GET['event_id']))){
        $selectedRound=race_final_projects(false);
        if(!$selectedRound)return;
        $_GET['round_id']=$selectedRound;
    }
    if($admin&&!$stage){echo '<details><summary>生成预赛／直接决赛名单</summary><p>生成后锁定此项目报名。100米每组最多8人，接力每组最多4队。系统随机均分并按组内冲线名次晋级，需要时自动安排半决赛。其他个人项目仍可按下方设置分组。</p>';race_form('generate','rounds');race_options('event_id','项目',all('events'));race_options('stage','比赛安排',[['id'=>'heat','name'=>'先预赛，自动晋级决赛'],['id'=>'final','name'=>'直接决赛']],'heat');field('capacity','其他个人项目每组人数','number','8','min="1" max="100"');echo '<label><input type="checkbox" name="individual_split" value="1"> 其他个人项目分组（每组前三晋级）</label>';finish('生成名单');echo '</details>';}

    if(empty($_GET['round_id'])){echo '<p>请从左侧选择项目及比赛分组。</p>';return;}$round=race_one('race_rounds',(int)$_GET['round_id']);$event=race_event((int)$round['event_id']);if($stage&&($stage==='final')!==($round['stage']==='final')){echo '<p class="error">此轮不属于当前页面，请从对应预赛／决赛入口选择。</p>';return;}$ready=true;try{race_round_ready($round);}catch(\Throwable $e){$ready=false;echo '<p class="error">'.esc_html($e->getMessage()).'</p>';}
    $format=(int)($round['eight_lanes']??0);
    $timedHeat=$round['stage']==='heat'&&$format===1;
    $placingRound=$round['stage']!=='final'&&$format>=2;
    $maxPlace=($placingRound||$round['stage']==='final')?(int)q('SELECT COUNT(*) FROM race_entries WHERE round_id=?',[$round['id']])->fetchColumn():3;
    if($placingRound)echo '<p class="notice">本组前 '.race_placing_quota($round).' 名晋级。请录入全组冲线名次。</p>';
    echo '<h2>'.esc_html($event['name'].' · '.race_round_label($round)).'</h2><p>版本 '.(int)$round['version'].'。勾选实际参赛者。'.($timedHeat?'预赛按用时自动排组内名次。':($placingRound?'按冲线顺序录入晋级名次。':'距离项目由成绩自动排名，其他项目填写前3名。')).'暂存不公示、不计分；提交完成后才计分和晋级。</p>';
    if($timedHeat){
        $groups=(int)q("SELECT COUNT(*) FROM race_rounds WHERE event_id=? AND stage='heat'",[$round['event_id']])->fetchColumn();
        $quota=race_lane_quota($groups);
        echo '<p class="notice">共 '.$groups.' 组预赛，每组前 '.$quota.' 名直接晋级，剩余 '.(8-$groups*$quota).' 席按预赛用时补足。有效成绩不足时决赛少于8席。用时填秒，无有效成绩填0。</p>';
    }
    if($round['status']==='finalized'){ $summaryRows=q('SELECT confirmed FROM race_entries WHERE round_id=?',[$round['id']])->fetchAll();echo '<p class="notice race-completed-summary">本轮已完成 · 名单'.count($summaryRows).'人／队 · 确认参赛'.array_sum(array_column($summaryRows,'confirmed')).'人／队 · 提交时间'.esc_html($round['updated']).'。以下显示已保存结果，前三名已置顶。</p>'; }
    $distanceEvent=race_is_distance($event);if($distanceEvent)echo '<p class="notice">本项目填距离（米），由大到小自动排出前三。无有效距离填0；影响前三的同距选手须填写同距顺序1、2、3…。</p>';$editable=$ready&&$round['status']!=='finalized';if($editable){race_form('round','rounds');$manual=relay_manual_state((int)$event['id']);if($manual)echo '<input type="hidden" name="relay_manual_revision" value="'.esc_attr($manual['revision']).'">';echo '<input type="hidden" name="round_id" value="'.(int)$round['id'].'"><input type="hidden" name="version" value="'.(int)$round['version'].'">';}
    $accepted=disclaimer_acceptances();echo '<div class="scroll"><table '.($distanceEvent?'data-distance-table':'').'><thead><tr>'.(race_eight_lane_event($event)?'<th>赛道</th>':'').'<th class="sm-consent-cell">免责声明</th><th>所属高校</th><th>姓名／接力队</th><th>比赛号码</th><th>确认参赛</th>'.($distanceEvent?'<th>距离（米）</th><th>同距顺序</th><th>自动名次</th>':($timedHeat?'<th>用时（秒）</th><th>同时间顺序</th><th>自动名次</th>':'<th>名次</th>')).'</tr></thead><tbody>';
    $displayRows=q('SELECT * FROM race_entries WHERE round_id=? ORDER BY COALESCE(lane,999),id',[$round['id']])->fetchAll();if($round['status']==='finalized')$displayRows=race_completed_order($displayRows);
    foreach($displayRows as $r){$p=race_participant($r);echo '<tr>'.(race_eight_lane_event($event)?'<td>'.esc_html($r['lane']??'—').'</td>':'').'<td class="sm-consent-cell">'.disclaimer_entry_mark($r,$accepted).'</td><td>'.esc_html($p['team']).'</td><td>'.esc_html($p['name']).($r['relay_id']?'<small>'.esc_html($p['members']).'</small>':'').'</td><td>'.esc_html($p['bib']).'</td><td>';
        if($editable)echo '<input type="checkbox" name="entries['.(int)$r['id'].'][confirmed]" value="1" aria-label="'.esc_attr('确认'.$p['name'].'参赛').'" '.checked((int)$r['confirmed'],1,false).'>';else echo $r['confirmed']?'✓':'—';if($distanceEvent){echo '</td>';race_distance_cells($r,$p,$editable);echo '</tr>';continue;}echo '</td><td>';
        if($timedHeat){
            $elapsed=$r['elapsed']??(!empty($r['no_mark'])?'0':'');
            $input='entries['.(int)$r['id'].']';
            echo $editable?'<input type="number" min="0" step="0.001" name="'.$input.'[elapsed]" value="'.esc_attr($elapsed).'" aria-label="'.esc_attr($p['name'].'的用时').'">':esc_html($elapsed);
            echo '</td><td>';
            echo $editable?'<input type="number" min="1" max="999" name="'.$input.'[tie_order]" value="'.esc_attr($r['tie_order']??'').'" aria-label="'.esc_attr($p['name'].'的同时间顺序').'">':esc_html($r['tie_order']??'—');
            echo '</td><td>'.esc_html($r['place']??'—');
        }elseif($editable){
            $input='entries['.(int)$r['id'].']';
            echo '<input type="number" min="1" max="'.$maxPlace.'" name="'.$input.'[place]" value="'.esc_attr($r['place']??'').'" aria-label="'.esc_attr($p['name'].'的名次').'">';
            if($placingRound&&$round['status']==='running')echo '<label><input type="checkbox" name="'.$input.'[no_mark]" value="1" '.checked((int)$r['no_mark'],1,false).'> 未完赛</label>';
        }else echo $placingRound&&!empty($r['no_mark'])?'未完赛':esc_html($r['place']??'—');echo '</td></tr>';
    }echo '</tbody></table></div>';
    if($editable)echo '<button name="decision" value="save">暂存</button><button name="decision" value="finalize">提交本轮完成并计分</button><details><summary>本轮全部无人出赛</summary><label>确保全部取消确认勾选及名次，再输入DNS<input name="empty_confirm" placeholder="DNS"></label><button name="decision" value="empty">按无人出赛提交（0分）</button></details></form>';
    if($event['kind']==='relay'){echo '<details><summary>各接力队员免责声明</summary>';foreach($displayRows as $entry){echo '<h3>'.esc_html(race_participant($entry)['name']).'</h3>';disclaimer_members_table(race_members((int)$entry['relay_id']),$accepted);}echo '</details>';}
    if($admin){echo '<details><summary>管理员调整分组／撤回更正</summary><p>调整预赛分组仅在尚无预赛提交完成时允许，移动条目会清除该条目的暂存确认和名次。撤回预赛会重新生成决赛名单，并清除尚未完成决赛的暂存记录；已完成决赛须先撤回决赛。更正后须再次提交完成才公示。</p>';race_form('move','rounds');field('entry_id','要移动的条目ID','number','','min="1"');field('heat_no','目标预赛组号','number','','min="1" max="100"');finish('移动到目标组');race_form('reopen','rounds');field('round_id','撤回轮次ID','number',(string)$round['id'],'min="1"');field('confirm','输入REOPEN');finish('撤回本轮');echo '</details>';}
}
function race_relay_rules():void {echo race_relay_rules_html();}

function race_ui_rules():void {
    race_relay_rules();
    $rules=get_option('sports_meet_race_rules');$s=q('SELECT * FROM settings WHERE id=1')->fetch();echo '<h2>比赛积分规则</h2><p>参赛分每人每项目只计一次，进入决赛不重复加分。接力名次总分除以8，每位队员得分计回所属高校。更改分值会重算已提交成绩。</p>';race_form('rules','rules');field('title','比赛名称','text',$s['title']);field('individual_part','个人每人参赛分','number',$rules['individual_part'],'min="0" step="0.01"');foreach([1,2,3] as $i)field('individual_'.$i,'个人决赛第'.$i.'名','number',$rules['individual_places'][$i-1],'min="0" step="0.01"');field('relay_part','接力每人参赛分','number',$rules['relay_part'],'min="0" step="0.01"');foreach([1,2,3] as $i)field('relay_'.$i,'接力决赛第'.$i.'名总分','number',$rules['relay_places'][$i-1],'min="0" step="0.01"');field('relay_males','8人混合接力中男性人数','number',(string)$s['relay_males'],'min="1" max="7"');finish();
}
function race_workspace(bool $frontend=false):string {
    if(!race_can_judge()){if(!is_user_logged_in())return '<div class="sports-meet"><h2>裁判工作台</h2>'.wp_login_form(['echo'=>false,'redirect'=>page_url('judge')]).'</div>';return '<p>此页面仅供管理员或运动会裁判使用，请联系管理员分配裁判角色。</p>';}
    ob_start();echo '<section class="sports-meet race-workspace">';if(is_admin())echo '<h2>报名及比赛工作台</h2>';if(is_admin())echo '<nav class="race-tabs">';$tabs=current_user_can('manage_options')?['registration'=>'报名及CSV','events'=>'项目','relay'=>'接力组队','checkin'=>'检录发号','rounds'=>'预赛／决赛','rules'=>'积分设置','audit'=>'操作记录']:['checkin'=>'检录发号','relay'=>'接力组队','rounds'=>'预赛／决赛'];
    $tab=race_tab();if(!isset($tabs[$tab]))$tab='checkin';if(is_admin())foreach($tabs as $t=>$label)echo '<a href="'.esc_url(race_url($t)).'">'.esc_html($label).'</a>';if(is_admin())echo '</nav>';else echo race_judge_navigation($tab==='rounds'?'heats':$tab);race_notice();
    try{switch($tab){case 'registration':race_ui_registration();break;case 'events':race_ui_events();break;case 'relay':race_ui_relay();break;case 'checkin':race_ui_checkin();break;case 'rounds':race_ui_rounds();break;case 'rules':race_ui_rules();break;case 'audit':$rows=[];foreach(q('SELECT * FROM race_audit ORDER BY id DESC LIMIT 100')->fetchAll() as $r){$user=get_userdata((int)$r['actor']);$rows[]=[$r['created'],$user?$user->display_name:$r['actor'],$r['action'],$r['record_id'],$r['payload']];}table(['时间','操作人','动作','记录ID','变更记录'],$rows);break;}}catch(\Throwable $e){error_log('Sports Meet UI: '.$e->getMessage());echo '<p class="error">无法显示，请刷新或检查所选资料是否仍存在。</p>';}
    echo '</section>';return ob_get_clean();
}
function race_export():void {
    if(!current_user_can('manage_options'))wp_die('没有导出权限。','',['response'=>403]);check_admin_referer('sports_meet_race_export');$type=sanitize_key($_GET['type']??'');if(in_array($type,['template','roster'],true))race_registration_export($type);$head=['team','registration_id','name','gender','age_group','event','kind','relay_name'];
    if($type==='template')downloadCsv('registration-template',$head,[['红队','R001','王小明','M','成人组','男子50米','individual',''],['红队','R001','王小明','M','成人组','8×50米混合接力','relay','红蓝联队']]);
    if($type==='roster'){$rows=q('SELECT t.name,a.bib,a.name AS athlete_name,a.gender,a.age_group,e.name AS event_name,e.kind,COALESCE(r.name,?) AS relay_name FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id JOIN events e ON e.id=n.event_id LEFT JOIN race_members m ON m.athlete_id=a.id AND m.event_id=e.id LEFT JOIN race_relays r ON r.id=m.relay_id ORDER BY a.id,e.id',[''])->fetchAll(3);downloadCsv('registrations',$head,$rows);}
    if($type==='scores'){$rows=[];foreach(race_scoreboard() as $t)$rows[]=[$t['rank'],$t['name'],race_fmt($t['part']),race_fmt($t['place']),race_fmt($t['total'])];downloadCsv('team-scores',['名次','所属高校','参赛分','名次分','总积分'],$rows);}
    wp_die('未知导出类型。');
}
add_action('admin_post_sports_meet_race_export',__NAMESPACE__.'\\race_export');
add_shortcode('sports_meet_roster',__NAMESPACE__.'\\race_public_roster');
add_shortcode('sports_meet_judge',fn()=>race_workspace(true));
