<?php
namespace davvag_cms_v7;

class CmsV7Api {
    private function appRoot(){
        return TENANT_RESOURCE_LOCATION . "/apps/davvag-cms-v7";
    }

    private function cleanSlug($value){
        $value = isset($value) ? strtolower(trim($value)) : "home";
        $value = preg_replace("/[^a-z0-9\\-]+/", "-", $value);
        $value = trim($value, "-");
        return $value === "" ? "home" : $value;
    }

    private function readJson($path, $fallback){
        if(!file_exists($path)){
            return $fallback;
        }
        $data = json_decode(file_get_contents($path));
        if($data === null && json_last_error() !== JSON_ERROR_NONE){
            return $fallback;
        }
        return $data;
    }

    private function pageSummary($path){
        $page = $this->readJson($path, null);
        if($page === null){
            return null;
        }
        $summary = new \stdClass();
        $summary->slug = isset($page->slug) ? $page->slug : basename($path, ".json");
        $summary->path = isset($page->path) ? $page->path : "/" . $summary->slug;
        $summary->title = isset($page->title) ? $page->title : $summary->slug;
        $summary->status = isset($page->status) ? $page->status : "published";
        return $summary;
    }

    public function getSite($req, $res){
        $root = $this->appRoot();
        $site = $this->readJson($root . "/content/site.json", new \stdClass());
        $site->pages = array();
        foreach(glob($root . "/content/pages/*.json") as $path){
            $summary = $this->pageSummary($path);
            if($summary !== null && (!isset($summary->status) || $summary->status === "published")){
                $site->pages[] = $summary;
            }
        }
        return $site;
    }

    public function getPage($req, $res){
        $slug = $this->cleanSlug(isset($_GET["slug"]) ? $_GET["slug"] : "home");
        $page = $this->readJson($this->appRoot() . "/content/pages/$slug.json", null);
        if($page === null){
            $res->SetError("Page not found.");
            return null;
        }
        if(isset($page->status) && $page->status !== "published"){
            $res->SetError("Page not found.");
            return null;
        }
        return $page;
    }

    public function getAssets($req, $res){
        $items = array();
        $folder = $this->appRoot() . "/assets/uploads";
        if(file_exists($folder)){
            foreach(glob($folder . "/*") as $path){
                if(is_file($path)){
                    $item = new \stdClass();
                    $item->name = basename($path);
                    $item->url = "assets/davvag-cms-v7/uploads/" . basename($path);
                    $item->size = filesize($path);
                    $items[] = $item;
                }
            }
        }
        return $items;
    }
}
?>
