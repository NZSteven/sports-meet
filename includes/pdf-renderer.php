<?php
namespace SportsMeetWP;
defined('ABSPATH') || exit;
require_once __DIR__.'/../vendor/tfpdf/font/unifont/ttfonts.php';
require_once __DIR__.'/../vendor/tfpdf/tfpdf.php';
class MeetEventPdf extends \SportsMeetWP\PdfVendor\tFPDF {
    private string $eventTitle='';private string $sectionTitle='';private array $columns=[];
    public function __construct(string $title){
        parent::__construct('L','mm','A4');
        $cache=trailingslashit(get_temp_dir()).'sports-meet-pdf-'.substr(hash('sha256',ABSPATH),0,12).'/';
        if(!wp_mkdir_p($cache.'unifont'))throw new \RuntimeException('无法建立PDF字体缓存目录。');
        $font=$cache.'SportsMeetSans.ttf';
        if(!is_file($font)){
            $bytes=gzdecode(file_get_contents(__DIR__.'/../media/pdf-fonts/SportsMeetSans.ttf.gz'));
            if($bytes===false)throw new \RuntimeException('PDF中文字体无法读取。');
            $temporary=tempnam($cache,'font-');if($temporary===false||file_put_contents($temporary,$bytes)===false)throw new \RuntimeException('PDF字体缓存无法写入。');
            if(!rename($temporary,$font)){@unlink($temporary);if(!is_file($font))throw new \RuntimeException('PDF字体缓存保存失败。');}
        }
        if(!defined('SPORTS_MEET_PDF_TTFONTS'))define('SPORTS_MEET_PDF_TTFONTS',$cache);
        // Keep library-generated font metrics in a per-request private folder to avoid concurrent partial reads.
        $request=$cache.'metrics-'.bin2hex(random_bytes(8)).'/';wp_mkdir_p($request.'unifont');$this->fontpath=$request;
        $this->SetMargins(10,10,10);$this->SetAutoPageBreak(false);$this->AliasNbPages();
        $this->AddFont('Meet','','SportsMeetSans.ttf',true);$this->SetFont('Meet','',10);
        $this->eventTitle=$title;$this->SetTitle($title,true);$this->SetAuthor('Run & Fun Sports Day');
        register_shutdown_function(static function()use($request){foreach(glob($request.'unifont/*')?:[] as $file)if(is_file($file))@unlink($file);@rmdir($request.'unifont');@rmdir($request);});
    }
    public function Header(){
        $this->SetFont('Meet','',15);$this->SetTextColor(22,66,53);$this->Cell(277,9,'第五届新西兰中国高校校友奔Fun运动会',0,1,'C');
        $this->SetFont('Meet','',12);$this->MultiCell(277,7,$this->eventTitle.' - '.$this->sectionTitle,0,'C');
        $this->SetFont('Meet','',8);$this->SetTextColor(95);$this->Cell(277,6,'导出时间：'.current_time('Y-m-d H:i').'（网站时间）   |   仅供裁判现场工作使用',0,1,'R');$this->SetTextColor(25);$this->Ln(2);
    }
    public function Footer(){
        $this->SetY(-17);$this->SetFont('Meet','',9);$this->SetTextColor(60);$this->Cell(210,7,'裁判签名：________________    复核签名：________________',0,0);$this->Cell(67,7,'第 '.$this->PageNo().' / {nb} 页',0,0,'R');
    }
    public function section(string $title,array $headers,array $widths):void {$this->sectionTitle=$title;$this->columns=[$headers,$widths];$this->AddPage();$this->tableHeader();}
    private function tableHeader():void {
        $this->SetFont('Meet','',9);$this->SetFillColor(225,235,227);$this->SetDrawColor(160,178,168);
        foreach($this->columns[0] as $i=>$label)$this->Cell($this->columns[1][$i],9,$label,1,0,'C',true);$this->Ln();
    }
    private function wrap(string $text,float $width):array {
        $text=str_replace(["\r\n","\r"],"\n",$text);$result=[];
        foreach(explode("\n",$text) as $line){$chunk='';foreach(preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){if($chunk!==''&&$this->GetStringWidth($chunk.$char)>$width){$result[]=$chunk;$chunk='';}$chunk.=$char;}$result[]=$chunk;}
        return $result?:[''];
    }
    public function row(array $cells,bool $shade=false):void {
        $this->SetFont('Meet','',9);$lines=[];$count=1;
        foreach($cells as $i=>$cell){$lines[$i]=$this->wrap((string)$cell,$this->columns[1][$i]-4);$count=max($count,count($lines[$i]));}
        $height=max(8,$count*5+3);
        if($this->GetY()+$height>187){$this->AddPage();$this->tableHeader();}
        $y=$this->GetY();$x=10;$this->SetDrawColor(170,184,177);$this->SetFillColor($shade?244:255,$shade?248:255,$shade?245:255);
        foreach($cells as $i=>$cell){$width=$this->columns[1][$i];$this->Rect($x,$y,$width,$height,'DF');foreach($lines[$i] as $j=>$text){$this->SetXY($x+2,$y+2+$j*5);$this->Cell($width-4,5,$text,0,0,$i===2||$i===3?'L':'C');}$x+=$width;}
        $this->SetXY(10,$y+$height);
    }
}
