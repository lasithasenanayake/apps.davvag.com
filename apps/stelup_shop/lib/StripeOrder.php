<?php
// Webhook receipts must not depend on Profile::getUserProfile() or a browser session.
// The caller has already verified Stripe's signature/session/account/amount and holds
// the checkout lock. Existing DAVVAG tables and its PayOrder contract are retained.
class StelupStripeOrder extends Davvag_Order {
    private $payments;
    public function __construct($payments) { $this->payments = $payments; }
    private function rows($table,$query) {
        // This adapter is called only after payment ownership/signature verification.
        // Scope every lookup explicitly; webhook delivery has no browser view-object session.
        $rows=array();$offset=0;
        do {
            $result=SOSSData::Query($table,urlencode($query),null,'DESC',1000,$offset,null,false);
            if(!$result->success){throw new Exception('Payment accounting is temporarily unavailable.');}
            $batch=$result->result;$rows=array_merge($rows,$batch);$offset+=1000;
        } while(count($batch)===1000);
        return $rows;
    }
    public function getOrder($id) {
        $rows=$this->rows('orderheader','invoiceno:'.(int)$id);
        if(!$rows){return null;}
        $order=$rows[0];$order->details=$this->rows('orderdetails','invoiceno:'.(int)$id);return $order;
    }
    public function AcceptOrder($id) {
        $pending=$this->rows('orderheader_pending','invoiceno:'.(int)$id);
        if(!$pending){return;}
        $order=$this->getOrder($id);
        if(!$order||(float)$order->balance>0){throw new Exception('An unpaid order cannot be accepted.');}
        $order->status='accepted';
        $existing=$this->rows('orderheader_accepted','invoiceno:'.(int)$id);
        if(!$existing){$this->save('Insert','orderheader_accepted',$order);}
        $existing=$this->rows('orderdetails_accepted','invoiceno:'.(int)$id);
        if(!$existing){$this->save('Insert','orderdetails_accepted',$order->details);}
        $this->save('Update','orderheader',$order);
        $this->save('Delete','orderheader_pending',$pending);
        $details=$this->rows('orderdetails_pending','invoiceno:'.(int)$id);
        if($details){$this->save('Delete','orderdetails_pending',$details);}
    }
    private function save($method,$table,$data) {
        $result = call_user_func(array('SOSSData',$method),$table,$data);
        if (!$result->success) { throw new Exception('Payment accounting could not be saved. Retry reconciliation.'); }
        return $result;
    }
    public function SavePayment($payment) {
        if ($payment->paymentType !== 'Stripe Connect' || !preg_match('/^(pi|cs)_[a-zA-Z0-9_]+$/D',$payment->source_id) || count($payment->InvoiceItems)!==1) {
            throw new Exception('Invalid Stripe receipt.');
        }
        return $this->payments->locked('ledger-'.(int)$payment->profileId,function()use($payment) {
            $previous = $this->rows('paymentheader','source_id:'.$payment->source_id.',profileId:'.(int)$payment->profileId);
            if ($previous) {
                $receipt = $previous[0];
                if (abs((float)$receipt->paymentAmount-(float)$payment->paymentAmount)>0.00001) { throw new Exception('Stripe receipt amount does not match.'); }
            } else {
                $receipt = clone $payment;
                $receipt->collectedBy = 'Stripe Connect';
                $receipt->collectedByID = 'stripe-webhook';
                $receipt->advanceAmount = 0;
                $receipt->advanceUtilized = 0;
                $receipt->outstandingAmount = $payment->paymentAmount;
                $receipt->balanceAmount = 0;
                $receipt->remarks = $payment->remark ?? 'Verified Stripe Connect payment';
                $result = $this->save('Insert','paymentheader',$receipt);
                $receipt->receiptNo = $result->result->generatedId;
            }
            if (empty($receipt->receiptNo)) { throw new Exception('Stripe receipt was not saved.'); }
            $detail = clone $payment->InvoiceItems[0];
            $detail->receiptNo = $receipt->receiptNo;
            $detail->DueAmount = $payment->paymentAmount;
            $detail->PaidAmout = $payment->paymentAmount;
            $detail->Balance = 0;
            $existing = $this->rows('paymentdetails','receiptNo:'.(int)$detail->receiptNo.',transactionid:'.(int)$detail->transactionid);
            $this->save($existing?'Update':'Insert','paymentdetails',array($detail));
            $ledger = (object)array('profileid'=>$payment->profileId,'tranid'=>$receipt->receiptNo,'trantype'=>'receipt','tranDate'=>$payment->receiptDate,'amount'=>-1*$payment->paymentAmount);
            $existing = $this->rows('ledger','profileid:'.(int)$payment->profileId.',tranid:'.(int)$receipt->receiptNo.',trantype:receipt');
            if (!$existing) { $this->save('Insert','ledger',$ledger); }
            // Rebuild from the authoritative ledger rather than incrementing twice on retry.
            $entries = $this->rows('ledger','profileid:'.(int)$payment->profileId);
            $old = $this->rows('profilestatus','profileid:'.(int)$payment->profileId);
            $status = $old ? clone $old[0] : (object)array('profileid'=>$payment->profileId);
            foreach(array('outstanding','totalInvoicedAmount','totalPaidAmount','totalGRNAmount','totalPaymentAmount')as $field){$status->$field=0;}
            foreach($entries as $entry) {
                $amount=(float)$entry->amount;$status->outstanding+=$amount;
                switch(strtolower($entry->trantype)) {
                    case 'invoice':$status->totalInvoicedAmount+=$amount;break;
                    case 'inv-del':$status->totalInvoicedAmount-=$amount;break;
                    case 'receipt':case 'receipt-r':$status->totalPaidAmount+=$amount;break;
                    case 'grn':$status->totalGRNAmount+=$amount;break;
                    case 'payment':$status->totalPaymentAmount+=$amount;break;
                }
            }
            $this->save($old?'Update':'Insert','profilestatus',$status);
            // Write paid state last, so retries can finish incomplete receipt/ledger work.
            $update=(object)array('invoiceNo'=>$detail->transactionid,'paidamount'=>$payment->paymentAmount,'balance'=>0,'PaymentComplete'=>'Y');
            $this->save('Update','orderheader',$update);
            foreach(array('paymentheader','paymentdetails','ledger','profilestatus','orderheader')as $table){CacheData::clearObjects($table);}
            return $receipt;
        });
    }
}
