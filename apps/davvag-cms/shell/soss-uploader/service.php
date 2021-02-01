<?php

class UploaderService {

    private function getPostBody() {
        $rawInput = fopen('php://input', 'r');
        $tempStream = fopen('php://temp', 'r+');
        stream_copy_to_stream($rawInput, $tempStream);
        rewind($tempStream);
        return stream_get_contents($tempStream);
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
           
            if(file_exists("$folder/$name")){
                header("Cache-Control: private, max-age=10800, pre-check=10800");
                header("Pragma: private");
                header("Expires: " . date(DATE_RFC822,strtotime("+2 day")));
                $type=mime_content_type("$folder/$name");
                header("Content-Type: $type");
                echo file_get_contents("$folder/$name");
                exit();
            }else{
                
                $name="0";
                if(file_exists("$folder/$name")){
                    header("Cache-Control: private, max-age=10800, pre-check=10800");
                    header("Pragma: private");
                    header("Expires: " . date(DATE_RFC822,strtotime("+1 day")));
                    $type=mime_content_type("$folder/$name");
                    header("Content-Type: $type");
                    echo file_get_contents("$folder/$name");
                    exit();
                } else{
                    return "Error Procesing";
                }
                
            }
            
        });

        Carbite::POST("/upload/@ns/@name",function($req,$res){
            $ns = $req->Params()->ns;
            $name = $req->Params()->name;
            $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
            $quality=60;
            if (!file_exists($folder))
                mkdir($folder, 0777, true);
            $info = getimagesize($this->getPostBody());
            if ($info['mime'] == 'image/jpeg') $image = imagecreatefromjpeg($this->getPostBody());
            elseif ($info['mime'] == 'image/gif') $image = imagecreatefromgif($this->getPostBody());
            elseif ($info['mime'] == 'image/png') $image = imagecreatefrompng($this->getPostBody());
            imagejpeg($image, "$folder/$name", $quality);
            /*
            file_put_contents("$folder/$name", $this->getPostBody());
            $resObj = new stdClass();
            $resObj->sucess = true;*/
            $resObj->message = "Successfully Uploaded!!!";
            $res->Set($resObj);
        });

        function compress_image($source_url, $destination_url, $quality)
        {
        $info = getimagesize($source_url);
        if ($info['mime'] == 'image/jpeg') $image = imagecreatefromjpeg($source_url);
        elseif ($info['mime'] == 'image/gif') $image = imagecreatefromgif($source_url);
        elseif ($info['mime'] == 'image/png') $image = imagecreatefrompng($source_url);
        imagejpeg($image, $destination_url, $quality);
        echo "Image uploaded successfully.";
        }

        $resObj = Carbite::Start();
        exit();
    }
}

?>