<?php 
class Davvag_Order{

    public function getOrder($id){
        $result = SOSSData::Query ("orderheader", urlencode("invoiceno:".$id.""));
        //sreturn $result;
        if($result->success && count($result->result)>0){
            $order= $result->result[0];
            $r = SOSSData::Query ("orderdetails", urlencode("invoiceno:".$id.""));
            $order->details=$r->result;
            return $order;
        }else{
            return null;
        }
    }
    
    public function PayOrder($id,$amount,$remarks,$paymentType,$online_ref_id){
        $order=$this->getOrder($id);
        if($order!=null){
            if($order->balance>=$amount){
                $order->balance=0;
                $order->PaymentComplete='Y';
                $order->paidamount=$amount;
                $order->status='Paid';
                $payment =new stdClass();
                $payment->profileId=$order->profileId;
                $payment->email=$order->email;
                $payment->name=$order->name;
                $payment->city=$order->city;
                $payment->address=$order->address;
                $payment->country=$order->country;
                $payment->contactno=$order->contactno;
                $payment->supplier_profileid=$order->supplier_profileId;
                $payment->supplier_email=$order->supplier_email;
                $payment->supplier_name=$order->supplier_name;
                $payment->supplier_city=$order->supplier_city;
                $payment->supplier_address=$order->supplier_address;
                $payment->supplier_country=$order->supplier_country;
                $payment->receiptDate=date("m-d-Y H:i:s");
                $payment->status='Paid';
                $payment->paymentAmount=$amount;
                $payment->outstandingAmount=$order->balance;
                $payment->balanceAmount=$order->balance-$amount;
                $payment->source_id=$online_ref_id;
                $payment->remark=$remarks;

                $payment->InvoiceItems=[];
                $invDetails =new stdClass();
                $invDetails->transactionid=$order->invoiceNo;
                $invDetails->tranType='invoice';
                $invDetails->description='Invoice #'.$order->invoiceNo.' Invoiced On '.$order->invoiceDate.'';
                $invDetails->DueAmount=$order->balance;
                $invDetails->PaidAmout=$amount;
                $invDetails->Balance=$order->balance-$amount;
                array_push($payment->InvoiceItems,$invDetails);
                return $this->SavePayment($payment);
                //$payment->InvoiceSave
            }else{
                return null;
            }
        }
    }
    
    public function SavePayment($payment)
    {
        //s$user= Auth::Autendicate("profile","postPaymentSave",$res);
        
        
        $result = SOSSData::Query ("profile", urlencode("id:".$payment->profileId.""));
        //$payment->collectedByID=$user->userid;
        //s$payment->collectedBy=$user->email;
        //return $result;
        if(count($result->result)!=0)
        {
            
            $result = SOSSData::Insert ("paymentheader", $payment,$tenantId = null);
            CacheData::clearObjects("paymentheader");
           
            if($result->success){
                $payment->receiptNo = $result->result->generatedId;
                $ledgertran =new StdClass;
                $ledgertran->profileid=$payment->profileId;
                $ledgertran->tranid=$payment->receiptNo;
                $ledgertran->trantype='receipt';
                $ledgertran->tranDate=$payment->receiptDate;
                $ledgertran->description='Payment recieved';
                $ledgertran->amount=-1*$payment->paymentAmount;
                //$result=SOSSData::Insert ("ledger", $ledgertran,$tenantId = null);
                //return $payment;
                $this->updateLedger($ledgertran);
                CacheData::clearObjects("ledger");
                if($result->success){
                    $balance=$payment->paymentAmount;
                    $invUpdate=array();
                    foreach($payment->InvoiceItems as &$value){
                        $value->receiptNo=$payment->receiptNo;
                        $paymentComplete='N';
                        if($balance!=0){
                            if($balance>=$value->DueAmount){
                                $value->PaidAmout=$value->DueAmount;
                                $balance-=$value->DueAmount;
                                $value->Balance=0;
                                $paymentComplete='Y';
                            }else{
                                $value->PaidAmout=$balance;
                                $value->Balance=$value->DueAmount-$balance;
                                $balance=0;
                            }
                            $invDetails=new stdClass();
                            $invDetails->invoiceNo=$value->transactionid;
                            $invDetails->paidamount=$value->PaidAmout;
                            $invDetails->balance=$value->Balance;
                            $invDetails->PaymentComplete=$paymentComplete;
                            $result=SOSSData::Update ("orderheader", $invDetails,$tenantId = null);
                            array_push($invUpdate,$invDetails);
                        }
                    }
                    //return $invUpdate;
                    $result = SOSSData::Insert ("paymentdetails", $payment->InvoiceItems,$tenantId = null);
                    CacheData::clearObjects("paymentdetails");
                    CacheData::clearObjects("orderheader");
                    //return $result;
                }else{
                    //$res->SetError ("Erorr");
                    throw new Exception("Error Processing Request", 1);
                    
                }
                unset($value); 
                                
                return $payment;
            }else{
                throw new Exception("Error Processing Request", 1);
            }
        }else{
           //var_dump($result->response[0]->id);
           //exit();
           throw new Exception("Error Processing Request", 1);
        }
        
    }

    private function updateLedger($ledgertran){
        $Transaction=$ledgertran;
        $result=SOSSData::Insert ("ledger", $ledgertran,$tenantId = null);
        $result = SOSSData::Query ("profilestatus", urlencode("profileid:".$Transaction->profileid.""));
        CacheData::clearObjects("profilestatus");
        CacheData::clearObjects("ledger");
        
        if(count($result->result)!=0){
            $status= $result->result[0];
            $status->outstanding+=$Transaction->amount;
            switch(strtolower($ledgertran->trantype)){
                case "invoice":
                    $status->totalInvoicedAmount+=$Transaction->amount;
                    break;
                case "receipt":
                    $status->totalPaidAmount+=$Transaction->amount;
                    break;
                case "grn":
                    $status->totalGRNAmount+=$Transaction->amount;
                    break;
                case "payment":
                    $status->totalPaymentAmount+=$Transaction->amount;
                    break;
            }
            $result=SOSSData::Update ("profilestatus", $status,$tenantId = null);
        }else{
            $status=new stdClass();
            $status->profileid=$Transaction->profileid;
            $status->outstanding=$Transaction->amount;
            $status->totalInvoicedAmount=0;
            $status->totalPaidAmount=0;
            $status->totalGRNAmount=0;
            $status->totalPaymentAmount=0;
            switch(strtolower($ledgertran->trantype)){
                case "invoice":
                    $status->totalInvoicedAmount+=$Transaction->amount;
                    break;
                case "receipt":
                    $status->totalPaidAmount+=$Transaction->amount;
                    break;
                case "grn":
                    $status->totalGRNAmount+=$Transaction->amount;
                    break;
                case "payment":
                    $status->totalPaymentAmount+=$Transaction->amount;
                    break;
            }
            $result=SOSSData::Insert ("profilestatus", $status,$tenantId = null);
                    
        }
    }

    public function InvoiceSave($Transaction,$res=null){

        $user= Auth::Autendicate("profile","postInvoiceSave",$res);
        if(!isset($Transaction->email)){
            throw new Exception("EmailNot found.");
            
        }
        if(!isset($Transaction->contactno)){
            throw new Exception("EmailNot found.");
        }
        
        $result = SOSSData::Query ("profile", urlencode("id:".$Transaction->profileId.""));
        $Transaction->status="new";
        //return $result;
        if(count($result->result)!=0)
        {
            $Transaction->preparedByID=$user->userid;
            $Transaction->preparedBy=$user->email;
            $Transaction->PaymentComplete="N";
            $Transaction->balance=$Transaction->total;
            $result = SOSSData::Insert ("orderheader", $Transaction,$tenantId = null);
            CacheData::clearObjects("orderheader");
            if($result->success){
                $Transaction->invoiceNo = $result->result->generatedId;
                $ledgertran =new StdClass;
                $ledgertran->profileid=$Transaction->profileId;
                $ledgertran->tranid=$Transaction->invoiceNo;
                $ledgertran->trantype='invoice';
                $ledgertran->tranDate=$Transaction->invoiceDate;
                $ledgertran->description='Invoice No Has been generated';
                $ledgertran->amount=$Transaction->total;
                $this->updateLedger($ledgertran);   
                
                //return $Transaction;
                if($result->success){
                
                    $profileservices=array();
                    foreach($Transaction->InvoiceItems as $key=>$value){
                        $Transaction->InvoiceItems[$key]->invoiceNo=$Transaction->invoiceNo;
                        if(strtolower($value->invType)=="service"){
                            $serviceitems =new StdClass;
                            $serviceitems->invid=$Transaction->invoiceNo;
                            $serviceitems->profileId=$Transaction->profileId;
                            $serviceitems->itemid=$value->itemid;
                            $serviceitems->name=$value->name;
                            $serviceitems->purchaseddate=$Transaction->invoiceDate;
                            $serviceitems->price=$value->total;
                            $serviceitems->catogory=$value->catogory;
                            $serviceitems->uom=$value->uom;
                            $serviceitems->qty=$value->qty;
                            $serviceitems->status="ToBeActive";
                            
                            array_push($profileservices,$serviceitems);
                        }
                        //var_dump($Transaction->InvoiceItems[$key]->invoiceNo);
                        $this->updateInventry($value,-1);
                    }
                    //return $profileservices;
                    $result = SOSSData::Insert ("orderdetails", $Transaction->InvoiceItems,$tenantId = null);
                    if(count($profileservices)!=0){
                        $result = SOSSData::Insert ("profileservices", $profileservices,$tenantId = null);
                        CacheData::clearObjects("profileservices");
                    }
                    //return $result;
                    
                    CacheData::clearObjects("orderdetails");
                }else{
                    throw new Exception(json_encode($result));
                }
                //unset($value); 
                
                
                return $Transaction;
            }else{
                throw new Exception(json_encode($result));
            }
        }else{
            throw new Exception("Profile Not Found.");
        }
        
        
    }

    private function updateInventry($value,$s){
        if(strtolower($value->invType)=="inventry"){
            $resultitems = SOSSData::Query ("product_inventrymaster", urlencode("itemid:".$value->itemid.""));//SOSSData::Insert ("", $Transaction,$tenantId = null);
            if(count($resultitems->result)!=0){
                $itemInv=$resultitems->result[0];
                if($s<0){
                    $itemInv->qty=$itemInv->qty-$value->qty;
                }else{
                    $itemInv->qty=$itemInv->qty+$value->qty;
                }
                SOSSData::Update ("product_inventrymaster", $itemInv,$tenantId = null);
            }else{
                $itemInv =new StdClass;
                $itemInv->itemid=$value->itemid;
                $itemInv->uom=$value->uom;
                if($s<0){
                    $itemInv->qty=-1*$value->qty;
                }else{
                    $itemInv->qty=$value->qty;
                }
                SOSSData::Insert ("product_inventrymaster", $itemInv,$tenantId = null);
            }
        }
    }


}
?>