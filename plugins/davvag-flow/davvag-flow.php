<?php 
    class Davvag_Flow_Controller{
        public function getFlows($ns=null){
            $folderename= TENANT_RESOURCE_LOCATION."/davvag-flow/".(isset($ns)?"/".$ns."/":"");
            $files=[];
            //echo $folderename;
            if (is_dir($folderename)){
                if ($dh = opendir($folderename)){
                  while (($file = readdir($dh)) !== false){
                    //echo $file;
                    $filename=TENANT_RESOURCE_LOCATION."/davvag-flow/".(isset($ns)?"/".$ns."/":"").$file;
                    if(file_exists($filename)){
                      if($file!="." && $file!=".."){
                        $data=file_get_contents($filename);
                        $workflowData =json_decode($data);
                        //echo $filename;
                        $workflow=new stdClass();
                        $workflow->filename=$file;
                        $workflow->name= preg_replace('/\\.[^.\\s]{3,4}$/', '', $file);
                        $workflow->inputData=isset($workflowData->inputData)?$workflowData->inputData:[];
                        $workflow->startupNode=$workflowData->start_up_node;
                        $workflow->nodes=[];
                        array_push($files,$workflow);
                      }
                    }
                  }
                  closedir($dh);

                }
              }
              return $files;
        }
    }
?>