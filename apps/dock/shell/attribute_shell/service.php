<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");


class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        $queryStr="";
        for ($i=0; $i < count($data->primary); $i++) { 
            $queryStr.=$data->primary[$i].":".$data->data->{$data->primary[$i]}.",";
        }
        if(strlen($queryStr)>1){
            $queryStr=substr($queryStr,0,-1);
        }
        $data->query=$queryStr;
        $result=SOSSData::Query($data->id,$queryStr);
        if($result->success){
            if(count($result->result)>0){
                $data->status=SOSSData::Update($data->id,$data->data);
            }else{
                $data->status=SOSSData::Insert($data->id,$data->data);
            }
        }else{
            $res->SetError($result);
            return null;
        }
        CacheData::clearObjects($data->id);
        return $data; 
    }

    public function postDelete($req,$res){
        $data = $req->Body(true);
        $data->status=SOSSData::Delete($data->id,$data->data);
        CacheData::clearObjects($data->id);
        return $data;
    }

    


}

?>