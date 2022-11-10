<?php

class ViewObjectApi {

    function __construct(){
        
    } 

    

    public function postSave($req,$res){
        $data =$req->Body(true);
        //array_push($data,)
        $keySort=array();
        $header=new stdClass();
        $user=AUTH::Autendicate();
        if(isset($data[0]->viewObjectID)){
            $d =SOSSData::Query("user_object","viewObjectID:".$data[0]->viewObjectID);
            if(count($d->result)>0){
                $header=$d->result[0];
            }
        }
        
        foreach ($data as $key => $value) {
            # code...
            array_push($keySort,$value->item_value."-".$value->item_permision);
            
        }
        
        asort($keySort);
        
        $keyValue=md5(implode("_",$keySort));
        if(!isset($header->viewObjectID)){
            $d =SOSSData::Query("user_object","keyValue:".$keyValue);
            if(count($d->result)>0){
                $header=$d->result[0];
            }
        }
        $header->keyvalue=$keyValue;

        if(isset($header->viewObject)){
            SOSSData::Update("user_object",$header);
        }else{
           $header->owner=$user->userid;
           $result =SOSSData::Insert("user_object",$header);
           if($result->success){
                $header->viewObjectID=$result->result->generatedId;
           }
        }

        for ($i=0; $i < count($data) ; $i++) { 
            # code...
            $data[$i]->viewObjectID=$header->viewObjectID;
        }

        $r=SOSSData::Query("user_view_objects","viewObjectID:".$header->viewObjectID);
        if(count($r->result)>0){
            SOSSData::Delete("user_view_objects",$r->result);
        }

       $r= SOSSData::Insert("user_view_objects",$data);
       if($r->success){
        return $data;
       }else{
            $res->SetError($r);
            return $r;
       }

    }

    public function getUserVieObjects($req,$res){
        $user=Auth::Autendicate();
        $objects=array();
        $glob=new stdClass();
        $glob->tag="public";
        $glob->viewObjectID=0;
        $glob->keyvalue="";
        array_push($objects,$glob);
        if(isset($user->userid)){
            $objects=CacheData::getObjects($user->userid,"user_object");
            if(isset($objects)){
                return $objects;
            }else{

                $d =SOSSData::Query("user_object","owner:".$user->userid);
                if($d->sccuess){
                    $keySort=array($user->userid."-full");
                    $keyValue=md5(implode("_",$keySort));
                    $hasOnlyMe=false;
                    $header=null;
                    foreach ($d->result as $key => $value) {
                        # code...
                        $value->tag="Custom";
                        if($value->keyvalue==$keyValue){
                            $hasOnlyMe=true;
                            $header=$value;
                        }
                        array_push($objects,$value);
                    }
                    if(!$hasOnlyMe){
                        $header=new stdClass();
                        $header->keyvalue=$keyValue;
                        $header->owner=$user->userid;
                        $result =SOSSData::Insert("user_object",$header);
                        if($result->success){
                            $header->viewObjectID=$result->result->generatedId;
                            $header->tag="Only Me";
                            array_push($objects,$header);
                            $data=array();
                            $uprm =new stdClass();
                            $uprm->viewObjectID=$header->viewObjectID;
                            $uprm->item_type="user";
                            $uprm->item_permision="full";
                            $uprm->item_value=$user->userid;
                            $uprm->item_text=$user->email;
                            array_push($keySort,$user->userid."-full");
                            array_push($data,$uprm);
                            SOSSData::Insert("user_view_objects",$data);
                        }else{
                            $res->SetError($result);
                            return null;
                        }
                       
                        
                    }

                }else{
                    $res->SetError($d);
                    return null;
                }
            }
            

        }
        return $objects;
    }
    
    public function getPermisionValues($req,$res){
        $data_sent=array();
        switch ($req->Query()->item_type) {
            case 'group':
                # code...
                $data=SOSSData::Query("usergroups","");
                
                foreach ($data->result as $key => $value) {
                    # code...
                    array_push($data_sent,array("val"=>$value->groupid,"text"=>$value->groupid));
                }

                break;
            case 'user':
                # code...
                $data=SOSSData::Query("users","");
                
                foreach ($data->result as $key => $value) {
                    # code...
                    array_push($data_sent,array("val"=>$value->userid,"text"=>$value->email));
                }
                break;
            
            default:
                # code...
                return array();
                break;
        }
        return $data_sent;
    }
    


}

?>