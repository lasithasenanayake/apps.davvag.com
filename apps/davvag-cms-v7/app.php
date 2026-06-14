<?php
if ($_SERVER['REQUEST_METHOD'] == "GET"){
    if(isset($_GET["q"])){
        $redirectUrl = sprintf(
            "%s://%s%s",
            isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
            $_SERVER['SERVER_NAME'],
            $_SERVER['REQUEST_URI']
        );
        header("Location: ".explode("?",$redirectUrl)[0].$_GET["q"]);
    }else{
        require_once (dirname(__FILE__) . "/pages/index.php");
    }
}
?>
