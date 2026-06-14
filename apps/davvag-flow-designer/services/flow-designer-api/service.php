<?php
namespace davvag_flow_designer;

class FlowDesignerService {
    public function getDesignerData($req, $res) {
        $out = $this->ok();
        $out->workflows = $this->workflowList();
        $out->toolbox = $this->toolbox();
        $out->namespaces = $this->namespaces();
        return $out;
    }

    public function getListWorkflows($req, $res) {
        $out = $this->ok();
        $out->workflows = $this->workflowList();
        $out->namespaces = $this->namespaces();
        return $out;
    }

    public function postLoadWorkflow($req, $res) {
        $body = $this->body($req);
        $workflowRef = $this->workflowRef($body);
        if (!$workflowRef->success) {
            return $workflowRef;
        }

        if (!file_exists($workflowRef->path)) {
            return $this->fail("Workflow file was not found.");
        }

        $text = file_get_contents($workflowRef->path);
        $workflow = json_decode($text);
        if (!is_object($workflow)) {
            return $this->fail("Workflow JSON could not be decoded.");
        }

        $out = $this->ok();
        $out->filename = $workflowRef->filename . ".json";
        $out->flowid = $workflowRef->filename;
        $out->namespace = $workflowRef->namespace;
        $out->workflow = $workflow;
        $out->json = json_encode($workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $out;
    }

    public function postSaveWorkflow($req, $res) {
        $body = $this->body($req);
        $workflowRef = $this->workflowRef($body, true);
        if (!$workflowRef->success) {
            return $workflowRef;
        }

        $workflow = isset($body->workflow) ? $body->workflow : null;
        if (is_string($workflow)) {
            $workflow = json_decode($workflow);
        }
        if (!is_object($workflow)) {
            return $this->fail("Workflow payload must be a JSON object.");
        }

        $validation = $this->validateWorkflow($workflow);
        if ($validation !== true) {
            return $this->fail($validation);
        }

        $dir = dirname($workflowRef->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return $this->fail("Unable to create workflow namespace folder.");
        }

        $json = json_encode($workflow, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($workflowRef->path, $json) === false) {
            return $this->fail("Unable to save workflow JSON.");
        }

        $out = $this->ok();
        $out->filename = $workflowRef->filename . ".json";
        $out->flowid = $workflowRef->filename;
        $out->namespace = $workflowRef->namespace;
        $out->workflow = $workflow;
        $out->workflows = $this->workflowList();
        $out->namespaces = $this->namespaces();
        return $out;
    }

    public function postRunWorkflow($req, $res) {
        $body = $this->body($req);
        $workflowRef = $this->workflowRef($body, true);
        if (!$workflowRef->success) {
            return $workflowRef;
        }

        $workflow = isset($body->workflow) ? $body->workflow : null;
        if (is_string($workflow)) {
            $workflow = json_decode($workflow);
        }
        if (!is_object($workflow)) {
            return $this->fail("Workflow payload must be a JSON object.");
        }

        $validation = $this->validateWorkflow($workflow);
        if ($validation !== true) {
            return $this->fail($validation);
        }

        $inputData = isset($body->inputData) ? $body->inputData : new \stdClass();
        if (is_string($inputData)) {
            $inputData = json_decode($inputData);
        }
        if (is_array($inputData)) {
            $inputData = $this->arrayToObject($inputData);
        }
        if (!is_object($inputData)) {
            return $this->fail("Test input must be a JSON object.");
        }

        $runtime = $this->ensureFlowRuntime();
        if ($runtime !== true) {
            return $this->fail($runtime);
        }

        $executeData = new \stdClass();
        $executeData->excutionStack = new \stdClass();
        $executeData->outData = new \stdClass();
        $executeData->excutionStack->workFlowId = uniqid();
        $inputData->workflowid = $executeData->excutionStack->workFlowId;

        $out = $this->ok();
        $out->namespace = $workflowRef->namespace;
        $out->flowid = $workflowRef->filename;
        $out->startedAt = gmdate("c");

        try {
            $result = \DavvagFlow::Execute(
                $workflowRef->namespace === "" ? null : $workflowRef->namespace,
                $workflowRef->filename,
                $inputData,
                null,
                $executeData,
                $workflow
            );
            $out->runSuccess = true;
            $out->finishedAt = gmdate("c");
            $out->result = $result;
        } catch (\Throwable $e) {
            $out->runSuccess = false;
            $out->finishedAt = gmdate("c");
            $out->error = array(
                "message" => $e->getMessage(),
                "type" => get_class($e),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            );
            $out->result = $executeData;
        }

        return $out;
    }

    public function postDeleteWorkflow($req, $res) {
        $body = $this->body($req);
        $workflowRef = $this->workflowRef($body);
        if (!$workflowRef->success) {
            return $workflowRef;
        }

        if (file_exists($workflowRef->path) && !unlink($workflowRef->path)) {
            return $this->fail("Unable to delete workflow file.");
        }

        $out = $this->ok();
        $out->workflows = $this->workflowList();
        $out->namespaces = $this->namespaces();
        return $out;
    }

    private function workflowList() {
        $items = array();
        $dir = $this->workflowDir();
        if (!is_dir($dir)) {
            return $items;
        }

        foreach (glob($dir . "/*.json") as $file) {
            $items[] = $this->workflowPreview($file, "");
        }

        foreach (glob($dir . "/*", GLOB_ONLYDIR) as $namespaceDir) {
            $namespace = basename($namespaceDir);
            foreach (glob($namespaceDir . "/*.json") as $file) {
                $items[] = $this->workflowPreview($file, $namespace);
            }
        }

        usort($items, function($a, $b) {
            $left = strtolower(($a->namespace ? $a->namespace . "/" : "") . $a->filename);
            $right = strtolower(($b->namespace ? $b->namespace . "/" : "") . $b->filename);
            return strcmp($left, $right);
        });

        return $items;
    }

    private function workflowPreview($file, $namespace) {
        $workflow = json_decode(file_get_contents($file));
        $item = new \stdClass();
        $item->filename = basename($file);
        $item->flowid = preg_replace("/\\.json$/i", "", basename($file));
        $item->namespace = $namespace;
        $item->path = ($namespace ? $namespace . "/" : "") . basename($file);
        $item->name = is_object($workflow) && isset($workflow->name) ? $workflow->name : $item->flowid;
        $item->startupNode = is_object($workflow) && isset($workflow->start_up_node) ? $workflow->start_up_node : "";
        $item->nodeCount = is_object($workflow) ? count($this->nodeKeys($workflow)) : 0;
        $item->updatedAt = date("c", filemtime($file));
        return $item;
    }

    private function namespaces() {
        $items = array("");
        $dir = $this->workflowDir();
        if (is_dir($dir)) {
            foreach (glob($dir . "/*", GLOB_ONLYDIR) as $namespaceDir) {
                $items[] = basename($namespaceDir);
            }
        }
        $items = array_values(array_unique($items));
        sort($items);
        return $items;
    }

    private function toolbox() {
        $templates = array();
        $templates[] = $this->template(
            "blank-class",
            "Starter",
            "Class Activity",
            "class",
            array(
                "urntype" => "class",
                "file" => "test.php",
                "class" => "test",
                "method" => array(
                    "name" => "getMessage",
                    "params" => array(),
                    "return" => true,
                    "returnobj" => "message"
                )
            )
        );
        $templates[] = $this->template(
            "blank-service",
            "Starter",
            "App Service",
            "service",
            array(
                "urntype" => "service",
                "appCode" => "",
                "componentCode" => "",
                "method" => array(
                    "type" => "post",
                    "name" => "",
                    "params" => array(),
                    "return" => true,
                    "returnobj" => "result"
                )
            )
        );
        $templates[] = $this->template(
            "create-object",
            "Starter",
            "Create Object",
            "create_object",
            array(
                "urntype" => "create_object",
                "method" => array(
                    "type" => "create_object",
                    "name" => "BuildObject",
                    "return" => true,
                    "returnobj" => "result"
                ),
                "variables" => array(
                    array("name" => "message", "value" => "Done")
                )
            )
        );

        foreach ($this->classTemplates() as $template) {
            $templates[] = $template;
        }
        foreach ($this->serviceTemplates() as $template) {
            $templates[] = $template;
        }

        return $templates;
    }

    private function classTemplates() {
        $templates = array();
        $dir = $this->pluginLibDir();
        if (!is_dir($dir)) {
            return $templates;
        }

        foreach (glob($dir . "/*.php") as $file) {
            $methods = $this->phpClassMethods($file);
            foreach ($methods as $className => $methodNames) {
                foreach ($methodNames as $methodName) {
                    $returnObj = $this->returnObjectName($methodName);
                    $templates[] = $this->template(
                        "class:" . basename($file) . ":" . $className . ":" . $methodName,
                        "Plugin Classes",
                        $className . "::" . $methodName,
                        "class",
                        array(
                            "urntype" => "class",
                            "file" => basename($file),
                            "class" => $className,
                            "method" => array(
                                "name" => $methodName,
                                "params" => array(),
                                "return" => true,
                                "returnobj" => $returnObj
                            )
                        )
                    );
                }
            }
        }

        return $templates;
    }

    private function serviceTemplates() {
        $templates = array();
        $appsDir = $this->tenantRoot() . "/apps";
        if (!is_dir($appsDir)) {
            return $templates;
        }

        foreach (glob($appsDir . "/*/app.json") as $appFile) {
            $appCode = basename(dirname($appFile));
            if ($appCode === "davvag-flow-designer") {
                continue;
            }

            $app = json_decode(file_get_contents($appFile));
            if (!is_object($app) || !isset($app->components) || !is_object($app->components)) {
                continue;
            }

            foreach ($app->components as $componentCode => $component) {
                if (!isset($component->type) || $component->type !== "service") {
                    continue;
                }

                $location = isset($component->location) ? $component->location : "services";
                $componentFile = dirname($appFile) . "/" . $location . "/" . $componentCode . "/component.json";
                if (!file_exists($componentFile)) {
                    continue;
                }

                $componentJson = json_decode(file_get_contents($componentFile));
                if (!is_object($componentJson) || !isset($componentJson->serviceHandler->methods)) {
                    continue;
                }

                foreach ($componentJson->serviceHandler->methods as $methodName => $methodMeta) {
                    $methodType = "post";
                    if (isset($methodMeta->method)) {
                        $methodType = strtolower((string)$methodMeta->method);
                    }

                    $templates[] = $this->template(
                        "service:" . $appCode . ":" . $componentCode . ":" . $methodName,
                        "App Services",
                        $appCode . " / " . $componentCode . " / " . $methodName,
                        "service",
                        array(
                            "urntype" => "service",
                            "appCode" => $appCode,
                            "componentCode" => $componentCode,
                            "method" => array(
                                "type" => $methodType,
                                "name" => $methodName,
                                "params" => array(),
                                "return" => true,
                                "returnobj" => $this->returnObjectName($methodName)
                            )
                        )
                    );
                }
            }
        }

        return $templates;
    }

    private function phpClassMethods($file) {
        $classes = array();
        $source = file_get_contents($file);
        if ($source === false) {
            return $classes;
        }

        $tokens = token_get_all($source);
        $className = null;
        $pendingClass = false;
        $pendingFunction = false;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $pendingClass = true;
                $pendingFunction = false;
                continue;
            }

            if ($pendingClass && $token[0] === T_STRING) {
                $className = $token[1];
                if (!isset($classes[$className])) {
                    $classes[$className] = array();
                }
                $pendingClass = false;
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $pendingFunction = true;
                continue;
            }

            if ($pendingFunction && $token[0] === T_STRING) {
                $methodName = $token[1];
                if ($className && strpos($methodName, "__") !== 0) {
                    $classes[$className][] = $methodName;
                }
                $pendingFunction = false;
            }
        }

        return $classes;
    }

    private function template($id, $category, $label, $urnType, $node) {
        $template = new \stdClass();
        $template->id = $id;
        $template->category = $category;
        $template->label = $label;
        $template->urntype = $urnType;
        $template->node = $this->arrayToObject($node);
        return $template;
    }

    private function validateWorkflow($workflow) {
        if (!isset($workflow->name) || trim((string)$workflow->name) === "") {
            return "Workflow name is required.";
        }
        if (!isset($workflow->start_up_node) || trim((string)$workflow->start_up_node) === "") {
            return "Startup node is required.";
        }

        $nodeKeys = $this->nodeKeys($workflow);
        if (count($nodeKeys) && !in_array($workflow->start_up_node, $nodeKeys)) {
            return "Startup node must match an existing node id.";
        }

        foreach ($nodeKeys as $nodeKey) {
            $node = $workflow->$nodeKey;
            if (!isset($node->urntype)) {
                return "Node " . $nodeKey . " is missing urntype.";
            }
            if (isset($node->success) && $node->success !== "" && !in_array($node->success, $nodeKeys)) {
                return "Node " . $nodeKey . " has a success link to a missing node.";
            }
            if (isset($node->fail) && $node->fail !== "" && !in_array($node->fail, $nodeKeys)) {
                return "Node " . $nodeKey . " has a fail link to a missing node.";
            }
        }

        return true;
    }

    private function nodeKeys($workflow) {
        $reserved = array("name", "start_up_node", "inputData", "__designer");
        $keys = array();
        foreach (get_object_vars($workflow) as $key => $value) {
            if (in_array($key, $reserved)) {
                continue;
            }
            if (is_object($value) && isset($value->urntype)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    private function workflowRef($body, $allowNew = false) {
        $namespace = isset($body->namespace) ? trim((string)$body->namespace) : "";
        if ($namespace !== "" && preg_match("/^[A-Za-z0-9_.-]+$/", $namespace) !== 1) {
            return $this->fail("Namespace can only contain letters, numbers, dots, underscores, and hyphens.");
        }

        $filename = isset($body->filename) ? trim((string)$body->filename) : "";
        if ($filename === "" && isset($body->flowid)) {
            $filename = trim((string)$body->flowid);
        }
        $filename = preg_replace("/\\.json$/i", "", $filename);

        if ($filename === "" && !$allowNew) {
            return $this->fail("Workflow filename is required.");
        }
        if ($filename === "" || preg_match("/^[A-Za-z0-9_.-]+$/", $filename) !== 1 || $filename === "." || $filename === "..") {
            return $this->fail("Workflow filename can only contain letters, numbers, dots, underscores, and hyphens.");
        }

        $base = rtrim($this->workflowDir(), "\\/");
        $path = $base . "/" . ($namespace !== "" ? $namespace . "/" : "") . $filename . ".json";

        $ref = $this->ok();
        $ref->namespace = $namespace;
        $ref->filename = $filename;
        $ref->path = $path;
        return $ref;
    }

    private function body($req) {
        $body = $req->Body(true);
        return is_object($body) ? $body : new \stdClass();
    }

    private function tenantRoot() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/");
        }
        return dirname(dirname(dirname(dirname(__DIR__))));
    }

    private function workflowDir() {
        return $this->tenantRoot() . "/davvag-flow";
    }

    private function pluginLibDir() {
        $candidates = array();
        if (defined("PLUGIN_PATH")) {
            $candidates[] = rtrim(PLUGIN_PATH, "\\/") . "/davvag-flow/lib";
        }
        if (defined("PLUGIN_PATH_LOCAL")) {
            $candidates[] = rtrim(PLUGIN_PATH_LOCAL, "\\/") . "/davvag-flow/lib";
        }
        $candidates[] = $this->tenantRoot() . "/plugins/davvag-flow/lib";

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return $this->tenantRoot() . "/plugins/davvag-flow/lib";
    }

    private function ensureFlowRuntime() {
        if (!defined("TENANT_RESOURCE_LOCATION")) {
            define("TENANT_RESOURCE_LOCATION", $this->tenantRoot());
        }
        if (!defined("PLUGIN_PATH_LOCAL")) {
            define("PLUGIN_PATH_LOCAL", $this->tenantRoot() . "/plugins");
        }
        if (!defined("PLUGIN_PATH")) {
            define("PLUGIN_PATH", dirname(dirname($this->tenantRoot())) . "/plugins");
        }

        $candidates = array(
            rtrim(PLUGIN_PATH, "\\/") . "/davvag-flow/flow.php",
            rtrim(PLUGIN_PATH_LOCAL, "\\/") . "/davvag-flow/flow.php",
            $this->tenantRoot() . "/plugins/davvag-flow/flow.php"
        );

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                require_once($file);
                return class_exists("\\DavvagFlow") ? true : "DavvagFlow runtime class was not loaded.";
            }
        }

        return "Davvag Flow runtime file was not found.";
    }

    private function returnObjectName($value) {
        $value = strtolower(preg_replace("/[^A-Za-z0-9_]+/", "_", $value));
        $value = trim($value, "_");
        return $value !== "" ? $value : "result";
    }

    private function arrayToObject($value) {
        return json_decode(json_encode($value));
    }

    private function ok() {
        $out = new \stdClass();
        $out->success = true;
        return $out;
    }

    private function fail($message) {
        $out = new \stdClass();
        $out->success = false;
        $out->message = $message;
        return $out;
    }
}
?>
