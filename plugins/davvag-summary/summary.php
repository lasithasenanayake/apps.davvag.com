<?php
class Summary{
    public static function Save($data){
        $d=self::Get($data->code);
        if($d){
            CacheData::setObjects($data->code,"davvag_summery",$data);
            return  SOSSData::Update ("davvag_summery", $data);
        }else{
            $userprofile=Profile::getUserProfile();
            if($userprofile){
                $data->pid=$userprofile->profile->id;
                $data->name =$userprofile->profile->name;
            }else{
                $data->pid=0;
                $data->name ="anonymous";
            }
            return SOSSData::Insert ("davvag_summery", $data);
        }
        

    }

    public static function Get($code){
        $result= CacheData::getObjects_fullcache($code,"davvag_summery");
        if(isset($result)){
            return $result;
        }else{
            $result = SOSSData::Query("davvag_summery",urlencode("code:".$code));
            if($result->success && count($result->result)>0){
                CacheData::setObjects($code,"davvag_summery",$result->result[0]);
                return $result->result[0];
            }else{
                return null;
            }
        }
    }

    public static function GetCode($appcode,$id){
        $code= md5($appcode."-".$id);
        return self::Get($code);
    }

    public static function GetObject($appcode,$id,$imgurl,$url,$title,$description,$tag,$date=null,$applicationtype="URL"){
        $sum=new stdClass();
        $sum->sumDate=$date?$date:date("m-d-Y H:i:s");
        $sum->appcode=$appcode;
        $sum->id=$id;
        $sum->code=md5($appcode."-".$id);
        $sum->title=$title;
        $sum->imgurl=$imgurl;
        $sum->url=$url;
        $sum->description=$description;
        $sum->tag=$tag;
        $sum->applicationtype=$applicationtype;
        

        return $sum;
    }
}
?>