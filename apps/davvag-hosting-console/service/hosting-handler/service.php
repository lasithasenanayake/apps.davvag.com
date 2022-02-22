<?php


class hostingService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        return $data; 
    }

    public function getBackupDatabase(){
        //Hosting::BackupSystem();
        return Hosting::BackupDataBase();
    }

    public function getBackupSystem(){
        
        return Hosting::BackupSystem();
    }

    public function getDataBackupFiles(){
        $backup_location=TENANT_RESOURCE_LOCATION. "/apps/davvag-hosting-console/backups/";
        $scanned_directory = array_diff(scandir($backup_location), array('..', '.'));
        $files=[];
        foreach ($scanned_directory as $key => $value) {
            # code...
            $file =new stdClass();
            $file->name=$value;
            $file->size=filesize($backup_location.$value);
            $file->type=filetype($backup_location.$value);
            $file->createdDate=fileatime($backup_location.$value);
            array_push($files,$file);
        }
        return $files;
    }

    public function postDeleteFile($req,$res){
        $data = $req->Body(true);
        $backup_location=TENANT_RESOURCE_LOCATION. "/apps/davvag-hosting-console/backups/";
        if(file_exists($backup_location.$data->name)){
            unlink($backup_location.$data->name);
            return $data;
        }else{
            throw new Exception("File dose not exist.");
        }
    }

    public function getFile(){
        $name = $_GET["file"];
        header("Cache-Control: private, max-age=10800, pre-check=10800");
            header("Pragma: private");
            header('Content-disposition: inline; filename="'.$name.'"');
            
            $folder =TENANT_RESOURCE_LOCATION. "/apps/davvag-hosting-console/backups/";
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
    }

    


}

?>