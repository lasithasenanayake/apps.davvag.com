<?php
    require_once(PLUGIN_PATH . "/phpcache/cache.php");
    require_once(PLUGIN_PATH . "/auth/auth.php");
    $domain = Auth::GetDomainAttributes();
    
    if(isset($domain->name)){
        define("DOMAIN",$domain->domain);
        define("DOMAINNAME",$domain->name);

    }else{
        define("DOMAIN","NotReg-405");
        define("DOMAINNAME","This Domain is not registered please contact davvag.com for information");
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
  <meta name="description" content="">
  <meta name="author" content="">
  <link rel="icon" href="assets/davvag-cms/images/favicon.png" type="image/png">
  <title><?php echo DOMAINNAME; ?></title>
  <link href="assets/davvag-cms/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
  <link href="assets/davvag-cms/css/dockanimation.css" rel="stylesheet">
  <link href="assets/davvag-cms/css/custom.css" rel="stylesheet">
  <link href="assets/davvag-cms/css/style.loaders.css" rel="stylesheet">
  <link href="assets/davvag-cms/css/font-awesome.min.css" integrity="sha256-3dkvEK0WLHRJ7/Csr0BZjAWxERc5WH7bdeUya2aXxdU= sha512-+L4yy6FRcDGbXJ9mPG8MT/3UCDzwR9gPeyFNMCtInsol++5m3bk2bXWKdZjvybmohrAsn3Ua5x8gfLnbE1YkOg==" rel="stylesheet">
  
</head>

<body>
<div id="preloader">
    <div id="status"><i class="fa fa-spinner fa-spin"></i></div>
</div>

<div class="headerbar" webdock-component="headerbar_launcher" id="id-headerbar">
    </div>
<div id="idLeftPanel" class="leftpanel" webdock-component="left-menu" style="display:none">

    </div><!-- leftpanel -->
<div  webdock-component="soss-routes" class="id-soss-routes" style="padding-top: 0px;">
    </div><!-- contentpanel -->

<div  webdock-component="footer-bar" id="id-footer-bar">
    </div>
    


<script src="assets/davvag-cms/js/moments.js"></script>
<script src="assets/davvag-cms/js/masory.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js" integrity="sha384-UO2eT0CpHqdSJQ6hJty5KVphtPhzWj9WO1clHTMGa3JDZwrnQq4sF86dIHNDz0W1" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js" integrity="sha384-JjSmVgyd0p3pXB1rRibZUAYoIIy6OrQ6VrjIEaFf/nJGzIxFDsf4x0xIM+B07jRM" crossorigin="anonymous"></script>

<script src="lib/webdock.js" webdockapp="davvag-cms"></script>


<script type="text/javascript">
    
    
    
    WEBDOCK.onReady (function(){
        jQuery('#preloader').delay(350).fadeOut(function(){
            jQuery('body').delay(350).css({'overflow':'visible'});
        });

        jQuery('.menutoggle').click(function(){
        
        var body = jQuery('body');
        var bodypos = body.css('position');
        
        if(bodypos != 'relative') {
            
            if(!body.hasClass('leftpanel-collapsed')) {
                body.addClass('leftpanel-collapsed');
                jQuery('.nav-bracket ul').attr('style','');
                
                jQuery(this).addClass('menu-collapsed');
                
            } else {
                body.removeClass('leftpanel-collapsed chat-view');
                jQuery('.nav-bracket li.active ul').css({display: 'block'});
                
                jQuery(this).removeClass('menu-collapsed');
                
            }
        } else {
            
            if(body.hasClass('leftpanel-show'))
                body.removeClass('leftpanel-show');
            else
                body.addClass('leftpanel-show');
            
            adjustmainpanelheight();         
        }
    
        });
    });  
</script>

</body>
</html>
