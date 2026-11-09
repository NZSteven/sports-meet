<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

function disclaimer_keep_signature(int $id,string $source,string $expected):void {
    $athlete=race_one('athletes',$id);$identity=disclaimer_identity($athlete);
    if(!hash_equals($identity,$expected))throw new \RuntimeException('报名姓名已变化，请刷新核对。');
    $hash=hash('sha256',disclaimer_text());
    $record=q('SELECT * FROM disclaimer_acceptances WHERE athlete_id=? AND statement_hash=? AND identity_hash=?',[$id,$hash,$source])->fetch();
    $data=$record?json_decode($record['signature_data']??'',true):null;
    if(!$record||!is_array($data)||empty($data['participant']))throw new \RuntimeException('没有可保留的原电子签署，请参赛者重新签署。');
    if(disclaimer_agreed($athlete,disclaimer_acceptances($id)))return;
    $data['name_correction']=['original_name'=>$data['participant'],'current_name'=>$athlete['name'],'source_identity'=>$source,'confirmed_by'=>get_current_user_id(),'confirmed_at'=>current_time('mysql')];
    q('INSERT INTO disclaimer_acceptances(athlete_id,statement_hash,identity_hash,actor,accepted_at,signature_data) VALUES(?,?,?,?,?,?)',[$id,$hash,$identity,$record['actor'],$record['accepted_at'],wp_json_encode($data)]);
    race_audit('waiver_name_correction',$id,$data['name_correction']);
    bib_auto_issue($athlete);
}
function disclaimer_name_corrections_ui():void {
    $hash=hash('sha256',disclaimer_text());$accepted=disclaimer_acceptances();
    $records=q('SELECT d.athlete_id,d.identity_hash,d.signature_data,d.accepted_at,a.name,a.team_id,t.name AS school FROM disclaimer_acceptances d JOIN athletes a ON a.id=d.athlete_id JOIN teams t ON t.id=a.team_id WHERE d.statement_hash=? ORDER BY d.accepted_at DESC',[$hash])->fetchAll();
    echo '<details><summary>姓名更正：保留原签署</summary><p>仅用于同一参赛者的姓名更正。保留原签署姓名、签署时间和影像授权；更换参赛者须重新签署。</p><div class="scroll"><table><thead><tr><th>所属高校</th><th>当前姓名</th><th>原签署姓名</th><th>签署时间</th><th>操作</th></tr></thead><tbody>';
    $seen=[];$count=0;
    foreach($records as $r){
        if(isset($seen[$r['athlete_id']])||disclaimer_agreed(['id'=>$r['athlete_id'],'name'=>$r['name'],'team_id'=>$r['team_id']],$accepted))continue;
        $data=json_decode($r['signature_data']??'',true);if(!is_array($data)||empty($data['participant']))continue;
        $seen[$r['athlete_id']]=true;$count++;
        echo '<tr>';foreach([$r['school'],$r['name'],$data['participant'],$r['accepted_at']] as $v)echo '<td>'.esc_html($v).'</td>';
        echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sports_meet_keep_signature">'.wp_nonce_field('sports_meet_keep_signature','sm_nonce',false,false).'<input type="hidden" name="athlete_id" value="'.(int)$r['athlete_id'].'"><input type="hidden" name="source_identity" value="'.esc_attr($r['identity_hash']).'"><input type="hidden" name="expected_identity" value="'.esc_attr(disclaimer_identity(['team_id'=>$r['team_id'],'name'=>$r['name']])).'"><label><input type="checkbox" name="same_person" value="1" required> 确认是同一人</label><button>保留原签署</button></form></td></tr>';
    }
    if(!$count)echo '<tr><td colspan="5">暂无需要核对的姓名更正。</td></tr>';
    echo '</tbody></table></div></details>';
}
add_action('admin_post_sports_meet_keep_signature',function(){
    if(!current_user_can('manage_options'))wp_die('需要管理员权限。','',['response'=>403]);
    check_admin_referer('sports_meet_keep_signature','sm_nonce');
    try{
        if(($_POST['same_person']??'')!=='1')throw new \RuntimeException('请确认是同一参赛者。');
        race_transaction(fn()=>disclaimer_keep_signature(absint($_POST['athlete_id']??0),sanitize_text_field(wp_unslash($_POST['source_identity']??'')),sanitize_text_field(wp_unslash($_POST['expected_identity']??''))));
        photo_notice('已保留原签署，无需参赛者重签。');
    }catch(\Throwable $e){photo_notice($e->getMessage());}
    wp_safe_redirect(admin_url('admin.php?page=sports-meet&tab=disclaimer'));exit;
});
