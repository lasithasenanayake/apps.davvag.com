<?php
namespace image_editor;

if (defined("PLUGIN_PATH")) {
    require_once PLUGIN_PATH . "/sossdata/SOSSData.php";
    require_once PLUGIN_PATH . "/phpcache/cache.php";
    require_once PLUGIN_PATH . "/auth/auth.php";
}
if (defined("PLUGIN_PATH_LOCAL")) require_once PLUGIN_PATH_LOCAL . "/profile/profile.php";
require_once dirname(__DIR__, 2) . "/lib/ImageEditorDocument.php";
require_once dirname(__DIR__, 2) . "/lib/FontInspector.php";
require_once dirname(__DIR__, 2) . "/lib/ImageEditorMediaAccess.php";

class ApiService
{
    private $projectNs = "image_editor_project";
    private $revisionNs = "image_editor_revision";
    private $pageNs = "image_editor_page";
    private $elementNs = "image_editor_element";
    private $assetNs = "image_editor_asset";
    private $fontNs = "image_editor_font";
    private $templateNs = "image_editor_template";

    public function getCapabilities($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null;
        $this->ensureBuiltInFonts();
        return array("authenticated" => true, "profileId" => $profileId, "administrator" => $this->isAdmin(), "templatePublisher" => $this->isAdmin(), "limits" => array("maxSide" => 8192, "maxElements" => 500, "maxAssetBytes" => 15728640, "maxFontBytes" => 10485760));
    }

    public function getPresets($req, $res)
    {
        if (!$this->requireProfile($res)) return null;
        return array("items" => array(
            array("code" => "thumbnail", "name" => "Video thumbnail", "width" => 1280, "height" => 720),
            array("code" => "social", "name" => "Square post", "width" => 1080, "height" => 1080),
            array("code" => "social", "name" => "Portrait post", "width" => 1080, "height" => 1350),
            array("code" => "social", "name" => "Story", "width" => 1080, "height" => 1920),
            array("code" => "social", "name" => "Landscape post", "width" => 1200, "height" => 630),
            array("code" => "presentation", "name" => "Presentation", "width" => 1920, "height" => 1080),
            array("code" => "poster", "name" => "Portrait poster", "width" => 1800, "height" => 2400)
        ));
    }

    public function postListProjects($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $status = $this->enum($this->value($body, "status", "active"), array("active", "archived", "deleted"), "status");
        $rows = $this->rows($this->projectNs, "owner_profile_id:" . $profileId . ",status:" . $status, 500);
        usort($rows, function ($a, $b) { return intval(isset($b->sysversionid) ? $b->sysversionid : 0) - intval(isset($a->sysversionid) ? $a->sysversionid : 0); });
        return $this->paginate($rows, $body, 40, 100);
    }

    public function postCreateProject($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $width = $this->boundedInt($body, "width", 64, 8192); $height = $this->boundedInt($body, "height", 64, 8192);
        if ($width * $height > 40000000) return $this->fail($res, "The canvas contains too many pixels.");
        $project = (object) array("owner_profile_id" => $profileId, "title" => $this->plain($this->value($body, "title", "Untitled design"), 255), "description" => "", "design_type" => $this->enum($this->value($body, "designType", "custom"), array("thumbnail", "poster", "social", "presentation", "custom"), "design type"), "width_px" => $width, "height_px" => $height, "status" => "active", "source_template_id" => 0, "current_revision_id" => 0, "preview_media_reference" => "");
        $project = $this->insert($this->projectNs, $project, $res); if (!$project) return null;
        $document = $this->blankDocument($width, $height);
        $saved = $this->saveDocument($project, $document, "manual", $this->uuid(), 0, $profileId, $res);
        if (!$saved) return null; $project->current_revision_id = $saved["revisionId"];
        return array("project" => $project, "revisionId" => $saved["revisionId"]);
    }

    public function postGetProject($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $revisionId = intval(isset($project->current_revision_id) ? $project->current_revision_id : 0);
        $document = $revisionId ? $this->documentFromRevision($revisionId) : $this->blankDocument(intval($project->width_px), intval($project->height_px));
        if (!$document) return $this->fail($res, "The current design revision could not be loaded.");
        return array("project" => $project, "revisionId" => $revisionId ?: null, "document" => $document);
    }

    public function postRenameProject($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $project->title = $this->plain($this->value($body, "title", ""), 255); if ($project->title === "") return $this->fail($res, "A title is required.");
        return array("project" => $this->update($this->projectNs, $project, $res));
    }

    public function postDuplicateProject($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $source = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$source) return null;
        $document = $this->documentFromRevision(intval($source->current_revision_id)); if (!$document) return $this->fail($res, "The source design could not be loaded.");
        $project = (object) array("owner_profile_id" => $profileId, "title" => $this->plain($source->title . " copy", 255), "description" => isset($source->description) ? $source->description : "", "design_type" => $source->design_type, "width_px" => intval($source->width_px), "height_px" => intval($source->height_px), "status" => "active", "source_template_id" => intval(isset($source->source_template_id) ? $source->source_template_id : 0), "current_revision_id" => 0, "preview_media_reference" => "");
        $project = $this->insert($this->projectNs, $project, $res); if (!$project) return null;
        $saved = $this->saveDocument($project, $document, "manual", $this->uuid(), 0, $profileId, $res); if (!$saved) return null;
        $project->current_revision_id = $saved["revisionId"]; return array("project" => $project);
    }

    public function postChangeProjectStatus($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $next = $this->enum($this->value($body, "status", ""), array("active", "archived", "deleted"), "status");
        $allowed = array("active" => array("archived", "deleted"), "archived" => array("active", "deleted"), "deleted" => array("active"));
        if (!in_array($next, isset($allowed[$project->status]) ? $allowed[$project->status] : array(), true)) return $this->fail($res, "That project state change is not allowed.");
        $project->status = $next; return array("project" => $this->update($this->projectNs, $project, $res));
    }

    public function postSaveRevision($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        if ($project->status !== "active") return $this->fail($res, "Only active projects can be saved.");
        $incoming = isset($body->document) ? $body->document : null;
        try { $document = ImageEditorDocument::normalize($incoming, $project); } catch (\Throwable $e) { return $this->fail($res, $e->getMessage()); }
        $baseRevisionId = intval(isset($incoming->baseRevisionId) ? $incoming->baseRevisionId : 0);
        $clientSaveKey = $this->plain(isset($incoming->clientSaveKey) ? $incoming->clientSaveKey : "", 80);
        if ($clientSaveKey === "") return $this->fail($res, "A client save key is required.");
        $existing = $this->first($this->revisionNs, "project_id:" . intval($project->id) . ",client_save_key:" . $clientSaveKey . ",state:complete");
        if ($existing) return array("revisionId" => intval($existing->id), "revisionNumber" => intval($existing->revision_number), "idempotent" => true);
        $current = intval(isset($project->current_revision_id) ? $project->current_revision_id : 0);
        if ($baseRevisionId !== $current) return $this->fail($res, "This design changed in another editor. Reload before saving again.");
        $type = $this->enum($this->value($body, "revisionType", "manual"), array("manual", "autosave", "restore"), "revision type");
        return $this->saveDocument($project, $document, $type, $clientSaveKey, $baseRevisionId, $profileId, $res);
    }

    public function postListProjectRevisions($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $rows = array_values(array_filter($this->rows($this->revisionNs, "project_id:" . intval($project->id), 200), function ($row) { return $row->state === "complete"; }));
        usort($rows, function ($a, $b) { return intval($b->revision_number) - intval($a->revision_number); });
        return $this->paginate($rows, $body, 30, 100);
    }

    public function postRestoreRevision($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $revision = $this->first($this->revisionNs, "id:" . $this->id($body, "revisionId"));
        if (!$revision || intval($revision->project_id) !== intval($project->id) || $revision->state !== "complete") return $this->fail($res, "The revision was not found.");
        $document = $this->documentFromRevision(intval($revision->id));
        return $this->saveDocument($project, $document, "restore", $this->uuid(), intval($project->current_revision_id), $profileId, $res);
    }

    public function postRegisterAsset($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $project = $this->ownedProject($this->id($body, "projectId"), $profileId, $res); if (!$project) return null;
        $reference = $this->mediaReference($this->value($body, "mediaReference", ""), "image_editor_asset"); $name = basename(parse_url($reference, PHP_URL_PATH));
        if (!ImageEditorMediaAccess::ownsStaged("image_editor_asset", $name, $profileId)) return $this->fail($res, "Upload this image in your current session before registering it.");
        $path = $this->mediaPath("image_editor_asset", $name); if (!$path || !is_file($path)) return $this->fail($res, "The uploaded image could not be verified.");
        if (filesize($path) > 15728640) return $this->fail($res, "Images are limited to 15 MB.");
        $info = @getimagesize($path); $allowed = array("image/png" => "png", "image/jpeg" => "jpg", "image/webp" => "webp");
        if (!$info || !isset($allowed[$info["mime"]])) return $this->fail($res, "Only valid PNG, JPEG, and WebP images are supported.");
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 8192 || $info[1] > 8192 || $info[0] * $info[1] > 40000000) return $this->fail($res, "The image dimensions are unsupported.");
        $asset = (object) array("owner_profile_id" => $profileId, "project_id" => intval($project->id), "media_reference" => $reference, "original_name" => $this->plain(basename($this->value($body, "originalName", $name)), 255), "mime_type" => $info["mime"], "extension" => $allowed[$info["mime"]], "byte_size" => filesize($path), "width_px" => intval($info[0]), "height_px" => intval($info[1]), "asset_type" => "image", "checksum" => hash_file("sha256", $path), "alternative_text" => "", "status" => "active");
        $duplicate = $this->first($this->assetNs, "owner_profile_id:" . $profileId . ",checksum:" . $asset->checksum . ",status:active");
        if ($duplicate) return array("asset" => $duplicate, "duplicate" => true);
        return array("asset" => $this->insert($this->assetNs, $asset, $res));
    }

    public function postListAssets($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $rows = $this->rows($this->assetNs, "owner_profile_id:" . $profileId . ",status:active", 500);
        return $this->paginate($rows, $body, 40, 100);
    }

    public function postChangeAssetStatus($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        $asset = $this->first($this->assetNs, "id:" . $this->id($body, "assetId"));
        if (!$asset || intval($asset->owner_profile_id) !== $profileId) return $this->fail($res, "The asset was not found.");
        $asset->status = $this->enum($this->value($body, "status", "archived"), array("active", "archived", "deleted"), "asset status");
        return array("asset" => $this->update($this->assetNs, $asset, $res));
    }

    public function postRegisterFont($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $body = $this->body($req);
        if (!$this->boolean($body, "attested")) return $this->fail($res, "You must confirm that you have permission to use this font.");
        $reference = $this->mediaReference($this->value($body, "mediaReference", ""), "image_editor_font"); $name = basename(parse_url($reference, PHP_URL_PATH));
        if (!ImageEditorMediaAccess::ownsStaged("image_editor_font", $name, $profileId)) return $this->fail($res, "Upload this font in your current session before registering it.");
        try { $inspection = FontInspector::inspect($this->mediaPath("image_editor_font", $name), $this->value($body, "originalName", $name)); } catch (\Throwable $e) { return $this->fail($res, $e->getMessage()); }
        $duplicate = $this->first($this->fontNs, "owner_profile_id:" . $profileId . ",checksum:" . $inspection["checksum"]);
        if ($duplicate && $duplicate->status !== "deleted") return array("font" => $this->safeFont($duplicate), "duplicate" => true);
        $visibility = $this->value($body, "visibility", "private") === "tenant" && $this->isAdmin() ? "tenant" : "private";
        $family = $inspection["family"] !== "" ? $inspection["family"] : $this->plain($this->value($body, "displayFamily", ""), 160);
        if ($family === "") return $this->fail($res, "A font family name is required.");
        $font = (object) array("owner_profile_id" => $profileId, "source" => "upload", "display_family" => $family, "normalized_family" => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $family)), "subfamily" => $inspection["subfamily"], "postscript_name" => $inspection["postscript_name"], "internal_family" => "", "font_weight" => $inspection["font_weight"], "font_style" => $inspection["font_style"], "font_stretch" => "normal", "format" => $inspection["format"], "mime_type" => $inspection["mime_type"], "byte_size" => $inspection["byte_size"], "checksum" => $inspection["checksum"], "media_reference" => $reference, "visibility" => $visibility, "license_type" => "declared", "license_name" => $this->plain($this->value($body, "licenseName", ""), 160), "license_url" => "", "license_media_reference" => "", "attested_by_profile_id" => $profileId, "attested_at" => $this->now(), "approved_by_profile_id" => $visibility === "private" ? $profileId : 0, "approved_at" => $visibility === "private" ? $this->now() : null, "status" => $visibility === "private" ? "active" : "pending", "rejection_reason" => "", "embedding_flags" => intval($inspection["fs_type"]));
        $font = $this->insert($this->fontNs, $font, $res); if (!$font) return null;
        $font->internal_family = "DAVVAGFont-" . intval($font->id) . "-" . substr($font->checksum, 0, 10); $font = $this->update($this->fontNs, $font, $res);
        return array("font" => $this->safeFont($font));
    }

    public function postListFonts($req, $res)
    {
        $profileId = $this->requireProfile($res); if (!$profileId) return null; $this->ensureBuiltInFonts(); $body = $this->body($req);
        $all = $this->rows($this->fontNs, "", 1000); $admin = $this->isAdmin(); $includePending = $admin && $this->boolean($body, "includePending");
        $safe = array();
        foreach ($all as $font) {
            $visible = $font->source === "builtin" || ($font->visibility === "tenant" && $font->status === "active") || (intval($font->owner_profile_id) === $profileId && ($font->status === "active" || $includePending)) || ($includePending && in_array($font->status, array("pending", "rejected"), true));
            if ($visible) $safe[] = $this->safeFont($font);
        }
        usort($safe, function ($a, $b) { return strcasecmp($a->display_family, $b->display_family); });
        return $this->paginate($safe, $body, 100, 250);
    }

    public function postGetFont($req, $res) { $profileId = $this->requireProfile($res); if (!$profileId) return null; $font = $this->authorizedFont($this->id($this->body($req), "fontId"), $profileId, false); return $font ? array("font" => $this->safeFont($font)) : $this->fail($res, "The font is unavailable."); }
    public function postUpdateFontMetadata($req, $res) { $profileId = $this->requireProfile($res); if (!$profileId) return null; $body=$this->body($req); $font=$this->first($this->fontNs,"id:".$this->id($body,"fontId")); if(!$font||intval($font->owner_profile_id)!==$profileId)return $this->fail($res,"The font was not found."); $font->license_name=$this->plain($this->value($body,"licenseName",$font->license_name),160); return array("font"=>$this->safeFont($this->update($this->fontNs,$font,$res))); }
    public function postRequestTenantFontApproval($req, $res) { $profileId=$this->requireProfile($res);if(!$profileId)return null;$body=$this->body($req);$font=$this->first($this->fontNs,"id:".$this->id($body,"fontId"));if(!$font||intval($font->owner_profile_id)!==$profileId||$font->status!=="active")return $this->fail($res,"The font is not eligible for approval.");$font->visibility="tenant";$font->status="pending";$font->approved_by_profile_id=0;$font->approved_at=null;return array("font"=>$this->safeFont($this->update($this->fontNs,$font,$res))); }
    public function postApproveFont($req, $res) { $admin=$this->requireAdmin($res);if(!$admin)return null;$body=$this->body($req);$font=$this->first($this->fontNs,"id:".$this->id($body,"fontId"));if(!$font||$font->status!=="pending"||$font->visibility!=="tenant")return $this->fail($res,"The pending font was not found.");if((intval($font->embedding_flags)&2)===2)return $this->fail($res,"This font declares restricted embedding rights and cannot be approved for tenant use.");$font->status="active";$font->approved_by_profile_id=$admin;$font->approved_at=$this->now();$font->rejection_reason="";return array("font"=>$this->safeFont($this->update($this->fontNs,$font,$res))); }
    public function postRejectFont($req, $res) { $admin=$this->requireAdmin($res);if(!$admin)return null;$body=$this->body($req);$font=$this->first($this->fontNs,"id:".$this->id($body,"fontId"));if(!$font||$font->status!=="pending")return $this->fail($res,"The pending font was not found.");$font->status="rejected";$font->rejection_reason=$this->plain($this->value($body,"reason","Tenant font approval was declined."),500);return array("font"=>$this->safeFont($this->update($this->fontNs,$font,$res))); }
    public function postArchiveFont($req, $res) { $profileId=$this->requireProfile($res);if(!$profileId)return null;$body=$this->body($req);$font=$this->first($this->fontNs,"id:".$this->id($body,"fontId"));if(!$font||$font->source==="builtin"||(intval($font->owner_profile_id)!==$profileId&&!$this->isAdmin()))return $this->fail($res,"The font was not found.");$font->status="archived";return array("font"=>$this->safeFont($this->update($this->fontNs,$font,$res))); }

    public function postListTemplates($req, $res)
    {
        $profileId=$this->requireProfile($res);if(!$profileId)return null;$body=$this->body($req);$includeDrafts=$this->boolean($body,"includeDrafts")&&$this->isAdmin();$rows=$this->rows($this->templateNs,"",500);$rows=array_values(array_filter($rows,function($row)use($includeDrafts){return $includeDrafts||($row->status==="published"&&$row->visibility==="tenant");}));return $this->paginate($rows,$body,40,100);
    }
    public function postGetTemplate($req,$res){$profileId=$this->requireProfile($res);if(!$profileId)return null;$template=$this->first($this->templateNs,"id:".$this->id($this->body($req),"templateId"));if(!$template||($template->status!=="published"&&!$this->isAdmin()))return $this->fail($res,"The template was not found.");return array("template"=>$template,"document"=>$this->documentFromRevision(intval($template->published_revision_id)));}
    public function postCreateProjectFromTemplate($req,$res){$profileId=$this->requireProfile($res);if(!$profileId)return null;$template=$this->first($this->templateNs,"id:".$this->id($this->body($req),"templateId"));if(!$template||$template->status!=="published"||$template->visibility!=="tenant")return $this->fail($res,"The template is unavailable.");$document=$this->documentFromRevision(intval($template->published_revision_id));if(!$document)return $this->fail($res,"The published template revision is unavailable.");$source=$this->first($this->projectNs,"id:".intval($template->source_project_id));if(!$source)return $this->fail($res,"The template source is unavailable.");$project=(object)array("owner_profile_id"=>$profileId,"title"=>$this->plain($template->title,255),"description"=>isset($template->description)?$template->description:"","design_type"=>"custom","width_px"=>intval($template->width_px),"height_px"=>intval($template->height_px),"status"=>"active","source_template_id"=>intval($template->id),"current_revision_id"=>0,"preview_media_reference"=>isset($template->preview_media_reference)?$template->preview_media_reference:"");$project=$this->insert($this->projectNs,$project,$res);if(!$project)return null;$document=$this->cloneTemplateAssets($document,intval($source->owner_profile_id),$profileId,intval($project->id),$res);if(!$document)return null;$saved=$this->saveDocument($project,$document,"manual",$this->uuid(),0,$profileId,$res);if(!$saved)return null;$project->current_revision_id=$saved["revisionId"];return array("project"=>$project);}
    public function postCreateTemplateDraft($req,$res){$admin=$this->requireAdmin($res);if(!$admin)return null;$body=$this->body($req);$project=$this->ownedProject($this->id($body,"projectId"),$admin,$res);if(!$project)return null;if(intval($project->current_revision_id)<1)return $this->fail($res,"Save the project before creating a template.");$template=(object)array("source_project_id"=>intval($project->id),"published_revision_id"=>0,"title"=>$this->plain($this->value($body,"title",$project->title),255),"description"=>"","category"=>$this->plain($this->value($body,"category","General"),80),"tags_json"=>array(),"width_px"=>intval($project->width_px),"height_px"=>intval($project->height_px),"preview_media_reference"=>isset($project->preview_media_reference)?$project->preview_media_reference:"","status"=>"draft","visibility"=>"tenant","created_by_profile_id"=>$admin,"published_by_profile_id"=>0,"published_at"=>null);return array("template"=>$this->insert($this->templateNs,$template,$res));}
    public function postUpdateTemplateMetadata($req,$res){$admin=$this->requireAdmin($res);if(!$admin)return null;$body=$this->body($req);$template=$this->first($this->templateNs,"id:".$this->id($body,"templateId"));if(!$template)return $this->fail($res,"The template was not found.");$template->title=$this->plain($this->value($body,"title",$template->title),255);$template->description=$this->plain($this->value($body,"description",$template->description),2000);$template->category=$this->plain($this->value($body,"category",$template->category),80);return array("template"=>$this->update($this->templateNs,$template,$res));}
    public function postPublishTemplate($req,$res){$admin=$this->requireAdmin($res);if(!$admin)return null;$body=$this->body($req);$template=$this->first($this->templateNs,"id:".$this->id($body,"templateId"));if(!$template)return $this->fail($res,"The template was not found.");$project=$this->first($this->projectNs,"id:".intval($template->source_project_id));if(!$project||intval($project->current_revision_id)<1)return $this->fail($res,"The template source project is unavailable.");$document=$this->documentFromRevision(intval($project->current_revision_id));if(!$this->validateDocumentReferences($document,intval($project->owner_profile_id),$res,true))return null;$template->published_revision_id=intval($project->current_revision_id);$template->status="published";$template->published_by_profile_id=$admin;$template->published_at=$this->now();$template->width_px=intval($project->width_px);$template->height_px=intval($project->height_px);return array("template"=>$this->update($this->templateNs,$template,$res));}
    public function postUnpublishTemplate($req,$res){return $this->templateStatus($req,$res,"unpublished");}
    public function postArchiveTemplate($req,$res){return $this->templateStatus($req,$res,"archived");}

    private function templateStatus($req,$res,$status){$admin=$this->requireAdmin($res);if(!$admin)return null;$template=$this->first($this->templateNs,"id:".$this->id($this->body($req),"templateId"));if(!$template)return $this->fail($res,"The template was not found.");$template->status=$status;return array("template"=>$this->update($this->templateNs,$template,$res));}

    private function saveDocument($project, $input, $type, $saveKey, $baseRevisionId, $profileId, $res)
    {
        try { $document = ImageEditorDocument::normalize($input, $project); } catch (\Throwable $e) { return $this->fail($res, $e->getMessage()); }
        if (!$this->validateDocumentReferences($document, $profileId, $res, false)) return null;
        $revisions = $this->rows($this->revisionNs, "project_id:" . intval($project->id), 1000); $number = 1;
        foreach ($revisions as $row) $number = max($number, intval($row->revision_number) + 1);
        $revision = (object) array("project_id" => intval($project->id), "revision_number" => $number, "revision_type" => $type, "state" => "writing", "client_save_key" => $saveKey, "base_revision_id" => intval($baseRevisionId), "document_version" => ImageEditorDocument::VERSION, "renderer_version" => "fabric-7.4.0", "label" => $type === "manual" ? "Manual save" : ucfirst($type), "checksum" => ImageEditorDocument::checksum($document), "saved_by_profile_id" => $profileId);
        $revision = $this->insert($this->revisionNs, $revision, $res); if (!$revision) return null;
        try {
            $page = $document->pages[0];
            $pageRow = (object) array("revision_id" => intval($revision->id), "page_uuid" => $page->pageUuid, "page_number" => 1, "width_px" => $page->width, "height_px" => $page->height, "background_json" => $page->background);
            $pageRow = $this->insert($this->pageNs, $pageRow, $res); if (!$pageRow) throw new \RuntimeException("The design page could not be saved.");
            foreach ($page->elements as $element) {
                $row = (object) array("page_id" => intval($pageRow->id), "element_uuid" => $element->elementUuid, "parent_uuid" => "", "element_type" => $element->type, "name" => $element->name, "z_index" => intval($element->zIndex), "is_locked" => $element->locked, "is_hidden" => $element->hidden, "properties_json" => $element->properties);
                if (!$this->insert($this->elementNs, $row, $res)) throw new \RuntimeException("A design element could not be saved.");
            }
            $storedPages = $this->rows($this->pageNs, "revision_id:" . intval($revision->id), 10);
            $storedElements = $this->rows($this->elementNs, "page_id:" . intval($pageRow->id), ImageEditorDocument::MAX_ELEMENTS + 1);
            if (count($storedPages) !== 1 || count($storedElements) !== count($page->elements)) throw new \RuntimeException("The saved design failed its integrity check.");
            $revision->state = "complete"; if (!$this->update($this->revisionNs, $revision, $res)) throw new \RuntimeException("The design revision could not be finalized.");
            $project->current_revision_id = intval($revision->id); if (!$this->update($this->projectNs, $project, $res)) throw new \RuntimeException("The project could not point to its completed revision.");
            return array("revisionId" => intval($revision->id), "revisionNumber" => $number, "checksum" => $revision->checksum, "idempotent" => false);
        } catch (\Throwable $e) {
            $revision->state = "failed"; \SOSSData::Update($this->revisionNs, $revision);
            return $this->fail($res, $e->getMessage());
        }
    }

    private function validateDocumentReferences($document, $profileId, $res, $forTemplate)
    {
        foreach (ImageEditorDocument::assetIds($document) as $id) { $asset=$this->first($this->assetNs,"id:".intval($id)); if(!$asset||intval($asset->owner_profile_id)!==intval($profileId)||$asset->status!=="active")return $this->fail($res,"A referenced image asset is unavailable.")!==null; }
        foreach (ImageEditorDocument::fontIds($document) as $id) { $font=$this->authorizedFont($id,$profileId,$forTemplate); if(!$font)return $this->fail($res,$forTemplate?"Templates may use only active built-in or tenant fonts.":"A referenced font is unavailable.")!==null; }
        return true;
    }

    private function cloneTemplateAssets($document,$sourceOwner,$newOwner,$projectId,$res)
    {
        $mapped=array();
        foreach(ImageEditorDocument::assetIds($document) as $assetId){
            $asset=$this->first($this->assetNs,"id:".intval($assetId));
            if(!$asset||intval($asset->owner_profile_id)!==intval($sourceOwner)||$asset->status!=="active"){ $this->fail($res,"A template image asset is unavailable."); return null; }
            $existing=$this->first($this->assetNs,"owner_profile_id:".intval($newOwner).",checksum:".$asset->checksum.",status:active");
            if($existing){$mapped[intval($assetId)]=intval($existing->id);continue;}
            $copy=(object)array("owner_profile_id"=>intval($newOwner),"project_id"=>intval($projectId),"media_reference"=>$asset->media_reference,"original_name"=>$asset->original_name,"mime_type"=>$asset->mime_type,"extension"=>$asset->extension,"byte_size"=>intval($asset->byte_size),"width_px"=>intval($asset->width_px),"height_px"=>intval($asset->height_px),"asset_type"=>$asset->asset_type,"checksum"=>$asset->checksum,"alternative_text"=>isset($asset->alternative_text)?$asset->alternative_text:"","status"=>"active");
            $copy=$this->insert($this->assetNs,$copy,$res);if(!$copy)return null;$mapped[intval($assetId)]=intval($copy->id);
        }
        foreach($document->pages[0]->elements as $element)if($element->type==="image")$element->properties->assetId=$mapped[intval($element->properties->assetId)];
        return $document;
    }

    private function documentFromRevision($revisionId)
    {
        $revision=$this->first($this->revisionNs,"id:".intval($revisionId));if(!$revision||$revision->state!=="complete")return null;$pages=$this->rows($this->pageNs,"revision_id:".intval($revisionId),10);if(!$pages)return null;$page=$pages[0];$elements=$this->rows($this->elementNs,"page_id:".intval($page->id),600);usort($elements,function($a,$b){return intval($a->z_index)-intval($b->z_index);});$out=array();foreach($elements as $element)$out[]=(object)array("elementUuid"=>$element->element_uuid,"type"=>$element->element_type,"name"=>$element->name,"zIndex"=>intval($element->z_index),"locked"=>$this->truth(isset($element->is_locked)?$element->is_locked:false),"hidden"=>$this->truth(isset($element->is_hidden)?$element->is_hidden:false),"properties"=>$this->decodeObject($element->properties_json));return (object)array("documentVersion"=>intval($revision->document_version),"pages"=>array((object)array("pageUuid"=>$page->page_uuid,"width"=>intval($page->width_px),"height"=>intval($page->height_px),"background"=>$this->decodeObject($page->background_json),"elements"=>$out)));
    }

    private function blankDocument($width,$height){return (object)array("documentVersion"=>1,"pages"=>array((object)array("pageUuid"=>$this->uuid(),"width"=>$width,"height"=>$height,"background"=>(object)array("type"=>"solid","color"=>"#ffffff"),"elements"=>array())));}
    private function authorizedFont($id,$profileId,$forTemplate){$font=$this->first($this->fontNs,"id:".intval($id));if(!$font||$font->status!=="active")return null;if($forTemplate)return $font->source==="builtin"||$font->visibility==="tenant"?$font:null;return $font->source==="builtin"||$font->visibility==="tenant"||intval($font->owner_profile_id)===intval($profileId)?$font:null;}
    private function safeFont($font){$safe=clone $font;unset($safe->embedding_flags);return $safe;}
    private function ensureBuiltInFonts(){if($this->first($this->fontNs,"source:builtin"))return;$path=dirname(__DIR__,2)."/components/editor-runtime/fonts/DejaVuSans.ttf";$checksum=is_file($path)?hash_file("sha256",$path):str_repeat("0",64);$font=(object)array("owner_profile_id"=>0,"source"=>"builtin","display_family"=>"DejaVu Sans","normalized_family"=>"dejavu-sans","subfamily"=>"Regular","postscript_name"=>"DejaVuSans","internal_family"=>"DAVVAGBuiltinDejaVuSans","font_weight"=>400,"font_style"=>"normal","font_stretch"=>"normal","format"=>"ttf","mime_type"=>"font/ttf","byte_size"=>is_file($path)?filesize($path):0,"checksum"=>$checksum,"media_reference"=>"components/image-editor/editor-runtime/file/fonts/DejaVuSans.ttf","visibility"=>"tenant","license_type"=>"open-font-license","license_name"=>"DejaVu Fonts License","license_url"=>"https://dejavu-fonts.github.io/License.html","license_media_reference"=>"","attested_by_profile_id"=>0,"attested_at"=>$this->now(),"approved_by_profile_id"=>0,"approved_at"=>$this->now(),"status"=>"active","rejection_reason"=>"","embedding_flags"=>0);\SOSSData::Insert($this->fontNs,$font);}

    private function ownedProject($id,$profileId,$res){$project=$this->first($this->projectNs,"id:".intval($id));if(!$project||intval($project->owner_profile_id)!==intval($profileId)){$this->fail($res,"The project was not found.");return null;}return $project;}
    private function currentProfileId(){if(!class_exists("\\Profile"))return null;$stored=\Profile::getUserProfile();$profile=is_object($stored)&&isset($stored->profile)?$stored->profile:$stored;return is_object($profile)&&isset($profile->id)&&intval($profile->id)>0?intval($profile->id):null;}
    private function requireProfile($res){$id=$this->currentProfileId();if(!$id)$this->fail($res,"Sign in with an active profile to continue.");return $id;}
    private function isAdmin(){if(defined("GROUPID"))return in_array(strtolower(strval(GROUPID)),array("admin","sysadmin"),true);if(class_exists("\\Auth")){$user=\Auth::Autendicate();return is_object($user)&&isset($user->group)&&in_array(strtolower($user->group),array("admin","sysadmin"),true);}return false;}
    private function requireAdmin($res){if(!$this->isAdmin()){return $this->fail($res,"Administrator access is required.");}return $this->requireProfile($res);}
    private function body($req){$body=$req->Body(true);return is_object($body)?$body:(object)array();}
    private function value($body,$key,$default=""){return isset($body->{$key})?$body->{$key}:$default;}
    private function id($body,$key){$id=isset($body->{$key})?intval($body->{$key}):0;if($id<1)throw new \InvalidArgumentException("A valid ".$key." is required.");return $id;}
    private function boundedInt($body,$key,$min,$max){$value=intval($this->value($body,$key,0));if($value<$min||$value>$max)throw new \InvalidArgumentException("Invalid ".$key.".");return $value;}
    private function enum($value,$allowed,$label){$value=strval($value);if(!in_array($value,$allowed,true))throw new \InvalidArgumentException("Invalid ".$label.".");return $value;}
    private function boolean($body,$key){return $this->truth($this->value($body,$key,false));}
    private function truth($value){return $value===true||$value===1||$value==="1"||$value==="true";}
    private function plain($value,$max){$value=trim(preg_replace('/\s+/u',' ',strip_tags(str_replace("\0","",strval($value)))));return function_exists("mb_substr")?mb_substr($value,0,$max,"UTF-8"):substr($value,0,$max);}
    private function uuid(){if(function_exists("random_bytes")){$hex=bin2hex(random_bytes(16));return substr($hex,0,8)."-".substr($hex,8,4)."-4".substr($hex,13,3)."-a".substr($hex,17,3)."-".substr($hex,20,12);}return uniqid("ie-",true);}
    private function now(){return date("Y-m-d H:i:s");}
    private function decodeObject($value){if(is_object($value))return $value;if(is_array($value))return (object)$value;if(is_string($value)){ $decoded=json_decode($value); if(is_object($decoded))return $decoded;}return (object)array();}
    private function mediaReference($reference,$store){$reference=trim(strval($reference));$pattern='#^components/(?:dock|davvag-cms-v7)/soss-uploader/service/get/'.preg_quote($store,'#').'/[A-Za-z0-9][A-Za-z0-9._-]*$#D';if(!preg_match($pattern,$reference))throw new \InvalidArgumentException("Invalid media reference.");return $reference;}
    private function mediaPath($store,$name){if(!defined("MEDIA_FOLDER")||!defined("DATASTORE_DOMAIN"))return null;return rtrim(MEDIA_FOLDER,"/\\")."/".DATASTORE_DOMAIN."/".$store."/".$name;}
    private function rows($namespace,$query,$limit){$result=\SOSSData::Query($namespace,urlencode($query),null,"DESC",min(1000,max(1,$limit)),0);return !empty($result->success)&&isset($result->result)&&is_array($result->result)?$result->result:array();}
    private function first($namespace,$query){$rows=$this->rows($namespace,$query,1);return $rows?$rows[0]:null;}
    private function insert($namespace,$object,$res){$result=\SOSSData::Insert($namespace,$object);if(empty($result->success))return $this->fail($res,"A required record could not be created.");if(isset($result->result->generatedId))$object->id=intval($result->result->generatedId);elseif(is_array($result->result)&&isset($result->result[0]->id))$object->id=intval($result->result[0]->id);return $object;}
    private function update($namespace,$object,$res){$result=\SOSSData::Update($namespace,$object);if(empty($result->success))return $this->fail($res,"A required record could not be updated.");return $object;}
    private function paginate($rows,$body,$default,$max){$page=max(0,intval($this->value($body,"page",0)));$size=min($max,max(1,intval($this->value($body,"pageSize",$default))));$total=count($rows);return array("items"=>array_slice($rows,$page*$size,$size),"pagination"=>array("page"=>$page,"pageSize"=>$size,"total"=>$total,"hasMore"=>(($page+1)*$size)<$total));}
    private function fail($res,$message){$res->SetError($message);return null;}
}
?>
