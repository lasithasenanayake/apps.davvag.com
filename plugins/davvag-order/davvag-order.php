<?php 
require_once (PLUGIN_PATH_LOCAL . "/profile/profile.php");
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

    public function DeleteOrder($id,$remark,$cProfileId,$cName,$profileId){
        $order =$this->getOrder($id);
        if($order){
            if($order->status!="deleted"){
                if(floatval($order->profileId)!=floatval($profileId)){
                    throw new Exception("This is a invalid request.",1);
                    return;
                }
                if($order->paidamount>0){
                    //insert Advance;
                    $advance =new StdClass;
                    $advance->profileId=$order->profileId;
                    $advance->paymentType="Cancellation";
                    $advance->tranDate=date_format(new DateTime(), 'm-d-Y H:i:s');
                    $advance->description=$remark;
                    $advance->status="new";
                    $advance->amount=$order->paidamount;
                    $advance->balance=$order->paidamount;
                    $advance->collectedBy=$cName;
                    $advance->collectedByID=$cProfileId;
                    $advance->source_id=$order->invoiceNo;
                    SOSSData::Insert("payment_advance",$advance);
                }
                
                $ledgertran =new StdClass;
                $ledgertran->profileid=$order->profileId;
                $ledgertran->tranid=$order->invoiceNo;
                $ledgertran->trantype='inv-del';
                $ledgertran->tranDate=date_format(new DateTime(), 'm-d-Y H:i:s');
                $ledgertran->description='Invoice No '. $order->invoiceNo .' Cancelled';
                $ledgertran->amount=-1*$order->total;
                $this->updateLedger($ledgertran);   
                $profileservices=array();
                    foreach($order->details as $key=>$value){
                        if(isset($value->invType)){
                            //$Transaction->InvoiceItems[$key]->invoiceNo=$Transaction->invoiceNo;
                            if(strtolower($value->invType)=="service"){
                                $serviceitems =new StdClass;
                                $serviceitems->invid=$order->invoiceNo;
                                $serviceitems->profileId=$order->profileId;
                                $serviceitems->itemid=$value->itemid;
                                $serviceitems->name=$value->name;
                                $serviceitems->purchaseddate=$order->invoiceDate;
                                $serviceitems->price=$value->total;
                                $serviceitems->catogory=$value->catogory;
                                $serviceitems->uom=$value->uom;
                                $serviceitems->qty=$value->qty;
                                $serviceitems->status="Removed";
                                
                                array_push($profileservices,$serviceitems);
                            }
                            $this->updateInventry($value,1);
                        }
                    }
                $order->status="deleted";
                
                SOSSData::Delete("orderheader",$order);
                SOSSData::Delete("orderdetails",$order->details);
                $order->remarks=$remark;
                $order->preparedBy=$cProfileId;
                $order->PreparedByID=$cName;
                SOSSData::Insert("orderheader_deleted",$order);
                SOSSData::Insert("orderdetails_deleted",$order->details);
                switch($order->status){
                    case "accepted":
                        SOSSData::Insert("orderheader_accepted",$order);
                        SOSSData::Insert("orderdetails_accepted",$order->details);
                    break;
                    case "rejected":
                        SOSSData::Insert("orderheader_rejected",$order);
                        SOSSData::Insert("orderdetails_rejected",$order->details);
                    break;
                    case "pending":
                        SOSSData::Insert("orderheader_pending",$order);
                        SOSSData::Insert("orderdetails_pending",$order->details);
                    break;
                }
                CacheData::clearObjects("ledger");
                CacheData::clearObjects("orderheader");
                CacheData::clearObjects("orderdetails");
                CacheData::clearObjects("payment_advance");
                return $order;
            }else{
                throw new Exception("Already Deleted.",1);
            }
        }else{
            throw new Exception("Order is not found.",1);
        }
    }

    public function ExtPaymentRequest($id){
        //sreturn $id;
        $result = SOSSData::Query ("payment_ext_request", urlencode("id:".$id.""));
        //return $result;
        if($result->success && count($result->result)>0){
            $order= $result->result[0];
            //$r = SOSSData::Query ("orderdetails", urlencode("invoiceno:".$id.""));
            //$order->details=$r->result;
            return $order;
        }else{
            return null;
        }
    }

    public function ConfirmExtPayment($p){
        $result = SOSSData::Query ("payment_ext_request", urlencode("id:".$p->id.""));
        if($result->success && count($result->result)>0){
            $oldpay= $result->result[0];
            
                $payment =new stdClass();
                $payment->profileId=$p->profileId;
                $payment->email=$p->email;
                $payment->name=$p->name;
                $payment->paymentType=$p->paymentType;
                $payment->city=$p->city;
                $payment->address=$p->address;
                $payment->country=$p->country;
                $payment->contactno=$p->contactno;
                $payment->supplier_profileid=isset($p->supplier_profileId)?$p->supplier_profileId:0;
                $payment->supplier_email=isset($p->supplier_email)?$p->supplier_email:"";
                $payment->supplier_name=isset($p->supplier_name)?$p->supplier_name:"";
                $payment->supplier_city=isset($p->supplier_city)?$p->supplier_city:"";
                $payment->supplier_address=isset($p->supplier_address)?$p->supplier_address:"";
                $payment->supplier_country=isset($p->supplier_country)?$p->supplier_country:"";
                $payment->receiptDate=date("m-d-Y H:i:s");
                $payment->status='Paid';
                $payment->paymentAmount=$p->amount;
                $payment->outstandingAmount=$p->outstandingAmount;
                $payment->balanceAmount=$p->balanceAmount-$p->amount;
                $payment->source_id=$p->OnlineTranID;
                $payment->remarks=$p->remarks;
                $payment->advanceAmount=0;
                //$payment->advance
                
                $amount=$p->amount;
                $rep=$this->PayforPendingOrders($payment);
                if(isset($rep->receiptNo)){
                    $p->paymentAmount=$p->amount;
                    $p->outstandingAmount=$p->outstandingAmount;
                    $p->balanceAmount=$p->balanceAmount-$p->amount;
                    $p->recieptid=$rep->receiptNo;
                    $p->status='Paid';
                    SOSSData::Insert("payment_ext_request_confirm",$p);
                    SOSSData::Delete("payment_ext_request",$p);
                    return $rep;
                }else {
                    throw new Exception("Payment has not been made.", 1);
                }
            
        }else{
            throw new Exception("No External Payment Request found.", 1);
            //return null;
        }
    }
    
    private function PayforPendingOrders($payment){
        $r = SOSSData::Query ("orderheader", urlencode("profileId:".$payment->profileId.",paymentComplete:N"));
        $payment->InvoiceItems=[];
        if($r->success && count($r->result)>0){
            $amount=$payment->paymentAmount;
            $orderUpdate=[];
            foreach ($r->result as $key => $order) {
                # code...
                
                if($amount>0){
                    $invDetails =new stdClass();
                    $invDetails->transactionid=$order->invoiceNo;
                    $invDetails->tranType='invoice';
                    $invDetails->description='Invoice #'.$order->invoiceNo.' Invoiced On '.$order->invoiceDate.'';
                    $invDetails->DueAmount=$order->balance;
                    
                    if($amount>$order->balance){
                        $invDetails->PaidAmout=$order->balance;
                        $invDetails->Balance=0;
                    }else{
                        $invDetails->PaidAmout=$amount;
                        $invDetails->Balance=$order->balance-$amount;
                    }
                    
                    if($order->balance>=$amount){
                        $amount=0;
                    }else{
    
                        $amount=$amount-$order->balance;
                    }
                    //$order->balance=$order->balance-$amount;
                    
                    array_push($payment->InvoiceItems,$invDetails);
                }else{
                    break;
                }
            }
            
        }
        return $this->SavePayment($payment);
    }

    public function AcceptOrder($id){
        $result = SOSSData::Query ("orderheader_pending", urlencode("invoiceno:".$id.""));
        if($result->success && count($result->result)>0){
            $order=$this->getOrder($id);
            $order->status='accepted';
            SOSSData::Insert("orderheader_accepted",$order);
            SOSSData::Insert("orderdetails_accepted",$order->details);
            SOSSData::Update("orderheader",$order);
            
            SOSSData::Delete("orderheader_pending",$result->result);
            $r = SOSSData::Query ("orderdetails_pending", urlencode("invoiceno:".$id.""));
            SOSSData::Delete("orderdetails_pending",$r->result);

        }

    }

    public function DispatchOrder($id){
        $result = SOSSData::Query ("orderheader_accepted", urlencode("invoiceno:".$id.""));
        if($result->success && count($result->result)>0){
            $order=$this->getOrder($id);
            $order->status='dispatched';
            SOSSData::Insert("orderheader_dispatched",$order);
            SOSSData::Insert("orderheader_dispatched",$order->details);
            SOSSData::Update("orderheader",$order);
            
            SOSSData::Delete("orderheader_accepted",$result->result);
            $r = SOSSData::Query ("orderdetails_accepted", urlencode("invoiceno:".$id.""));
            SOSSData::Delete("orderdetails_accepted",$r->result);

        }

    }


    public function PayOrder($id,$amount,$remarks,$paymentType,$online_ref_id){
        $order=$this->getOrder($id);
        if($order!=null){
            if($order->balance>0){
               
                $order->PaymentComplete='Y';
                $order->paidamount=$amount;
                $order->status='Paid';
                $payment =new stdClass();
                $payment->profileId=$order->profileId;
                $payment->email=$order->email;
                $payment->name=$order->name;
                $payment->paymentType=$paymentType;
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
                $amountToPay=0;
                if($amount>$order->balance){
                    $amountToPay=$order->balance;
                }else{
                    $amountToPay=$amount;
                }
                $payment->InvoiceItems=[];
                $invDetails =new stdClass();
                $invDetails->transactionid=$order->invoiceNo;
                $invDetails->tranType='invoice';
                $invDetails->description='Invoice #'.$order->invoiceNo.' Invoiced On '.$order->invoiceDate.'';
                $invDetails->DueAmount=$order->balance;
                $invDetails->PaidAmout=$amount;
                $invDetails->Balance=$order->balance-$amountToPay;
                $order->balance=$order->balance-$amountToPay;
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
        //$user= Auth::Autendicate("profile","postPaymentSave",$res);
        
        
        $r = SOSSData::Query ("profile", urlencode("id:".$payment->profileId.""));
        //$payment->collectedByID=$user->userid;
        //s$payment->collectedBy=$user->email;
        //return $result;
        if(count($r->result)!=0)
        {
            
            $rx = SOSSData::Query ("payment_advance", urlencode("profileId:".$payment->profileId.",status:new"));
            $advances=$rx->success?$rx->result:[];
            $payment->advanceAmount=0;
            $payment->balanceAmount=0;
            $payment->outstandingAmount=0;
            $user=Profile::getUserProfile();
            
            $payment->collectedBy=$user->profile->id;
            $payment->collectedByID=$user->profile->name;
            foreach($advances as $advalue){
                $payment->advanceAmount+=$advalue->balance;
            }
            foreach($payment->InvoiceItems as $value){
                $payment->outstandingAmount+=$value->DueAmount;
            }
            $payment->balanceAmount=$payment->outstandingAmount-$payment->paymentAmount-$payment->advanceAmount;
            $result = SOSSData::Insert ("paymentheader", $payment);
            $payment->receiptNo = $result->result->generatedId;
            
           
            if($result->success){
                
                
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
                if(in_array($payment->paymenttype,["Cash","Donation[CASH]"])){
                    $this->updateInternalCashLedger($user->profile->id,"receipt",$payment->receiptNo,'Payment Collected',($payment->paymentAmount));
                }
                CacheData::clearObjects("ledger");
                //$hasInvoiceForAdvance=true;
                if($result->success){
                    $advanceUtilized=0;
                    $balance=$payment->paymentAmount;
                    $invUpdate=array();
                    foreach($payment->InvoiceItems as &$value){
                                $value->receiptNo=$payment->receiptNo;
                        
                            
                                $paymentComplete='N';
                                if($balance!=0){
                                    if($balance>=$value->DueAmount){
                                        $value->PaidAmout+=$value->DueAmount;
                                        $balance-=$value->DueAmount;
                                        $value->Balance=0;
                                        $paymentComplete='Y';
                                    }else{
                                        $value->PaidAmout+=$balance;
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
                                }else{

                                }   
                        
                        
                    }
                    if($balance>0){
                        $advance =new StdClass;
                        $advance->profileId=$payment->profileId;
                        $advance->paymentType="Advance";
                        $advance->tranDate=date_format(new DateTime(), 'm-d-Y H:i:s');
                        $advance->description='Advanced for Payment Reciept No '.$payment->receiptNo;
                        $advance->status="new";
                        $advance->amount=$balance;
                        $advance->balance=$balance;
                        $advance->collectedBy=$payment->collectedBy;
                        $advance->collectedByID=$payment->collectedByID;
                        $advance->source_id=$payment->receiptNo;
                        SOSSData::Insert("payment_advance",$advance);
                    }else{
                            if($payment->advanceAmount>0){
                                
                                $balance=$payment->advanceAmount;
                                
                                foreach($payment->InvoiceItems as &$value){
                                    
                                    if($invDetails->PaymentComplete!='Y'){
                                        $value->receiptNo=$payment->receiptNo;
                                        $paymentComplete='N';
                                        if($balance!=0){
                                            if($balance>=$value->DueAmount){
                                                $value->PaidAmout+=$value->DueAmount;
                                                $balance-=$value->DueAmount;
                                                $paymentComplete='Y';
                                                $advanceUtilized+=$value->Balance;
                                                $value->Balance=0;
                                            }else{
                                                $value->PaidAmout+=$balance;
                                                $value->Balance=$value->DueAmount-$balance;
                                                $advanceUtilized+=$balance;
                                                $balance=0;
                                            }
                                            $invDetails=new stdClass();
                                            $invDetails->invoiceNo=$value->transactionid;
                                            $invDetails->paidamount=$value->PaidAmout;
                                            $invDetails->balance=$value->Balance;
                                            $invDetails->PaymentComplete=$paymentComplete;
                                            $result=SOSSData::Update ("orderheader", $invDetails,$tenantId = null);
                                            //sarray_push($invUpdate,$invDetails); 
                                        }  
                                    }
                            
                            
                                }

                                $payment->advanceUtilized=$advanceUtilized;
                                $balance=$advanceUtilized;

                                foreach($advances as &$advalue){
                                    //$value->receiptNo=$payment->receiptNo;
                                        $status='new';
                                        if($balance!=0){
                                            if($balance>=$advalue->balance){
                                                $balance-=$advalue->balance;
                                                $advalue->balance=0;
                                                $advalue->status='done';
                                                //$advanceUtilized+=$value->DueAmount;
                                            }else{
                                                $advalue->balance-=$balance;
                                                $balance=0;
                                            }
                                            $ad=new stdClass();
                                            $ad->id=$advalue->id;
                                            $ad->balance=$advalue->balance;
                                            $ad->status=$advalue->status;
                                            //$invDetails->PaymentComplete=$paymentComplete;
                                            SOSSData::Update("payment_advance",$ad);
                                        }
                                }
                                //return $advances;
                                $payment->advance=$advances;
                                //SOSSData::Update("payment_advance",$advances);
                            
                            }
                    }
                    
                    //return $invUpdate;
                    $result = SOSSData::Insert ("paymentdetails", $payment->InvoiceItems,$tenantId = null);
                    $result = SOSSData::Update ("paymentheader", $payment,$tenantId = null);
                    CacheData::clearObjects("paymentheader");
                    CacheData::clearObjects("paymentdetails");
                    //CacheData::clearObjects("orderheader");
                    CacheData::clearObjects("ledger");
                    CacheData::clearObjects("orderheader");
                    CacheData::clearObjects("orderdetails");
                    CacheData::clearObjects("payment_advance");
                    return $payment;
                }else{
                    //$res->SetError ("Erorr");
                    throw new Exception("Error Processing Request", 1);
                    
                }
                unset($value); 
                                
                return $payment;
            }else{
                //var_dump($result);
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
                case "inv-del":
                    $status->totalInvoicedAmount-=$Transaction->amount;
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
                case "inv-del":
                    $status->totalInvoicedAmount-=$Transaction->amount;
                    break;
            }
            $result=SOSSData::Insert ("profilestatus", $status,$tenantId = null);
            CacheData::clearObjects("profilestatus");
            CacheData::clearObjects("ledger");
                    
        }
    }

    private function updateInternalCashLedger($profileId,$tranType,$TranID,$Description,$Amount){
        $Transaction=new stdClass();
        $Transaction->profileid=$profileId;
        $Transaction->tranid=$TranID;
        $Transaction->trantype=$tranType;
        $Transaction->tranDate=date("m-d-Y H:i:s");
        $Transaction->amount=$Amount;

        $result=SOSSData::Insert ("internal_ledger", $Transaction,$tenantId = null);
        $result = SOSSData::Query ("internal_profilestatus", urlencode("profileid:".$Transaction->profileid.""));
        CacheData::clearObjects("internal_profilestatus");
        CacheData::clearObjects("internal_ledger");
        
        if(count($result->result)!=0){
            $status= $result->result[0];
            $status->CashInHand+=$Transaction->amount;
            if($Transaction->amount>0){
                $status->totalRecived+=$Transaction->amount;
            }else{
                $status->totalGiven+=(-1*$Transaction->amount);
            }
            $result=SOSSData::Update ("internal_profilestatus", $status,$tenantId = null);
        }else{
            $status=new stdClass();
            $status->profileid=$Transaction->profileid;
            $status->CashInHand=$Transaction->amount;
            $status->totalRecived=0;
            $status->totalGiven=0;
            if($Transaction->amount>0){
                $status->totalRecived+=$Transaction->amount;
            }else{
                $status->totalGiven+=$Transaction->amount;
            }
            $result=SOSSData::Insert ("internal_profilestatus", $status,$tenantId = null);
            CacheData::clearObjects("internal_profilestatus");
            CacheData::clearObjects("internal_ledger");
                    
        }
    }

    public function InvoiceSave($Transaction,$res=null){

        $user= Auth::Autendicate("profile","postInvoiceSave",$res);
        if(!isset($Transaction->email)){
            throw new Exception("Email Not found.");
            
        }
        if(!isset($Transaction->contactno)){
            throw new Exception("Contact No not found.");
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
                            $serviceitems->catogory=isset($value->catogory)?$value->catogory:"unset";
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

    public function DipostSave($Transaction,$res=null){

        $user=Profile::getUserProfile();
        if(!isset($Transaction->email)){
            throw new Exception("Email Not found.");
            
        }
        if(!isset($Transaction->contactno)){
            throw new Exception("Contact No not found.");
        }
        
        $result = SOSSData::Query ("profile", urlencode("id:".$Transaction->profileId.""));
        $Transaction->status="new";
        //return $result;
        if(count($result->result)!=0)
        {
            //$Transaction->preparedByID=$user->userid;
            //$Transaction->preparedBy=$user->email;
            //$Transaction->PaymentComplete="N";
            $Transaction->balance=$Transaction->total;
            $result = SOSSData::Insert ("dipositheader", $Transaction,$tenantId = null);
            CacheData::clearObjects("dipositheader");
            if($result->success){
               $Transaction->TranNo = $result->result->generatedId;
               if($Transaction->vault==1){
                    $this->updateInternalCashLedger($Transaction->profileId,"diposit_tr",$Transaction->TranNo,'Cash Diposit Tranfer',-1*$Transaction->total);

               }
               if($Transaction->paymenttype=="Cash"){
                    $this->updateInternalCashLedger($user->profile->id,"diposit",$Transaction->TranNo,'Cash Diposit',$Transaction->total);
               } 
                if($result->success){
                
                    $profileservices=array();
                    foreach($Transaction->InvoiceItems as $key=>$value){
                        $Transaction->InvoiceItems[$key]->TranNo=$Transaction->TranNo;
                        
                    }
                    //return $profileservices;
                    $result = SOSSData::Insert ("dipositdetails", $Transaction->InvoiceItems,$tenantId = null);
                    
                    //return $result;
                    
                    CacheData::clearObjects("dipositdetails");
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
            $resultitems = SOSSData::Query ("products", urlencode("itemid:".$value->itemid.""));//SOSSData::Insert ("", $Transaction,$tenantId = null);
            if(count($resultitems->result)!=0){
                $itemInv=$resultitems->result[0];
                if($s<0){
                    $itemInv->qty=$itemInv->qty-$value->qty;
                }else{
                    $itemInv->qty=$itemInv->qty+$value->qty;
                }
                SOSSData::Update ("products", $itemInv,$tenantId = null);
            }else{
                $itemInv =new StdClass;
                $itemInv->itemid=$value->itemid;
                $itemInv->uom=$value->uom;
                if($s<0){
                    $itemInv->qty=-1*$value->qty;
                }else{
                    $itemInv->qty=$value->qty;
                }
                SOSSData::Insert ("products", $itemInv,$tenantId = null);
            }
        }
    }

    public function getPaymentRequest($id){

    }


}
?>