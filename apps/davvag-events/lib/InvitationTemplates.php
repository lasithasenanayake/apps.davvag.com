<?php
namespace davvag_events;

/** App-owned, versioned presentation templates. No framework/schema changes. */
final class InvitationTemplates {
    public static function enrich($templates) {
        $names=['wedding'=>'Petal & promise','birthday'=>'A little celebration','business'=>'Good company','corporate'=>'Gather & inspire','ticketed'=>'The main event'];
        foreach($templates as &$t){
            $t['name']=$names[$t['occasion']];$t['custom']=false;$t['active']=true;
            $t['html']='<article class="card '.$t['occasion'].'"><div class="halo halo-a"></div><div class="halo halo-b"></div><div class="content"><p class="eyebrow">{{heading}}</p><div class="ornament">&#10022;</div><h1>{{title}}</h1><p class="hosts">{{hosts}}</p><div class="rule"></div><p class="when">{{start}}</p><h2>{{venue}}</h2><p>{{address}}</p><p class="note">{{message}}</p></div></article>';
            $t['css']='*{box-sizing:border-box}body{margin:0;background:var(--background);color:var(--accent);font-family:var(--font)}.card{min-height:650px;position:relative;overflow:hidden;padding:48px 28px;text-align:center;display:grid;place-items:center;background:var(--background)}.content{position:relative;z-index:1;max-width:550px;animation:arrive 1.1s ease both}.eyebrow{font:600 12px/1.8 Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase}.ornament{font-size:44px;margin:26px 0}h1{font:inherit;font-size:46px;line-height:1.12;margin:20px 0;overflow-wrap:anywhere}h2{font-size:21px;margin:14px 0}p{line-height:1.7;white-space:pre-line}.hosts{font-size:22px}.rule{width:48px;height:1px;margin:28px auto;background:currentColor}.when{font-weight:bold}.note{margin-top:28px;max-width:420px}.halo{position:absolute;width:280px;height:280px;border-radius:50%;border:1px solid currentColor;opacity:.13;animation:float 9s ease-in-out infinite alternate}.halo-a{left:-140px;top:-80px}.halo-b{right:-140px;bottom:-80px;animation-delay:-4s}.birthday .halo{border:22px dotted currentColor;opacity:.12}.business .halo{border-radius:24px;transform:rotate(30deg)}.corporate .halo{border-radius:0;border-width:20px;opacity:.07}.ticketed .content{border:1px dashed currentColor;border-radius:16px;padding:24px}.ticketed .ornament{animation:glow 2.5s ease-in-out infinite alternate}@keyframes arrive{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}@keyframes float{to{transform:translate(20px,25px) rotate(20deg)}}@keyframes glow{to{opacity:.35;transform:scale(1.15)}}';
        }unset($t);return $templates;
    }
    private static $testLibrary;
    public static function useTestLibrary($path){if(PHP_SAPI!=='cli'||!defined('DAVVAG_EVENTS_TESTING')||realpath(dirname($path))!==realpath(dirname(__DIR__).'/tests')||!preg_match('/^template-test-[a-f0-9]{32}\.json$/D',basename($path)))throw new \RuntimeException('Template test storage is restricted to isolated app tests.');self::$testLibrary=$path;}
    private static function path(){return self::$testLibrary??dirname(__DIR__).'/templates/library.json';}
    private static function read(){
        $path=self::path();if(!is_file($path))return [];
        $data=json_decode(file_get_contents($path),true);
        if(!is_array($data)||!isset($data['templates'])||!is_array($data['templates']))Support::fail('TEMPLATES','The invitation library is unavailable.',503);
        return $data['templates'];
    }
    public static function listing($admin=false){
        $latest=[];foreach(self::read() as $t){if(!isset($latest[$t['id']])||$latest[$t['id']]['version']<$t['version'])$latest[$t['id']]=$t;}
        $out=Domain::templates();foreach($latest as $t)if($admin||$t['active']){unset($t['createdBy'],$t['createdAt']);$out[]=$t;}return $out;
    }
    public static function find($id,$version){
        foreach(Domain::templates() as $t)if($t['id']===$id&&$t['version']===$version)return $t;
        $versions=self::read();$latest=null;foreach($versions as $t)if($t['id']===$id&&(!$latest||$t['version']>$latest['version']))$latest=$t;
        if($latest&&$latest['active'])foreach($versions as $t)if($t['id']===$id&&$t['version']===$version)return $t;
        Support::fail('TEMPLATE','Choose an available invitation template again.',409);
    }
    public static function validate($in){
        $html=Support::text($in['html']??'',24000,true);$css=Support::text($in['css']??'',16000);
        // Cards accept body markup and separate CSS. DOM validation is followed by a
        // no-script, no-network opaque-origin iframe at every rendering location.
        if(preg_match('/<\s*[!?]/',$html))Support::fail('TEMPLATE_HTML','Use card body elements without document declarations or comments.');
        $previous=libxml_use_internal_errors(true);$doc=new \DOMDocument('1.0','UTF-8');
        $ok=$doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="template-root">'.$html.'</div></body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($previous);
        if(!$ok)Support::fail('TEMPLATE_HTML','The card HTML could not be read.');
        $allowed=['html','body','div','section','article','header','footer','main','span','p','h1','h2','h3','h4','h5','h6','strong','em','b','i','u','small','br','hr','ul','ol','li','dl','dt','dd','blockquote','figure','figcaption','table','thead','tbody','tr','th','td','time'];
        foreach($doc->getElementsByTagName('*') as $node){if(!in_array(strtolower($node->nodeName),$allowed,true))Support::fail('TEMPLATE_HTML','Use text and layout elements only. Scripts, embeds, forms and links are not supported in invitation cards.');foreach($node->attributes as $attr)if(!in_array(strtolower($attr->name),['class','id','title','aria-label','aria-hidden','role','colspan','rowspan'],true))Support::fail('TEMPLATE_HTML','Move styling into the CSS field. Event handlers and interactive attributes are not allowed.');}
        if(preg_match('~[<\\\\]|/\*|@import\b|url\s*\(|expression\s*\(|javascript\s*:|behavior\s*:|-moz-binding~i',$css))Support::fail('TEMPLATE_CSS','Use self-contained CSS without imports, external URLs or executable styles. CSS keyframe animations are supported.');
        $tokens=['title','hosts','heading','message','venue','address','start','end','rsvpDeadline','timezone'];
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/',$html,$matches);foreach($matches[1] as $token)if(!in_array($token,$tokens,true))Support::fail('TEMPLATE_TOKEN','Unknown placeholder: '.$token);
        $design=$in['design']??[];foreach(['accent'=>'#264b42','background'=>'#f4f9f6'] as $key=>$default){$value=$design[$key]??$default;if(!is_string($value)||!preg_match('/^#[A-Fa-f0-9]{6}$/D',$value))Support::fail('DESIGN','Choose a valid template colour.');$design[$key]=$value;}
        $design=['accent'=>$design['accent'],'background'=>$design['background'],'font'=>Support::choice($design['font']??'serif',['serif','sans','rounded']),'cover'=>'','sections'=>['message','details','agenda','rsvp','seating','greetings','media','tickets']];
        return ['name'=>Support::text($in['name']??'',100,true),'occasion'=>Support::choice($in['occasion']??'all',array_merge(['all'],Domain::OCCASIONS)),'heading'=>Support::text($in['heading']??'You are invited',240),'message'=>Support::text($in['message']??'',4000),'html'=>$html,'css'=>$css,'design'=>$design,'custom'=>true,'active'=>!empty($in['active'])];
    }
    public static function save($actor,$in){
        if(empty($actor['admin']))Support::fail('FORBIDDEN','Only tenant administrators can create invitation templates.',403);
        $next=self::validate($in);$id=$in['id']??null;if($id!==null&&(!is_string($id)||!preg_match('/^custom-[a-f0-9]{32}$/D',$id)))Support::fail('TEMPLATE','Invalid custom template ID.');
        $path=self::path();$lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))Support::fail('TEMPLATES','Could not lock the invitation library.',503);
        try{$all=self::read();$version=0;$ids=[];foreach($all as $t){$ids[$t['id']]=true;if($t['id']===$id)$version=max($version,$t['version']);}if($id!==null&&$version===0)Support::fail('TEMPLATE','Template not found.',404);if(isset($in['version'])&&(int)$in['version']!==$version)Support::fail('CONFLICT','This template changed. Reload it before saving.',409);if($id===null&&count($ids)>=40)Support::fail('TEMPLATES','The library supports up to 40 custom templates.',409);
            $next['id']=$id??'custom-'.Support::id();$next['version']=$version+1;$next['createdBy']=$actor['id'];$next['createdAt']=Support::now();$all[]=$next;$encoded=json_encode(['templates'=>$all],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(strlen($encoded)>2000000)Support::fail('TEMPLATES','The template library has reached its size limit.',409);
            $temp=$path.'.'.Support::id().'.tmp';if(file_put_contents($temp,$encoded,LOCK_EX)===false)Support::fail('TEMPLATES','The template library is not writable.',503);if(!rename($temp,$path)){@unlink($temp);Support::fail('TEMPLATES','Unable to commit this template version.',503);}unset($next['createdBy'],$next['createdAt']);return $next;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function snapshot($template){return array_intersect_key($template,array_flip(['id','version','name','html','css']));}
}
