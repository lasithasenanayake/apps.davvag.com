<?php
require_once PLUGIN_PATH.'/sossdata/SOSSData.php';
require_once PLUGIN_PATH.'/phpcache/cache.php';
require_once PLUGIN_PATH.'/auth/auth.php';
require_once PLUGIN_PATH_LOCAL.'/profile/profile.php';
require_once PLUGIN_PATH_LOCAL.'/davvag-order/davvag-order.php';
require_once dirname(__DIR__,2).'/lib/StripeConnect.php';
class StelupStripeApi {
    private function run($res,$fn) {
        try {
            return $fn(new StelupStripeConnect());
        }
        catch(Exception $e) {
            $res->SetError($e->getMessage());
            return null;
        }
    }
    private function profile() {
        $v=Profile::getUserProfile();
        if(empty($v->profile->id)) {
            throw new Exception('Sign in to continue.');
        }
        return $v->profile;
    }
    private function sellerData($profile) {
        $result=Profile::getProfile($profile->id,$profile->id);
        $p=$result->profile??$profile;
        $data=array();
        foreach(array('id','email','name','contactno','address','city','country','catogory')as $k) {
            $data[$k]=$p->$k??'';
        }
        $attrs=array();
        foreach(array('username','bio','website','instagram','refunds','internationalshipping','combinedshipping','bundleshipping','reviews','numberofreviews','sendvideo','sendphotocardvideo','icovershipping','localtradonly')as $k) {
            if(isset($p->attributes->$k)) {
                $attrs[$k]=$p->attributes->$k;
            }
        }
        $data['attributes']=(object)$attrs;
        return $data;
    }
    private function saveSeller($body,$complete,$core) {
        StelupStripeConnect::checkCsrf($body);
        $p=$this->profile();
        $data=$body->profile??null;
        if(!is_object($data)) {
            throw new Exception('Complete your seller profile.');
        }
        $result=SOSSData::Query('profile',urlencode('id:'.(int)$p->id));
        if(!$result->success||empty($result->result)) {
            throw new Exception('Profile not found.');
        }
        $record=$result->result[0];
        foreach(array('name','contactno','address','country')as $k) {
            if(empty($data->$k)||!is_scalar($data->$k)) {
                throw new Exception('Name, contact number, address and country are required.');
            }
        }
        foreach(array('name','contactno','address','city','country')as $k) {
            $record->$k=substr(trim((string)($data->$k??'')),0,1000);
        }
        if($complete) {
            $core->requireAccount($p->id);
            $record->catogory='vender';
        }
        $saved=SOSSData::Update('profile',$record);
        if(!$saved->success) {
            throw new Exception('Unable to save seller profile.');
        }
        $existing=SOSSData::Query('profile_attributes',urlencode('id:'.(int)$p->id));
        $attributes=!empty($existing->result)?$existing->result[0]:(object)array('id'=>$p->id);
        foreach(array('username','bio','website','instagram','refunds','internationalshipping','combinedshipping','bundleshipping','reviews','numberofreviews','sendvideo','sendphotocardvideo','icovershipping','localtradonly')as $k) {
            if(isset($data->attributes->$k)&&is_scalar($data->attributes->$k)) {
                $attributes->$k=substr((string)$data->attributes->$k,0,2000);
            }
        }
        $saved=!empty($existing->result)?SOSSData::Update('profile_attributes',$attributes):SOSSData::Insert('profile_attributes',$attributes);
        if(!$saved->success) {
            throw new Exception('Unable to save shop policies.');
        }
        if($complete&&defined('GROUPID')&&GROUPID==='web_user') {
            $user=Auth::Autendicate();
            Auth::Join(AUTH_DOMAIN,$user->userid,'seller');
        }
        CacheData::clearObjects('profile');
        CacheData::clearObjects('profile_data');
        return $this->sellerData($p);
    }
    public function getSettings($req,$res) {
        return $this->run($res,function($c) {
            return $c->adminSettings();
        }
        );
    }
    public function postSaveSettings($req,$res) {
        return $this->run($res,function($c)use($req) {
            return $c->saveSettings($req->Body(true));
        }
        );
    }
    public function getPublicSettings($req,$res) {
        return $this->run($res,function($c) {
            $s=$c->publicSettings();
            $s['can_manage_settings']=defined('GROUPID')&&in_array(strtolower(GROUPID),array('admin','sysadmin'),true);
            return $s;
        }
        );
    }
    public function getSellerStatus($req,$res) {
        return $this->run($res,function($c) {
            $p=$this->profile();
            $s=$c->publicSettings();
            $a=array();
            $connectionError='';
            if($s['enabled']) {
                try {
                    $a=$c->account($p->id);
                }
                catch(Exception $e) {
                    $connectionError=$e->getMessage();
                }
            }
            return array('profile'=>$this->sellerData($p),'connection'=>$a,'connection_error'=>$connectionError,'settings'=>$s,'csrf'=>StelupStripeConnect::csrf());
        }
        );
    }
    public function postSellerProfile($req,$res) {
        return $this->run($res,function($c)use($req) {
            return $this->saveSeller($req->Body(true),false,$c);
        }
        );
    }
    public function postCompleteSeller($req,$res) {
        return $this->run($res,function($c)use($req) {
            return $this->saveSeller($req->Body(true),true,$c);
        }
        );
    }
    public function postConnect($req,$res) {
        return $this->run($res,function($c)use($req) {
            StelupStripeConnect::checkCsrf($req->Body(true));
            return $c->authorize($this->profile());
        }
        );
    }
    public function getCallback($req,$res) {
        $c=new StelupStripeConnect();
        $s=$c->settings();
        try {
            $c->callback($this->profile(),$_GET);
            $status='connected';
        }
        catch(Exception $e) {
            $status='failed';
        }
        if(empty($s['base_url'])) {
            $res->SetError('Stripe callback is not configured.');
            return;
        }
        header('Location: '.$s['base_url'].'/#/app/stelup_shop/selleronboard?stripe='.$status, true,303);
        exit;
    }
    public function postQuote($req,$res) {
        return $this->run($res,function($c)use($req) {
            $b=$req->Body(true);
            return $c->issueQuote($b->cart??null,$this->profile());
        }
        );
    }
    public function getPaymentStatus($req,$res) {
        return $this->run($res,function($c) {
            return $c->paymentStatus((string)($_GET['token']??''),$this->profile());
        }
        );
    }
    public function postWebhook($req,$res) {
        try {
            $c=new StelupStripeConnect();
            return $c->webhook(file_get_contents('php://input'),$_SERVER['HTTP_STRIPE_SIGNATURE']??'');
        }
        catch(Exception $e) {
            http_response_code(400);
            $res->SetError($e->getMessage());
            return null;
        }
    }
}
