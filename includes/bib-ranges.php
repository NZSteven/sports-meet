<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function bib_range_plan():array {
    global $wpdb;
    $value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'sports_meet_bib_ranges'));
    $plan=$value?maybe_unserialize($value):[];
    return is_array($plan)?$plan:[];
}
function bib_range_counts():array {
    return q('SELECT t.id,t.name,COUNT(a.id) AS people FROM teams t LEFT JOIN athletes a ON a.team_id=t.id GROUP BY t.id,t.name ORDER BY t.id')->fetchAll();
}
function bib_range_propose(array $schools):array {
    if(!$schools)throw new \RuntimeException('尚无高校。');
    $total=array_sum(array_column($schools,'people'));
    if($total+count($schools)>300)throw new \RuntimeException('101–400不足以给每所高校预留多于现有报名人数的号码。');
    $empty=count(array_filter($schools,fn($s)=>(int)$s['people']===0));
    $future=$empty?max(1,(int)ceil(max(0,220-$total)/$empty)):1;
    $weights=[];$sizes=[];
    foreach($schools as $s){$id=(int)$s['id'];$sizes[$id]=(int)$s['people']+1;$weights[$id]=max(1,(int)$s['people'],$s['people']?1:$future);}
    $extra=300-array_sum($sizes);$sum=array_sum($weights);$fractions=[];
    foreach($weights as $id=>$w){$share=$extra*$w/$sum;$whole=(int)floor($share);$sizes[$id]+=$whole;$fractions[$id]=$share-$whole;}
    arsort($fractions,SORT_NUMERIC);$left=300-array_sum($sizes);
    foreach(array_keys($fractions) as $id){if($left--<=0)break;$sizes[$id]++;}
    $next=101;$ranges=[];
    foreach($schools as $s){$id=(int)$s['id'];$ranges[$id]=['name'=>$s['name'],'start'=>$next,'end'=>$next+$sizes[$id]-1,'registered'=>(int)$s['people']];$next+=$sizes[$id];}
    return ['ranges'=>$ranges,'expected'=>220,'created'=>current_time('mysql')];
}
function bib_competitor(int $id):bool {
    return (bool)q("SELECT id FROM race_enrollments WHERE athlete_id=? LIMIT 1",[$id])->fetch()
        ||(bool)q("SELECT athlete_id FROM registration_extras WHERE athlete_id=? AND activity IN ('three_legged','tug_of_war') LIMIT 1",[$id])->fetch();
}
// Caller holds the same settings-row transaction lock as check-in and signature saves.
function bib_auto_issue(array $athlete):?string {
    $plan=bib_range_plan();if(!$plan)return null;
    $id=(int)$athlete['id'];
    if(!bib_competitor($id)||!disclaimer_agreed($athlete,disclaimer_acceptances($id)))return null;
    $old=q('SELECT bib FROM checkins WHERE athlete_id=?',[$id])->fetchColumn();if($old)return (string)$old;
    $range=$plan['ranges'][(int)$athlete['team_id']]??null;if(!$range)return null;
    $used=array_fill_keys(q('SELECT bib FROM checkins')->fetchAll(7),true);
    for($bib=$range['start'];$bib<=$range['end'];$bib++){
        if(isset($used[(string)$bib]))continue;
        [$actor,$now]=race_actor();q('INSERT INTO checkins(athlete_id,bib,version,actor,updated) VALUES(?,?,?,?,?)',[$id,(string)$bib,(int)($plan['version_floor']??1),$actor,$now]);
        race_audit('auto_bib',$id,['bib'=>$bib,'school'=>(int)$athlete['team_id']]);return (string)$bib;
    }
    return null;
}
function bib_sync_signed():void {
    $accepted=disclaimer_acceptances();
    foreach(q('SELECT a.* FROM athletes a LEFT JOIN checkins c ON c.athlete_id=a.id WHERE c.athlete_id IS NULL ORDER BY a.id')->fetchAll() as $a){
        if(disclaimer_agreed($a,$accepted))bib_auto_issue($a);
    }
}
function bib_initialize():void {
    if(bib_range_plan())throw new \RuntimeException('号码区间已初始化，不会再次清除号码。');
    $plan=bib_range_propose(bib_range_counts());
    $old=q('SELECT * FROM checkins')->fetchAll();
    $plan['version_floor']=max([1,...array_map(fn($c)=>(int)$c['version']+1,$old)]);
    race_audit('reset_bibs',0,['previous'=>$old,'ranges'=>$plan]);
    if(!add_option('sports_meet_bib_ranges',$plan,'',false))throw new \RuntimeException('号码区间保存失败。');
    q('DELETE FROM checkins');
    bib_sync_signed();
}
add_action('admin_post_sports_meet_init_bib_ranges',function(){
    if(!current_user_can('manage_options'))wp_die('需要管理员权限。','',['response'=>403]);
    check_admin_referer('sports_meet_init_bib_ranges');
    try{race_transaction(fn()=>bib_initialize());$message='已清除旧比赛号码，固定101–400各校区间，并为已签署的参赛者自动发号。';}
    catch(\Throwable $e){wp_cache_delete('sports_meet_bib_ranges','options');wp_cache_delete('notoptions','options');$message=$e->getMessage();}
    set_transient(race_notice_key(),[false,$message],120);
    wp_safe_redirect(admin_url('admin.php?page=sports-meet&tab=races&race_tab=checkin'));exit;
});
add_action('init',function(){
    if(!get_option('sports_meet_bib_ranges')||get_transient('sports_meet_bib_sync_recent'))return;
    set_transient('sports_meet_bib_sync_recent',1,60);
    try{race_transaction(fn()=>bib_sync_signed());}catch(\Throwable $e){error_log('Sports Meet auto bib: '.$e->getMessage());}
},40);
function bib_range_ui():void {
    if(!current_user_can('manage_options'))return;
    $plan=bib_range_plan();
    echo '<details><summary>高校比赛号码预留（101–400）</summary>';
    if(!$plan){
        try{$preview=bib_range_propose(bib_range_counts());$plan=$preview;}
        catch(\Throwable $e){echo '<p>'.esc_html($e->getMessage()).'</p></details>';return;}
        echo '<p>按当前各校报名人数预留额外号码；未报名高校按预计总人数220人分配。初始化会清除现有比赛号码并立即为已签署的参赛者重新发号，仅执行一次。只报晚宴不发号。</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_init_bib_ranges">'.wp_nonce_field('sports_meet_init_bib_ranges','_wpnonce',false,false).'<button>初始化区间、清除旧号并自动发号</button></form>';
    }else echo '<p>区间已固定；签署当前免责声明后自动领取本校空余号码。区间不足时须联系管理员调整，不会跨校占号。</p>';
    $rows=[];
    foreach(bib_range_counts() as $s){$r=$plan['ranges'][(int)$s['id']]??null;$issued=(int)q('SELECT COUNT(*) FROM checkins c JOIN athletes a ON a.id=c.athlete_id WHERE a.team_id=?',[$s['id']])->fetchColumn();$eligible=0;$pending=0;$accepted=disclaimer_acceptances();foreach(q('SELECT * FROM athletes WHERE team_id=?',[$s['id']])->fetchAll() as $a)if(bib_competitor((int)$a['id'])&&disclaimer_agreed($a,$accepted)){$eligible++;if(!q('SELECT bib FROM checkins WHERE athlete_id=?',[$a['id']])->fetchColumn())$pending++;}
        $capacity=$r?$r['end']-$r['start']+1:0;
        $rows[]=[$s['name'],$s['people'],$r?$r['start'].'–'.$r['end']:'未预留',$capacity,$issued,max(0,$capacity-$issued),$pending?('已签未发'.$pending.'人，请检查区间容量'):'—'];
    }
    table(['高校','报名人数','号码区间','容量','已发号','剩余','提示'],$rows);echo '</details>';
}
