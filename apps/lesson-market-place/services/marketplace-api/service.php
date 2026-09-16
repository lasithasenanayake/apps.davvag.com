<?php
namespace lesson_market_place;
require_once PLUGIN_PATH . '/sossdata/SOSSData.php';
if(!class_exists('Auth'))require_once PLUGIN_PATH . '/auth/auth.php';
if(!class_exists('Profile'))require_once PLUGIN_PATH_LOCAL . '/profile/profile.php';
require_once dirname(__DIR__,2) . '/lib/MarketplaceSchema.php';
require_once dirname(__DIR__,2) . '/lib/MarketplaceCatalog.php';
require_once dirname(__DIR__,2) . '/lib/MarketplaceEnrolment.php';
require_once TENANT_RESOURCE_LOCATION . '/apps/davvag-credit-points/lib/CreditLedgerService.php';

class MarketplaceApi
{
    private $db;
    private $catalog;
    private $profile=0;
    private $admin=false;
    private $staff=false;

    private function call($req,$res,$callback,$authenticated=false,$staff=false)
    {
        try {
            return \SOSSData::WithServiceNamespaces(MarketplaceSchema::namespaces(),function() use($req,$callback,$authenticated,$staff) {
                $user=\Auth::Autendicate(); $role=strtolower(defined('GROUPID')?GROUPID:($user->group ?? 'anonymous'));
                // A profile alone (including an anonymous auto-created profile) is not authentication.
                if ($user && $role!=='anonymous') { $stored=\Profile::getUserProfile(); $this->profile=(int)(($stored->profile ?? $stored)->id ?? 0); }
                $this->admin=in_array($role,['sysadmin','admin'],true);
                $this->staff=$this->profile>0 && in_array($role,['sysadmin','admin','staff','teacher'],true);
                if ($authenticated && $this->profile<1) throw new MarketplaceException('Sign in with an active profile to continue.');
                if ($staff && !$this->staff) throw new MarketplaceException('Marketplace staff permission is required.');
                $body=$req ? $req->Body(true) : new \stdClass();
                if (!is_object($body)) throw new MarketplaceException('A JSON object is required.');
                $this->db=new \davvag_credit_points\CreditDatabase();
                MarketplaceSchema::ensure($this->db);
                $this->catalog=new MarketplaceCatalog($this->profile,$this->admin);
                return $callback($body);
            });
        } catch (MarketplaceException $error) { $res->SetError($error->getMessage()); }
        catch (\davvag_credit_points\CreditException $error) {
            $known=['Insufficient available credit balance.','The credit wallet is unavailable.','Credit program is unavailable.','Credit lots do not cover the requested amount.'];
            $res->SetError(in_array($error->getMessage(),$known,true)?$error->getMessage():'Credit processing is unavailable. Your request is preserved; refresh before retrying.');
        } catch (\Throwable $error) { error_log('Marketplace failure: '.get_class($error).' code '.$error->getCode()); $res->SetError('The operation could not complete. Refresh and retry with the same request.'); }
        return null;
    }
    private function id($body,$field='id') { return MarketplaceRules::integer($body->$field ?? 0,$field); }
    private function engine($paid=false) { return new MarketplaceEnrolment($this->db,$this->catalog,$paid?new \davvag_credit_points\CreditLedgerService($this->db):null); }
    private function manage($package) { if (!$package || !$this->staff || (!$this->admin && (int)$package->owner_profile_id!==$this->profile)) throw new MarketplaceException('Package is outside your staff scope.'); return $package; }
    private function packageById($id) { return $this->db->one('SELECT * FROM lmp_package WHERE id=?','i',[(int)$id]); }
    private function page($body) { return [MarketplaceRules::integer($body->limit ?? 20,'Page size',1,100),MarketplaceRules::integer($body->offset ?? 0,'Offset',0,1000000)]; }
    private function terms($package) { $version=$this->db->one('SELECT * FROM lmp_version WHERE id=? AND package_id=?','ii',[(int)$package->published_version_id,(int)$package->id]); return $version?json_decode($version->snapshot_json):null; }

    public function postCatalog($req,$res) { return $this->call($req,$res,function($body) {
        [$limit,$offset]=$this->page($body); $search=MarketplaceRules::text($body->search ?? '','Search',100);
        $conditions=[['column'=>'status','operator'=>'=','value'=>'published']]; if ($search!=='') $conditions[]=['column'=>'name','operator'=>'LIKE','value'=>'%'.$search.'%'];
        $result=\SOSSData::Query('lmp_package',['conditions'=>$conditions,'pageSize'=>$limit,'pageFrom'=>$offset,'sorting'=>[['column'=>'id','direction'=>'DESC']]]);
        if (!$result->success) throw new MarketplaceException('Catalog could not be loaded.');
        $items=[]; foreach ($result->result as $package) { $terms=$this->terms($package); if (!$terms) continue; $items[]=['id'=>(int)$package->id,'slug'=>$package->slug,'version_id'=>(int)$package->published_version_id,'name'=>$terms->name,'summary'=>$terms->summary,'cover_image'=>$terms->cover_image,'credit_price'=>(int)$terms->credit_price,'approval_required'=>(bool)$terms->approval_required,'lesson_count'=>count($terms->lesson_ids)]; }
        return ['items'=>$items,'total'=>(int)$result->numberOfRecords,'staff'=>$this->staff];
    }); }

    public function postPackage($req,$res) { return $this->call($req,$res,function($body) {
        $slug=MarketplaceRules::slug($body->slug ?? '');
        $package=$this->db->one('SELECT * FROM lmp_package WHERE slug=?','s',[$slug]);
        if (!$package) throw new MarketplaceException('Package not found.');
        $enrolment=$this->profile?$this->db->one('SELECT * FROM lmp_enrolment WHERE package_id=? AND profile_id=?','ii',[(int)$package->id,$this->profile]):null;
        $attempt=$enrolment?$this->db->one('SELECT * FROM lmp_attempt WHERE id=?','i',[(int)$enrolment->current_attempt_id]):null;
        if ($package->status!=='published' && !$attempt) throw new MarketplaceException('Package is unavailable for enrolment.');
        $terms=$attempt && !in_array($attempt->status,['rejected','cancelled'],true)?json_decode($attempt->snapshot_json):$this->terms($package);
        if (!$terms) throw new MarketplaceException('Package terms are unavailable.');
        $balance=null; $walletError='';
        if ($this->profile && $terms->credit_price>0) { try { $balance=(new \davvag_credit_points\CreditLedgerService($this->db))->summary($this->profile,$terms->program_code); } catch (\Throwable $error) { $walletError='The credit wallet is unavailable.'; } }
        $accessible=[];
        if ($this->profile) { require_once TENANT_RESOURCE_LOCATION . '/apps/lesson-manager/lib/LessonAccess.php'; $access=new \lesson_manager\LessonAccess(); foreach ($terms->lesson_ids as $id) { $lesson=$this->catalog->one('lesson_manager_lesson',$id); if ($lesson && $access->financiallyCovered($lesson,$this->profile)) $accessible[]=(int)$id; } }
        return ['id'=>(int)$package->id,'slug'=>$slug,'status'=>$package->status,'version_id'=>(int)($attempt && !in_array($attempt->status,['rejected','cancelled'],true)?$attempt->version_id:$package->published_version_id),'terms'=>$terms,'attempt'=>$this->engine()->safeAttempt($attempt),'signed_in'=>$this->profile>0,'balance'=>$balance,'wallet_error'=>$walletError,'already_accessible'=>$accessible];
    }); }

    public function postRequestEnrolment($req,$res) { return $this->call($req,$res,function($body) {
        $this->only($body,['package_id','version_id','operation_key','previous_attempt_id']);
        return $this->engine()->request($this->profile,$this->id($body,'package_id'),$this->id($body,'version_id'),$body->operation_key ?? '',MarketplaceRules::integer($body->previous_attempt_id ?? 0,'Previous attempt',0));
    },true); }
    public function postConfirmEnrolment($req,$res) { return $this->call($req,$res,function($body) { $this->only($body,['attempt_id','version_id']); return $this->engine(true)->confirm($this->profile,$this->id($body,'attempt_id'),$this->id($body,'version_id')); },true); }
    public function postCancelEnrolment($req,$res) { return $this->call($req,$res,function($body) { $this->only($body,['attempt_id']); return $this->engine()->decide($this->profile,$this->id($body,'attempt_id'),'cancel','','',function($p,$e) { if ((int)$e->profile_id!==$this->profile) throw new MarketplaceException('Request not found.'); }); },true); }
    public function postDecideEnrolment($req,$res) { return $this->call($req,$res,function($body) {
        $this->only($body,['attempt_id','action','reason','internal_note']); $action=$body->action ?? ''; if (!in_array($action,['approve','reject'],true)) throw new MarketplaceException('Choose approve or reject.');
        return $this->engine()->decide($this->profile,$this->id($body,'attempt_id'),$action,MarketplaceRules::text($body->reason ?? '','Reason',2000),MarketplaceRules::text($body->internal_note ?? '','Internal note',4000),function($p){$this->manage($p);});
    },true,true); }
    private function only($body,$fields) { if (array_diff(array_keys(get_object_vars($body)),$fields)) throw new MarketplaceException('Unexpected request fields. Refresh before continuing.'); }

    public function postMyEnrolments($req,$res) { return $this->call($req,$res,function($body) { return $this->enrolments($body,false); },true); }
    public function postAdminEnrolments($req,$res) { return $this->call($req,$res,function($body) { return $this->enrolments($body,true); },true,true); }
    private function enrolments($body,$staff)
    {
        [$limit,$offset]=$this->page($body); $status=MarketplaceRules::text($body->status ?? '','Status',30);
        $where=$staff?($this->admin?'1=1':'p.owner_profile_id=?'):'e.profile_id=?'; $params=$staff && $this->admin?[]:[$this->profile]; $types=count($params)?'i':'';
        if ($status!=='') { if (!in_array($status,['pending_approval','awaiting_payment','active','rejected','cancelled'],true)) throw new MarketplaceException('Unknown request status.'); $where.=' AND a.status=?';$params[]=$status;$types.='s'; }
        $sql=' FROM lmp_attempt a JOIN lmp_enrolment e ON e.id=a.enrolment_id JOIN lmp_package p ON p.id=e.package_id WHERE '.$where;
        $count=$this->db->one('SELECT COUNT(*) total'.$sql,$types,$params);
        $rows=$this->db->all('SELECT a.*,e.profile_id,p.slug,p.id package_id'.$sql.' ORDER BY a.id DESC LIMIT ? OFFSET ?',$types.'ii',array_merge($params,[$limit,$offset]));
        $items=[]; foreach($rows as $row) { $safe=$this->engine()->safeAttempt($row); if ($staff) { $safe->internal_note=$row->internal_note; $learner=$this->catalog->one('profile',$row->profile_id); $safe->learner_name=$learner->name ?? ('Learner #'.$row->profile_id); } $items[]=$safe; }
        return ['items'=>$items,'total'=>(int)$count->total];
    }

    public function postAdminPackages($req,$res) { return $this->call($req,$res,function($body) {
        [$limit,$offset]=$this->page($body); $conditions=[]; if (!$this->admin) $conditions[]=['column'=>'owner_profile_id','operator'=>'=','value'=>$this->profile];
        $search=MarketplaceRules::text($body->search ?? '','Search',100); if ($search!=='') $conditions[]=['column'=>'name','operator'=>'LIKE','value'=>'%'.$search.'%'];
        $r=\SOSSData::Query('lmp_package',['conditions'=>$conditions,'pageSize'=>$limit,'pageFrom'=>$offset]); if(!$r->success) throw new MarketplaceException('Packages could not be loaded.');
        foreach($r->result as $item) unset($item->draft_json); return ['items'=>$r->result,'total'=>(int)$r->numberOfRecords];
    },true,true); }
    public function postAdminPackage($req,$res) { return $this->call($req,$res,function($body) {
        $package=$this->manage($this->packageById($this->id($body))); $package->draft=json_decode($package->draft_json); unset($package->draft_json); $package->published=$this->terms($package); return $package;
    },true,true); }

    public function postSavePackage($req,$res) { return $this->call($req,$res,function($body) {
        $this->only($body,['id','revision','slug','name','summary','description','cover_image','outcomes','audience','prerequisites','product_id','lesson_ids','pricing_mode','credit_price','approval_required']);
        $draft=$this->catalog->validateDraft($body); $slug=MarketplaceRules::slug($body->slug ?? ''); $id=MarketplaceRules::integer($body->id ?? 0,'Package ID',0); $revision=MarketplaceRules::integer($body->revision ?? 0,'Revision',0);
        return $this->db->transaction(function($db) use($draft,$slug,$id,$revision) {
            $now=date('Y-m-d H:i:s');
            if ($id) { $stored=$this->manage($db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[$id])); if ((int)$stored->draft_revision!==$revision) throw new MarketplaceException('Another editor changed this draft. Reload before saving.'); if ($stored->slug!==$slug || (int)$stored->product_id!==(int)$draft->product_id) throw new MarketplaceException('The stable code and linked product cannot change.'); if ($stored->status==='archived') throw new MarketplaceException('Archived packages cannot be edited.');
                $db->updateById('lmp_package',$id,['draft_json'=>json_encode($draft),'draft_revision'=>$revision+1,'updated_at'=>$now]);
            } else {
                $duplicate=$db->one('SELECT id FROM lmp_package WHERE slug=? OR product_id=? FOR UPDATE','si',[$slug,(int)$draft->product_id]); if($duplicate) throw new MarketplaceException('The package code or product is already linked to a package.');
                $id=$db->insert('lmp_package',['slug'=>$slug,'product_id'=>(int)$draft->product_id,'owner_profile_id'=>$this->profile,'status'=>'draft','draft_json'=>json_encode($draft),'draft_revision'=>1,'published_version_id'=>0,'name'=>$draft->name,'created_at'=>$now,'updated_at'=>$now]);
            }
            return ['id'=>(int)$id,'revision'=>$revision+1];
        });
    },true,true); }

    public function postPublishPackage($req,$res) { return $this->call($req,$res,function($body) {
        $id=$this->id($body); $revision=$this->id($body,'revision'); $package=$this->manage($this->packageById($id)); $draft=json_decode($package->draft_json); $this->catalog->validateDraft($draft);
        $programCode=''; if($draft->credit_price>0) { $ledger=new \davvag_credit_points\CreditLedgerService($this->db); $configured=getenv('DAVVAG_LMP_CREDIT_PROGRAM'); $program=$configured?$ledger->program($configured):$ledger->program(); $programCode=$program->code; }
        return $this->db->transaction(function($db) use($id,$revision,$programCode) {
            $package=$this->manage($db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[$id])); if ((int)$package->draft_revision!==$revision || $package->status==='archived') throw new MarketplaceException('Draft changed or package is archived.');
            $draft=json_decode($package->draft_json); $this->catalog->deliverable($draft,$db); $draft->program_code=$programCode; $draft->slug=$package->slug;
            $number=$db->one('SELECT COALESCE(MAX(version_number),0)+1 next_number FROM lmp_version WHERE package_id=?','i',[$id]); $json=json_encode($draft);
            $version=$db->insert('lmp_version',['package_id'=>$id,'version_number'=>(int)$number->next_number,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json),'created_by'=>$this->profile,'created_at'=>date('Y-m-d H:i:s')]);
            foreach($draft->lessons as $order=>$lesson) $db->insert('lmp_version_lesson',['version_id'=>(int)$version,'lesson_id'=>(int)$lesson->id,'course_id'=>(int)$lesson->course_id,'subject_id'=>(int)$lesson->subject_id,'display_order'=>$order+1]);
            $db->updateById('lmp_package',$id,['status'=>'published','published_version_id'=>(int)$version,'name'=>$draft->name,'published_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]); return ['id'=>$id,'version_id'=>$version,'status'=>'published'];
        });
    },true,true); }
    public function postSetPackageStatus($req,$res) { return $this->call($req,$res,function($body) {
        $id=$this->id($body); $status=$body->status ?? ''; if(!in_array($status,['unpublished','archived'],true)) throw new MarketplaceException('Use Publish to publish a validated draft.');
        return $this->db->transaction(function($db) use($id,$status) { $package=$this->manage($db->one('SELECT * FROM lmp_package WHERE id=? FOR UPDATE','i',[$id])); if($package->status==='archived') throw new MarketplaceException('Package is already archived.'); $db->updateById('lmp_package',$id,['status'=>$status,'updated_at'=>date('Y-m-d H:i:s')]); return ['id'=>$id,'status'=>$status]; });
    },true,true); }

    public function postLookups($req,$res) { return $this->call($req,$res,function($body) {
        [$limit,$offset]=$this->page($body); $kind=$body->kind ?? 'lessons';
        if($kind==='products') { $result=$this->catalog->rows('products',[],$limit,$offset); $items=[];foreach($result->result as $p)$items[]=['id'=>(int)$p->itemid,'name'=>$p->name]; return ['items'=>$items,'total'=>$result->numberOfRecords]; }
        $conditions=[]; if(!$this->admin)$conditions['teacher_id']=$this->profile; $subjects=$this->catalog->rows('course_manager_subject',$conditions,$limit,$offset); $items=[];
        foreach($subjects->result as $subject) { $lessons=$this->catalog->rows('lesson_manager_lesson',['subject_id'=>(int)$subject->id],1000)->result; foreach($lessons as $lesson) if(strtolower($lesson->status ?? '')!=='deleted') $items[]=['id'=>(int)$lesson->id,'name'=>$lesson->title,'status'=>$lesson->status,'subject'=>$subject->name ?? $subject->title ?? ('Subject #'.$subject->id),'course_id'=>(int)$subject->course_id]; }
        return ['items'=>$items,'total'=>$subjects->numberOfRecords];
    },true,true); }

    public function postCreateProduct($req,$res) { return $this->call($req,$res,function($body) use($res) {
        $this->appPermission('productapp','product','Save');
        $name=MarketplaceRules::text($body->name ?? '','Product name',200,true);
        require_once TENANT_RESOURCE_LOCATION . '/apps/productapp/services/product/service.php';
        $request=new MarketplaceRequest((object)['name'=>$name,'caption'=>'Lesson package','price'=>0,'showonstore'=>'N']);
        $product=(new \ProductService())->postSave($request,$res); if(!$product || empty($product->itemid)) throw new MarketplaceException('Product could not be created.'); return ['id'=>(int)$product->itemid,'name'=>$name];
    },true,true); }

    private function appPermission($app,$component,$operation)
    {
        $group=json_decode(file_get_contents(TENANT_RESOURCE_LOCATION.'/'.GROUPID.'.json'));
        $permission=\Auth::GetAccess(GROUPID,$app,'service',$component,$operation);
        if (!isset($group->apps->$app) || !$permission || isset($permission->error)) throw new MarketplaceException('You need access to '.$app.' for this action.');
    }
    private function cms()
    {
        $this->appPermission('davvag-cms-v7-setting','settings-api','SavePage');
        require_once TENANT_RESOURCE_LOCATION.'/apps/davvag-cms-v7-setting/services/settings-api/service.php';
        return new \davvag_cms_v7_setting\CmsV7SettingsApi();
    }
    public function postCmsPages($req,$res) { return $this->call($req,$res,function() use($res) { return $this->cms()->getPages(null,$res); },true,true); }
    public function postPlaceOnPage($req,$res) { return $this->call($req,$res,function($body) use($res) {
        $package=$this->manage($this->packageById($this->id($body))); $slug=MarketplaceRules::slug($body->page_slug ?? ''); $cms=$this->cms();
        $path=TENANT_RESOURCE_LOCATION.'/apps/davvag-cms-v7/content/pages/'.$slug.'.json';
        $page=is_file($path)?json_decode(file_get_contents($path)):null;
        if (!$page) $page=(object)['slug'=>$slug,'path'=>'/'.$slug,'title'=>MarketplaceRules::text($body->page_title ?? '','Page title',200,true),'status'=>'draft','sections'=>[]];
        $input=htmlspecialchars(json_encode(['slug'=>$package->slug]),ENT_QUOTES,'UTF-8');
        $markup='<div webdock-component="package-card" webdock-app="lesson-market-place" webdock-data="'.$input.'"></div>';
        $exists=false; foreach($page->sections ?? [] as $section) if(($section->type ?? '')==='html' && ($section->html ?? '')===$markup)$exists=true;
        if (!$exists) { $page->sections[]=(object)['type'=>'html','html'=>$markup]; $saved=$cms->postSavePage(new MarketplaceRequest($page),$res); if(!$saved) throw new MarketplaceException('CMS page could not be saved.'); }
        return ['slug'=>$slug,'path'=>$page->path,'status'=>$page->status,'markup'=>$markup];
    },true,true); }
}

class MarketplaceRequest { private $data; public function __construct($data){$this->data=$data;} public function Body($decode=true){return $this->data;} }
