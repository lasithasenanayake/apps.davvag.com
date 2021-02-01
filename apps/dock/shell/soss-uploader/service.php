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
        
        if($type=='image/png' || $type=='image/jpeg' || $type=='image/gif'){
            //var_dump($type);
            $info = getimagesize("$folder/$tmpname");
            if ($info['mime'] == 'image/jpeg'){
                $image = imagecreatefromjpeg("$folder/$tmpname");
                imagejpeg($image, $destination, $quality);
            } 
            elseif ($info['mime'] == 'image/gif'){
                $image = imagecreatefromgif("$folder/$tmpname");
                imagejpeg($image, $destination, $quality);
            } 
            elseif ($info['mime'] == 'image/png'){
                $image = imagecreatefrompng("$folder/$tmpname");
                imagepng($image, $destination, $quality);
            } 
            
            
        }else{
            file_put_contents($destination, $source);
        }
        
        unlink("$folder/$tmpname");
        
      
    }

    function compressImage($source, $destination, $quality) {

        $info = getimagesize($source);
      
        if ($info['mime'] == 'image/jpeg') 
          $image = imagecreatefromjpeg($source);
      
        elseif ($info['mime'] == 'image/gif') 
          $image = imagecreatefromgif($source);
      
        elseif ($info['mime'] == 'image/png') 
          $image = imagecreatefrompng($source);
      
        imagejpeg($image, $destination, $quality);
      
    }

    public function __handle($req, $res){
        Carbite::Reset();
        Carbite::SetAttribute("reqUri",$req->Params()->handlerName .$req->Params()->route);
        Carbite::SetAttribute("no404",true);

        Carbite::GET("/test",function($req,$res){
            $res->Set("Hello World");
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

        $resObj = Carbite::Start();
        exit();
    }
}

?>