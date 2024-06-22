<?php


require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/mpdf/mpdf.php");
require_once(PLUGIN_PATH . "/notify/notify.php");


//use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
class appService {

    function __construct(){
        
    } 

    function getCountries(){
        return array("161"=>"Armenia",  
        "105"=>"Australia", 
        "125"=>"Canada",
        "026"=>"France",
        "053"=>"Germany", 
        "071"=>"Hungary", 
        "035"=>"Malta", 
        "017"=>"Netherlands",
        "116"=>"New Zealand",
        "044"=>"Poland", 
        "134"=>"Singapore", 
        "062"=>"Spain",
        "143"=>"Sri Lanka",
        "080"=>"Switzerland",
        "152"=>"UK"); 
    }

    function drawBox($nc,$text){
        $t=str_split($text);
        $out='';
        for ($i=0; $i < $nc ; $i++) { 
            # code...
            $out.='<td style="width=5px;">'.(isset($t[$i])?strtoupper($t[$i]):"&emsp;").'</td>';
        }
        return $out;
    }

    public function postUploadExcel($req,$res){
        $rawInput = fopen('php://input', 'r');
        $tempStream = fopen('php://temp', 'r+');
        stream_copy_to_stream($rawInput, $tempStream);
        rewind($tempStream);
        $excel= stream_get_contents($tempStream);
        $ns="_tmpexcelUploads";
        $name=time()."_";

        $folder = MEDIA_FOLDER . "/".  DATASTORE_DOMAIN . "/$ns";
        if (!file_exists($folder))
            mkdir($folder, 0777, true);
        file_put_contents("$folder/$name", $excel);
        $file = fopen("$folder/$name","r");
        //$lines = file('myCSVFile.csv', FILE_IGNORE_NEW_LINES);
        $worksheet_arr =array();
        while (($result = fgetcsv($file)) !== false)
        {
            $worksheet_arr[] = $result;
        }
         
        $header=$worksheet_arr[0];
        unset($worksheet_arr[0]);
        $dataList=array();
        foreach($worksheet_arr as $row){
            $item =new stdClass();
            $itemNumber=0;
            foreach($header as $hvalue){
                if(isset($hvalue))
                $item->{$hvalue}=$row[$itemNumber];
                $itemNumber++;
            } 
            array_push($dataList,$item);
        }
        foreach($dataList as $dataObj){
            $rec=SOSSData::Query("qibprofilerequest_results",urlencode("id_number:".$dataObj->id_number));
            if(count($rec->result)>0){
                $data=$rec->result[0];
                foreach ($dataObj as $key => $value) {
                    # code...
                    $data->{$key}=$value;
                }
                $r=SOSSData::Update("qibprofilerequest_results",$data);
            }else{
                $r=SOSSData::Insert("qibprofilerequest_results",$dataObj);
            }

        }
        
        unlink("$folder/$name");
        return $dataList;
    }


    public function postSave($req,$res){
        $data = $req->Body(true);
        $data->regdate=time();
        $data->country=$this->getCountries()[$data->countrycode];
        $rec=SOSSData::Query("profile",urlencode("email:".$data->email));
            $data->profileid=0;
            if(!count($rec->result)>0){
                $new=new stdClass();
                $new->name=$data->name;
                $new->email=$data->email;
                $new->id_number=$data->nic;
                $new->contactno=$data->conatctno;
                $r=SOSSData::Insert("profile",$new);
                $data->profileid=$r->result->generatedId;
            }else{
                $data->profileid=$rec->result[0]->id;
            }

        $rec=SOSSData::Query("qibprofilerequest",urlencode("email:".$data->email));
        if(count($rec->result)>0){
            $data->id=$rec->result[0]->id;
            $r=SOSSData::Update("qibprofilerequest",$data);
            $data->emailstatus=Notify::sendEmailMessage($data->name,$data->email,"qib-admision",$data);
            return $data;
        }else{
            
            $r=SOSSData::Insert("qibprofilerequest",$data);
            $data->id=$r->result->generatedId;
        }
        $data->emailstatus=Notify::sendEmailMessage($data->name,$data->email,"qib-admision",$data);
        return $data; 
    }

    public function getResults($req,$res){
        $id=$_GET["refid"];
        $rec=SOSSData::Query("qibprofilerequest_results",urlencode("id_number:".$id));
        if(count($rec->result)>0){
            return $rec->result[0];
        }else{
            $res->SetError($rec);
            exit;
        }
    }

    public function getResultPDF($req,$res){
        $id=$_GET["ref"];
        $rec=SOSSData::Query("qibprofilerequest_results","id:".$id);
        if(count($rec->result)>0){
             $html=$this->getRenderedHTML("result.php",array("data"=>$rec->result[0]));
             $mpdf = new \Mpdf\Mpdf();
            // echo $html;
             $mpdf->WriteHTML($html);
             $mpdf->Output("result.pdf",\Mpdf\Output\Destination::DOWNLOAD);
             exit();
         }else{
             echo "<h1>Error: Invalid Request</h1><br>We apologize, but the request you submitted is invalid. Please review the information provided and try again.";
         }
     }

    public function getPDF($req,$res){
       $id=$_GET["ref"];
       $rec=SOSSData::Query("qibprofilerequest","id:".$id);
       if(count($rec->result)>0){
            $html=$this->getRenderedHTML("admision.php",array("data"=>$rec->result[0]));
            $mpdf = new \Mpdf\Mpdf();
           // echo $html;
            $mpdf->WriteHTML($html);
            $mpdf->Output("Admision.pdf",\Mpdf\Output\Destination::DOWNLOAD);
            exit();
        }else{
            echo "<h1>Error: Invalid Request</h1><br>We apologize, but the request you submitted is invalid. Please review the information provided and try again.";
        }
    }

    function getRenderedHTML($path,$_data=array()) {
        foreach ($_data as $key => $value) {
            # code...
            
            $$key=$value;
        }
        ob_start();
        include($path);
        //if(isset($data)){
        
        //}
        $var = ob_get_contents();
        ob_end_clean();
        return $var;
    }

    public function getCSV($req,$res){
        $rec=SOSSData::Query("qibprofilerequest",null);
        //var_dump($rec);
        
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; accounts_list.csv");
        echo $this->getRenderedHTML("csv.php",array("data"=>$rec->result));
        exit();
    }


}

?>