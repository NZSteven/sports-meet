<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function awards_demo():bool {return (bool)get_option('sports_meet_awards_demo',false);}
function awards_state():array {return (array)get_option(awards_demo()?'sports_meet_awards_demo_state':'sports_meet_awards_state',['stage'=>0,'revision'=>0]);}
function awards_demo_scores():array {
    $teams=all('teams');shuffle($teams);$count=count($teams);$rows=[];
    foreach($teams as $i=>$team)$rows[]=['id'=>(int)$team['id'],'name'=>$team['name'],'rank'=>$i+1,'total'=>(40+($count-$i)*11+random_int(0,8))*800];
    return $rows;
}
function awards_public_state():array {
    $state=awards_state();$stage=(int)($state['stage']??0);$teams=$state['snapshot']??race_scoreboard();
    if(!$stage)usort($teams,fn($a,$b)=>strcmp($a['name'],$b['name']));
    $cutoff=[0=>PHP_INT_MAX,1=>7,2=>6,3=>5,4=>4,5=>3,6=>2,7=>1][$stage];$rows=[];
    foreach($teams as $i=>$t){$shown=$stage>0&&(int)$t['rank']>=$cutoff;$rows[]=['id'=>(int)$t['id'],'name'=>$t['name'],'rank'=>$stage?(int)$t['rank']:null,'score'=>$shown?race_fmt((int)$t['total']):null,'revealed'=>$shown];}
    $titles=['等待荣耀揭晓','后十名积分已公布','第六名积分揭晓','第五名积分揭晓','第四名积分揭晓','季军荣耀揭晓','亚军荣耀揭晓','冠军荣耀揭晓'];
    return ['stage'=>$stage,'revision'=>(int)($state['revision']??0),'title'=>(awards_demo()?'【测试积分 · 非正式赛果】':'').$titles[$stage],'teams'=>$rows,'frozen'=>isset($state['snapshot']),'demo'=>awards_demo()];
}
function awards_advance(string $action,int $revision,?bool $expectedDemo=null):array {
    if($expectedDemo!==null&&$expectedDemo!==awards_demo())throw new \RuntimeException('测试模式已切换，请刷新后操作。');
    $state=awards_state();if((int)($state['revision']??0)!==$revision)throw new \RuntimeException('公布状态已更新，请刷新后继续。');
    if($action==='demo_start'){
        update_option('sports_meet_awards_demo_state',['stage'=>0,'revision'=>$revision+1,'snapshot'=>awards_demo_scores()],false);
        update_option('sports_meet_awards_demo',true,false);
        race_audit('awards_demo_start',0,[]);return awards_public_state();
    }
    if($action==='demo_end'){
        update_option('sports_meet_awards_demo',false,false);
        race_audit('awards_demo_end',0,[]);return awards_public_state();
    }
    $stage=(int)($state['stage']??0);
    if($action==='reset'){$state=['stage'=>0,'revision'=>$revision+1]+(awards_demo()?['snapshot'=>$state['snapshot']??awards_demo_scores()]:[]);}
    else{
        $valid=($action==='points'&&$stage<4)||($action==='bronze'&&$stage===4)||($action==='silver'&&$stage===5)||($action==='gold'&&$stage===6);
        if(!$valid)throw new \RuntimeException('请依次公布：后十名、第六、第五、第四、季军、亚军、冠军。');
        if(!$stage){$state['snapshot']=awards_demo()?($state['snapshot']??awards_demo_scores()):race_scoreboard();$state['frozen_at']=current_time('mysql');}
        $state['stage']=$stage+1;$state['revision']=$revision+1;
    }
    update_option(awards_demo()?'sports_meet_awards_demo_state':'sports_meet_awards_state',$state,false);race_audit('awards_'.$action,0,['stage'=>$state['stage'],'revision'=>$state['revision'],'demo'=>awards_demo()]);return awards_public_state();
}
add_action('rest_api_init',function(){
    register_rest_route('sports-meet/v1','/awards',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function(){$r=new \WP_REST_Response(awards_public_state());$r->header('Cache-Control','no-store, max-age=0');return $r;}]);
    register_rest_route('sports-meet/v1','/awards/control',['methods'=>'POST','permission_callback'=>fn()=>current_user_can('manage_options'),'callback'=>function(\WP_REST_Request $request){try{$p=$request->get_json_params();$data=race_transaction(fn()=>awards_advance(sanitize_key($p['action']??''),(int)($p['revision']??-1),isset($p['demo'])?(bool)$p['demo']:null));return new \WP_REST_Response($data);}catch(\Throwable $e){return new \WP_Error('awards_control',$e->getMessage(),['status'=>409]);}}]);
});
function awards_page():string {
    $main=dirname(__DIR__).'/sports-meet.php';wp_enqueue_style('sports-meet-awards',plugins_url('awards.css',$main),[],'2.5.72');wp_enqueue_script('sports-meet-awards',plugins_url('awards.js',$main),[],'2.5.72',true);
    $state=awards_public_state();$admin=current_user_can('manage_options');
    $html='<section class="sm-awards" data-awards-url="'.esc_url(rest_url('sports-meet/v1/awards')).'" data-control-url="'.esc_url(rest_url('sports-meet/v1/awards/control')).'"'.($admin?' data-nonce="'.esc_attr(wp_create_nonce('wp_rest')).'"':'').'><header><p>为Fun而奔 · 荣耀此刻</p><h2>奔Fun运动会 · 荣誉揭晓</h2><p data-awards-status role="status">'.esc_html($state['title']).'</p></header><div class="sm-awards-grid" data-awards-grid>';
    foreach($state['teams'] as $t)$html.='<article><span class="sm-awards-cup" hidden>🏆</span><strong>'.esc_html($t['name']).'</strong><span class="sm-awards-score'.($t['revealed']?'':' is-masked').'">'.esc_html($t['revealed']?'第'.$t['rank'].'名 · '.$t['score'].'分':'•••').'</span></article>';
    $html.='</div><div class="sm-awards-controls" aria-label="名次公布控制">';
    foreach(['points'=>'公布积分','bronze'=>'季军','silver'=>'亚军','gold'=>'冠军'] as $key=>$label)$html.='<button type="button" data-awards-action="'.$key.'" disabled>'.$label.'</button>';
    $html.='</div><p class="sm-awards-hint">'.($admin?'管理员控制 · 观众页面自动同步。首次公布时固定积分快照，同分按并列名次展示。':'请等待主持人揭晓，页面将自动同步。').'</p><div class="sm-awards-podium" data-awards-podium></div><div class="sm-awards-overlay" data-awards-overlay hidden role="dialog" aria-label="获奖队伍揭晓"><button type="button" data-awards-close aria-label="关闭揭晓动画">×</button><div data-awards-celebration></div></div>';
    if($admin)$html.='<details class="sm-awards-admin"><summary>主持人设置</summary><p>临时测试仅影响本页，真实比赛成绩和正式积分榜保持不变。</p><button type="button" data-awards-demo-start>加载／重新随机测试积分</button> <button type="button" data-awards-demo-end>退出测试，恢复正式公布</button><p>重置仅清除本页公布进度，保留比赛成绩。重新公布时读取最新积分。</p><label><input type="checkbox" data-awards-reset-confirm> 确认重新开始公布</label><button type="button" data-awards-reset>重置公布进度</button></details>';
    $html.='<script type="application/json" data-awards-initial>'.wp_json_encode($state,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script></section>';return $html;
}
add_shortcode('sports_meet_awards',__NAMESPACE__.'\\awards_page');
add_action('wp_enqueue_scripts',function(){
    if(!is_singular()||!has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'sports_meet_awards'))return;
    $main=dirname(__DIR__).'/sports-meet.php';
    wp_enqueue_style('sports-meet-awards',plugins_url('awards.css',$main),[],'2.5.72');
    wp_enqueue_script('sports-meet-awards',plugins_url('awards.js',$main),[],'2.5.72',true);
});
add_action('admin_init',function(){
    if(!current_user_can('manage_options'))return;$id=(int)get_option('sports_meet_awards_page');
    if($id&&get_post_status($id)&&get_post_status($id)!=='trash')return;
    $page=get_page_by_path('sports-awards');
    if($page){if(has_shortcode($page->post_content,'sports_meet_awards'))update_option('sports_meet_awards_page',$page->ID,false);return;}
    $id=wp_insert_post(['post_title'=>'荣誉揭晓','post_name'=>'sports-awards','post_type'=>'page','post_status'=>'publish','post_content'=>'[sports_meet_awards]','comment_status'=>'closed'],true);
    if(!is_wp_error($id))update_option('sports_meet_awards_page',$id,false);
});
add_action('template_redirect',function(){if(is_singular()&&has_shortcode((string)get_post_field('post_content',get_queried_object_id()),'sports_meet_awards')){if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);nocache_headers();}});
