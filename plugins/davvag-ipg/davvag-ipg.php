<?php 
    class Davvag_IPG{
        public static function SaveNewIPG($appcode,$id,$name,$description,$url,$icon){
            $result = SOSSData::Query ("davvag_ipgs", urlencode("id:".$id.",AppCode:".$appcode));
            $item=new stdClass();
                $item->id=$id;
                $item->AppCode=$appcode;
                $item->name=$name;
                $item->description=$description;
                $item->url=$url;
                $item->iconurl=$icon;
            if($result->success && count($result->result)==0){
                
                SOSSData::Insert("davvag_ipgs",$item);
            }else{
                SOSSData::Update("davvag_ipgs",$item);
            }
        }

        public static function DeleteIPG($appcode,$id){
            $result = SOSSData::Query ("davvag_ipgs", urlencode("id:".$id.",AppCode:".$appcode));
            if($result->success && count($result->result)>0){
                SOSSData::Delete("davvag_ipgs",$result->result[0]);
            }
        }

        public static function getIPGs($id){
            $result = SOSSData::Query ("davvag_ipgs", urlencode("id:".$id));
            return $result->result;
        }
    }
?>