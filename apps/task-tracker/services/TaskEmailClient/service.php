<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");

class TaskEmailClient {
    private $projectNamespace = "task_manager_projects";
    private $projectAccessNamespace = "task_manager_project_access";
    private $taskNamespace = "task_manager_tasks";
    private $assigneeNamespace = "task_manager_task_assignees";
    private $attachmentNamespace = "task_manager_task_attachments";
    private $commentNamespace = "task_manager_task_comments";
    private $commentAttachmentNamespace = "task_manager_comment_attachments";
    private $notificationNamespace = "task_manager_notifications";

    public function getGetMail($req, $res) {
        return $this->getMail($req, $res);
    }

    public function getMail($req, $res) {
        if (!function_exists("imap_open")) {
            $res->SetError("PHP IMAP extension is required for TaskEmailClient.");
            return null;
        }

        $query = method_exists($req, "Query") ? $req->Query() : new stdClass();
        $projectId = isset($query->projectId) && $query->projectId !== "" ? intval($query->projectId) : null;
        $search = isset($query->search) && $query->search !== "" ? $query->search : "UNSEEN";
        $limit = isset($query->limit) && intval($query->limit) > 0 ? intval($query->limit) : 25;
        $markSeen = !isset($query->markSeen) || $query->markSeen !== "false";

        $summary = new stdClass();
        $summary->projects = array();
        $summary->createdTasks = 0;
        $summary->createdComments = 0;
        $summary->skipped = 0;
        $summary->errors = array();

        foreach ($this->getProjects($projectId) as $project) {
            $projectResult = $this->importProjectMail($project, $search, $limit, $markSeen);
            array_push($summary->projects, $projectResult);
            $summary->createdTasks += $projectResult->createdTasks;
            $summary->createdComments += $projectResult->createdComments;
            $summary->skipped += $projectResult->skipped;
            foreach ($projectResult->errors as $error) {
                array_push($summary->errors, $error);
            }
        }

        CacheData::clearObjects($this->taskNamespace);
        CacheData::clearObjects($this->assigneeNamespace);
        CacheData::clearObjects($this->attachmentNamespace);
        CacheData::clearObjects($this->commentNamespace);
        CacheData::clearObjects($this->commentAttachmentNamespace);
        CacheData::clearObjects($this->notificationNamespace);
        return $summary;
    }

    private function importProjectMail($project, $search, $limit, $markSeen) {
        $result = new stdClass();
        $result->projectId = $project->projectId;
        $result->projectName = isset($project->name) ? $project->name : "";
        $result->checked = 0;
        $result->createdTasks = 0;
        $result->createdComments = 0;
        $result->skipped = 0;
        $result->errors = array();

        if (!isset($project->imapHost) || trim($project->imapHost) === "" || !isset($project->imapUser)) {
            array_push($result->errors, "Project " . $project->projectId . " has no IMAP settings.");
            return $result;
        }

        $mailboxName = $this->mailboxName($project);
        $mailbox = @imap_open($mailboxName, $project->imapUser, isset($project->imapPassword) ? $project->imapPassword : "");
        if (!$mailbox) {
            array_push($result->errors, "Project " . $project->projectId . " IMAP connection failed: " . imap_last_error());
            return $result;
        }

        $messageNumbers = @imap_search($mailbox, $search);
        if (!is_array($messageNumbers)) {
            imap_close($mailbox);
            return $result;
        }

        rsort($messageNumbers);
        $messageNumbers = array_slice($messageNumbers, 0, $limit);
        $profilesByEmail = $this->getProjectProfilesByEmail($project);

        foreach ($messageNumbers as $messageNumber) {
            $result->checked++;
            $message = $this->readMessage($mailbox, $messageNumber);
            if ($message === null || $message->messageId === "") {
                $result->skipped++;
                continue;
            }

            $senderEmail = strtolower($message->fromEmail);
            if ($senderEmail === "" || !isset($profilesByEmail[$senderEmail])) {
                $result->skipped++;
                continue;
            }

            $profile = $profilesByEmail[$senderEmail];
            $existingTask = $this->findTaskForMessage($project->projectId, $message);
            if ($existingTask === null) {
                if ($this->createTaskFromMessage($project, $profile, $message)) {
                    $result->createdTasks++;
                    $this->markMessageRead($mailbox, $messageNumber);
                } else {
                    $result->skipped++;
                }
                continue;
            }

            if ($this->isOriginalTaskMessage($existingTask, $message)) {
                $result->skipped++;
                if ($markSeen) {
                    imap_setflag_full($mailbox, (string)$messageNumber, "\\Seen");
                }
                continue;
            }

            if ($this->commentAlreadyImported($existingTask->taskId, $message->messageId)) {
                $result->skipped++;
                if ($markSeen) {
                    imap_setflag_full($mailbox, (string)$messageNumber, "\\Seen");
                }
                continue;
            }

            if ($this->createCommentFromMessage($existingTask, $profile, $message)) {
                $result->createdComments++;
                if ($markSeen) {
                    imap_setflag_full($mailbox, (string)$messageNumber, "\\Seen");
                }
            } else {
                $result->skipped++;
            }
        }

        imap_close($mailbox);
        return $result;
    }

    private function markMessageRead($mailbox, $messageNumber) {
        imap_setflag_full($mailbox, (string)$messageNumber, "\\Seen");
    }

    private function createTaskFromMessage($project, $profile, $message) {
        $task = new stdClass();
        $task->projectId = $project->projectId;
        $task->subject = $message->subject !== "" ? $message->subject : "(No subject)";
        $task->body = $message->bodyHtml !== "" ? $message->bodyHtml : nl2br(htmlspecialchars($message->bodyText, ENT_QUOTES, "UTF-8"));
        $task->status = "New";
        $task->priority = "Normal";
        $task->progress = 0;
        $task->createdate = $message->date !== "" ? $message->date : date("Y-m-d H:i:s");
        $task->updatedate = date("Y-m-d H:i:s");
        $task->emailMessageId = $message->messageId;
        $task->emailFromEmail = $message->fromEmail;
        $task->emailFromName = $message->fromName;

        $insert = SOSSData::Insert($this->taskNamespace, $task);
        if (!$insert->success || !isset($insert->result->generatedId)) {
            return false;
        }

        $task->taskId = $insert->result->generatedId;
        $assignee = new stdClass();
        $assignee->taskId = $task->taskId;
        $assignee->profileId = $profile->id;
        $assignee->profileName = isset($profile->name) ? $profile->name : "";
        $assignee->email = isset($profile->email) ? $profile->email : "";
        $assignee->status = "Active";
        SOSSData::Insert($this->assigneeNamespace, $assignee);

        foreach ($message->attachments as $attachment) {
            $this->saveAttachment($this->attachmentNamespace, "task_manager_attachments", "taskId", $task->taskId, $attachment);
        }
        $this->notifyAssignees($task->taskId, "Email", "Task created from email");
        return true;
    }

    private function createCommentFromMessage($task, $profile, $message) {
        $comment = new stdClass();
        $comment->taskId = $task->taskId;
        $comment->parentCommentId = 0;
        $comment->profileId = $profile->id;
        $comment->profileName = isset($profile->name) ? $profile->name : $message->fromName;
        $comment->body = $message->bodyHtml !== "" ? $message->bodyHtml : nl2br(htmlspecialchars($message->bodyText, ENT_QUOTES, "UTF-8"));
        $comment->commentDate = $message->date !== "" ? $message->date : date("Y-m-d H:i:s");
        $comment->status = "Active";
        $comment->emailMessageId = $message->messageId;
        $comment->emailFromEmail = $message->fromEmail;

        if (trim(strip_tags($comment->body)) === "" && count($message->attachments) === 0) {
            return false;
        }

        $insert = SOSSData::Insert($this->commentNamespace, $comment);
        if (!$insert->success || !isset($insert->result->generatedId)) {
            return false;
        }

        $comment->commentId = $insert->result->generatedId;
        foreach ($message->attachments as $attachment) {
            $this->saveAttachment($this->commentAttachmentNamespace, "task_manager_comment_attachments", "commentId", $comment->commentId, $attachment, $task->taskId);
        }
        $this->notifyAssignees($task->taskId, "Discussion", "Task discussion updated from email");
        return true;
    }

    private function saveAttachment($namespace, $storeName, $parentField, $parentId, $attachment, $taskId = null) {
        $fileName = $this->safeFileName($attachment->name);
        if ($fileName === "") {
            $fileName = "attachment-" . date("YmdHis");
        }

        if (!$this->writeUploaderFile($storeName, $parentId . "-" . $fileName, $attachment->content)) {
            return;
        }

        $row = new stdClass();
        $row->{$parentField} = $parentId;
        if ($taskId !== null) {
            $row->taskId = $taskId;
        }
        $row->name = $fileName;
        $row->caption = "Email attachment";
        $row->fileType = $attachment->mimeType;
        $row->size = strlen($attachment->content);
        SOSSData::Insert($namespace, $row);
    }

    private function writeUploaderFile($ns, $name, $content) {
        if (!defined("MEDIA_FOLDER") || !defined("DATASTORE_DOMAIN")) {
            return false;
        }

        $folder = MEDIA_FOLDER . "/" . DATASTORE_DOMAIN . "/$ns";
        if (!file_exists($folder)) {
            mkdir($folder, 0777, true);
        }
        return file_put_contents("$folder/$name", $content) !== false;
    }

    private function findTaskForMessage($projectId, $message) {
        $messageIds = $this->messageMatchIds($message);
        $tasks = SOSSData::Query($this->taskNamespace, "projectId:" . $projectId, null, "desc", 500, 0);
        if (!$tasks->success) {
            return null;
        }

        foreach ($tasks->result as $task) {
            if (isset($task->emailMessageId) && in_array($this->normalizeMessageId($task->emailMessageId), $messageIds)) {
                return $task;
            }
        }
        return null;
    }

    private function isOriginalTaskMessage($task, $message) {
        if (!isset($task->emailMessageId)) {
            return false;
        }
        return $this->normalizeMessageId($task->emailMessageId) === $this->normalizeMessageId($message->messageId)
            && $this->normalizeMessageId($message->inReplyTo) === ""
            && $this->normalizeMessageId($message->references) === "";
    }

    private function commentAlreadyImported($taskId, $messageId) {
        $comments = SOSSData::Query($this->commentNamespace, "taskId:" . $taskId, null, "desc", 500, 0);
        if (!$comments->success) {
            return false;
        }
        $messageId = $this->normalizeMessageId($messageId);
        foreach ($comments->result as $comment) {
            if (isset($comment->emailMessageId) && $this->normalizeMessageId($comment->emailMessageId) === $messageId) {
                return true;
            }
        }
        return false;
    }

    private function readMessage($mailbox, $messageNumber) {
        $headerText = imap_fetchheader($mailbox, $messageNumber, FT_PREFETCHTEXT);
        $overview = imap_fetch_overview($mailbox, $messageNumber, 0);
        $overview = is_array($overview) && count($overview) > 0 ? $overview[0] : new stdClass();
        $structure = imap_fetchstructure($mailbox, $messageNumber);

        $message = new stdClass();
        $message->messageId = $this->headerValue($headerText, "Message-ID");
        $message->inReplyTo = $this->headerValue($headerText, "In-Reply-To");
        $message->references = $this->headerValue($headerText, "References");
        $message->subject = isset($overview->subject) ? $this->decodeMimeText($overview->subject) : "";
        $message->date = isset($overview->date) ? date("Y-m-d H:i:s", strtotime($overview->date)) : "";
        $message->fromEmail = "";
        $message->fromName = "";
        $message->bodyText = "";
        $message->bodyHtml = "";
        $message->attachments = array();

        $header = imap_headerinfo($mailbox, $messageNumber);
        if (isset($header->from) && count($header->from) > 0) {
            $from = $header->from[0];
            $message->fromEmail = strtolower((isset($from->mailbox) ? $from->mailbox : "") . "@" . (isset($from->host) ? $from->host : ""));
            $message->fromName = isset($from->personal) ? $this->decodeMimeText($from->personal) : $message->fromEmail;
        }

        if ($structure) {
            $this->readPart($mailbox, $messageNumber, $structure, "", $message);
        } else {
            $message->bodyText = imap_body($mailbox, $messageNumber, FT_PEEK);
        }

        $message->messageId = $this->normalizeMessageId($message->messageId);
        return $message;
    }

    private function readPart($mailbox, $messageNumber, $part, $partNumber, $message) {
        if (isset($part->parts) && count($part->parts) > 0) {
            for ($i = 0; $i < count($part->parts); $i++) {
                $childPartNumber = $partNumber === "" ? (string)($i + 1) : $partNumber . "." . ($i + 1);
                $this->readPart($mailbox, $messageNumber, $part->parts[$i], $childPartNumber, $message);
            }
            return;
        }

        $body = imap_fetchbody($mailbox, $messageNumber, $partNumber === "" ? "1" : $partNumber, FT_PEEK);
        $body = $this->decodeBody($body, isset($part->encoding) ? $part->encoding : 0);
        $fileName = $this->partFileName($part);

        if ($fileName !== "") {
            $attachment = new stdClass();
            $attachment->name = $this->decodeMimeText($fileName);
            $attachment->mimeType = $this->partMimeType($part);
            $attachment->content = $body;
            array_push($message->attachments, $attachment);
            return;
        }

        $subtype = isset($part->subtype) ? strtoupper($part->subtype) : "";
        $body = $this->convertCharset($body, $this->partCharset($part));
        if ($subtype === "HTML") {
            $message->bodyHtml .= $body;
        } elseif ($subtype === "PLAIN") {
            $message->bodyText .= $body;
        }
    }

    private function getProjects($projectId = null) {
        $query = "status:Active";
        if ($projectId !== null) {
            $query = "projectId:" . $projectId;
        }
        $projects = SOSSData::Query($this->projectNamespace, $query, null, "asc", 200, 0);
        if (!$projects->success) {
            return array();
        }

        $out = array();
        foreach ($projects->result as $project) {
            if (isset($project->imapHost) && trim($project->imapHost) !== "") {
                array_push($out, $project);
            }
        }
        return $out;
    }

    private function getProjectProfilesByEmail($project) {
        $ids = array();
        $access = SOSSData::Query($this->projectAccessNamespace, "projectId:" . $project->projectId, null, "asc", 500, 0);
        if ($access->success && count($access->result) > 0) {
            foreach ($access->result as $row) {
                $ids[(string)$row->profileId] = true;
            }
        } elseif (isset($project->profileids) && $project->profileids !== "") {
            foreach (explode(",", $project->profileids) as $id) {
                $ids[trim($id)] = true;
            }
        }

        $profiles = SOSSData::Query("profile", "", null, "asc", 1000, 0);
        if (!$profiles->success) {
            return array();
        }

        $byEmail = array();
        foreach ($profiles->result as $profile) {
            if (isset($ids[(string)$profile->id]) && isset($profile->email) && trim($profile->email) !== "") {
                $byEmail[strtolower(trim($profile->email))] = $profile;
            }
        }
        return $byEmail;
    }

    private function notifyAssignees($taskId, $eventType, $message) {
        $assignees = SOSSData::Query($this->assigneeNamespace, "taskId:" . $taskId, null, "asc", 200, 0);
        if (!$assignees->success) {
            return;
        }
        $notifications = array();
        foreach ($assignees->result as $assignee) {
            $notification = new stdClass();
            $notification->taskId = $taskId;
            $notification->profileId = $assignee->profileId;
            $notification->profileName = isset($assignee->profileName) ? $assignee->profileName : "";
            $notification->email = isset($assignee->email) ? $assignee->email : "";
            $notification->eventType = $eventType;
            $notification->message = $message;
            $notification->status = "Queued";
            $notification->createdate = date("Y-m-d H:i:s");
            array_push($notifications, $notification);
        }
        if (count($notifications) > 0) {
            SOSSData::Insert($this->notificationNamespace, $notifications);
        }
    }

    private function mailboxName($project) {
        $port = isset($project->imapPort) && $project->imapPort !== "" ? $project->imapPort : "993";
        $secure = isset($project->imapSecure) ? strtolower(trim($project->imapSecure)) : "ssl";
        $mailbox = isset($project->imapMailbox) && $project->imapMailbox !== "" ? $project->imapMailbox : "INBOX";
        $flags = "/imap";
        if ($secure === "ssl" || $secure === "tls") {
            $flags .= "/" . $secure;
        } elseif ($secure === "none" || $secure === "notls") {
            $flags .= "/notls";
        }
        return "{" . $project->imapHost . ":" . $port . $flags . "}" . $mailbox;
    }

    private function messageMatchIds($message) {
        $ids = array($this->normalizeMessageId($message->messageId));
        foreach (array($message->inReplyTo, $message->references) as $value) {
            preg_match_all('/<[^>]+>|[^\\s,]+/', $value, $matches);
            foreach ($matches[0] as $match) {
                $id = $this->normalizeMessageId($match);
                if ($id !== "") {
                    array_push($ids, $id);
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function headerValue($headerText, $name) {
        if (preg_match('/^' . preg_quote($name, '/') . ':(.*(?:\\r?\\n[ \\t].*)*)/mi', $headerText, $match)) {
            return trim(preg_replace('/\\r?\\n[ \\t]+/', ' ', $match[1]));
        }
        return "";
    }

    private function normalizeMessageId($value) {
        return strtolower(trim(trim($value), "<> \t\r\n"));
    }

    private function decodeMimeText($value) {
        $decoded = @imap_mime_header_decode($value);
        if (!is_array($decoded)) {
            return $value;
        }
        $out = "";
        foreach ($decoded as $part) {
            $text = $part->text;
            if (isset($part->charset) && strtoupper($part->charset) !== "DEFAULT" && function_exists("iconv")) {
                $converted = @iconv($part->charset, "UTF-8//IGNORE", $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
            $out .= $text;
        }
        return $out;
    }

    private function decodeBody($body, $encoding) {
        if ($encoding == ENCBASE64) {
            return base64_decode($body);
        }
        if ($encoding == ENCQUOTEDPRINTABLE) {
            return quoted_printable_decode($body);
        }
        return $body;
    }

    private function convertCharset($body, $charset) {
        if ($charset !== "" && function_exists("iconv")) {
            $converted = @iconv($charset, "UTF-8//IGNORE", $body);
            if ($converted !== false) {
                return $converted;
            }
        }
        return $body;
    }

    private function partFileName($part) {
        foreach (array("dparameters", "parameters") as $property) {
            if (isset($part->{$property}) && is_array($part->{$property})) {
                foreach ($part->{$property} as $param) {
                    $attribute = isset($param->attribute) ? strtolower($param->attribute) : "";
                    if (($attribute === "filename" || $attribute === "name") && isset($param->value)) {
                        return $param->value;
                    }
                }
            }
        }
        return "";
    }

    private function partCharset($part) {
        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (isset($param->attribute) && strtolower($param->attribute) === "charset") {
                    return $param->value;
                }
            }
        }
        return "";
    }

    private function partMimeType($part) {
        $primary = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");
        $type = isset($part->type) && isset($primary[$part->type]) ? strtolower($primary[$part->type]) : "application";
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : "octet-stream";
        return $type . "/" . $subtype;
    }

    private function safeFileName($name) {
        $name = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', "-", $name);
        $name = preg_replace('/\\s+/', " ", $name);
        return trim($name, ". ");
    }
}
?>
