<?php
namespace davvag_developer;

require_once dirname(__DIR__, 2) . '/lib/DeploymentManager.php';

use DavvagDeveloper\DeploymentConfig;
use DavvagDeveloper\DeploymentManager;
use RuntimeException;
use Throwable;

class DeploymentApiService {
    private $manager;
    public function __construct($options = array()) {
        $this->manager = new DeploymentManager(new DeploymentConfig(TENANT_RESOURCE_LOCATION, dirname(__DIR__, 2)));
    }
    public function getBootstrap($req,$res){return $this->run($res,function(){ $this->authorize(); $data=$this->manager->bootstrap();$data['csrf']=$this->csrf();return$data;});}
    public function postAnalyze($req,$res){return $this->run($res,function()use($req){$this->authorize();$body=$this->body($req);$this->verifyCsrf($body);return$this->manager->analyze(isset($body['applications'])?$body['applications']:array());});}
    public function postBuild($req,$res){return $this->run($res,function()use($req){$this->authorize();$body=$this->body($req);$this->verifyCsrf($body);return$this->manager->build($body);});}
    public function getHistory($req,$res){return $this->run($res,function(){ $this->authorize();return$this->manager->history();});}
    public function postUploadPackage($req,$res){return $this->run($res,function(){ $this->authorize();$this->verifyCsrf($_POST);if(empty($_FILES['package'])||!isset($_FILES['package']['tmp_name']))throw new RuntimeException('Select a package ZIP.');if($_FILES['package']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Package upload failed with code '.$_FILES['package']['error'].'.');return$this->manager->importer()->upload($_FILES['package']['tmp_name'],$_FILES['package']['name']);});}
    public function postPlanInstall($req,$res){return $this->run($res,function()use($req){$this->authorize();$body=$this->body($req);$this->verifyCsrf($body);return$this->manager->importer()->plan(isset($body['token'])?$body['token']:'');});}
    public function postInstallPackage($req,$res){return $this->run($res,function()use($req){$this->authorize();$body=$this->body($req);$this->verifyCsrf($body);return$this->manager->importer()->install(isset($body['token'])?$body['token']:'',$body);});}
    public function getDownload($req,$res){$this->authorize();$build=isset($_GET['build'])?$_GET['build']:'';if(!preg_match('/^[0-9]{14}-[a-f0-9]{12}$/',$build))throw new RuntimeException('Invalid build ID.');$artifact=$this->manager->artifact($build);while(ob_get_level())ob_end_clean();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.str_replace('"','',$artifact['name']).'"');header('Content-Length: '.filesize($artifact['path']));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($artifact['path']);exit;}
    private function run($res,$callback){try{return$callback();}catch(Throwable$e){http_response_code($e instanceof RuntimeException?400:500);$res->SetError($e->getMessage());return null;}}
    private function body($req){$body=$req->Body(true);return json_decode(json_encode($body),true)?:array();}
    private function authorize(){if(!defined('GROUPID')||GROUPID!=='sysadmin')throw new RuntimeException('Deployment Manager requires the sysadmin group.');}
    private function csrf(){if(session_status()===PHP_SESSION_NONE)session_start();if(empty($_SESSION['davvag_deployment_csrf']))$_SESSION['davvag_deployment_csrf']=bin2hex(random_bytes(24));return$_SESSION['davvag_deployment_csrf'];}
    private function verifyCsrf($data){$provided=is_array($data)&&isset($data['csrf'])?(string)$data['csrf']:'';if(!hash_equals($this->csrf(),$provided))throw new RuntimeException('CSRF validation failed.');}
}

