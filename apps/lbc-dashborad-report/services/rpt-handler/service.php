<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
require_once (PLUGIN_PATH . "/sossdata/SOSSData.php");

class rptService {

    function __construct(){
        
    } 

    public function getallOutstandingProfiles($req,$res){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
           

            $resultObj = SOSSData::ExecuteRaw("lbc_rpt_outstanding", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }


    public function getCustomerEnrolment($req,$res){
        if (isset($_GET["startdate"]) && isset($_GET["enddate"])){
            
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->startdate = $_GET["startdate"];
            $mainObj->parameters->enddate = $_GET["enddate"];
           

            $resultObj = SOSSData::ExecuteRaw("orderdetails_purchase_sum_by_month", $mainObj);
            $data=array();
            foreach ($resultObj->result as $key => $value) {
                # code...
                $year = explode("-",$value->month)[1];
                $month = explode("-",$value->month)[0];
                $data[$year]=isset($data[$year])?$data[$year]:[];
                $row=null;

                foreach ($data[$year] as $k => $v) {
                    if($v->name==$value->name){
                        $row=$v;
                    }
                    # code...
                }
                if(empty($row)){
                    $row=new stdClass();
                    $row->name=$value->name;
                }
                for ($i=1; $i < 13; $i++) { 
                   $dateObj   = DateTime::createFromFormat('!m', $i);
                   $monthName = $dateObj->format('F'); // March
                   $row->{$monthName}=$month==$i?array("customers"=>$value->numberofCustomers,"qty"=>$value->qty,"total"=>$value->total):array("customers"=>0,"qty"=>0,"total"=>0);
                  // $row->{$monthName."_customers"}+=$month==$i?$value->numberofCustomers:0;
                    //array_push($data[$year],)
                    # code...
                    //$value->name][$value->month]=array("Customers"=>$value->numberofCustomers,"Total"=>$value->total,"Quantity"=>$value->qty);
                }

                array_push($data[$year],$row);
                
            }
            //foreach($resultObj)
            return (array)$data;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }


    public function getallProfiles($req,$res){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
           

            $resultObj = SOSSData::ExecuteRaw("lbc_rpt_all_invoice_reciept", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function getallProfiles_withoutfilter($req,$res){
        if (isset($_GET["page"]) && isset($_GET["size"])){
            
            $mainObj = new stdClass();
            $mainObj->parameters = new stdClass();
            $mainObj->parameters->page = $_GET["page"];
            $mainObj->parameters->size = $_GET["size"];
           

            $resultObj = SOSSData::ExecuteRaw("lbc_rpt_all_invoice_reciept_all_status", $mainObj);
            return $resultObj->result;
        } else {
            
            $mainObj = new stdClass();
            $mainObj->error="Invalied Query";
            return $mainObj;
        }
    }

    public function getPendingSchedulesBy($req,$res){
        if(isset($_GET["app"]) && isset($_GET["service"]) && isset($_GET["method"])){
            $app=$_GET["app"];
            $service=$_GET["service"];
            $method=$_GET["method"];
            $r = SOSSData::Query ("schedule_pending", "app:$app,service:$service,method:$method");
            if($r->success){
                return $r->result;
            }else{
                $res->SetError ($r->result);
                return $r->result; 
            }
        }else{
            $res->SetError ("Error Loading data");
        }
    }

    public function postDeleteItem($req,$res){
        $body=$req->Body(true);
        $rd=SOSSData::Delete("schedule_pending", $body);
        if($rd->success){
            return $rd->result;
        }else{
            $res->SetError ($rd->result);
            return $rd->result; 
        }
    }


}

?>