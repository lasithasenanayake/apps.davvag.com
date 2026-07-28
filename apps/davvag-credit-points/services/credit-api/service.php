<?php
namespace davvag_credit_points;
require_once dirname(__DIR__,2)."/lib/CreditServiceSupport.php";
require_once dirname(__DIR__,2)."/lib/CreditRewardService.php";
require_once dirname(__DIR__,2)."/lib/CreditCouponService.php";
require_once dirname(__DIR__,2)."/lib/CreditPaymentService.php";
class CreditApiService {
    private function call($res,$fn){try{return$fn();}catch(\Throwable$e){return CreditServiceSupport::fail($res,$e);}}
    public function postBootstrap($req,$res){return$this->call($res,function(){$p=CreditServiceSupport::profile();$l=new CreditLedgerService();$pay=new CreditPaymentService($l);$reward=new CreditRewardService($l);return array("profile"=>$p,"balance"=>$l->summary($p->id),"packages"=>$pay->packages(),"rewards"=>$reward->status($p->id),"role"=>CreditServiceSupport::role(),"couponConfigured"=>strlen(strval(getenv("DAVVAG_CREDIT_COUPON_PEPPER")))>=32);});}
    public function postBalance($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);return(new CreditLedgerService())->summary($p->id,CreditServiceSupport::value($b,"program_code","CREDIT"));});}
    public function postTransactions($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);return(new CreditLedgerService())->history($p->id,array("programCode"=>CreditServiceSupport::value($b,"program_code","CREDIT"),"transactionType"=>CreditServiceSupport::value($b,"transaction_type",""),"limit"=>CreditServiceSupport::value($b,"limit",50),"offset"=>CreditServiceSupport::value($b,"offset",0)));});}
    public function postPackages($req,$res){return$this->call($res,function(){CreditServiceSupport::profile();return(new CreditPaymentService())->packages();});}
    public function postCreatePurchase($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);return(new CreditPaymentService())->createOrder($p->id,CreditServiceSupport::value($b,"package_id",0),CreditServiceSupport::idempotency($b,"purchase-order"));});}
    public function postPurchaseStatus($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);$row=(new CreditPaymentService())->orderForProfile(CreditServiceSupport::value($b,"order_id",0),$p->id);if(!$row)throw new CreditException("Purchase order was not found.");return$row;});}
    public function postRewardStatus($req,$res){return$this->call($res,function(){$p=CreditServiceSupport::profile();return(new CreditRewardService())->status($p->id);});}
    public function postClaimReward($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);return(new CreditRewardService())->claim($p->id,CreditServiceSupport::value($b,"rule_id",0),CreditServiceSupport::idempotency($b,"reward"),array("profileId"=>$p->id));});}
    public function postRedeemCoupon($req,$res){return$this->call($res,function()use($req){$p=CreditServiceSupport::profile();$b=CreditServiceSupport::body($req);return(new CreditCouponService())->redeem($p->id,CreditServiceSupport::value($b,"code",""),CreditServiceSupport::idempotency($b,"coupon"),array("profileId"=>$p->id));});}
}
?>
