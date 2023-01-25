<?php

class TaskApi {

    function __construct(){
        
    } 

    public function postSaveTask($req,$res){
        $data=$req->Body(true);
        $r=SOSSData::Query("davvag_task_header_active","taskId:".(isset($data->taskId)?$data->taskId:0));
        if(count($r->result)>0){
           $result= SOSSData::Update("davvag_task_header_active",$data);
        }else{
            $result=SOSSData::Insert("davvag_task_header_active",$data);
        }

        if($result->success){
            $data->taskId=isset($result->result->generatedId)?$result->result->generatedId:$data->taskId;
            return $data;
        }else{
            $res->SetError($result);
        }
    }

    public function postSaveProject($req,$res){
        $data=$req->Body(true);
        $r=SOSSData::Query("davvag_task_projects","projectId:".(isset($data->projectId)?$data->projectId:0));
        if(count($r->result)>0){
            $result= SOSSData::Update("davvag_task_projects",$data);
         }else{
             $result=SOSSData::Insert("davvag_task_projects",$data);
         }
 
         if($result->success){
             $data->projectId=isset($result->result->generatedId)?$result->result->generatedId:$data->projectId;
             return $data;
         }else{
             $res->SetError($result);
         }
    }

    public function postSaveType($req,$res){
        $data=$req->Body(true);
        $r=SOSSData::Query("davvag_task_project_types","typeId:".(isset($data->typeId)?$data->typeId:0));
        if(count($r->result)>0){
            $result= SOSSData::Update("davvag_task_project_types",$data);
         }else{
             $result=SOSSData::Insert("davvag_task_project_types",$data);
         }
 
         if($result->success){
             $data->typeId=isset($result->result->generatedId)?$result->result->generatedId:$data->typeId;
             return $data;
         }else{
             $res->SetError($result);
         }
    }

    public function getAllTypes($req,$res){
        $query=$req->Query();
        $fromPage=$query->fromPage;
        $pagesize=50;
        $r=SOSSData::Query("davvag_task_project_types","",null,"asc",$pagesize,$fromPage);
        return $r->result;
        
    }

    public function getTypesForProject($req,$res){
        $query=$req->Query();
        $projectId=$query->projectId;
        $pagesize=50;
        $r=SOSSData::Query("davvag_task_project_types","projectId:".$projectId);
        return $r->result;
        
    }

    public function getAllProjects($req,$res){
        $query=$req->Query();
        $fromPage=$query->fromPage;
        $pagesize=50;
        $r=SOSSData::Query("davvag_task_projects","",null,"asc",$pagesize,$fromPage);
        return $r->result;
        
    }

    public function getTypeByID($req,$res){
        $query=$req->Query();
        $id=$query->typeId;
       
        $r=SOSSData::Query("davvag_task_project_types","typeId:".$id);
        return count($r->result)>0?$r->result[0]:null;
        
    }

    public function getProjectByID($req,$res){
        $query=$req->Query();
        $id=$query->projectId;
        $r=SOSSData::Query("davvag_task_projects","projectId".$id);
        return count($r->result)?$r->result[0]:null;
        
    }
    
    
    


}

?>