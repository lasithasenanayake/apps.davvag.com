<?php

class UploaderService {

    private function getPostBody() {
        $rawInput = fopen('php://input', 'r');
        $tempStream = fopen('php://temp', 'r+');
        stream_copy_to_stream($rawInput, $tempStream);
        rewind($tempStream);
        return stream_get_contents($tempStream);
    }

    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function compress($source, $destination, $quality) {
        $tmpname=$this->generateRandomString();
        $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/tmp";
        if (!file_exists($folder))
        mkdir($folder, 0777, true);
        

        file_put_contents("$folder/$tmpname", $source);
        $type=mime_content_type("$folder/$tmpname");
        //echo filesize("$folder/$tmpname");
        //echo $type;
       
        if($type=='image/png' || $type=='image/jpeg' || $type=='image/gif'){
            $info = getimagesize("$folder/$tmpname");
            $image = null;

            if (function_exists("imagejpeg") && is_array($info) && isset($info['mime'])) {
                if ($info['mime'] == 'image/jpeg' && function_exists("imagecreatefromjpeg")){
                    $image = imagecreatefromjpeg("$folder/$tmpname");
                } 
                elseif ($info['mime'] == 'image/gif' && function_exists("imagecreatefromgif")){
                    $image = imagecreatefromgif("$folder/$tmpname");
                } 
                elseif ($info['mime'] == 'image/png' && function_exists("imagecreatefrompng")){
                    $image = imagecreatefrompng("$folder/$tmpname");
                }
            }

            if ($image) {
                $saved = false;
                if ($info['mime'] == 'image/png' && function_exists("imagepng")) {
                    $saved = imagepng($image, $destination);
                } elseif ($info['mime'] == 'image/gif' && function_exists("imagegif")) {
                    $saved = imagegif($image, $destination);
                } elseif ($info['mime'] == 'image/jpeg' && function_exists("imagejpeg")) {
                    $saved = imagejpeg($image, $destination, $quality);
                }
                imagedestroy($image);
                if (!$saved) {
                    file_put_contents($destination, $source);
                }
            } else {
                file_put_contents($destination, $source);
            }
        }else{
            file_put_contents($destination, $source);
        }
        
        unlink("$folder/$tmpname");
        
      
    }

    function compressImage($source, $destination, $quality) {

        $info = getimagesize($source);
      
        if (!function_exists("imagejpeg")) {
            copy($source, $destination);
            return;
        }

        if ($info['mime'] == 'image/jpeg' && function_exists("imagecreatefromjpeg")) 
          $image = imagecreatefromjpeg($source);
      
        elseif ($info['mime'] == 'image/gif' && function_exists("imagecreatefromgif")) 
          $image = imagecreatefromgif($source);
      
        elseif ($info['mime'] == 'image/png' && function_exists("imagecreatefrompng")) 
          $image = imagecreatefrompng($source);

        if (isset($image) && $image) {
            $saved = false;
            if ($info['mime'] == 'image/png' && function_exists("imagepng")) {
                $saved = imagepng($image, $destination);
            } elseif ($info['mime'] == 'image/gif' && function_exists("imagegif")) {
                $saved = imagegif($image, $destination);
            } elseif ($info['mime'] == 'image/jpeg' && function_exists("imagejpeg")) {
                $saved = imagejpeg($image, $destination, $quality);
            }
            imagedestroy($image);
            if (!$saved) {
                copy($source, $destination);
            }
        } else {
            copy($source, $destination);
        }
      
    }

    public function __handle($req, $res){
        Carbite::Reset();
        Carbite::SetAttribute("reqUri",$req->Params()->handlerName .$req->Params()->route);
        Carbite::SetAttribute("no404",true);

        Carbite::GET("/test/@ns/@name",function($req,$res){
            $ns = $req->Params()->ns;
            $name = $req->Params()->name;
            $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
            $source="$folder/$name";
            $info=getimagesize($source);
            var_dump($info);
            
                echo $info['mime'];
      
        
        });

        Carbite::GET("/get/@ns/@name",function($req,$res){
            $ns = $req->Params()->ns;
            $name = $req->Params()->name;
            $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
            //echo "im here";
            //echo "$folder/$name";
            if(!file_exists("$folder/$name")){
                $name="0";
                //echo "im here in no file";
                //return 0;
            }
            if(file_exists("$folder/$name")){
                $type=mime_content_type("$folder/$name");
                header("Content-Type: $type");
                echo file_get_contents("$folder/$name");
                exit();
            }else{
                return "Error Procesing";
            }
            
        });

        Carbite::POST("/upload/@ns/@name",function($req,$res){
            $ns = $req->Params()->ns;
            $name = $req->Params()->name;
            $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
            
            if (!file_exists($folder))
                mkdir($folder, 0777, true);
            
            $this->compress($this->getPostBody(),"$folder/$name",80);
            $resObj = new stdClass();
            $resObj->sucess = true;
            $resObj->message = "Successfully Uploaded!!!";
            $res->Set($resObj);
        });

        Carbite::POST("/upload_uncompressed/@ns/@name",function($req,$res){
            $ns = $req->Params()->ns;
            $name = $req->Params()->name;
            $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
            
            if (!file_exists($folder))
                mkdir($folder, 0777, true);
                
            file_put_contents("$folder/$name", $this->getPostBody());
            
            $resObj = new stdClass();
            $resObj->sucess = true;
            $resObj->message = "Successfully Uploaded!!!";
            $res->Set($resObj);
        });

        $resObj = Carbite::Start();
        exit();
    }
}

?>
