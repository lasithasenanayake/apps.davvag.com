<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");


class appService {

    function __construct(){
        
    } 

    public function postSave($req,$res){
        $data = $req->Body(true);
        return $data; 
    }

    public function postSaveEntrol($req,$res){
        $data = $req->Body(true);
        $r = SOSSData::Query("lbc_course_entrolments","profileId:".$data->id.",courseCode:".$data->course->code.",status:active");
        if($r->success && count($r->result)==0){
            $saveData=new stdClass();
            $saveData->profileId=$data->id;
            $saveData->courseCode=$data->course->code;
            $saveData->courseName=$data->course->name;
            $saveData->enrolDate=date_format(new DateTime(), 'm-d-Y H:i:s');
            $saveData->status="pending";
            $re=SOSSData::Insert("lbc_course_entrolments",$saveData);
            if($re->success){
                $saveData->id=$re->result->generatedId;
                SOSSData::Insert("lbc_course_entrolments_active",$saveData);
                CacheData::clearObjects("lbc_course_entrolments");
                CacheData::clearObjects("lbc_course_entrolments_active");

                return $saveData;
            }
            else{
                $res->SetError("Error Not Saved");
                return null;
            }
        }else{
            $res->SetError("Already Active Course is there or Internal Error!");
        }
        //return $data; 
    }

    public function postSaveEntrolSubjects($req,$res){
        $data = $req->Body(true);
        $r = SOSSData::Query("attr_lbc_entrollments","id:".$data->id.",subject_id:".$data->subject->ID);
        if($r->success && count($r->result)==0){
            $saveData=new stdClass();
            $saveData->id=$data->id;
            $saveData->profileId=$data->profileId;
            $saveData->subject_id=$data->subject->ID;
            $saveData->subject_code=$data->subject->code;
            $saveData->subject_name=$data->subject->name;
            $saveData->status="pending";
            $re=SOSSData::Insert("attr_lbc_entrollments",$saveData);
            if($re->success){
                //$saveData->id=$re->result->generatedId;
                CacheData::clearObjects("attr_lbc_entrollments");
                return $saveData;
            }
            else{
                $res->SetError("Error Enrolling");
                return null;
            }
        }else{
            $res->SetError("Already Active Subject there or Internal Error!");
        }
        //return $data; 
    }

    public function getActiveEnrolments($req,$res){
        if($_GET["id"]){
            $r = SOSSData::Query("lbc_course_entrolments","profileId:".$_GET["id"]);
            return $r->result;
        }
    }
    public function getCourses($req,$res){
        if($_GET["id"]){
            $r=SOSSData::Query("profile","id:".$_GET["id"]);
            if($r->success && count($r->result)>0){
                $profile=$r->result[0];
                $r=SOSSData::Query("attr_course_creation","status:1");
                if($r->success){
                    $profile->courses= $r->result;
                }else{
                    $profile->courses= [];
                }
                return $profile;
            }else{
                $res->SetError("Profile Could not be found");
            }
            
        }else{
            $res->SetError("Invalied request;");
        }
        
        
    }
    
    public function getSubjects($req,$res){
        if($_GET["id"]){
            $r=SOSSData::Query("lbc_course_entrolments_active","id:".$_GET["id"]);
            if($r->success && count($r->result)>0){
                $profile=$r->result[0];
                $r=SOSSData::Query("attr_subject_creation",urlencode("course_code:".$profile->courseCode.",status:1"));
                if($r->success){
                    $profile->subjects= $r->result;
                }else{
                    $profile->Error=$r;
                    $profile->subjects= [];
                }
                //$profile->s=$r;
                return $profile;
            }else{
                $res->SetError("Error cannot find a Enrolment.");
            }
            
        }else{
            $res->SetError("Invalied request;");
        }
        
        
    }

}

?>