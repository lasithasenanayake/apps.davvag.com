<?php
ob_start();
define('DAVVAG_EVENTS_TESTING',true);
require dirname(__DIR__).'/lib/bootstrap.php';
use davvag_events\{Support,Domain,InvitationTemplates,Fault};
$checks=0;
function expectTemplate($ok,$label){global $checks;if(!$ok)throw new RuntimeException('FAILED: '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function rejectTemplate($code,$callback,$label){try{$callback();}catch(Fault $e){expectTemplate($e->reason===$code,$label);return;}throw new RuntimeException('Expected '.$code.': '.$label);}
$path=__DIR__.'/template-test-'.Support::id().'.json';InvitationTemplates::useTestLibrary($path);
try{
    expectTemplate(Support::localInstant('2027-06-12T17:30','Asia/Colombo')==='2027-06-12T12:00:00Z','local date picker converts a half-hour timezone correctly');
    expectTemplate(Support::localInstant('2027-06-12T17:30','Europe/London')==='2027-06-12T16:30:00Z','date picker handles daylight saving automatically');
    expectTemplate(Support::localInstant('', 'UTC', true)===null,'optional reply deadline can be cleared');
    rejectTemplate('DATE',function(){Support::localInstant('2027-02-30T12:00','UTC');},'invalid calendar date rejected');
    rejectTemplate('DATE',function(){Support::localInstant('2027-03-28T01:30','Europe/London');},'nonexistent daylight saving time rejected');
    $e=Domain::create(['occasion'=>'wedding','title'=>'Keep this event','venue'=>'Garden House','timezone'=>'Asia/Colombo','localStart'=>'2027-06-12T17:30','localEnd'=>'2027-06-12T21:30','localRsvpDeadline'=>'2027-06-01T20:00'],'owner');
    expectTemplate($e['draft']['start']==='2027-06-12T12:00:00Z'&&$e['draft']['end']==='2027-06-12T16:00:00Z','event accepts picker values without offset syntax');
    $original=$e['draft'];$e['draft']['start']='2027-10-31T01:30:45Z';$e['draft']['end']='2027-10-31T04:30:00Z';$e['draft']['timezone']='Europe/London';Domain::edit($e,['localStart'=>'2027-10-31T01:30']);expectTemplate($e['draft']['start']==='2027-10-31T01:30:45Z','unchanged repeated-hour input preserves exact stored instant');$e['draft']=$original;
    $input=['name'=>'Animated garden','occasion'=>'wedding','active'=>true,'html'=>'<section class="invite"><h1>{{title}}</h1><p>{{venue}}</p><p>{{start}}</p></section>','css'=>'.invite {color:var(--accent);animation:fade 1s ease} .invite > p {font-size:18px}@keyframes fade {from{opacity:0}to{opacity:1}}','design'=>['accent'=>'#224433','background'=>'#f4faf5','font'=>'serif']];
    $validated=InvitationTemplates::validate($input);expectTemplate(str_contains($validated['css'],'@keyframes'),'HTML/CSS animated card accepted');
    rejectTemplate('FORBIDDEN',function()use($input){InvitationTemplates::save(['id'=>'host','admin'=>false],$input);},'non-admin cannot create templates');
    foreach(['<script>alert(1)</script>','<iframe src="https://example.com"></iframe>','<div onclick="alert(1)">Hi</div>','<meta http-equiv="refresh" content="0;url=https://example.com">','<svg onload="alert(1)"></svg>','<form><input name="x"></form>'] as $bad)rejectTemplate('TEMPLATE_HTML',function()use($input,$bad){$x=$input;$x['html']=$bad;InvitationTemplates::validate($x);},'active or interactive template HTML rejected');
    foreach(['@import "https://example.com/x.css";','.x{background:url(https://example.com/x)}','</style><script>alert(1)</script>'] as $bad)rejectTemplate('TEMPLATE_CSS',function()use($input,$bad){$x=$input;$x['css']=$bad;InvitationTemplates::validate($x);},'external/executable template CSS rejected');
    rejectTemplate('TEMPLATE_TOKEN',function()use($input){$x=$input;$x['html']='<p>{{secret}}</p>';InvitationTemplates::validate($x);},'unknown placeholders rejected');
    $t=InvitationTemplates::save(['id'=>'admin','admin'=>true],$input);expectTemplate($t['version']===1&&count(InvitationTemplates::listing(false))===6,'saved admin template appears in host library');
    Domain::edit($e,['templateId'=>$t['id'],'templateVersion'=>1,'design'=>$t['design']]);expectTemplate($e['draft']['title']===$original['title']&&$e['draft']['venue']===$original['venue']&&$e['draft']['start']===$original['start'],'template selection preserves event information');
    Domain::transition($e,'published');$snapshot=$e['published']['templateSnapshot'];$edit=$input;$edit['id']=$t['id'];$edit['version']=1;$edit['html']='<section><h1>Version two: {{title}}</h1></section>';$next=InvitationTemplates::save(['id'=>'admin','admin'=>true],$edit);expectTemplate($next['version']===2&&$e['published']['templateSnapshot']===$snapshot,'new template version cannot change published invitation');
    rejectTemplate('CONFLICT',function()use($edit){InvitationTemplates::save(['id'=>'admin','admin'=>true],$edit);},'stale template edit rejected');
    $edit['version']=2;$edit['active']=false;InvitationTemplates::save(['id'=>'admin','admin'=>true],$edit);expectTemplate(count(InvitationTemplates::listing(false))===5,'hidden template is removed from host choices');rejectTemplate('TEMPLATE',function()use($t){InvitationTemplates::find($t['id'],1);},'hidden templates cannot be newly selected by older version');
    Domain::edit($e,['templateId'=>$t['id'],'templateVersion'=>1,'localRsvpDeadline'=>'']);expectTemplate($e['draft']['templateSnapshot']===$snapshot&&$e['draft']['rsvpDeadline']===null,'existing saved template survives hiding while deadline is cleared');
    echo $checks.' editor/date/template checks passed.'.PHP_EOL;
}finally{if(is_file($path))unlink($path);if(is_file($path.'.lock'))unlink($path.'.lock');}
