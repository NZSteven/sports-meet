<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function mutate(array $p): void {
 $action=$p['sm_action']??'';
 switch($action) {
        case 'team':
            $name=trim($p['name']??'');if($name==='' || mb_strlen($name)>80) throw new \RuntimeException('队伍名称1至80字');
            if((int)($p['id']??0)>0) q('UPDATE teams SET name=? WHERE id=?',[$name,(int)$p['id']]); else q('INSERT INTO teams(name) VALUES(?)',[$name]);break;
        case 'athlete':
            $bib=trim($p['bib']??'');$name=trim($p['name']??'');$gender=$p['gender']??'';$group=trim($p['age_group']??'');$team=(int)($p['team_id']??0);$id=(int)($p['id']??0);
            if($bib==='' || mb_strlen($bib)>40 || $name==='' || mb_strlen($name)>80 || mb_strlen($group)>80 || !in_array($gender,['M','F'],true)) throw new \RuntimeException('请检查号码、姓名、性别和组别');
            if(!q('SELECT id FROM teams WHERE id=?',[$team])->fetch()) throw new \RuntimeException('队伍不存在');
            if($id>0) {
                $old=q('SELECT * FROM athletes WHERE id=?',[$id])->fetch();if(!$old) throw new \RuntimeException('运动员不存在');
                $has=q('SELECT id FROM results WHERE athlete_id=? UNION SELECT result_id FROM relay_members WHERE athlete_id=?',[$id,$id])->fetch();
                if($has && ($old['gender']!==$gender || $old['age_group']!==$group || (int)$old['team_id']!==$team)) throw new \RuntimeException('已有成绩或接力名单，请先删除相关成绩，再改性别、组别或队伍');
                q('UPDATE athletes SET bib=?,name=?,gender=?,age_group=?,team_id=? WHERE id=?',[$bib,$name,$gender,$group,$team,$id]);
            } else q('INSERT INTO athletes(bib,name,gender,age_group,team_id) VALUES(?,?,?,?,?)',[$bib,$name,$gender,$group,$team]);break;
        case 'event':
            $name=trim($p['name']??'');$kind=$p['kind']??'';$gender=$p['gender']??'X';$group=trim($p['age_group']??'');$direction=$p['direction']??'';$unit=trim($p['unit']??'');
            if($name==='' || mb_strlen($name)>120 || !in_array($kind,['individual','relay'],true) || !in_array($gender,['M','F','X'],true) || !in_array($direction,['asc','desc'],true) || mb_strlen($group)>80 || $unit==='' || mb_strlen($unit)>20) throw new \RuntimeException('项目资料无效');
            if($kind==='relay') {$gender='X';$direction='asc';$unit='秒';}
            q('INSERT INTO events(name,kind,gender,age_group,direction,unit) VALUES(?,?,?,?,?,?)',[$name,$kind,$gender,$group,$direction,$unit]);break;
        case 'result':saveResult($p);break;
        case 'delete':
            $table=$p['table']??'';$id=(int)($p['id']??0); if(!in_array($table,['teams','athletes','events','results'],true)) throw new \RuntimeException('操作无效');
            if(($p['confirm']??'')!=='DELETE') throw new \RuntimeException('删除需要输入 DELETE 确认');
            q("DELETE FROM $table WHERE id=?",[$id]);break;
        case 'settings':
            $title=trim($p['title']??'');$points=trim($p['points']??'');$mult=(int)($p['relay_multiplier']??0);$male=(int)($p['relay_males']??-1);
            if($title==='' || mb_strlen($title)>120 || !preg_match('/^\d+(?:\.\d{1,2})?(?:,\d+(?:\.\d{1,2})?){0,31}$/',$points) || $mult<1 || $mult>10 || $male<1 || $male>7) throw new \RuntimeException('积分用英文逗号分隔非负数字，最多32名；接力倍数1至10；男性1至7名');
            $ps=array_map('floatval',explode(',',$points));for($i=1;$i<count($ps);$i++) if($ps[$i]>$ps[$i-1]) throw new \RuntimeException('积分必须按名次递减或相同');
            $oldMale=(int)q('SELECT relay_males FROM settings WHERE id=1')->fetchColumn(); if($male!==$oldMale && q("SELECT r.id FROM results r JOIN events e ON e.id=r.event_id WHERE e.kind='relay' LIMIT 1")->fetch()) throw new \RuntimeException('已有接力成绩，须先删除后才能更改男女比例');
            q('UPDATE settings SET title=?,points=?,relay_multiplier=?,relay_males=? WHERE id=1',[$title,$points,$mult,$male]);break;
        case 'import':
            $type=$p['type']??'';if(!in_array($type,['athletes','results'],true)) throw new \RuntimeException('请选择导入类型');
            if(!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK || $_FILES['csv']['size']>2097152) throw new \RuntimeException('请上传不超过2MB的CSV文件');
            $f=fopen($_FILES['csv']['tmp_name'],'r');$head=fgetcsv($f,0,',','"','');if(!$head) throw new \RuntimeException('CSV为空');$head[0]=preg_replace('/^\xEF\xBB\xBF/','',$head[0]);
            $expected=$type==='athletes'?['bib','name','gender','age_group','team']:['event','bib','team','entry_name','performance','status','members'];
            if($head!==$expected) throw new \RuntimeException('CSV表头与模板不符');
            $line=1;while(($r=fgetcsv($f,0,',','"',''))!==false) { $line++;if($line>10001) throw new \RuntimeException('每批最多10000条');if($r===[null]) continue;if(count($r)!==count($head)) throw new \RuntimeException('第'.$line.'行列数不符');
                $d=array_combine($head,$r);foreach($d as $v) if(!mb_check_encoding($v,'UTF-8')) throw new \RuntimeException('请保存为UTF-8 CSV');
                try {
                    if($type==='athletes') {
                        if(trim($d['bib'])==='' || mb_strlen($d['bib'])>40 || trim($d['name'])==='' || mb_strlen($d['name'])>80 || mb_strlen($d['age_group'])>80 || trim($d['team'])==='' || mb_strlen($d['team'])>80 || !in_array($d['gender'],['M','F'],true)) throw new \RuntimeException('运动员资料无效');
                        if(q('SELECT id FROM athletes WHERE bib=?',[$d['bib']])->fetch()) throw new \RuntimeException('号码重复；请在管理页面编辑已有运动员');
                        q('INSERT INTO teams(name) VALUES(?) ON DUPLICATE KEY UPDATE name=VALUES(name)',[$d['team']]);$t=q('SELECT id FROM teams WHERE name=?',[$d['team']])->fetchColumn();
                        q('INSERT INTO athletes(bib,name,gender,age_group,team_id) VALUES(?,?,?,?,?)',[$d['bib'],$d['name'],$d['gender'],$d['age_group'],$t]);
                    } else {
                        $e=q('SELECT * FROM events WHERE name=?',[$d['event']])->fetch();if(!$e) throw new \RuntimeException('项目名称不存在');
                        $a=q('SELECT id,team_id FROM athletes WHERE bib=?',[$d['bib']])->fetch();$t=q('SELECT id FROM teams WHERE name=?',[$d['team']])->fetchColumn();$ids=[];
                        if($e['kind']==='relay') foreach(explode('|',$d['members']) as $bib) { $id=q('SELECT id FROM athletes WHERE bib=?',[$bib])->fetchColumn();if(!$id) throw new \RuntimeException('接力号码不存在：'.$bib);$ids[]=$id; }
                        if($e['kind']==='individual' && (!$a || (int)$t!==(int)$a['team_id'])) throw new \RuntimeException('号码或队伍名称不符');
                        saveResult(['event_id'=>$e['id'],'athlete_id'=>$a['id']??0,'team_id'=>$t,'entry_name'=>$d['entry_name'],'performance'=>$d['performance'],'status'=>$d['status'],'members'=>implode(',',$ids)]);
                    }
                } catch(\Throwable $e) { throw new \RuntimeException('第'.$line.'行：'.($e instanceof DatabaseException?'数据重复或超出字段长度':$e->getMessage())); }
            }fclose($f);break;

 default: throw new \RuntimeException('未知操作');
 }
}
