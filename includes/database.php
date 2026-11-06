<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;

class DatabaseException extends \RuntimeException {}
class Statement {
    private array $rows; private int $cursor=0;
    public function __construct(array $rows) { $this->rows=$rows; }
    public function fetch() { return $this->rows[$this->cursor++]??false; }
    public function fetchColumn(int $index=0) { $r=$this->fetch();return $r===false?false:(array_values($r)[$index]??false); }
    public function fetchAll(int $mode=0): array {
        $rows=array_slice($this->rows,$this->cursor);$this->cursor=count($this->rows);
        if($mode===3)return array_map('array_values',$rows);
        if($mode===7)return array_map(fn($r)=>array_values($r)[0],$rows);
        return $rows;
    }
}
function q(string $sql,array $args=[]): Statement {
    global $wpdb;
    // Only internal SQL reaches this adapter; all user values use placeholders.
    $sql=preg_replace_callback('/\b(settings|teams|athletes|events|results|relay_members|photos|checkins|race_enrollments|race_relays|race_members|race_rounds|race_entries|race_audit|disclaimer_acceptances)\b/',fn($m)=>'`'.$wpdb->prefix.'sm_'.$m[1].'`',$sql);
    if(count($args)!==substr_count($sql,'?'))throw new DatabaseException('参数数量错误');
    if($args) {
        $parts=explode('?',$sql);$sql=array_shift($parts);$values=[];
        foreach($args as $i=>$v){$sql.=($v===null?'NULL':'%s').$parts[$i];if($v!==null)$values[]=$v;}
        if($values)$sql=$wpdb->prepare($sql,$values);
    }
    $old=$wpdb->suppress_errors(true);
    $ok=$wpdb->query($sql);$error=$wpdb->last_error;$rows=$wpdb->last_result;
    $wpdb->suppress_errors($old);
    if($ok===false){error_log('Sports Meet database: '.$error);throw new DatabaseException('数据库操作失败');}
    return new Statement(array_map(fn($r)=>(array)$r,$rows?:[]));
}
