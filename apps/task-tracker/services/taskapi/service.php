<?php
require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
require_once(PLUGIN_PATH . "/phpcache/cache.php");
require_once(PLUGIN_PATH . "/auth/auth.php");
if (file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
    require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
}

class TaskManagerService {
    private $projectNamespace = "task_manager_projects";
    private $projectAccessNamespace = "task_manager_project_access";
    private $taskNamespace = "task_manager_tasks";
    private $assigneeNamespace = "task_manager_task_assignees";
    private $attachmentNamespace = "task_manager_task_attachments";
    private $workLogNamespace = "task_manager_work_logs";
    private $commentNamespace = "task_manager_task_comments";
    private $commentAttachmentNamespace = "task_manager_comment_attachments";
    private $notificationNamespace = "task_manager_notifications";

    public function postListProfiles($req, $res) {
        $body = $this->body($req);
        $query = "";
        if (isset($body->search) && $body->search !== "") {
            $query = "name:" . $body->search;
        }
        $result = SOSSData::Query("profile", $query, null, "asc", 200, 0);
        return $result->success ? $result->result : array();
    }

    public function postSearchProfileByEmail($req, $res) {
        $body = $this->body($req);
        $email = isset($body->email) ? trim($body->email) : "";
        if ($email === "" && isset($body->search)) {
            $email = trim($body->search);
        }
        if (strlen($email) < 2) {
            return array();
        }

        $safeEmail = str_replace(array(",", ":"), " ", $email);
        $result = SOSSData::Query("profile", "email:" . $safeEmail, null, "asc", 20, 0);
        if ($result->success && count($result->result) > 0) {
            return $this->filterProfilesByEmail($result->result, $email, 10);
        }

        $all = SOSSData::Query("profile", "", null, "asc", 1000, 0);
        return $all->success ? $this->filterProfilesByEmail($all->result, $email, 10) : array();
    }

    public function postListProjects($req, $res) {
        $body = $this->body($req);
        $query = "";
        if (isset($body->status) && $body->status !== "") {
            $query = "status:" . $body->status;
        }
        $result = SOSSData::Query($this->projectNamespace, $query, null, "asc", 100, 0);
        return $result->success ? $this->filterProjectsForCurrentProfile($result->result) : array();
    }

    public function postProjectDetails($req, $res) {
        $body = $this->body($req);
        $details = new stdClass();
        $details->project = null;
        $details->profiles = array();
        $details->accessProfileIds = array();

        if (!isset($body->projectId) || !$this->canAccessProject($body->projectId)) {
            return $details;
        }

        $projectResult = SOSSData::Query($this->projectNamespace, "projectId:" . $body->projectId);
        if ($projectResult->success && count($projectResult->result) > 0) {
            $details->project = $projectResult->result[0];
            $details->accessProfileIds = $this->getProjectProfileIds($body->projectId, $details->project);
            $details->profiles = $this->getProfilesByIds($details->accessProfileIds);
        }
        return $details;
    }

    public function postProjectAssignedProfiles($req, $res) {
        $body = $this->body($req);
        $ids = array();

        if (isset($body->profileIds) && is_array($body->profileIds)) {
            $ids = $body->profileIds;
        } elseif (isset($body->AccessProfiles) && is_array($body->AccessProfiles)) {
            $ids = $body->AccessProfiles;
        } elseif (isset($body->projectId) && intval($body->projectId) > 0) {
            if (!$this->canAccessProject($body->projectId)) {
                return array();
            }
            $projectResult = SOSSData::Query($this->projectNamespace, "projectId:" . $body->projectId);
            $project = $projectResult->success && count($projectResult->result) > 0 ? $projectResult->result[0] : null;
            $ids = $this->getProjectProfileIds($body->projectId, $project);
        }

        return $this->getProfilesByIds($this->normalizeProfileIds($ids));
    }

    public function postSaveProject($req, $res) {
        $project = $this->body($req);
        if (!isset($project->name) || trim($project->name) === "") {
            $res->SetError("Project name is required.");
            return null;
        }
        if (isset($project->projectId) && intval($project->projectId) > 0 && !$this->canAccessProject($project->projectId)) {
            $res->SetError("You do not have access to this project.");
            return null;
        }

        $project->status = isset($project->status) && $project->status !== "" ? $project->status : "Active";
        if (isset($project->AccessProfiles)) {
            if (count($project->AccessProfiles) === 0) {
                $profileId = $this->currentProfileId();
                if ($profileId !== null) {
                    array_push($project->AccessProfiles, $profileId);
                }
            }
            $project->profileids = $this->idsToCsv($project->AccessProfiles);
        }

        $result = $this->saveObject($this->projectNamespace, "projectId", $project);
        if (!$result->success) {
            $res->SetError($result);
            return null;
        }

        if (!isset($project->projectId) && isset($result->result->generatedId)) {
            $project->projectId = $result->result->generatedId;
        }

        if (isset($project->AccessProfiles)) {
            $this->syncProjectAccess($project);
        }
        CacheData::clearObjects($this->projectNamespace);
        CacheData::clearObjects($this->projectAccessNamespace);
        return $project;
    }

    public function postDeleteProject($req, $res) {
        $project = $this->body($req);
        if (!isset($project->projectId)) {
            $res->SetError("Project id is required.");
            return null;
        }
        if (!$this->canAccessProject($project->projectId)) {
            $res->SetError("You do not have access to this project.");
            return null;
        }
        $taskResult = SOSSData::Query($this->taskNamespace, "projectId:" . $project->projectId);
        if ($taskResult->success) {
            foreach ($taskResult->result as $task) {
                $this->deleteByQuery($this->assigneeNamespace, "taskId:" . $task->taskId);
                $this->deleteByQuery($this->attachmentNamespace, "taskId:" . $task->taskId);
                $this->deleteByQuery($this->workLogNamespace, "taskId:" . $task->taskId);
                $this->deleteByQuery($this->commentNamespace, "taskId:" . $task->taskId);
                $this->deleteByQuery($this->commentAttachmentNamespace, "taskId:" . $task->taskId);
                $this->deleteByQuery($this->notificationNamespace, "taskId:" . $task->taskId);
            }
            if (count($taskResult->result) > 0) {
                SOSSData::Delete($this->taskNamespace, $taskResult->result);
            }
        }
        $this->deleteByQuery($this->projectAccessNamespace, "projectId:" . $project->projectId);
        $result = SOSSData::Delete($this->projectNamespace, $project);
        CacheData::clearObjects($this->projectNamespace);
        return $result->success ? $project : null;
    }

    public function postListTasks($req, $res) {
        $body = $this->body($req);
        if (!isset($body->projectId)) {
            return array();
        }
        if (!$this->canAccessProject($body->projectId)) {
            return array();
        }

        $query = "projectId:" . $body->projectId;
        if (isset($body->status) && $body->status !== "") {
            $query .= ",status:" . $body->status;
        }
        $result = SOSSData::Query($this->taskNamespace, $query, null, "desc", 200, 0);
        if (!$result->success) {
            return array();
        }
        $projectCache = array();
        $tasks = $this->normalizeTasks($result->result);
        foreach ($tasks as $task) {
            $this->attachProjectSummary($task, $projectCache);
        }
        return $tasks;
    }

    public function postListMyTasks($req, $res) {
        $body = $this->body($req);
        $profileId = $this->currentProfileId();
        if ($profileId === null) {
            return array();
        }

        $assignments = SOSSData::Query($this->assigneeNamespace, "profileId:" . $profileId, null, "desc", 500, 0);
        if (!$assignments->success || count($assignments->result) === 0) {
            return array();
        }

        $tasks = array();
        $seenTasks = array();
        $projectCache = array();
        $status = isset($body->status) ? trim($body->status) : "";

        foreach ($assignments->result as $assignment) {
            if (!isset($assignment->taskId) || isset($seenTasks[(string)$assignment->taskId])) {
                continue;
            }
            if (!isset($assignment->profileId) || (string)$assignment->profileId !== (string)$profileId) {
                continue;
            }

            $taskResult = SOSSData::Query($this->taskNamespace, "taskId:" . $assignment->taskId);
            if (!$taskResult->success || count($taskResult->result) === 0) {
                continue;
            }

            $task = $this->normalizeTask($taskResult->result[0]);
            if ($status !== "" && (!isset($task->status) || $task->status !== $status)) {
                continue;
            }
            if (!isset($task->projectId) || !$this->canAccessProject($task->projectId)) {
                continue;
            }

            $this->attachProjectSummary($task, $projectCache);
            $task->assigneeStatus = isset($assignment->status) ? $assignment->status : "";
            $seenTasks[(string)$assignment->taskId] = true;
            array_push($tasks, $task);
        }

        usort($tasks, function ($left, $right) {
            $leftDate = isset($left->updatedate) && $left->updatedate !== "" ? $left->updatedate : (isset($left->createdate) ? $left->createdate : "");
            $rightDate = isset($right->updatedate) && $right->updatedate !== "" ? $right->updatedate : (isset($right->createdate) ? $right->createdate : "");
            return strtotime($rightDate) - strtotime($leftDate);
        });
        return $tasks;
    }

    public function postSaveTask($req, $res) {
        $task = $this->body($req);
        if (!isset($task->projectId)) {
            $res->SetError("Project id is required.");
            return null;
        }
        if (!$this->canAccessProject($task->projectId)) {
            $res->SetError("You do not have access to this project.");
            return null;
        }
        if (!isset($task->subject) || trim($task->subject) === "") {
            $res->SetError("Task subject is required.");
            return null;
        }

        $task->status = isset($task->status) && $task->status !== "" ? $task->status : "New";
        $task->priority = isset($task->priority) && $task->priority !== "" ? $task->priority : "Normal";
        $task->progress = isset($task->progress) ? intval($task->progress) : 0;
        $task->updatedate = date("Y-m-d H:i:s");
        if (!isset($task->createdate)) {
            $task->createdate = date("Y-m-d H:i:s");
        }

        $result = $this->saveObject($this->taskNamespace, "taskId", $task);
        if (!$result->success) {
            $res->SetError($result);
            return null;
        }

        if (!isset($task->taskId) && isset($result->result->generatedId)) {
            $task->taskId = $result->result->generatedId;
        }

        $this->syncTaskAssignees($task);
        $this->syncTaskAttachments($task);
        CacheData::clearObjects($this->taskNamespace);
        return $task;
    }

    public function postDeleteTask($req, $res) {
        $task = $this->body($req);
        if (!isset($task->taskId)) {
            $res->SetError("Task id is required.");
            return null;
        }
        if (!$this->canAccessTask($task->taskId)) {
            $res->SetError("You do not have access to this task.");
            return null;
        }

        $this->deleteByQuery($this->assigneeNamespace, "taskId:" . $task->taskId);
        $this->deleteByQuery($this->attachmentNamespace, "taskId:" . $task->taskId);
        $this->deleteByQuery($this->workLogNamespace, "taskId:" . $task->taskId);
        $this->deleteByQuery($this->commentNamespace, "taskId:" . $task->taskId);
        $this->deleteByQuery($this->commentAttachmentNamespace, "taskId:" . $task->taskId);
        $this->deleteByQuery($this->notificationNamespace, "taskId:" . $task->taskId);
        $result = SOSSData::Delete($this->taskNamespace, $task);
        CacheData::clearObjects($this->taskNamespace);
        return $result->success ? $task : null;
    }

    public function postTaskDetails($req, $res) {
        $body = $this->body($req);
        $details = new stdClass();
        $details->task = null;
        $details->assignees = array();
        $details->attachments = array();
        $details->workLogs = array();
        $details->comments = array();
        $details->notifications = array();

        if (!isset($body->taskId)) {
            return $details;
        }
        if (!$this->canAccessTask($body->taskId)) {
            return $details;
        }

        $task = SOSSData::Query($this->taskNamespace, "taskId:" . $body->taskId);
        $assignees = SOSSData::Query($this->assigneeNamespace, "taskId:" . $body->taskId);
        $attachments = SOSSData::Query($this->attachmentNamespace, "taskId:" . $body->taskId);
        $workLogs = SOSSData::Query($this->workLogNamespace, "taskId:" . $body->taskId, null, "desc", 100, 0);
        $notifications = SOSSData::Query($this->notificationNamespace, "taskId:" . $body->taskId, null, "desc", 100, 0);

        $details->task = $task->success && count($task->result) > 0 ? $this->normalizeTask($task->result[0]) : null;
        $details->assignees = $assignees->success ? $assignees->result : array();
        $details->attachments = $attachments->success ? $attachments->result : array();
        $details->workLogs = $workLogs->success ? $workLogs->result : array();
        $details->comments = $this->getTaskComments($body->taskId);
        $details->notifications = $notifications->success ? $notifications->result : array();
        return $details;
    }

    public function postSaveWorkLog($req, $res) {
        $log = $this->body($req);
        if (!isset($log->taskId)) {
            $res->SetError("Task id is required.");
            return null;
        }
        if (!$this->canAccessTask($log->taskId)) {
            $res->SetError("You do not have access to this task.");
            return null;
        }

        $profile = $this->currentProfile();
        $log->profileId = $profile->id;
        $log->profileName = $profile->name;
        $log->logDate = isset($log->logDate) && $log->logDate !== "" ? $log->logDate : date("Y-m-d H:i:s");
        $log->startDate = isset($log->startDate) && $log->startDate !== "" ? $log->startDate : null;
        $log->endDate = isset($log->endDate) && $log->endDate !== "" ? $log->endDate : null;
        $log->durationInMinutes = isset($log->durationInMinutes) ? intval($log->durationInMinutes) : 0;
        if ($log->durationInMinutes === 0 && $log->startDate !== null && $log->endDate !== null) {
            $duration = strtotime($log->endDate) - strtotime($log->startDate);
            if ($duration > 0) {
                $log->durationInMinutes = intval(round($duration / 60));
            }
        }
        $log->progress = isset($log->progress) ? intval($log->progress) : 0;
        $log->status = isset($log->status) && $log->status !== "" ? $log->status : "In Progress";

        $result = SOSSData::Insert($this->workLogNamespace, $log);
        if (!$result->success) {
            $res->SetError($result);
            return null;
        }
        $log->logId = isset($result->result->generatedId) ? $result->result->generatedId : null;

        $taskResult = SOSSData::Query($this->taskNamespace, "taskId:" . $log->taskId);
        if ($taskResult->success && count($taskResult->result) > 0) {
            $task = $taskResult->result[0];
            $task->progress = $log->progress;
            $task->status = $log->status;
            $task->updatedate = date("Y-m-d H:i:s");
            SOSSData::Update($this->taskNamespace, $task);
        }

        CacheData::clearObjects($this->workLogNamespace);
        CacheData::clearObjects($this->taskNamespace);
        return $log;
    }

    public function postSaveComment($req, $res) {
        $comment = $this->body($req);
        if (!isset($comment->taskId)) {
            $res->SetError("Task id is required.");
            return null;
        }
        if (!$this->canAccessTask($comment->taskId)) {
            $res->SetError("You do not have access to this task.");
            return null;
        }
        if ((!isset($comment->body) || trim($comment->body) === "") && count(isset($comment->Attachments) ? $comment->Attachments : array()) === 0) {
            $res->SetError("Comment text or attachment is required.");
            return null;
        }

        $profile = $this->currentProfile();
        $comment->profileId = $profile->id;
        $comment->profileName = $profile->name;
        $comment->parentCommentId = isset($comment->parentCommentId) && $comment->parentCommentId !== "" ? intval($comment->parentCommentId) : 0;
        $comment->commentDate = date("Y-m-d H:i:s");
        $comment->status = "Active";

        $result = SOSSData::Insert($this->commentNamespace, $comment);
        if (!$result->success) {
            $res->SetError($result);
            return null;
        }

        if (isset($result->result->generatedId)) {
            $comment->commentId = $result->result->generatedId;
        }
        $this->syncCommentAttachments($comment);
        CacheData::clearObjects($this->commentNamespace);
        CacheData::clearObjects($this->commentAttachmentNamespace);
        return $comment;
    }

    public function postNotifyTaskAssignees($req, $res) {
        $body = $this->body($req);
        if (!isset($body->taskId)) {
            $res->SetError("Task id is required.");
            return null;
        }
        if (!$this->canAccessTask($body->taskId)) {
            $res->SetError("You do not have access to this task.");
            return null;
        }

        $assignees = SOSSData::Query($this->assigneeNamespace, "taskId:" . $body->taskId);
        if (!$assignees->success) {
            return array();
        }

        $notifications = array();
        foreach ($assignees->result as $assignee) {
            $notification = new stdClass();
            $notification->taskId = $body->taskId;
            $notification->profileId = $assignee->profileId;
            $notification->profileName = isset($assignee->profileName) ? $assignee->profileName : "";
            $notification->email = isset($assignee->email) ? $assignee->email : "";
            $notification->eventType = isset($body->eventType) ? $body->eventType : "TaskChanged";
            $notification->message = isset($body->message) ? $body->message : "Task changed";
            $notification->status = "Queued";
            $notification->createdate = date("Y-m-d H:i:s");
            array_push($notifications, $notification);
        }

        if (count($notifications) > 0) {
            SOSSData::Insert($this->notificationNamespace, $notifications);
        }
        CacheData::clearObjects($this->notificationNamespace);
        return $notifications;
    }

    private function getTaskComments($taskId) {
        $commentsResult = SOSSData::Query($this->commentNamespace, "taskId:" . $taskId, null, "asc", 500, 0);
        if (!$commentsResult->success) {
            return array();
        }

        $attachmentsResult = SOSSData::Query($this->commentAttachmentNamespace, "taskId:" . $taskId, null, "asc", 500, 0);
        $attachmentsByComment = array();
        if ($attachmentsResult->success) {
            foreach ($attachmentsResult->result as $attachment) {
                $key = (string)$attachment->commentId;
                if (!isset($attachmentsByComment[$key])) {
                    $attachmentsByComment[$key] = array();
                }
                array_push($attachmentsByComment[$key], $attachment);
            }
        }

        $byId = array();
        $roots = array();
        foreach ($commentsResult->result as $comment) {
            $comment->Attachments = isset($attachmentsByComment[(string)$comment->commentId]) ? $attachmentsByComment[(string)$comment->commentId] : array();
            $comment->replies = array();
            $byId[(string)$comment->commentId] = $comment;
        }

        foreach ($commentsResult->result as $comment) {
            $parentId = isset($comment->parentCommentId) ? intval($comment->parentCommentId) : 0;
            if ($parentId > 0 && isset($byId[(string)$parentId])) {
                array_push($byId[(string)$parentId]->replies, $comment);
            } else {
                array_push($roots, $comment);
            }
        }
        return $roots;
    }

    private function syncCommentAttachments($comment) {
        if (!isset($comment->commentId)) {
            return;
        }
        $attachments = isset($comment->Attachments) ? $comment->Attachments : array();
        foreach ($attachments as $attachment) {
            $attachment->commentId = $comment->commentId;
            $attachment->taskId = $comment->taskId;
            $attachment->fileType = isset($attachment->fileType) ? $attachment->fileType : "";
            $attachment->size = isset($attachment->size) ? intval($attachment->size) : 0;
            $attachment->caption = isset($attachment->caption) ? $attachment->caption : "";
            unset($attachment->file);
            unset($attachment->scr);
            unset($attachment->id);
            SOSSData::Insert($this->commentAttachmentNamespace, $attachment);
        }
    }

    private function body($req) {
        $data = $req->Body(true);
        return isset($data) ? $data : new stdClass();
    }

    private function saveObject($namespace, $primaryKey, $data) {
        if (isset($data->{$primaryKey}) && $data->{$primaryKey} !== "" && intval($data->{$primaryKey}) > 0) {
            return SOSSData::Update($namespace, $data);
        }
        return SOSSData::Insert($namespace, $data);
    }

    private function syncProjectAccess($project) {
        if (!isset($project->projectId)) {
            return;
        }

        $this->deleteByQuery($this->projectAccessNamespace, "projectId:" . $project->projectId);
        $profiles = isset($project->AccessProfiles) ? $project->AccessProfiles : array();
        $items = array();
        foreach ($profiles as $profileId) {
            $profileId = is_object($profileId) && isset($profileId->profileId) ? $profileId->profileId : $profileId;
            $item = new stdClass();
            $item->projectId = $project->projectId;
            $item->profileId = $profileId;
            $item->role = "member";
            array_push($items, $item);
        }
        if (count($items) > 0) {
            SOSSData::Insert($this->projectAccessNamespace, $items);
        }
    }

    private function syncTaskAssignees($task) {
        if (!isset($task->taskId)) {
            return;
        }

        $this->deleteByQuery($this->assigneeNamespace, "taskId:" . $task->taskId);
        $assignees = isset($task->Assignees) ? $task->Assignees : array();
        $items = array();
        foreach ($assignees as $assignee) {
            $item = new stdClass();
            $item->taskId = $task->taskId;
            $item->profileId = isset($assignee->profileId) ? $assignee->profileId : $assignee->id;
            $item->profileName = isset($assignee->profileName) ? $assignee->profileName : (isset($assignee->name) ? $assignee->name : "");
            $item->email = isset($assignee->email) ? $assignee->email : "";
            $item->status = "Active";
            array_push($items, $item);
        }
        if (count($items) > 0) {
            SOSSData::Insert($this->assigneeNamespace, $items);
        }
    }

    private function syncTaskAttachments($task) {
        if (!isset($task->taskId)) {
            return;
        }

        $removed = isset($task->RemovedAttachments) ? $task->RemovedAttachments : array();
        if (count($removed) > 0) {
            SOSSData::Delete($this->attachmentNamespace, $removed);
        }

        $attachments = isset($task->Attachments) ? $task->Attachments : array();
        foreach ($attachments as $attachment) {
            $attachment->taskId = $task->taskId;
            $attachment->fileType = isset($attachment->fileType) ? $attachment->fileType : "";
            $attachment->size = isset($attachment->size) ? intval($attachment->size) : 0;
            $attachment->caption = isset($attachment->caption) ? $attachment->caption : "";
            unset($attachment->file);
            unset($attachment->scr);

            if (isset($attachment->id) && intval($attachment->id) > 0) {
                SOSSData::Update($this->attachmentNamespace, $attachment);
            } else {
                unset($attachment->id);
                $result = SOSSData::Insert($this->attachmentNamespace, $attachment);
                if ($result->success && isset($result->result->generatedId)) {
                    $attachment->id = $result->result->generatedId;
                }
            }
        }
        CacheData::clearObjects($this->attachmentNamespace);
    }

    private function deleteByQuery($namespace, $query) {
        $result = SOSSData::Query($namespace, $query);
        if ($result->success && count($result->result) > 0) {
            SOSSData::Delete($namespace, $result->result);
        }
    }

    private function idsToCsv($ids) {
        $out = array();
        foreach ($ids as $id) {
            if (is_object($id) && isset($id->profileId)) {
                $id = $id->profileId;
            }
            if ($id !== "" && $id !== null) {
                array_push($out, $id);
            }
        }
        return implode(",", $out);
    }

    private function filterProjectsForCurrentProfile($projects) {
        if ($this->isSysAdmin()) {
            return $projects;
        }

        $profileId = $this->currentProfileId();
        if ($profileId === null) {
            return array();
        }

        $access = SOSSData::Query($this->projectAccessNamespace, "profileId:" . $profileId);
        if (!$access->success || count($access->result) === 0) {
            return array();
        }

        $allowed = array();
        foreach ($access->result as $row) {
            $allowed[(string)$row->projectId] = true;
        }

        $filtered = array();
        foreach ($projects as $project) {
            if (isset($allowed[(string)$project->projectId])) {
                array_push($filtered, $project);
            }
        }
        return $filtered;
    }

    private function getProjectProfileIds($projectId, $project = null) {
        $ids = array();
        $access = SOSSData::Query($this->projectAccessNamespace, "projectId:" . $projectId);
        if ($access->success && count($access->result) > 0) {
            foreach ($access->result as $row) {
                array_push($ids, $row->profileId);
            }
            return $ids;
        }

        if ($project !== null && isset($project->profileids) && $project->profileids !== "") {
            return explode(",", $project->profileids);
        }
        return $ids;
    }

    private function getProfilesByIds($ids) {
        if (count($ids) === 0) {
            return array();
        }

        $profileResult = SOSSData::Query("profile", "", null, "asc", 500, 0);
        if (!$profileResult->success) {
            return array();
        }

        $allowed = array();
        foreach ($ids as $id) {
            $allowed[(string)$id] = true;
        }

        $profiles = array();
        foreach ($profileResult->result as $profile) {
            if (isset($allowed[(string)$profile->id])) {
                array_push($profiles, $profile);
            }
        }
        return $profiles;
    }

    private function normalizeProfileIds($ids) {
        $out = array();
        $seen = array();
        foreach ($ids as $id) {
            if (is_object($id)) {
                if (isset($id->profileId)) {
                    $id = $id->profileId;
                } elseif (isset($id->id)) {
                    $id = $id->id;
                }
            }
            if ($id !== "" && $id !== null && !isset($seen[(string)$id])) {
                $seen[(string)$id] = true;
                array_push($out, $id);
            }
        }
        return $out;
    }

    private function filterProfilesByEmail($profiles, $email, $limit) {
        $email = strtolower(trim($email));
        $matches = array();
        foreach ($profiles as $profile) {
            $profileEmail = isset($profile->email) ? strtolower(trim($profile->email)) : "";
            if ($profileEmail !== "" && strpos($profileEmail, $email) !== false) {
                array_push($matches, $profile);
            }
            if (count($matches) >= $limit) {
                break;
            }
        }
        return $matches;
    }

    private function normalizeTasks($tasks) {
        $out = array();
        foreach ($tasks as $task) {
            array_push($out, $this->normalizeTask($task));
        }
        return $out;
    }

    private function normalizeTask($task) {
        if (!isset($task->priority) || trim($task->priority) === "") {
            $task->priority = "Normal";
        }
        if (!isset($task->status) || trim($task->status) === "") {
            $task->status = "New";
        }
        if (!isset($task->progress) || $task->progress === "") {
            $task->progress = 0;
        }
        return $task;
    }

    private function projectName($projectId, &$cache) {
        $summary = $this->projectSummary($projectId, $cache);
        return $summary->name;
    }

    private function attachProjectSummary($task, &$cache) {
        if (!isset($task->projectId)) {
            return;
        }
        $summary = $this->projectSummary($task->projectId, $cache);
        $task->projectName = $summary->name;
        $task->projectColor = $summary->color;
    }

    private function projectSummary($projectId, &$cache) {
        $key = (string)$projectId;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $summary = new stdClass();
        $summary->name = "";
        $summary->color = "";
        $project = SOSSData::Query($this->projectNamespace, "projectId:" . $projectId);
        if ($project->success && count($project->result) > 0) {
            $summary->name = isset($project->result[0]->name) ? $project->result[0]->name : "";
            $summary->color = isset($project->result[0]->projectColor) ? $project->result[0]->projectColor : "";
        }
        $cache[$key] = $summary;
        return $summary;
    }

    private function canAccessTask($taskId) {
        $taskResult = SOSSData::Query($this->taskNamespace, "taskId:" . $taskId);
        if (!$taskResult->success || count($taskResult->result) === 0) {
            return false;
        }
        return $this->canAccessProject($taskResult->result[0]->projectId);
    }

    private function canAccessProject($projectId) {
        if ($this->isSysAdmin()) {
            return true;
        }

        $profileId = $this->currentProfileId();
        if ($profileId === null) {
            return false;
        }

        $access = SOSSData::Query($this->projectAccessNamespace, "projectId:" . $projectId . ",profileId:" . $profileId);
        return $access->success && count($access->result) > 0;
    }

    private function currentProfileId() {
        $profile = $this->currentProfile();
        return $profile->id === 0 ? null : $profile->id;
    }

    private function currentProfile() {
        $out = new stdClass();
        $out->id = 0;
        $out->name = "Unknown";

        if (class_exists("Profile")) {
            $profile = Profile::getUserProfile();
            if (isset($profile->profile) && isset($profile->profile->id)) {
                $out->id = $profile->profile->id;
                $out->name = isset($profile->profile->name) ? $profile->profile->name : "Unknown";
                return $out;
            }
        }

        $user = Auth::Autendicate();
        if (isset($user->userid)) {
            $profileResult = SOSSData::Query("profile", "linkeduserid:" . $user->userid);
            if ($profileResult->success && count($profileResult->result) > 0) {
                $out->id = $profileResult->result[0]->id;
                $out->name = isset($profileResult->result[0]->name) ? $profileResult->result[0]->name : "Unknown";
                return $out;
            }
            $out->name = isset($user->email) ? $user->email : "Unknown";
        }
        return $out;
    }

    private function isSysAdmin() {
        return defined("GROUPID") && GROUPID === "sysadmin";
    }
}
?>
