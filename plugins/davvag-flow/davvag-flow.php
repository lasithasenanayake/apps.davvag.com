<?php 
    class Davvag_Flow_Controller{
        public function getFlows($ns=null){
            $folderename= TENANT_RESOURCE_LOCATION.(isset($ns)?"/".$ns."/":"");
            $files=[];
            if (is_dir($folderename)){
                if ($dh = opendir($folderename)){
                  while (($file = readdir($dh)) !== false){
                    array_push($files,$file);
                  }
                  closedir($dh);

                }
              }
              return $files;
        }
    }
?>