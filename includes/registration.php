<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_categories():array {return ['成年男子'=>['M','成年'],'成年女子'=>['F','成年'],'少年男子'=>['M','少年'],'少年女子'=>['F','少年']];}
function race_category(array $a):string {$young=preg_match('/少年|青少年|小学|中学/u',$a['age_group']??'');return ($young?'少年':'成年').(($a['gender']??'M')==='F'?'女子':'男子');}
function race_group_matches(string $required,string $actual,string $gender):bool {
    if($required===''||$required===$actual)return true;
    $base=function($g){if(preg_match('/^(成年|成人)(组|男子|女子)?$/u',$g))return '成年';if(preg_match('/^(少年|青少年)(组|男子|女子)?$/u',$g))return '少年';return $g;};
    if(str_contains($required,'男子')&&$gender!=='M')return false;if(str_contains($required,'女子')&&$gender!=='F')return false;
    return $base($required)===$base($actual);
}
function race_event_categories(array $event):array {
    $allowed=[];$gender=$event['gender']??'X';$age=$event['age_group']??'';$name=$event['name']??'';$namedGender='X';$namedAge='';
    if(($event['kind']??'individual')!=='relay') {if(str_contains($name,'女子')&&!str_contains($name,'男子'))$namedGender='F';elseif(str_contains($name,'男子')&&!str_contains($name,'女子'))$namedGender='M';}
    $young=(bool)preg_match('/少年|青少年/u',$name);$adult=(bool)preg_match('/成年|成人/u',$name);if($young&&!$adult)$namedAge='少年';elseif($adult&&!$young)$namedAge='成年';
    foreach(race_categories() as $category=>$spec)if(($gender==='X'||$gender===$spec[0])&&($namedGender==='X'||$namedGender===$spec[0])&&race_group_matches($age,$spec[1],$spec[0])&&race_group_matches($namedAge,$spec[1],$spec[0]))$allowed[]=$category;
    return $allowed;
}
function race_registration_save(array $p):int {
    $category=race_text($p['category']??'',20);$spec=race_categories()[$category]??null;if(!$spec)throw new \RuntimeException('请选择成年男子、成年女子、少年男子或少年女子。');
    if(!isset($p['event_ids'])||!is_array($p['event_ids']))throw new \RuntimeException('请至少勾选一个参赛项目。');
    $selected=[];foreach($p['event_ids'] as $v){if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<1)throw new \RuntimeException('参赛项目格式错误。');$e=race_event((int)$v);if(!in_array($category,race_event_categories($e),true))throw new \RuntimeException($e['name'].'不适用于'.$category.'，请重新选择项目。');$selected[(int)$e['id']]=true;}if(!$selected)throw new \RuntimeException('请至少勾选一个参赛项目。');
    $id=(int)($p['id']??0);$old=$id?race_one('athletes',$id):null;$group=$spec[1];
    if($old && $old['age_group']!=='' && race_category($old)===$category)$group=$old['age_group'];
    if($old)$key=$old['bib'];else{$token=(string)($p['registration_token']??'');if(!preg_match('/^[a-f0-9-]{36}$/',$token))throw new \RuntimeException('报名页面已过期，请刷新重试。');$key='AUTO-'.str_replace('-','',$token);if(q('SELECT id FROM athletes WHERE bib=?',[$key])->fetch())throw new \RuntimeException('此报名已提交，请从报名名单点击编辑。');}
    $current=$old?q('SELECT event_id FROM race_enrollments WHERE athlete_id=?',[$id])->fetchAll(7):[];$existing=array_fill_keys(array_map('intval',$current),true);
    foreach($existing as $event=>$yes)if(!isset($selected[$event])){race_unstarted((int)$event);q('DELETE FROM race_members WHERE event_id=? AND athlete_id=?',[$event,$id]);q('DELETE FROM race_enrollments WHERE event_id=? AND athlete_id=?',[$event,$id]);}
    $id=race_athlete_save(['id'=>$id,'registration_id'=>$key,'name'=>$p['name']??'','team_id'=>$p['team_id']??0,'gender'=>$spec[0],'age_group'=>$group]);
    foreach($selected as $event=>$yes)if(!isset($existing[$event]))race_enroll((int)$event,$id);
    race_audit('registration',$id,['events'=>array_keys($selected),'category'=>$category]);return $id;
}
function race_import_csv(string $path,bool $update=false):int {
    $f=fopen($path,'r');if(!$f)throw new \RuntimeException('无法读取CSV。');$temp=null;
    try{$head=fgetcsv($f,0,',','"','');if(!$head)throw new \RuntimeException('CSV为空。');$head[0]=preg_replace('/^\xEF\xBB\xBF/','',$head[0]);
        if($head===['team','registration_id','name','gender','age_group','event','kind','relay_name'])return race_import_legacy_csv($path,$update);
        if($head!==['university','name','category','event','kind','relay_name'])throw new \RuntimeException('请使用新版6列报名模板。');
        $temp=tmpfile();if(!$temp)throw new \RuntimeException('无法建立导入临时文件。');fputcsv($temp,['team','registration_id','name','gender','age_group','event','kind','relay_name'],',','"','');$line=1;
        while(($r=fgetcsv($f,0,',','"',''))!==false){$line++;if($r===[null])continue;if($line>10001)throw new \RuntimeException('每批最多10000行。');if(count($r)!==6)throw new \RuntimeException('CSV第'.$line.'行列数不符。');foreach($r as $v)if(!mb_check_encoding($v,'UTF-8'))throw new \RuntimeException('请保存为UTF-8 CSV。');$d=array_map('trim',array_combine($head,$r));$spec=race_categories()[$d['category']]??null;if(!$spec)throw new \RuntimeException('CSV第'.$line.'行组别无效。');
            $matches=array_values(array_filter(q('SELECT a.* FROM athletes a JOIN teams t ON t.id=a.team_id WHERE t.name=? AND a.name=? AND a.gender=?',[$d['university'],$d['name'],$spec[0]])->fetchAll(),fn($a)=>race_category($a)===$d['category']));
            if(count($matches)>1)throw new \RuntimeException('CSV第'.$line.'行有同高校、同姓名、同组别的多人，请先在后台核对，未自动合并。');
            $key=$matches?$matches[0]['bib']:'U-'.substr(hash('sha256',wp_json_encode([mb_strtolower($d['university']),mb_strtolower($d['name']),$d['category']])),0,32);$group=$matches?$matches[0]['age_group']:$spec[1];
            fputcsv($temp,[$d['university'],$key,$d['name'],$spec[0],$group,$d['event'],$d['kind'],$d['relay_name']],',','"','');
        }fflush($temp);$meta=stream_get_meta_data($temp);return race_import_legacy_csv($meta['uri'],$update);
    }finally{fclose($f);if($temp)fclose($temp);}
}
function race_registration_export(string $type):void {
    $head=['university','name','category','event','kind','relay_name'];
    if($type==='template')downloadCsv('registration-template',$head,[['中山大学','Steven Lee','成年男子','男子50米','individual',''],['中山大学','Steven Lee','成年男子','8×50米混合接力','relay','']]);
    $rows=[];foreach(q('SELECT a.*,t.name AS university,e.name AS event_name,e.kind,COALESCE(r.name,?) AS relay_name FROM race_enrollments n JOIN athletes a ON a.id=n.athlete_id JOIN teams t ON t.id=a.team_id JOIN events e ON e.id=n.event_id LEFT JOIN race_members m ON m.athlete_id=a.id AND m.event_id=e.id LEFT JOIN race_relays r ON r.id=m.relay_id ORDER BY a.id,e.id',[''])->fetchAll() as $r)$rows[]=[$r['university'],$r['name'],race_category($r),$r['event_name'],$r['kind'],$r['relay_name']];downloadCsv('registrations',$head,$rows);
}
