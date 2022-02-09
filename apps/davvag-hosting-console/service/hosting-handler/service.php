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

    


}

?>