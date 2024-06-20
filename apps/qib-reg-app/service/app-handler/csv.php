<?php
function filter($text){
   return str_replace(",","¸",isset($text)?$text:"");
}
$str_header="";
foreach ($data[0] as $property => $value) {
    $str_header.= $property.",";
}

$head=false;
$body="";
foreach($data as $key=>$value){

    foreach ($value as $property => $x) {
        if(!$head)
            $str_header.= $property.",";
        $body.="'".filter($x)."',";
        
    }
    if(!$head){
        echo rtrim($str_header,",")."\n";
        $head=true;
    }
    echo rtrim($body,",")."\n";
    $body="";
}

?>