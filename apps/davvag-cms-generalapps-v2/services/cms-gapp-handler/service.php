<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once(PLUGIN_PATH_LOCAL . "/davvag-summary/summary.php");
require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
class ArticalService{
    public function postDeleteButton($req,$res){
        $item=$req->Body(true);
        $result=SOSSData::Delete("d_cms_buttons_v1", $item);
        CacheData::clearObjects("d_cms_buttons_v1");
        return $result;
    }

    public function postSaveArtical($req,$res){
        
        $Artical=$req->Body(true);
        $user= Auth::Autendicate("profile","postInvoiceSave",$res);
        $imgname=isset($Artical->imgname)? $Artical->imgname : '';
        $date=$Artical->createdate?$Artical->createdate:null;

        if(!isset($Artical->id)){
            $result=SOSSData::Insert ("d_cms_artical_v1", $Artical,$tenantId = null);
            //return $result;
            //var_dump($result);
            if($result->success){
                $Artical->id = $result->result->generatedId;
                
                $summery =Summary::GetObject("davvag-cms-generalapps",$Artical->id,
                "/components/davvag-cms/soss-uploader/service/get/d_cms_artical/".$Artical->id."-".$imgname,
                "/#/app/davvag-cms-generalapps/a?id=".$Artical->id
                ,$Artical->title,$Artical->summery,$Artical->tags,$date);
                Summary::Save($summery);
                
                //return $Artical;
            }else{
                $res->SetError ("Error Saving.");
                //exit();
                return $res;
            }
        }else{
            $result=SOSSData::Update ("d_cms_artical_v1", $Artical,$tenantId = null);
            $summery =Summary::GetObject("davvag-cms-generalapps",$Artical->id,
                "/components/davvag-cms/soss-uploader/service/get/d_cms_artical/".$Artical->id."-".$imgname,
                "/#/app/davvag-cms-generalapps/a?id=".$Artical->id
                ,$Artical->title,$Artical->summery,$Artical->tags,$date);
                Summary::Save($summery);
        }
        CacheData::clearObjects("d_cms_artical_v1");
        CacheData::clearObjects("d_cms_artical_v1_pod_bycat_paging");
        CacheData::clearObjects("d_cms_artical_v1_pod_paging");
        if(count($Artical->RemovedImages)>0){
            $Artical->removedStatus=SOSSData::Delete("d_cms_artical_imagev1",$Artical->RemovedImages);
        }
        foreach($Artical->Images as $key=>$value){
            $Artical->Images[$key]->articalid=$Artical->id;
            if($Artical->Images[$key]->id==0){
                $result2=SOSSData::Insert ("d_cms_artical_imagev1", $Artical->Images[$key],$tenantId = null);
                if($result2->success){
                    $Artical->Images[$key]->id = $result2->result->generatedId;
                }

            }else{
                $result2=SOSSData::Update ("d_cms_artical_imagev1", $Artical->Images[$key],$tenantId = null);
            }
            
            //var_dump($invoice->InvoiceItems[$key]->invoiceNo);
        }
        CacheData::clearObjects("d_cms_artical_imagev1");
        return $Artical;
        
    }

    public function postSaveAlbum($req,$res){
        
        $Artical=$req->Body(true);
        $user= Auth::Autendicate("profile","postInvoiceSave",$res);
        
        if(!isset($Artical->id)){
            $result=SOSSData::Insert ("d_cms_album_v1", $Artical,$tenantId = null);
            //return $result;
            //var_dump($result);
            if($result->success){
                $Artical->id = $result->result->generatedId; 
                //return $Artical;
            }else{
                $res->SetError ("Error Saving.");
                //exit();
                return $res;
            }
        }else{
            $result=SOSSData::Update ("d_cms_album_v1", $Artical,$tenantId = null);
        }
        CacheData::clearObjects("d_cms_album_v1");
        CacheData::clearObjects("d_all_summery");
        CacheData::clearObjects("d_cms_album_v1_pod_bycat_paging");
        CacheData::clearObjects("d_cms_album_v1_pod_paging");
        if(count($Artical->RemovedImages)>0){
            $Artical->removedStatus=SOSSData::Delete("d_cms_album_imagev1",$Artical->RemovedImages);
        }
        foreach($Artical->Images as $key=>$value){
            $Artical->Images[$key]->articalid=$Artical->id;
            if($Artical->Images[$key]->id==0){
                $result2=SOSSData::Insert ("d_cms_album_imagev1", $Artical->Images[$key],$tenantId = null);
                if($result2->success){
                    $Artical->Images[$key]->id = $result2->result->generatedId;
                }

            }else{
                $result2=SOSSData::Update ("d_cms_album_imagev1", $Artical->Images[$key],$tenantId = null);
            }
            
            //var_dump($invoice->InvoiceItems[$key]->invoiceNo);
        }
        CacheData::clearObjects("d_cms_album_imagev1");
        return $Artical;
        
    }

    public function postSaveCarousel($req,$res)
    {
        $Carousel=$req->Body(true);
        $r= SOSSData::Query("d_cms_carousel_v1","catid:".$Carousel->catid);
        if($r->success ){
            if(count($r->result)>0){
                $result2=SOSSData::Update ("d_cms_carousel_v1", $Carousel);
                if($result2->success){
                    $resultq=SOSSData::Query("d_cms_carousel_dtl_v1","catid:".$Carousel->catid);
                    SOSSData::Delete("d_cms_carousel_dtl_v1",$resultq->result);
                    if(count($Carousel->carouselitems)>0){
                        SOSSData::Insert ("d_cms_carousel_dtl_v1", $Carousel->carouselitems);
                    }
                    
                    CacheData::clearObjects("d_cms_carousel_dtl_v1");
                    CacheData::clearObjects("d_cms_carousel_v1");
                    return $Carousel;
                    
                }else{
                    $res->SetError ("Error Updateing.");
                    return null;
                }
            }else{
                $result2=SOSSData::Insert ("d_cms_carousel_v1", $Carousel);
                if($result2->success){
                    if(count($Carousel->carouselitems)>0){
                        SOSSData::Insert ("d_cms_carousel_dtl_v1", $Carousel->carouselitems);
                    }
                    CacheData::clearObjects("d_cms_carousel_dtl_v1");
                    CacheData::clearObjects("d_cms_carousel_v1");
                    return $Carousel;
                }else{
                    $res->SetError ("Error Saving.");
                    return null;
                }
                
            }
        }
    }

    function getArtical($req){
        //echo "imain";
        $data =null;
        if(isset($_GET["q"])){
            $data = Summary::GetCode("davvag-cms-generalapps",$_GET["q"]);

            if(isset($data)){
                
                
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8" />
                    <meta name="description" content="'.urldecode($data->description).'"/>
                    <meta name="tags" content="'.urldecode($data->tags).'"/>
                    <meta name="og:title" content="'.urldecode($data->title).'"/>
                    <meta name="og:description" content="'.urldecode($data->description).'"/>
                    <meta name="og:tags"  content="'.urldecode($data->tag).'">
                    <meta name="og:image"  itemprop="image" content="http://'.$_SERVER["HTTP_HOST"].$data->imgurl.'"/>
                    <meta property="og:type" content="website" />
                    <title>'.urldecode($data->title).'</title>
                    
                </head>
                <script type="text/javascript">
                    window.location = "'.$data->url.'";
                        
                        
                </script>    
                <body>
                    Please Wait Redirecting....</br>
                    
                    <a href="'.$data->url.'"> Click here to read </a>
                </body>
                </html>';
                exit();      

            }
        }
    }

    function getAlbum($req){
        //echo "imain";
        $data =null;
        if(isset($_GET["q"])){
            //echo "in here";
            $result= CacheData::getObjects_fullcache(md5("id:".$_GET["q"]),"d_cms_album_v1");
            if(!isset($result)){
                //echo "in here";
                $result = SOSSData::Query("d_cms_album_v1",urlencode("id:".$_GET["q"]));
                //return $result;
                if($result->success){
                    //$f->{$s->storename}=$result->result;
                    if(isset($result->result[0])){
                        $data= $result->result[0];
                        CacheData::setObjects(md5("id:".$_GET["q"]),"d_cms_album_v1",$result->result);
                    }
                }
            }else{
                $data= $result[0];
            }
            //$result = SOSSData::Query ("d_cms_artical_v1",urlencode("id:".$_GET["q"]));
            //var_dump($result);
            //echo "imain";
            if(isset($data)){
                
                
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <meta charset="utf-8" />
                    <meta name="description" content="'.urldecode($data->summery).'">
                    <meta name="tags" content="">
                    <meta name="og:title" content="'.urldecode($data->title).'">
                    <meta name="og:description" content="'.urldecode($data->summery).'">
                    <meta name="og:tags"  content="">
                    <meta name="og:image"  content="http://'.$_SERVER["HTTP_HOST"].'/components/dock/soss-uploader/service/get/d_cms_album/'.$_GET["q"]."-".$data->imgname.'">
                    <title>'.urldecode($data->title).'</title>
                    
                </head>
                <body>
                    loading.....
                    <script type="text/javascript">
                        setTimeout(function(){ window.location = "/#/app/davvag-cms-generalapps/abm?id='.$_GET["q"].'"; }, 1000);
                        
                    </script>    
                </body>
                </html>';
                exit();      

            }
        }
    }

    function postsaveSettings($req,$res){
        $path = MEDIA_FOLDER."/".DATASTORE_DOMAIN."/global-setting/";
        $saveObj=$req->Body(true);
        if (!file_exists($path))
              mkdir($path, 0777, true);
        
        $filename=$saveObj->name.".glb";
        $string=json_encode($saveObj->body);
        $path=$path."/".$filename;
        $f = fopen($path, 'w');
        fwrite ($f, $string, strlen($string));
        fclose($f);
    }

    function postSettings($req){
        $path = MEDIA_FOLDER."/".DATASTORE_DOMAIN."/global-setting/";
        $saveObj=$req->Body(true);
        if (!file_exists($path))
              mkdir($path, 0777, true);
        
        $filename=$saveObj->name;
        $path=$path."/".$filename.".glb";
        if (file_exists($path)){
                $f = fopen($path, 'r');
                $buffer = '';
                while(!feof($f)) {
                    $buffer .= fread($f, 2048);
                }
                fclose($f);
                return json_decode($buffer);
            
            }else{
            return null;
        }    
    }

}
?>