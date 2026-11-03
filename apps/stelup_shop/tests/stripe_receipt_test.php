<?php
// The adapter must work without Profile::getUserProfile() or any login session.
class Davvag_Order{}
class Profile{public static function getUserProfile(){throw new Exception('No interactive user is available.');}}
class CacheData{public static function clearObjects($table){}}
class SOSSData {
    public static $data=array();
    public static $fail=false;
    public static function Query($table,$query,$version=null,$sort='DESC',$size=1000,$offset=0,$tenant=null,$view=true){
        if($view){throw new Exception('Webhook adapter must use explicit verified-row scope.');}
        $conditions=explode(',',urldecode($query));$rows=array_values(array_filter(self::$data[$table]??array(),function($row)use($conditions){foreach($conditions as $condition){list($key,$value)=explode(':',$condition,2);$found=null;foreach((array)$row as $field=>$actual){if(strtolower($field)===strtolower($key)){$found=$actual;break;}}if((string)$found!==$value){return false;}}return true;}));
        return(object)array('success'=>true,'result'=>array_map(function($r){return clone $r;},array_slice($rows,$offset,$size)));
    }
    public static function Insert($table,$value){
        if(self::$fail&&$table==='paymentdetails'){self::$fail=false;return(object)array('success'=>false);}
        if(is_array($value)){foreach($value as $row){self::Insert($table,$row);}return(object)array('success'=>true);}
        $value=clone $value;
        if($table==='paymentheader'){$value->receiptNo=count(self::$data[$table]??array())+1;}
        self::$data[$table][]=$value;
        return(object)array('success'=>true,'result'=>(object)array('generatedId'=>$value->receiptNo??0));
    }
    public static function Update($table,$value){
        if(is_array($value)){foreach($value as $row){self::Update($table,$row);}return(object)array('success'=>true);}
        $keys=array('orderheader'=>'invoiceNo','profilestatus'=>'profileid','paymentdetails'=>'receiptNo');$key=$keys[$table];
        foreach(self::$data[$table]??array() as $row){if((string)$row->$key===(string)$value->$key){foreach((array)$value as $field=>$data){$row->$field=$data;}}}
        return(object)array('success'=>true);
    }
    public static function Delete($table,$value){self::$data[$table]=array();return(object)array('success'=>true);}
}
require_once dirname(__DIR__).'/lib/StripeOrder.php';
function check($v,$m){if(!$v){throw new Exception($m);}}
$locks=new class{public function locked($key,$fn){return $fn();}};
$adapter=new StelupStripeOrder($locks);
$order=(object)array('invoiceNo'=>10,'profileId'=>99,'total'=>105,'balance'=>105);
SOSSData::$data=array('orderheader'=>array($order),'orderdetails'=>array((object)array('invoiceNo'=>10)),'orderheader_pending'=>array(clone $order),'orderdetails_pending'=>array((object)array('invoiceNo'=>10)),'ledger'=>array((object)array('profileid'=>99,'tranid'=>10,'trantype'=>'invoice','amount'=>105)));
$payment=(object)array('paymentType'=>'Stripe Connect','source_id'=>'pi_test','profileId'=>99,'paymentAmount'=>105,'receiptDate'=>'11-03-2026 10:00:00','InvoiceItems'=>array((object)array('transactionid'=>10,'tranType'=>'invoice')));
SOSSData::$fail=true;try{$adapter->SavePayment($payment);throw new Exception('Expected a database failure');}catch(Exception $e){check($order->balance===105,'Partial write must not mark an order paid');}
$adapter->SavePayment($payment);$adapter->SavePayment($payment);$adapter->AcceptOrder(10);$adapter->AcceptOrder(10);
check(count(SOSSData::$data['paymentheader'])===1,'Receipt retries must not duplicate header');
check(count(SOSSData::$data['paymentdetails'])===1,'Receipt detail must be idempotent');
check(count(SOSSData::$data['ledger'])===2,'Receipt ledger must be inserted once');
check(SOSSData::$data['profilestatus'][0]->outstanding==0,'Receipt must settle the profile ledger');
check($order->paidamount===105&&$order->balance===0,'Receipt must apply exactly the paid amount');
check($order->status==='accepted','Paid order must be accepted');
check(count(SOSSData::$data['orderheader_accepted'])===1,'Acceptance retry must not duplicate order');
echo "Stripe receipt tests passed without an interactive user, including partial-write retry and idempotent accounting.\n";
