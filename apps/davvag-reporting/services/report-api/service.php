<?php
namespace davvag_reporting;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) {
        require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    }
    if (file_exists(PLUGIN_PATH . "/phpcache/cache.php")) {
        require_once(PLUGIN_PATH . "/phpcache/cache.php");
    }
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) {
        require_once(PLUGIN_PATH . "/auth/auth.php");
    }
    if (file_exists(PLUGIN_PATH . "/notify/notify.php")) {
        require_once(PLUGIN_PATH . "/notify/notify.php");
    }
}

class ReportApiService {
    private $appCode = "davvag-reporting";

    public function getListReports($req, $res) {
        $this->ensureFolders();
        $reports = array();
        foreach (glob($this->reportsFolder() . "/*.json") as $file) {
            $report = $this->readJsonFile($file);
            if (!is_object($report)) {
                continue;
            }
            $reports[] = $this->listItem($report, $file);
        }
        usort($reports, function($a, $b) {
            return strcmp(strtolower($a->title), strtolower($b->title));
        });
        return $reports;
    }

    public function getGetReport($req, $res) {
        $code = isset($_GET["code"]) ? $_GET["code"] : "";
        $report = $this->loadReport($code);
        if (!$report) {
            $res->SetError("Report was not found.");
            return null;
        }
        return $report;
    }

    public function postSaveReport($req, $res) {
        $user = $this->requireDesignerAccess($res);
        if ($user === null) {
            return null;
        }

        $body = $this->body($req);
        $existing = isset($body->code) ? $this->loadReport($body->code) : null;
        $report = $this->normalizeReport($body, $res, $existing, $user);
        if ($report === null) {
            return null;
        }

        $this->ensureFolders();
        $schema = $this->buildRawSchema($report);
        $reportPath = $this->reportPath($report->code);
        $schemaPath = $this->schemaPath($report->namespace);
        $stamp = gmdate("YmdHis");
        $this->backupFile($reportPath, $this->reportsFolder() . "/backup", $report->code . "-" . $stamp . ".json");
        $this->backupFile($schemaPath, $this->schemasFolder() . "/backup", $report->namespace . "-" . $stamp . ".json");

        if (file_put_contents($schemaPath, $this->encodeJson($schema)) === false) {
            $res->SetError("Unable to write generated raw query schema.");
            return null;
        }

        if (file_put_contents($reportPath, $this->encodeJson($report)) === false) {
            $res->SetError("Unable to write report definition.");
            return null;
        }

        $this->clearCaches($report);
        return $report;
    }

    public function postDeleteReport($req, $res) {
        $user = $this->requireDesignerAccess($res);
        if ($user === null) {
            return null;
        }

        $body = $this->body($req);
        $code = $this->cleanCode(isset($body->code) ? $body->code : "");
        if ($code === "") {
            $res->SetError("Report code is required.");
            return null;
        }

        $report = $this->loadReport($code);
        if (!$report) {
            $res->SetError("Report was not found.");
            return null;
        }

        $reportPath = $this->reportPath($code);
        $schemaPath = $this->schemaPath($report->namespace);
        $stamp = gmdate("YmdHis");
        $this->backupFile($reportPath, $this->reportsFolder() . "/backup", $code . "-" . $stamp . ".json");
        $this->backupFile($schemaPath, $this->schemasFolder() . "/backup", $report->namespace . "-" . $stamp . ".json");

        if (file_exists($reportPath) && !unlink($reportPath)) {
            $res->SetError("Unable to delete report definition.");
            return null;
        }
        if (isset($body->deleteSchema) && $this->truthy($body->deleteSchema) && file_exists($schemaPath)) {
            unlink($schemaPath);
        }

        $out = new \stdClass();
        $out->deleted = true;
        $out->code = $code;
        return $out;
    }

    public function postInferFields($req, $res) {
        $body = $this->body($req);
        $sql = isset($body->sql) ? trim((string)$body->sql) : "";
        if ($sql === "") {
            $res->SetError("SQL query is required.");
            return null;
        }

        $out = new \stdClass();
        $out->parameters = $this->parameterObjectsFromNames($this->extractQueryParameters($sql));
        $out->fields = $this->inferFieldsFromSql($sql);
        return $out;
    }

    public function postRunReport($req, $res) {
        $body = $this->body($req);
        $result = $this->runReport($body);
        if (!$result->success) {
            $res->SetError($result->message);
            return null;
        }
        return $result;
    }

    public function postExportReport($req, $res) {
        $body = $this->body($req);
        $export = $this->buildExport($body, $res);
        if ($export === null) {
            return null;
        }

        $out = new \stdClass();
        $out->filename = $export->filename;
        $out->mime = $export->mime;
        $out->format = $export->format;
        $out->encoding = "base64";
        $out->content = base64_encode($export->content);
        if (isset($export->note)) {
            $out->note = $export->note;
        }
        return $out;
    }

    public function postEmailReport($req, $res) {
        $user = $this->requireDesignerAccess($res);
        if ($user === null) {
            return null;
        }

        $body = $this->body($req);
        $toEmail = isset($body->toEmail) ? trim((string)$body->toEmail) : "";
        $toName = isset($body->toName) ? trim((string)$body->toName) : "";
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $res->SetError("A valid recipient email is required.");
            return null;
        }
        if ($toName === "") {
            $toName = $toEmail;
        }

        $export = $this->buildExport($body, $res);
        if ($export === null) {
            return null;
        }

        $report = $this->loadReport(isset($body->code) ? $body->code : "");
        $subject = isset($body->subject) && trim((string)$body->subject) !== ""
            ? trim((string)$body->subject)
            : "DAVVAG report: " . ($report ? $report->title : $export->filename);
        $message = isset($body->message) ? trim((string)$body->message) : "";

        if (!$this->sendReportEmail($toEmail, $toName, $subject, $message, $export, $res)) {
            return null;
        }

        $out = new \stdClass();
        $out->sent = true;
        $out->toEmail = $toEmail;
        $out->filename = $export->filename;
        return $out;
    }

    public function getGetPdfSettings($req, $res) {
        $this->ensureFolders();
        return $this->pdfSettings();
    }

    public function postSavePdfSettings($req, $res) {
        $user = $this->requireDesignerAccess($res);
        if ($user === null) {
            return null;
        }

        $body = $this->body($req);
        $settings = $this->normalizePdfSettings($body, $user);
        $this->ensureFolders();

        $path = $this->pdfSettingsPath();
        $stamp = gmdate("YmdHis");
        $this->backupFile($path, $this->settingsFolder() . "/backup", "pdf-" . $stamp . ".json");
        if (file_put_contents($path, $this->encodeJson($settings)) === false) {
            $res->SetError("Unable to write PDF settings.");
            return null;
        }
        return $settings;
    }

    private function normalizeReport($body, $res, $existing, $user) {
        if (!is_object($body)) {
            $res->SetError("Report definition is required.");
            return null;
        }

        $title = $this->stringValue($body, "title", "");
        $code = $this->cleanCode($this->stringValue($body, "code", $title));
        if ($code === "") {
            $res->SetError("Report code is required.");
            return null;
        }
        if ($title === "") {
            $title = $code;
        }

        $sql = $this->normalizeSql($this->stringValue($body, "sql", ""));
        $sqlError = $this->validateReportSql($sql);
        if ($sqlError !== "") {
            $res->SetError($sqlError);
            return null;
        }

        $namespace = $this->cleanNamespace($this->stringValue($body, "namespace", "rpt_" . $code));
        if ($namespace === "") {
            $namespace = "rpt_" . $code;
        }

        $report = new \stdClass();
        $report->code = $code;
        $report->title = $title;
        $report->description = $this->stringValue($body, "description", "");
        $report->namespace = $namespace;
        $report->sql = $sql;
        $report->parameters = $this->normalizeParameters(isset($body->parameters) ? $body->parameters : array(), $sql);
        $report->fields = $this->normalizeFields(isset($body->fields) ? $body->fields : array(), $sql);
        $report->grid = $this->normalizeGrid(isset($body->grid) ? $body->grid : null, $report->fields);
        $report->chart = $this->normalizeChart(isset($body->chart) ? $body->chart : null, $report->fields);
        $report->schemaFile = "schemas/" . $namespace . ".json";
        $report->createdAt = $existing && isset($existing->createdAt) ? $existing->createdAt : gmdate("Y-m-d H:i:s");
        $report->updatedAt = gmdate("Y-m-d H:i:s");
        $report->createdBy = $existing && isset($existing->createdBy) ? $existing->createdBy : $this->userId($user);
        $report->updatedBy = $this->userId($user);
        return $report;
    }

    private function normalizeParameters($parameters, $sql) {
        $byName = array();
        foreach ($this->asArray($parameters) as $param) {
            if (!is_object($param)) {
                continue;
            }
            $name = $this->cleanFieldName(isset($param->name) ? $param->name : "");
            if ($name === "") {
                continue;
            }
            $item = new \stdClass();
            $item->name = $name;
            $item->label = $this->stringValue($param, "label", $this->labelFromName($name));
            $item->type = $this->parameterType(isset($param->type) ? $param->type : "text");
            $item->defaultValue = isset($param->defaultValue) ? (string)$param->defaultValue : "";
            $item->required = $this->truthy(isset($param->required) ? $param->required : false);
            $byName[$name] = $item;
        }

        foreach ($this->extractQueryParameters($sql) as $name) {
            if (!isset($byName[$name])) {
                $item = new \stdClass();
                $item->name = $name;
                $item->label = $this->labelFromName($name);
                $item->type = $this->parameterType($this->inferParameterType($name));
                $item->defaultValue = "";
                $item->required = false;
                $byName[$name] = $item;
            }
        }

        return array_values($byName);
    }

    private function normalizeFields($fields, $sql) {
        $out = array();
        foreach ($this->asArray($fields) as $field) {
            if (!is_object($field)) {
                continue;
            }
            $name = $this->cleanFieldName(isset($field->fieldName) ? $field->fieldName : (isset($field->name) ? $field->name : ""));
            if ($name === "") {
                continue;
            }
            $item = new \stdClass();
            $item->fieldName = $name;
            $item->label = $this->stringValue($field, "label", $this->labelFromName($name));
            $item->dataType = $this->dataType(isset($field->dataType) ? $field->dataType : "java.lang.String");
            $item->visible = !isset($field->visible) || $this->truthy($field->visible);
            $item->format = $this->stringValue($field, "format", "");
            $item->width = $this->stringValue($field, "width", "");
            if (isset($field->maxLen) && trim((string)$field->maxLen) !== "") {
                $item->maxLen = max(1, intval($field->maxLen));
            }
            $out[] = $item;
        }

        if (count($out) === 0) {
            $out = $this->inferFieldsFromSql($sql);
        }
        return $out;
    }

    private function normalizeGrid($grid, $fields) {
        $out = new \stdClass();
        $out->pageSize = 50;
        $out->columns = array();
        if (is_object($grid) && isset($grid->pageSize)) {
            $out->pageSize = max(1, min(5000, intval($grid->pageSize)));
        }
        foreach ($fields as $field) {
            if (isset($field->visible) && !$this->truthy($field->visible)) {
                continue;
            }
            $column = new \stdClass();
            $column->field = $field->fieldName;
            $column->label = isset($field->label) ? $field->label : $this->labelFromName($field->fieldName);
            $column->dataType = $field->dataType;
            $column->format = isset($field->format) ? $field->format : "";
            $column->width = isset($field->width) ? $field->width : "";
            $out->columns[] = $column;
        }
        return $out;
    }

    private function normalizeChart($chart, $fields) {
        $out = new \stdClass();
        $out->enabled = false;
        $out->type = "bar";
        $out->xField = count($fields) > 0 ? $fields[0]->fieldName : "";
        $out->yFields = array();

        if (is_object($chart)) {
            $out->enabled = isset($chart->enabled) && $this->truthy($chart->enabled);
            $out->type = in_array(isset($chart->type) ? strtolower((string)$chart->type) : "bar", array("bar", "line", "summary")) ? strtolower((string)$chart->type) : "bar";
            $out->xField = $this->cleanFieldName(isset($chart->xField) ? $chart->xField : $out->xField);
            $items = isset($chart->yFields) ? $chart->yFields : array();
            if (is_string($items)) {
                $items = explode(",", $items);
            }
            foreach ($this->asArray($items) as $field) {
                $name = $this->cleanFieldName($field);
                if ($name !== "") {
                    $out->yFields[] = $name;
                }
            }
        }

        if (count($out->yFields) === 0) {
            foreach ($fields as $field) {
                if (in_array($field->dataType, array("int", "float"))) {
                    $out->yFields[] = $field->fieldName;
                    break;
                }
            }
        }

        return $out;
    }

    private function buildRawSchema($report) {
        $schema = new \stdClass();
        $schema->rawquery = new \stdClass();
        $schema->rawquery->type = "sql";
        $schema->rawquery->parameters = array();
        foreach ($report->parameters as $param) {
            $schema->rawquery->parameters[] = $param->name;
        }
        $schema->rawquery->query = $report->sql;
        $schema->fields = array();
        foreach ($report->fields as $item) {
            $field = new \stdClass();
            $field->fieldName = $item->fieldName;
            $field->dataType = $item->dataType;
            $annotations = new \stdClass();
            if (isset($item->maxLen) && intval($item->maxLen) > 0) {
                $annotations->maxLen = intval($item->maxLen);
            }
            if (isset($item->encoding) && trim((string)$item->encoding) !== "") {
                $annotations->encoding = trim((string)$item->encoding);
            }
            if (count(get_object_vars($annotations)) > 0) {
                $field->annotations = $annotations;
            }
            $schema->fields[] = $field;
        }
        return $schema;
    }

    private function runReport($body) {
        if (!class_exists("\\SOSSData")) {
            return $this->fail("SOSSData is not available.");
        }

        $code = isset($body->code) ? $body->code : "";
        $report = $this->loadReport($code);
        if (!$report) {
            return $this->fail("Report was not found.");
        }

        $schemaPath = $this->schemaPath($report->namespace);
        if (!file_exists($schemaPath)) {
            file_put_contents($schemaPath, $this->encodeJson($this->buildRawSchema($report)));
        }

        $mainObj = new \stdClass();
        $mainObj->storename = $report->namespace;
        $mainObj->parameters = $this->executionParameters($report, isset($body->parameters) ? $body->parameters : new \stdClass());
        $result = \SOSSData::ExecuteRaw($report->namespace, $mainObj);
        if (!isset($result->success) || !$result->success) {
            $message = isset($result->message) ? $result->message : (isset($result->result) ? json_encode($result->result) : "Report query failed.");
            return $this->fail($message);
        }

        $out = $this->ok();
        $out->report = $this->publicReport($report);
        $out->columns = isset($report->grid->columns) ? $report->grid->columns : array();
        $out->chart = isset($report->chart) ? $report->chart : new \stdClass();
        $out->parameters = $mainObj->parameters;
        $out->rows = isset($result->result) && is_array($result->result) ? $result->result : array();
        $out->rowCount = count($out->rows);
        return $out;
    }

    private function executionParameters($report, $incoming) {
        $incomingValues = is_object($incoming) ? $incoming : new \stdClass();
        $params = new \stdClass();
        foreach ($report->parameters as $param) {
            $name = $param->name;
            if (isset($incomingValues->$name)) {
                $params->$name = $this->parameterValue($incomingValues->$name, $param);
            } else {
                $params->$name = isset($param->defaultValue) ? $param->defaultValue : "";
            }
        }
        return $params;
    }

    private function parameterValue($value, $param) {
        if (is_array($value) || is_object($value)) {
            return "";
        }
        $value = trim((string)$value);
        $type = isset($param->type) ? $param->type : "text";
        if ($type === "number") {
            return is_numeric($value) ? $value : "0";
        }
        if ($type === "date" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return "";
        }
        return substr($value, 0, 1000);
    }

    private function buildExport($body, $res) {
        $format = isset($body->format) ? strtolower(trim((string)$body->format)) : "csv";
        if (!in_array($format, array("csv", "json", "html", "pdf", "xlsx"))) {
            $format = "csv";
        }

        $result = $this->runReport($body);
        if (!$result->success) {
            $res->SetError($result->message);
            return null;
        }

        $report = $result->report;
        $rows = $result->rows;
        $columns = isset($result->columns) && is_array($result->columns) ? $result->columns : array();
        if (count($columns) === 0 && count($rows) > 0) {
            foreach (get_object_vars($rows[0]) as $key => $value) {
                $col = new \stdClass();
                $col->field = $key;
                $col->label = $this->labelFromName($key);
                $columns[] = $col;
            }
        }

        if ($format === "json") {
            return $this->exportObject($report->code . ".json", "application/json", "json", $this->encodeJson($rows));
        }
        if ($format === "html") {
            return $this->exportObject($report->code . ".html", "text/html; charset=utf-8", "html", $this->reportHtml($report, $columns, $rows));
        }
        if ($format === "pdf") {
            return $this->pdfExport($report, $columns, $rows);
        }
        if ($format === "xlsx") {
            return $this->xlsxExport($report, $columns, $rows);
        }
        return $this->exportObject($report->code . ".csv", "text/csv; charset=utf-8", "csv", $this->csvContent($columns, $rows));
    }

    private function csvContent($columns, $rows) {
        $handle = fopen("php://temp", "r+");
        $headers = array();
        foreach ($columns as $column) {
            $headers[] = isset($column->label) && $column->label !== "" ? $column->label : $column->field;
        }
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            $line = array();
            foreach ($columns as $column) {
                $field = $column->field;
                $line[] = $this->rowValue($row, $field);
            }
            fputcsv($handle, $line);
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        return $content;
    }

    private function reportHtml($report, $columns, $rows) {
        $html = "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . $this->escape($report->title) . "</title>";
        $html .= "<style>body{font-family:Arial,Helvetica,sans-serif;color:#26343d}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #d7e0e5;padding:6px;text-align:left}th{background:#eef3f6}h1{font-size:22px;margin-bottom:4px}.meta{color:#657782;margin-bottom:18px}</style>";
        $html .= "</head><body><h1>" . $this->escape($report->title) . "</h1><div class=\"meta\">" . $this->escape($report->code) . " | " . gmdate("Y-m-d H:i:s") . " UTC</div><table><thead><tr>";
        foreach ($columns as $column) {
            $html .= "<th>" . $this->escape(isset($column->label) ? $column->label : $column->field) . "</th>";
        }
        $html .= "</tr></thead><tbody>";
        foreach ($rows as $row) {
            $html .= "<tr>";
            foreach ($columns as $column) {
                $html .= "<td>" . $this->escape($this->rowValue($row, $column->field)) . "</td>";
            }
            $html .= "</tr>";
        }
        if (count($rows) === 0) {
            $html .= "<tr><td colspan=\"" . max(1, count($columns)) . "\">No rows returned.</td></tr>";
        }
        $html .= "</tbody></table></body></html>";
        return $html;
    }

    private function pdfExport($report, $columns, $rows) {
        $this->loadOptionalLibrary(array(
            "mpdf/vendor/autoload.php",
            "mpdf/autoload.php"
        ));
        $html = $this->reportHtml($report, $columns, $rows);
        if (class_exists("\\Mpdf\\Mpdf")) {
            $settings = $this->pdfSettings();
            $mpdfConfig = array();
            if (isset($settings->headerHtml) && trim((string)$settings->headerHtml) !== "") {
                $mpdfConfig["margin_top"] = 32;
            }
            if (isset($settings->footerHtml) && trim((string)$settings->footerHtml) !== "") {
                $mpdfConfig["margin_bottom"] = 24;
            }
            $mpdf = new \Mpdf\Mpdf($mpdfConfig);
            if (isset($settings->headerHtml) && trim((string)$settings->headerHtml) !== "") {
                $mpdf->SetHTMLHeader($this->renderPdfTemplate($settings->headerHtml, $report));
            }
            if (isset($settings->footerHtml) && trim((string)$settings->footerHtml) !== "") {
                $mpdf->SetHTMLFooter($this->renderPdfTemplate($settings->footerHtml, $report));
            }
            $mpdf->WriteHTML($html);
            $content = $mpdf->Output("", "S");
            return $this->exportObject($report->code . ".pdf", "application/pdf", "pdf", $content);
        }
        $export = $this->exportObject($report->code . ".html", "text/html; charset=utf-8", "html", $html);
        $export->note = "mPDF is not installed; exported HTML instead.";
        return $export;
    }

    private function xlsxExport($report, $columns, $rows) {
        $this->loadOptionalLibrary(array(
            "phpspreadsheet/vendor/autoload.php",
            "phpspreadsheet/autoload.php"
        ));
        if (class_exists("\\PhpOffice\\PhpSpreadsheet\\Spreadsheet")) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $colIndex = 1;
            foreach ($columns as $column) {
                $sheet->setCellValueByColumnAndRow($colIndex, 1, isset($column->label) ? $column->label : $column->field);
                $colIndex++;
            }
            $rowIndex = 2;
            foreach ($rows as $row) {
                $colIndex = 1;
                foreach ($columns as $column) {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $this->rowValue($row, $column->field));
                    $colIndex++;
                }
                $rowIndex++;
            }
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            ob_start();
            $writer->save("php://output");
            $content = ob_get_clean();
            return $this->exportObject($report->code . ".xlsx", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "xlsx", $content);
        }
        $export = $this->exportObject($report->code . ".csv", "text/csv; charset=utf-8", "csv", $this->csvContent($columns, $rows));
        $export->note = "PhpSpreadsheet is not installed; exported CSV instead.";
        return $export;
    }

    private function sendReportEmail($toEmail, $toName, $subject, $message, $export, $res) {
        $this->loadOptionalLibrary(array(
            "phpmailer/PHPMailerAutoload.php",
            "phpmailer/vendor/autoload.php",
            "notify/PHPMailerAutoload.php",
            "notify/vendor/autoload.php"
        ));
        $mailClass = "";
        if (class_exists("\\PHPMailer")) {
            $mailClass = "\\PHPMailer";
        } elseif (class_exists("\\PHPMailer\\PHPMailer\\PHPMailer")) {
            $mailClass = "\\PHPMailer\\PHPMailer\\PHPMailer";
        }
        if ($mailClass === "") {
            $res->SetError("PHPMailer is not available for report email delivery.");
            return false;
        }

        $configPath = $this->tenantRoot() . "/global/config/emailsmtp.conf";
        if (!file_exists($configPath)) {
            $res->SetError("Email SMTP configuration was not found.");
            return false;
        }
        $config = json_decode(file_get_contents($configPath));
        if (!is_object($config)) {
            $res->SetError("Email SMTP configuration is invalid.");
            return false;
        }

        $mail = new $mailClass();
        $mail->IsSMTP();
        $mail->CharSet = "UTF-8";
        $mail->Host = isset($config->host) ? $config->host : "";
        $mail->SMTPAuth = true;
        $mail->Port = isset($config->port) ? $config->port : 587;
        $mail->Username = isset($config->username) ? $config->username : "";
        $mail->Password = isset($config->password) ? $config->password : "";
        $mail->SMTPSecure = isset($config->secure) ? $config->secure : "tls";
        $mail->From = $mail->Username;
        $mail->FromName = isset($config->companyname) ? $config->companyname : "DAVVAG Reporting";
        $mail->Subject = $subject;
        $mail->IsHTML(true);
        $mail->addAddress($toEmail, $toName);
        $mail->Body = "<p>" . nl2br($this->escape($message !== "" ? $message : "Your DAVVAG report export is attached.")) . "</p>";
        $mail->addStringAttachment($export->content, $export->filename, "base64", $export->mime);
        if (!$mail->Send()) {
            $res->SetError("Unable to send report email.");
            return false;
        }
        return true;
    }

    private function inferFieldsFromSql($sql) {
        $selectStart = stripos($sql, "select");
        if ($selectStart === false) {
            return array();
        }
        $fromStart = $this->findTopLevelKeyword($sql, "from", $selectStart + 6);
        if ($fromStart === -1) {
            return array();
        }
        $selectList = substr($sql, $selectStart + 6, $fromStart - ($selectStart + 6));
        $fields = array();
        $index = 1;
        foreach ($this->splitTopLevel($selectList, ",") as $expr) {
            $expr = trim($expr);
            if ($expr === "" || $expr === "*" || preg_match('/\\.\\*$/', $expr)) {
                continue;
            }
            $name = $this->aliasForExpression($expr);
            if ($name === "") {
                $name = "column_" . $index;
            }
            $field = new \stdClass();
            $field->fieldName = $this->cleanFieldName($name);
            if ($field->fieldName === "") {
                $field->fieldName = "column_" . $index;
            }
            $field->label = $this->labelFromName($field->fieldName);
            $field->dataType = $this->inferDataType($field->fieldName, $expr);
            $field->visible = true;
            $field->format = "";
            $fields[] = $field;
            $index++;
        }
        return $fields;
    }

    private function aliasForExpression($expr) {
        if (preg_match('/\\s+as\\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\\s*$/i', $expr, $match)) {
            return $match[1];
        }
        if (preg_match('/\\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\\s*$/', $expr, $match) && substr(trim($expr), -1) !== ")") {
            return $match[1];
        }
        if (preg_match('/`?([A-Za-z_][A-Za-z0-9_]*)`?\\s*$/', $expr, $match)) {
            return $match[1];
        }
        return "";
    }

    private function inferDataType($name, $expr) {
        $haystack = strtolower($name . " " . $expr);
        if (preg_match('/\\b(count|sum)\\s*\\(/i', $expr)) {
            return "int";
        }
        if (preg_match('/\\b(avg|decimal|amount|total|balance|rate|price|cost)\\b/i', $haystack)) {
            return "float";
        }
        if (preg_match('/\\b(date|time|created|updated|month|year)\\b/i', $haystack)) {
            return "java.util.Date";
        }
        return "java.lang.String";
    }

    private function extractQueryParameters($sql) {
        preg_match_all('/\\$([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        $names = array();
        foreach ($matches[1] as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function parameterObjectsFromNames($names) {
        $out = array();
        foreach ($names as $name) {
            $item = new \stdClass();
            $item->name = $name;
            $item->label = $this->labelFromName($name);
            $item->type = $this->parameterType($this->inferParameterType($name));
            $item->defaultValue = "";
            $item->required = false;
            $out[] = $item;
        }
        return $out;
    }

    private function inferParameterType($name) {
        $lower = strtolower($name);
        if (strpos($lower, "date") !== false || strpos($lower, "month") !== false) {
            return "date";
        }
        if (in_array($lower, array("page", "size", "limit", "offset", "id"))) {
            return "number";
        }
        return "text";
    }

    private function validateReportSql($sql) {
        if ($sql === "") {
            return "SQL query is required.";
        }
        $trimmed = trim($sql);
        if (substr_count($trimmed, ";") > 0 && substr($trimmed, -1) !== ";") {
            return "Only one SQL statement is allowed.";
        }
        $withoutFinalSemicolon = rtrim($trimmed, "; \t\r\n");
        if (strpos($withoutFinalSemicolon, ";") !== false) {
            return "Only one SQL statement is allowed.";
        }
        if (!preg_match('/^\\s*(select|with)\\b/i', $trimmed)) {
            return "Reports must use SELECT queries.";
        }
        if (preg_match('/\\b(insert|update|delete|drop|truncate|alter|create|replace|grant|revoke)\\b/i', $trimmed)) {
            return "Report SQL cannot contain data-changing statements.";
        }
        return "";
    }

    private function normalizeSql($sql) {
        $sql = trim((string)$sql);
        return rtrim($sql, "; \t\r\n");
    }

    private function loadReport($code) {
        $code = $this->cleanCode($code);
        if ($code === "") {
            return null;
        }
        $path = $this->reportPath($code);
        if (!file_exists($path)) {
            return null;
        }
        $report = $this->readJsonFile($path);
        return is_object($report) ? $report : null;
    }

    private function publicReport($report) {
        $out = new \stdClass();
        foreach (array("code", "title", "description", "namespace", "parameters", "fields", "grid", "chart", "schemaFile", "updatedAt") as $key) {
            if (isset($report->$key)) {
                $out->$key = $report->$key;
            }
        }
        return $out;
    }

    private function listItem($report, $file) {
        $item = new \stdClass();
        $item->code = isset($report->code) ? $report->code : basename($file, ".json");
        $item->title = isset($report->title) ? $report->title : $item->code;
        $item->description = isset($report->description) ? $report->description : "";
        $item->namespace = isset($report->namespace) ? $report->namespace : "";
        $item->schemaFile = isset($report->schemaFile) ? $report->schemaFile : "";
        $item->updatedAt = isset($report->updatedAt) ? $report->updatedAt : gmdate("Y-m-d H:i:s", filemtime($file));
        $item->fieldCount = isset($report->fields) && is_array($report->fields) ? count($report->fields) : 0;
        $item->parameterCount = isset($report->parameters) && is_array($report->parameters) ? count($report->parameters) : 0;
        $item->chartEnabled = isset($report->chart) && isset($report->chart->enabled) && $this->truthy($report->chart->enabled);
        return $item;
    }

    private function reportPath($code) {
        return $this->reportsFolder() . "/" . $this->cleanCode($code) . ".json";
    }

    private function schemaPath($namespace) {
        return $this->schemasFolder() . "/" . $this->cleanNamespace($namespace) . ".json";
    }

    private function pdfSettingsPath() {
        return $this->settingsFolder() . "/pdf.json";
    }

    private function reportsFolder() {
        return $this->appFolder() . "/data/reports";
    }

    private function settingsFolder() {
        return $this->appFolder() . "/data/settings";
    }

    private function appFolder() {
        return $this->tenantRoot() . "/apps/" . $this->appCode;
    }

    private function schemasFolder() {
        return $this->tenantRoot() . "/schemas";
    }

    private function tenantRoot() {
        if (defined("TENANT_RESOURCE_LOCATION")) {
            return rtrim(TENANT_RESOURCE_LOCATION, "\\/");
        }
        return dirname(__FILE__, 4);
    }

    private function ensureFolders() {
        foreach (array($this->appFolder(), $this->reportsFolder(), $this->settingsFolder(), $this->schemasFolder()) as $folder) {
            if (!file_exists($folder)) {
                mkdir($folder, 0777, true);
            }
        }
    }

    private function backupFile($source, $folder, $name) {
        if (!file_exists($source)) {
            return;
        }
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }
        copy($source, $folder . "/" . $name);
    }

    private function readJsonFile($file) {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        $json = json_decode($contents);
        return json_last_error() === JSON_ERROR_NONE ? $json : null;
    }

    private function encodeJson($value) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function cleanCode($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9_-]+/', "-", $value);
        $value = trim($value, "-_");
        return substr($value, 0, 80);
    }

    private function cleanNamespace($value) {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9_]+/', "_", $value);
        $value = trim($value, "_");
        return substr($value, 0, 100);
    }

    private function cleanFieldName($value) {
        $value = trim((string)$value);
        $value = preg_replace('/[^A-Za-z0-9_]+/', "_", $value);
        $value = trim($value, "_");
        if ($value === "" || preg_match('/^[A-Za-z_]/', $value) !== 1) {
            return $value === "" ? "" : "field_" . $value;
        }
        return substr($value, 0, 80);
    }

    private function labelFromName($name) {
        $label = preg_replace('/[_-]+/', " ", (string)$name);
        $label = preg_replace('/(?<!^)([A-Z])/', " $1", $label);
        return ucwords(trim($label));
    }

    private function dataType($value) {
        $value = trim((string)$value);
        if (in_array($value, array("int", "float", "java.util.Date", "java.lang.String"), true)) {
            return $value;
        }
        return "java.lang.String";
    }

    private function parameterType($value) {
        $value = strtolower(trim((string)$value));
        if (in_array($value, array("text", "number", "date", "email"), true)) {
            return $value;
        }
        return "text";
    }

    private function body($req) {
        $body = method_exists($req, "Body") ? $req->Body(true) : null;
        return is_object($body) ? $body : new \stdClass();
    }

    private function stringValue($object, $key, $default) {
        if (!is_object($object) || !isset($object->$key)) {
            return $default;
        }
        return trim((string)$object->$key);
    }

    private function rowValue($row, $field) {
        if (is_object($row) && isset($row->$field)) {
            if (is_scalar($row->$field) || $row->$field === null) {
                return (string)$row->$field;
            }
            return json_encode($row->$field);
        }
        if (is_array($row) && isset($row[$field])) {
            return is_scalar($row[$field]) || $row[$field] === null ? (string)$row[$field] : json_encode($row[$field]);
        }
        return "";
    }

    private function truthy($value) {
        return $value === true || $value === 1 || $value === "1" || strtolower((string)$value) === "true" || strtoupper((string)$value) === "Y";
    }

    private function asArray($value) {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \stdClass) {
            return array_values(get_object_vars($value));
        }
        return array();
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

    private function exportObject($filename, $mime, $format, $content) {
        $out = new \stdClass();
        $out->filename = $filename;
        $out->mime = $mime;
        $out->format = $format;
        $out->content = $content;
        return $out;
    }

    private function escape($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
    }

    private function pdfSettings() {
        $path = $this->pdfSettingsPath();
        if (file_exists($path)) {
            $settings = $this->readJsonFile($path);
            if (is_object($settings)) {
                return $this->normalizePdfSettings($settings, null);
            }
        }
        return $this->defaultPdfSettings();
    }

    private function defaultPdfSettings() {
        $settings = new \stdClass();
        $settings->headerHtml = "";
        $settings->footerHtml = "<div style=\"text-align:right;font-size:10px;color:#777;\">Page @pageNumber of @pageCount</div>";
        $settings->updatedAt = "";
        $settings->updatedBy = "";
        return $settings;
    }

    private function normalizePdfSettings($body, $user) {
        $settings = $this->defaultPdfSettings();
        if (is_object($body)) {
            $settings->headerHtml = isset($body->headerHtml) ? (string)$body->headerHtml : "";
            $settings->footerHtml = isset($body->footerHtml) ? (string)$body->footerHtml : "";
            if (isset($body->updatedAt)) {
                $settings->updatedAt = (string)$body->updatedAt;
            }
            if (isset($body->updatedBy)) {
                $settings->updatedBy = (string)$body->updatedBy;
            }
        }
        if ($user !== null) {
            $settings->updatedAt = gmdate("Y-m-d H:i:s");
            $settings->updatedBy = $this->userId($user);
        }
        return $settings;
    }

    private function renderPdfTemplate($html, $report) {
        $tokens = array(
            "@reportTitle" => isset($report->title) ? $report->title : "",
            "@reportCode" => isset($report->code) ? $report->code : "",
            "@namespace" => isset($report->namespace) ? $report->namespace : "",
            "@generatedAt" => gmdate("Y-m-d H:i:s") . " UTC",
            "@pageNumber" => "{PAGENO}",
            "@pageCount" => "{nbpg}"
        );
        return str_replace(array_keys($tokens), array_values($tokens), (string)$html);
    }

    private function loadOptionalLibrary($relativePaths) {
        foreach ($relativePaths as $relative) {
            $paths = array();
            if (defined("PLUGIN_PATH")) {
                $paths[] = rtrim(PLUGIN_PATH, "\\/") . "/" . $relative;
            }
            if (defined("PLUGIN_PATH_LOCAL")) {
                $paths[] = rtrim(PLUGIN_PATH_LOCAL, "\\/") . "/" . $relative;
            }
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    require_once($path);
                    return true;
                }
            }
        }
        return false;
    }

    private function clearCaches($report) {
        if (class_exists("\\CacheData")) {
            \CacheData::clearObjects($report->namespace);
            \CacheData::clearObjects("davvag-reporting");
        }
    }

    private function requireDesignerAccess($res) {
        $hasSignedInGroup = false;
        if (defined("GROUPID")) {
            $group = strtolower((string)GROUPID);
            if ($group === "anonymous" || $group === "web_user") {
                $res->SetError("Administrator access is required for report design actions.");
                return null;
            }
            $hasSignedInGroup = true;
        }

        $user = $this->currentUser();
        if ($user !== null) {
            return $user;
        }

        if ($hasSignedInGroup) {
            return new \stdClass();
        }

        if (!class_exists("\\Auth") && !isset($_COOKIE["authData"]) && !isset($_COOKIE["securityToken"]) && !isset($_COOKIE["sosskey"])) {
            return new \stdClass();
        }

        $res->SetError("You must be signed in to manage reports.");
        return null;
    }

    private function currentUser() {
        if (isset($_COOKIE["authData"])) {
            $data = json_decode(stripslashes($_COOKIE["authData"]));
            if (is_object($data)) {
                return $data;
            }
        }
        $token = "";
        if (isset($_COOKIE["securityToken"])) {
            $token = $_COOKIE["securityToken"];
        } elseif (isset($_COOKIE["sosskey"])) {
            $token = $_COOKIE["sosskey"];
        }
        if ($token !== "" && class_exists("\\Auth")) {
            $session = @\Auth::GetSession($token);
            if (is_object($session) && (isset($session->userid) || isset($session->email))) {
                return $session;
            }
        }
        return null;
    }

    private function userId($user) {
        if (!is_object($user)) {
            return "";
        }
        foreach (array("userid", "userId", "email", "id") as $key) {
            if (isset($user->$key) && trim((string)$user->$key) !== "") {
                return trim((string)$user->$key);
            }
        }
        return "";
    }

    private function findTopLevelKeyword($sql, $keyword, $offset) {
        $length = strlen($sql);
        $keywordLength = strlen($keyword);
        $depth = 0;
        $quote = "";
        for ($i = $offset; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== "") {
                if ($char === $quote && ($i === 0 || $sql[$i - 1] !== "\\")) {
                    $quote = "";
                }
                continue;
            }
            if ($char === "'" || $char === "\"" || $char === "`") {
                $quote = $char;
                continue;
            }
            if ($char === "(") {
                $depth++;
                continue;
            }
            if ($char === ")") {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && strcasecmp(substr($sql, $i, $keywordLength), $keyword) === 0) {
                $before = $i === 0 ? " " : $sql[$i - 1];
                $after = $i + $keywordLength >= $length ? " " : $sql[$i + $keywordLength];
                if (!preg_match('/[A-Za-z0-9_]/', $before) && !preg_match('/[A-Za-z0-9_]/', $after)) {
                    return $i;
                }
            }
        }
        return -1;
    }

    private function splitTopLevel($text, $separator) {
        $items = array();
        $depth = 0;
        $quote = "";
        $start = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== "") {
                if ($char === $quote && ($i === 0 || $text[$i - 1] !== "\\")) {
                    $quote = "";
                }
                continue;
            }
            if ($char === "'" || $char === "\"" || $char === "`") {
                $quote = $char;
                continue;
            }
            if ($char === "(") {
                $depth++;
                continue;
            }
            if ($char === ")") {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth === 0 && $char === $separator) {
                $items[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $items[] = substr($text, $start);
        return $items;
    }
}
?>
