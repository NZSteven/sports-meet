<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
function race_is_distance(array $event):bool {return ($event['kind']??'individual')==='individual'&&(bool)preg_match('/铅球|鉛球|跳远|跳遠/u',$event['name']??'');}
function install_distances():void {
    foreach([
        'race_entries'=>['lane'=>'INT NULL','distance'=>'DECIMAL(12,4) NULL','no_mark'=>'TINYINT NOT NULL DEFAULT 0','tie_order'=>'INT NULL','elapsed'=>'DECIMAL(9,3) NULL'],
        'race_rounds'=>['eight_lanes'=>'TINYINT NOT NULL DEFAULT 0'],
        'race_relays'=>['school_id'=>'INT NULL'],
    ] as $table=>$columns){
        foreach($columns as $column=>$definition)if(!q("SHOW COLUMNS FROM $table LIKE ?",[$column])->fetch()){
            try{q("ALTER TABLE $table ADD COLUMN $column $definition");}
            catch(DatabaseException $e){if(!q("SHOW COLUMNS FROM $table LIKE ?",[$column])->fetch())throw $e;}
        }
    }
}
function race_distance_units(string $value):int {
    if(!preg_match('/^\d{1,5}(?:\.\d{1,4})?$/',$value))throw new \RuntimeException('距离须为大于0的米数，最多4位小数。');$parts=explode('.',$value);$units=(int)$parts[0]*10000+(int)str_pad($parts[1]??'',4,'0');if($units<1)throw new \RuntimeException('距离必须大于0；无有效成绩请勾选对应选项。');return $units;
}
function race_distance_text(?string $value):string {return $value===null?'—':(str_contains($value,'.')?rtrim(rtrim($value,'0'),'.'):$value).' 米';}
function race_rank_distances(array $rows,array $submitted,bool $finalize):array {
    $clean=[];$valid=[];
    foreach($rows as $row){$id=$row['id'];$d=$submitted[$id]??null;if(!is_array($d))throw new \RuntimeException('参赛名单已变化，请刷新。');$raw=$d['distance']??'';$tie=$d['tie_order']??'';if(!is_scalar($raw)||!is_scalar($tie))throw new \RuntimeException('距离或同距顺序格式错误。');$raw=trim((string)$raw);$tie=trim((string)$tie);$yes=!empty($d['confirmed']);$zero=(bool)preg_match('/^0{1,5}(?:\.0{1,4})?$/',$raw);$noMark=!empty($d['no_mark'])||$zero;$units=$raw===''||$zero?null:race_distance_units($raw);
        if($noMark&&$units!==null)throw new \RuntimeException('无有效成绩不能同时填写有效距离。');if($finalize&&$yes&&!$noMark&&$units===null)throw new \RuntimeException('已确认参赛者须填写距离；无有效距离请填0。');if($tie!==''&&(!ctype_digit($tie)||(int)$tie<1||(int)$tie>200))throw new \RuntimeException('同距顺序须为1至200的整数。');
        $distance=$units===null?null:intdiv($units,10000).'.'.str_pad((string)($units%10000),4,'0',STR_PAD_LEFT);
        $clean[$id]=['confirmed'=>$yes?1:0,'place'=>'','distance'=>$distance,'no_mark'=>$noMark?1:0,'tie_order'=>$tie===''?null:(int)$tie];if($yes&&$units!==null&&!$noMark)$valid[]=['id'=>$id,'units'=>$units];
    }
    usort($valid,fn($a,$b)=>($b['units']<=>$a['units'])?:($a['id']<=>$b['id']));
    for($i=0;$i<count($valid);){$j=$i+1;while($j<count($valid)&&$valid[$j]['units']===$valid[$i]['units'])$j++;$size=$j-$i;$group=array_slice($valid,$i,$size);
        if($size>1&&$i<3){$orders=[];foreach($group as $r)$orders[]=$clean[$r['id']]['tie_order'];$sorted=$orders;sort($sorted);$resolved=$sorted===range(1,$size);
            if(!$resolved){if($finalize)throw new \RuntimeException('有同距离影响前三名，请裁判给同距选手分别填写1至'.$size.'的同距顺序。');$i=$j;continue;}
            usort($group,fn($a,$b)=>$clean[$a['id']]['tie_order']<=>$clean[$b['id']]['tie_order']);
        }else foreach($group as $r)$clean[$r['id']]['tie_order']=null;
        foreach($group as $offset=>$r)if($i+$offset<3)$clean[$r['id']]['place']=(string)($i+$offset+1);$i=$j;
    }return $clean;
}
function race_distance_cells(array $row,array $participant,bool $editable):void {
    $id=(int)$row['id'];$label=$participant['name'];echo '<td>';
    if($editable)echo '<input type="number" name="entries['.$id.'][distance]" value="'.esc_attr(!empty($row['no_mark'])?'0':($row['distance']??'')).'" min="0" max="99999.9999" step="0.0001" inputmode="decimal" data-distance aria-label="'.esc_attr($label.'距离（米）').'">';else echo esc_html(!empty($row['no_mark'])?'无有效距离':race_distance_text($row['distance']??null));echo '</td><td>';
    if($editable)echo '<input type="number" name="entries['.$id.'][tie_order]" value="'.esc_attr($row['tie_order']??'').'" min="1" max="200" data-tie-order aria-label="'.esc_attr($label.'同距顺序').'">';else echo esc_html($row['tie_order']??'—');echo '</td><td><output data-auto-place>'.esc_html($row['place']??'—').'</output></td>';
}
function race_completed_order(array $rows):array {
    $rank=fn($r)=>!empty($r['confirmed'])&&in_array((int)($r['place']??0),[1,2,3],true)?(int)$r['place']:4;
    usort($rows,fn($a,$b)=>($rank($a)<=>$rank($b))?:((int)$a['id']<=>(int)$b['id']));return $rows;
}
