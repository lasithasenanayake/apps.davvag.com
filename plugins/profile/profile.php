<?php
    
    class Profile{
        private static $notifyObjects;
        public static function getProfile($id,$pid,$userid=null){
            $profile =CacheData::getObjects($userid?$userid:$id."-".$pid,"profile_data");
            $profile=$profile?$profile:self::dbProfile($id,$pid,$userid);
            return $profile;
        }

        public static function getClearProfile($id,$pid,$userid=null){
            //$profile =CacheData::getObjects($id."-".$pid,"profile_data");
            $profile=self::dbProfile($id,$pid,$userid);
            return $profile;
        }

        private static function dbProfile($id,$pid,$userid){
            $profile = new stdClass();
            
            if($userid){
                $result = SOSSData::Query ("profile", urlencode("linkeduserid:".$userid.""));
            }else{
                if($id==0){
                    $user=Auth::GetDomainAttributes();
                    $result = SOSSData::Query ("profile", urlencode("email:".$user->email.""));
                    if($result->success && count($result->result)){
                        $profile->profile=$result->result[0];
                        return $profile;
                    }else{
                        $user->id=0;
                        $profile->profile=$user;
                        return $profile;
                    }
                    
                }else{
                    //$mainObj = new stdClass();
                    //$mainObj->parameters = new stdClass();
                    //$mainObj->parameters->pid = $pid;
                    //$mainObj->parameters->id = $id;
                    //$result = SOSSData::ExecuteRaw("profile_internal", $mainObj);
                    $result = SOSSData::Query ("profile", urlencode("id:".$id.""));

                }
               
            }
            //return $result;
            if($result->success && count($result->result)>0){
                
                $profile->profile=$result->result[0];
                $result = SOSSData::Query ("profile_policy", urlencode("id:".$profile->profile->id.""));
                if($result->success && count($result->result)>0){
                    $profile->policy=$result->result[0];
                }else{
                    $profile->policy=array("profilephoto"=>1,"lastseen"=>1,"status"=>1,"read_receipts"=>1,"posts"=>1);
                }
                $result = SOSSData::Query ("profile_attributes", urlencode("id:".$profile->profile->id.""));
                if($result->success && count($result->result)>0){
                    $profile->attributes=$result->result[0];
                }else{
                    $profile->attributes=null;
                }
                CacheData::setObjects($userid?$userid:$id."-".$pid,"profile_data",$profile);
                return $profile;
            }else{
                $profile->profile=null;
                $profile->result=$result;
                return $profile;
            }
        }
        
        public static function getUserProfile(){
            if(isset($_COOKIE["authData"])){
                $auth= json_decode($_COOKIE["authData"]);
                $profile =CacheData::getObjects($auth->userid,"profile_data");
                if($profile){
                    return $profile;
                }else{
                    return self::getProfile(0,0,$auth->userid);
                }
            }else{
                return null;
            }
        }

        public static function AddNotify($id,$className,$data,$url=null){
            $profile =self::getProfile($id,0);
            if($profile->profile){
                if(file_exists(TENANT_RESOURCE_LOCATION."/global/templetes/app/".$className.".jnx")){
                    
                    $notify=json_decode(file_get_contents(TENANT_RESOURCE_LOCATION."/global/templetes/app/".$className.".jnx"));

                    foreach ($data as $propertyToSet => $value) {
                        if(!is_array($value) && isset($value) && !($value instanceof stdClass)){
                            $notify->message=str_replace("@".$propertyToSet,$value,$notify->message);
                        }
                    }
                    foreach ($profile->profile as $propertyToSet => $value) {
                        if(!is_array($value) && isset($value) && !($value instanceof stdClass)){
                            //var_dump($value);
                            $notify->message=str_replace("@".$propertyToSet,$value,$notify->message);
                        }
                            
                    }

                    $obj=new stdClass();
                    $obj->pid=$profile->profile->id;
                    $obj->notify_date=date("m-d-Y H:i:s");
                    $obj->notify_message=$notify->message;
                    $obj->notify_type=$className;
                    $obj->notify_data=addslashes(json_encode($data));
                    $obj->appcode=isset($notify->appcode)?$notify->appcode:null;
                    $obj->app=isset($notify->app)?$notify->app:null;
                    $obj->apptitle=isset($notify->apptitle)?$notify->apptitle:null;
                    $obj->m_read=0;
                    $obj->app_state=isset($notify->app_state)?$notify->app_state:0;
                    self::$notifyObjects=self::$notifyObjects?self::$notifyObjects:array();
                    array_push(self::$notifyObjects,$obj);
                    return $obj;
                    //
                    
                }else{
                    return array("success"=>false,"ERROR"=>"ERROR Finding config File.");
                }
            }else{
                return array("success"=>false,"ERROR"=>"ERROR Finding Profile.");;
            }
        }

        public static function Send_Notify(){
            return SOSSData::Insert("profile_notify_u",self::$notifyObjects);
        }

        public static function Notify_Complete($id){

        }
    }
?>