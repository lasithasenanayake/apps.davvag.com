<?php
namespace davvag_cms_v7_setting;

class CmsV7SettingsApi {
    private function targetRoot(){
        return TENANT_RESOURCE_LOCATION . "/apps/davvag-cms-v7";
    }

    private function pagesRoot(){
        return $this->targetRoot() . "/content/pages";
    }

    private function uploadsRoot(){
        return $this->targetRoot() . "/assets/uploads";
    }

    private function partialsRoot(){
        return $this->targetRoot() . "/partials";
    }

    private function ensureFolder($path){
        if(!file_exists($path)){
            mkdir($path, 0777, true);
        }
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

    private function writeJson($path, $data){
        $this->ensureFolder(dirname($path));
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        return file_put_contents($path, json_encode($data, $flags)) !== false;
    }

    private function cleanSlug($value, $fallback = "page"){
        $value = isset($value) ? strtolower(trim($value)) : "";
        $value = preg_replace("/[^a-z0-9\\-]+/", "-", $value);
        $value = trim($value, "-");
        return $value === "" ? $fallback : $value;
    }

    private function cleanAppCode($value){
        $value = isset($value) ? strtolower(trim($value)) : "";
        $value = preg_replace("/[^a-z0-9_\\.\\-]+/", "-", $value);
        $value = trim($value, ".-");
        return $value;
    }

    private function cleanPath($value, $slug){
        $value = isset($value) ? trim($value) : "";
        if($value === ""){
            return $slug === "home" ? "/" : "/" . $slug;
        }
        $value = str_replace("\\", "/", $value);
        $value = preg_replace("/\\.\\.+/", "", $value);
        if(substr($value, 0, 1) !== "/"){
            $value = "/" . $value;
        }
        return $value;
    }

    private function text($value, $fallback = "", $max = 4000){
        if(!isset($value)){
            return $fallback;
        }
        $value = trim((string)$value);
        if(strlen($value) > $max){
            $value = substr($value, 0, $max);
        }
        return $value;
    }

    private function number($value, $fallback, $min, $max){
        if(!isset($value) || !is_numeric($value)){
            return $fallback;
        }
        $value = (float)$value;
        if($value < $min){
            return $min;
        }
        if($value > $max){
            return $max;
        }
        return $value;
    }

    private function links($value){
        $items = array();
        if(is_array($value)){
            foreach($value as $link){
                if(!is_object($link)){
                    continue;
                }
                $label = $this->text(isset($link->label) ? $link->label : "", "", 120);
                $url = $this->text(isset($link->url) ? $link->url : "", "", 500);
                if($label === "" && $url === ""){
                    continue;
                }
                $item = new \stdClass();
                $item->label = $label;
                $item->url = $url;
                if(isset($link->target)){
                    $item->target = $this->text($link->target, "", 80);
                }
                $this->copyOptional($link, $item, "role");
                $this->copyOptional($link, $item, "roles");
                $this->copyOptional($link, $item, "group");
                $this->copyOptional($link, $item, "groups");
                $this->copyOptional($link, $item, "groupid");
                $this->copyOptional($link, $item, "groupId");
                $this->copyOptional($link, $item, "groupIds");
                $this->copyOptional($link, $item, "allowedRoles");
                $this->copyOptional($link, $item, "visibleFor");
                $this->copyOptional($link, $item, "auth");
                $this->copyOptional($link, $item, "hidden");
                $this->copyOptional($link, $item, "visible");
                $items[] = $item;
            }
        }
        return $items;
    }

    private function copyOptional($source, $target, $name){
        if(isset($source->{$name})){
            $target->{$name} = $source->{$name};
        }
    }

    private function themes($value){
        $items = array();
        if(is_array($value)){
            $index = 1;
            foreach($value as $theme){
                if(!is_object($theme)){
                    continue;
                }
                $item = new \stdClass();
                $item->name = $this->cleanSlug(isset($theme->name) ? $theme->name : "theme-" . $index, "theme-" . $index);
                $item->label = $this->text(isset($theme->label) ? $theme->label : $item->name, $item->name, 120);
                $item->background = $this->text(isset($theme->background) ? $theme->background : "#ffffff", "#ffffff", 80);
                $item->surface = $this->text(isset($theme->surface) ? $theme->surface : "#f6f8fb", "#f6f8fb", 80);
                $item->text = $this->text(isset($theme->text) ? $theme->text : "#18202f", "#18202f", 80);
                $item->muted = $this->text(isset($theme->muted) ? $theme->muted : "#647083", "#647083", 80);
                $item->primary = $this->text(isset($theme->primary) ? $theme->primary : "#0f766e", "#0f766e", 80);
                $item->secondary = $this->text(isset($theme->secondary) ? $theme->secondary : "#2563eb", "#2563eb", 80);
                $item->accent = $this->text(isset($theme->accent) ? $theme->accent : "#e11d48", "#e11d48", 80);
                $item->font = $this->text(isset($theme->font) ? $theme->font : "Arial, Helvetica, sans-serif", "Arial, Helvetica, sans-serif", 200);
                $items[] = $item;
                $index++;
            }
        }
        if(count($items) === 0){
            $default = new \stdClass();
            $default->name = "clean";
            $default->label = "Clean";
            $default->background = "#ffffff";
            $default->surface = "#f6f8fb";
            $default->text = "#18202f";
            $default->muted = "#647083";
            $default->primary = "#0f766e";
            $default->secondary = "#2563eb";
            $default->accent = "#e11d48";
            $default->font = "Arial, Helvetica, sans-serif";
            $items[] = $default;
        }
        return $items;
    }

    private function normalizeSite($data){
        if(!is_object($data)){
            $data = new \stdClass();
        }
        $site = new \stdClass();
        $site->name = $this->text(isset($data->name) ? $data->name : "Davvag CMS v7", "Davvag CMS v7", 180);
        $site->tagline = $this->text(isset($data->tagline) ? $data->tagline : "", "", 500);
        $site->logo = $this->text(isset($data->logo) ? $data->logo : "", "", 500);
        $site->favicon = $this->text(isset($data->favicon) ? $data->favicon : "assets/davvag-cms-v7/favicon.svg", "assets/davvag-cms-v7/favicon.svg", 500);
        $site->theme = $this->cleanSlug(isset($data->theme) ? $data->theme : "clean", "clean");

        $startupSource = isset($data->startup) && is_object($data->startup) ? $data->startup : new \stdClass();
        $startup = new \stdClass();
        $startup->mode = isset($startupSource->mode) && $startupSource->mode === "app" ? "app" : "page";
        if($startup->mode === "app"){
            $startup->appCode = $this->cleanAppCode(isset($startupSource->appCode) ? $startupSource->appCode : "");
            $startup->appRoute = $this->cleanPath(isset($startupSource->appRoute) ? $startupSource->appRoute : "/", "");
            if($startup->appCode === ""){
                $startup->mode = "page";
                $startup->route = "/";
            }
        }else{
            $startup->route = $this->cleanPath(isset($startupSource->route) ? $startupSource->route : "/", "home");
        }
        $site->startup = $startup;

        $navSource = isset($data->nav) && is_object($data->nav) ? $data->nav : new \stdClass();
        $site->nav = new \stdClass();
        $site->nav->source = $this->text(isset($navSource->source) ? $navSource->source : "hybrid", "hybrid", 40);
        $site->nav->launcherAppCode = $this->cleanAppCode(isset($navSource->launcherAppCode) ? $navSource->launcherAppCode : "davvag-cms-v7");
        $site->nav->launcherComponent = $this->cleanAppCode(isset($navSource->launcherComponent) ? $navSource->launcherComponent : "nav-bar");
        if(isset($navSource->dynamic)){
            $site->nav->dynamic = (bool)$navSource->dynamic;
        }
        if(isset($navSource->fallback)){
            $site->nav->fallback = (bool)$navSource->fallback;
        }
        $site->nav->variant = $this->cleanSlug(isset($navSource->variant) ? $navSource->variant : "clean", "clean");
        $site->nav->links = $this->links(isset($navSource->links) ? $navSource->links : array());
        $site->nav->cta = new \stdClass();
        $ctaSource = isset($navSource->cta) && is_object($navSource->cta) ? $navSource->cta : new \stdClass();
        $site->nav->cta->label = $this->text(isset($ctaSource->label) ? $ctaSource->label : "", "", 120);
        $site->nav->cta->url = $this->text(isset($ctaSource->url) ? $ctaSource->url : "", "", 500);

        $footerSource = isset($data->footer) && is_object($data->footer) ? $data->footer : new \stdClass();
        $site->footer = new \stdClass();
        $site->footer->variant = $this->cleanSlug(isset($footerSource->variant) ? $footerSource->variant : "simple", "simple");
        $site->footer->copyright = $this->text(isset($footerSource->copyright) ? $footerSource->copyright : "", "", 300);
        $site->footer->links = $this->links(isset($footerSource->links) ? $footerSource->links : array());
        $site->themes = $this->themes(isset($data->themes) ? $data->themes : array());
        return $site;
    }

    private function normalizePage($data){
        if(!is_object($data)){
            $data = new \stdClass();
        }
        $page = new \stdClass();
        $page->slug = $this->cleanSlug(isset($data->slug) ? $data->slug : "page", "page");
        $page->path = $this->cleanPath(isset($data->path) ? $data->path : "", $page->slug);
        $page->title = $this->text(isset($data->title) ? $data->title : $page->slug, $page->slug, 180);
        $page->status = isset($data->status) && $data->status === "draft" ? "draft" : "published";
        $page->sections = array();

        if(isset($data->sections) && is_array($data->sections)){
            foreach($data->sections as $section){
                if(!is_object($section)){
                    continue;
                }
                $item = new \stdClass();
                $item->type = $this->cleanSlug(isset($section->type) ? $section->type : "text", "text");
                $item->animation = $this->cleanSlug(isset($section->animation) ? $section->animation : "fade-up", "fade-up");
                if($item->type === "hero"){
                    $item->heroMode = $this->cleanSlug(isset($section->heroMode) ? $section->heroMode : "auto-fade", "auto-fade");
                    $item->rotationSeconds = $this->number(isset($section->rotationSeconds) ? $section->rotationSeconds : 6, 6, 2, 30);
                }
                $item->eyebrow = $this->text(isset($section->eyebrow) ? $section->eyebrow : "", "", 180);
                $item->title = $this->text(isset($section->title) ? $section->title : "", "", 260);
                $item->body = $this->text(isset($section->body) ? $section->body : "", "", 12000);
                $item->image = $this->text(isset($section->image) ? $section->image : "", "", 500);
                $item->primaryLabel = $this->text(isset($section->primaryLabel) ? $section->primaryLabel : "", "", 120);
                $item->primaryUrl = $this->text(isset($section->primaryUrl) ? $section->primaryUrl : "", "", 500);
                $item->secondaryLabel = $this->text(isset($section->secondaryLabel) ? $section->secondaryLabel : "", "", 120);
                $item->secondaryUrl = $this->text(isset($section->secondaryUrl) ? $section->secondaryUrl : "", "", 500);
                if($item->type === "features"){
                    $item->items = array();
                    if(isset($section->items) && is_array($section->items)){
                        foreach($section->items as $feature){
                            if(!is_object($feature)){
                                continue;
                            }
                            $featureItem = new \stdClass();
                            $featureItem->title = $this->text(isset($feature->title) ? $feature->title : "", "", 180);
                            $featureItem->body = $this->text(isset($feature->body) ? $feature->body : "", "", 1200);
                            if($featureItem->title !== "" || $featureItem->body !== ""){
                                $item->items[] = $featureItem;
                            }
                        }
                    }
                }
                if($item->type === "html"){
                    $item->html = isset($section->html) ? (string)$section->html : "";
                }
                $page->sections[] = $item;
            }
        }
        return $page;
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
        $summary->sections = isset($page->sections) && is_array($page->sections) ? count($page->sections) : 0;
        return $summary;
    }

    private function pagesList(){
        $this->ensureFolder($this->pagesRoot());
        $items = array();
        foreach(glob($this->pagesRoot() . "/*.json") as $path){
            $summary = $this->pageSummary($path);
            if($summary !== null){
                $items[] = $summary;
            }
        }
        usort($items, function($a, $b){
            if($a->slug === "home"){
                return -1;
            }
            if($b->slug === "home"){
                return 1;
            }
            return strcmp($a->title, $b->title);
        });
        return $items;
    }

    private function assetsList(){
        $this->ensureFolder($this->uploadsRoot());
        $items = array();
        foreach(glob($this->uploadsRoot() . "/*") as $path){
            if(!is_file($path)){
                continue;
            }
            $item = new \stdClass();
            $item->name = basename($path);
            $item->url = "assets/davvag-cms-v7/uploads/" . basename($path);
            $item->size = filesize($path);
            $items[] = $item;
        }
        usort($items, function($a, $b){
            return strcmp($a->name, $b->name);
        });
        return $items;
    }

    private function ensurePartial($slug, $title){
        $folder = $this->partialsRoot() . "/" . $slug;
        $this->ensureFolder($folder);
        $componentPath = $folder . "/component.json";
        if(!file_exists($componentPath)){
            $component = new \stdClass();
            $component->name = $slug;
            $component->description = "Davvag CMS v7 generated " . $title . " partial";
            $component->author = "Davvag";
            $component->version = "0.1";
            $component->resources = new \stdClass();
            $component->resources->files = array();
            $script = new \stdClass();
            $script->type = "mainScript";
            $script->location = "script.js";
            $view = new \stdClass();
            $view->type = "mainView";
            $view->location = "partial.html";
            $component->resources->files[] = $script;
            $component->resources->files[] = $view;
            $component->resources->css = array();
            $css = new \stdClass();
            $css->type = "css";
            $css->location = "style.css";
            $component->resources->css[] = $css;
            $this->writeJson($componentPath, $component);
        }
        if(!file_exists($folder . "/partial.html")){
            file_put_contents($folder . "/partial.html", "<div></div>");
        }
        if(!file_exists($folder . "/script.js")){
            file_put_contents($folder . "/script.js", "WEBDOCK.component().register(function(exports){\n    exports.onReady = function(){};\n});");
        }
        if(!file_exists($folder . "/style.css")){
            file_put_contents($folder . "/style.css", "/* Page content is rendered from content/pages/" . $slug . ".json. */");
        }
    }

    private function updateAppJson($site = null){
        $path = $this->targetRoot() . "/app.json";
        $app = $this->readJson($path, new \stdClass());
        $this->ensureFolder($this->pagesRoot());
        if(!isset($app->components) || !is_object($app->components)){
            $app->components = new \stdClass();
        }
        if(!isset($app->configuration) || !is_object($app->configuration)){
            $app->configuration = new \stdClass();
        }
        if(!isset($app->configuration->webdock) || !is_object($app->configuration->webdock)){
            $app->configuration->webdock = new \stdClass();
        }
        if(!isset($app->configuration->webdock->routes) || !is_object($app->configuration->webdock->routes)){
            $app->configuration->webdock->routes = new \stdClass();
        }
        if(!isset($app->configuration->cmsv7) || !is_object($app->configuration->cmsv7)){
            $app->configuration->cmsv7 = new \stdClass();
        }

        $partials = new \stdClass();
        foreach(glob($this->pagesRoot() . "/*.json") as $pagePath){
            $page = $this->readJson($pagePath, null);
            if($page === null || !isset($page->slug)){
                continue;
            }
            $slug = $this->cleanSlug($page->slug, basename($pagePath, ".json"));
            $component = new \stdClass();
            $component->type = "partial";
            $component->location = "partials";
            $app->components->{$slug} = $component;
            if(!isset($page->status) || $page->status === "published"){
                $route = $this->cleanPath(isset($page->path) ? $page->path : "", $slug);
                $partials->{$route} = $slug;
                if($slug === "home"){
                    $partials->{"/"} = "home";
                    $partials->{"/home"} = "home";
                }
            }
        }

        if(!isset($partials->{"/"})){
            $partials->{"/"} = "home";
        }
        $partials->{"/app/@appName/*appRoute"} = "partial-app";
        $partials->{"/not-found"} = "partial-404";
        $partials->{"/notFound"} = "partial-404";

        $legacyAppPartial = new \stdClass();
        $legacyAppPartial->type = "partial";
        $legacyAppPartial->location = "partials";
        $app->components->{"partial-app"} = $legacyAppPartial;

        $notFoundPartial = new \stdClass();
        $notFoundPartial->type = "partial";
        $notFoundPartial->location = "partials";
        $app->components->{"partial-404"} = $notFoundPartial;
        $app->configuration->webdock->routes->notFound = "/not-found";
        $app->configuration->webdock->routes->partials = $partials;

        if($site !== null && isset($site->startup)){
            $app->configuration->cmsv7->startup = $site->startup;
            if(isset($site->startup->mode) && $site->startup->mode === "page"){
                $app->configuration->webdock->routes->home = isset($site->startup->route) ? $site->startup->route : "/";
            }else{
                $app->configuration->webdock->routes->home = "/";
            }
        }

        if(!isset($app->dependencies) || !is_object($app->dependencies)){
            $app->dependencies = new \stdClass();
        }
        $app->dependencies->apps = isset($app->dependencies->apps) && is_array($app->dependencies->apps) ? $app->dependencies->apps : array();
        $app->dependencies->schemas = isset($app->dependencies->schemas) && is_array($app->dependencies->schemas) ? $app->dependencies->schemas : array();
        $app->dependencies->workflows = isset($app->dependencies->workflows) && is_array($app->dependencies->workflows) ? $app->dependencies->workflows : array();
        $app->dependencies->plugins = isset($app->dependencies->plugins) && is_array($app->dependencies->plugins) ? $app->dependencies->plugins : array();
        $app->dependencies->{"php-extensions"} = isset($app->dependencies->{"php-extensions"}) && is_array($app->dependencies->{"php-extensions"}) ? $app->dependencies->{"php-extensions"} : array();

        return $this->writeJson($path, $app);
    }

    private function safeFileName($name){
        $name = basename((string)$name);
        $name = preg_replace("/[^A-Za-z0-9_\\.\\-]+/", "-", $name);
        $name = trim($name, ".-");
        return $name === "" ? "asset" : $name;
    }

    public function getSite($req, $res){
        $site = $this->readJson($this->targetRoot() . "/content/site.json", new \stdClass());
        $site = $this->normalizeSite($site);
        $site->pages = $this->pagesList();
        $site->assets = $this->assetsList();
        return $site;
    }

    public function postSaveSite($req, $res){
        $site = $this->normalizeSite($req->Body(true));
        if(!$this->writeJson($this->targetRoot() . "/content/site.json", $site)){
            $res->SetError("Unable to write site configuration.");
            return null;
        }
        if(!$this->updateAppJson($site)){
            $res->SetError("Site saved, but app.json could not be updated.");
            return null;
        }
        return $site;
    }

    public function getPages($req, $res){
        return $this->pagesList();
    }

    public function getPage($req, $res){
        $slug = $this->cleanSlug(isset($_GET["slug"]) ? $_GET["slug"] : "home", "home");
        $page = $this->readJson($this->pagesRoot() . "/" . $slug . ".json", null);
        if($page === null){
            $res->SetError("Page not found.");
            return null;
        }
        return $this->normalizePage($page);
    }

    public function postSavePage($req, $res){
        $page = $this->normalizePage($req->Body(true));
        if($page->title === ""){
            $res->SetError("Page title is required.");
            return null;
        }
        $this->ensurePartial($page->slug, $page->title);
        if(!$this->writeJson($this->pagesRoot() . "/" . $page->slug . ".json", $page)){
            $res->SetError("Unable to write page file.");
            return null;
        }
        $site = $this->readJson($this->targetRoot() . "/content/site.json", null);
        $site = $site === null ? null : $this->normalizeSite($site);
        if(!$this->updateAppJson($site)){
            $res->SetError("Page saved, but app.json could not be updated.");
            return null;
        }
        return $page;
    }

    public function getAssets($req, $res){
        return $this->assetsList();
    }

    public function postUploadAsset($req, $res){
        $data = $req->Body(true);
        if(!is_object($data) || !isset($data->filename) || !isset($data->dataUrl)){
            $res->SetError("Upload payload is invalid.");
            return null;
        }

        $fileName = $this->safeFileName($data->filename);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = array("jpg", "jpeg", "png", "gif", "webp", "svg", "ico", "pdf", "txt", "css", "js", "json");
        if(!in_array($extension, $allowed)){
            $res->SetError("This file type is not allowed.");
            return null;
        }

        $dataUrl = (string)$data->dataUrl;
        if(!preg_match("/^data:[^;]+;base64,(.*)$/", $dataUrl, $matches)){
            $res->SetError("Upload data must be base64 encoded.");
            return null;
        }
        $binary = base64_decode($matches[1]);
        if($binary === false){
            $res->SetError("Upload data could not be decoded.");
            return null;
        }
        if(strlen($binary) > 10485760){
            $res->SetError("Asset is larger than 10 MB.");
            return null;
        }

        $this->ensureFolder($this->uploadsRoot());
        $targetName = $fileName;
        $targetPath = $this->uploadsRoot() . "/" . $targetName;
        if(file_exists($targetPath)){
            $targetName = date("YmdHis") . "-" . $fileName;
            $targetPath = $this->uploadsRoot() . "/" . $targetName;
        }
        if(file_put_contents($targetPath, $binary) === false){
            $res->SetError("Unable to write uploaded asset.");
            return null;
        }

        $item = new \stdClass();
        $item->name = $targetName;
        $item->url = "assets/davvag-cms-v7/uploads/" . $targetName;
        $item->size = filesize($targetPath);
        return $item;
    }
}
?>
