<?php
namespace lesson_manager;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) require_once(PLUGIN_PATH . "/auth/auth.php");
    if (defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
    if (file_exists(PLUGIN_PATH . "/davvag-flow/flow.php")) require_once(PLUGIN_PATH . "/davvag-flow/flow.php");
}
if (defined("TENANT_RESOURCE_LOCATION") && file_exists(TENANT_RESOURCE_LOCATION . "/apps/davvag-credit-points/lib/CreditLedgerService.php")) require_once(TENANT_RESOURCE_LOCATION . "/apps/davvag-credit-points/lib/CreditLedgerService.php");

class LessonRules {
    public static function truthy($value) {
        return $value === true || $value === 1 || $value === "1" || strtolower(strval($value)) === "true" || strtolower(strval($value)) === "yes";
    }

    public static function requirementsMet($lesson, $progress) {
        if ($progress === null) return false;
        if (self::truthy(isset($progress->override_unlocked) ? $progress->override_unlocked : false)) return true;
        $requirements = array(
            "require_reading" => "reading_completed",
            "require_video" => "video_completed",
            "require_quiz" => "quiz_passed",
            "require_assignment" => "assignment_passed",
            "require_teacher_approval" => "teacher_approved"
        );
        foreach ($requirements as $rule => $state) {
            if (self::truthy(isset($lesson->{$rule}) ? $lesson->{$rule} : false) && !self::truthy(isset($progress->{$state}) ? $progress->{$state} : false)) return false;
        }
        return true;
    }

    public static function scoreQuestion($question, $answer, $negativeEnabled) {
        if (self::truthy(isset($question->requires_manual_marking) ? $question->requires_manual_marking : false) || (isset($question->question_type) && $question->question_type === "short_answer")) return null;
        $expected = isset($question->correct_answer) ? $question->correct_answer : "";
        $correct = self::answerKey($expected) === self::answerKey($answer);
        if ($correct) return floatval(isset($question->marks) ? $question->marks : 1);
        return $negativeEnabled ? -abs(floatval(isset($question->negative_marks) ? $question->negative_marks : 0)) : 0;
    }

    public static function answerKey($value) {
        if (is_object($value)) $value = get_object_vars($value);
        if (is_array($value)) {
            $items = array();
            foreach ($value as $item) $items[] = strtolower(trim(strval($item)));
            sort($items);
            return implode("|", $items);
        }
        return strtolower(trim(strval($value)));
    }

    public static function completionPercent($completed, $total) {
        return $total > 0 ? round(($completed / $total) * 100, 1) : 0;
    }

    public static function validCreditRequirement($isFree, $points) {
        if (self::truthy($isFree)) return true;
        $value = trim(strval($points));
        return preg_match('/^\d+$/', $value) === 1 && intval($value) >= 1 && intval($value) <= 1000000000;
    }
}

class ApiService {
    private $lessonNs = "lesson_manager_lesson";
    private $contentNs = "lesson_manager_content";
    private $videoNs = "lesson_manager_video";
    private $quizNs = "lesson_manager_quiz";
    private $questionNs = "lesson_manager_question";
    private $attemptNs = "lesson_manager_attempt";
    private $progressNs = "lesson_manager_progress";
    private $ruleNs = "lesson_manager_assignment_rule";
    private $courseNs = "course_manager_course";
    private $subjectNs = "course_manager_subject";
    private $classNs = "course_manager_classgrade";
    private $enrollmentNs = "course_manager_enrollment";
    private $assignmentNs = "course_manager_assignment";
    private $submissionNs = "course_manager_submission";
    private $assessmentNs = "course_manager_assessment";
    private $markNs = "course_manager_mark";
    private $notificationNs = "course_manager_notification";
    private $providerNs = "lesson_manager_provider_connection";

    public function postBootstrap($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $subjects=array();foreach($this->rows($this->subjectNs,"","asc") as $subject)if($this->canManageSubject($subject))$subjects[]=$subject;$courseIds=array();foreach($subjects as $subject)$courseIds[strval($subject->course_id)]=true;$courses=array();foreach($this->rows($this->courseNs,"","asc") as $course)if(isset($courseIds[strval($course->id)]))$courses[]=$course;$assignments=array();foreach($this->rows($this->assignmentNs,"","desc") as $assignment)foreach($subjects as $subject)if(strval($subject->id)===strval($assignment->subject_id)){$assignments[]=$assignment;break;}
        return array(
            "role" => $this->currentRole(), "profile" => $this->currentProfile(),
            "courses" => $courses,
            "subjects" => $subjects,
            "classGrades" => $this->rows($this->classNs, "", "asc"),
            "assignments" => $assignments,
            "profiles" => $this->isTeacher() ? $this->rows("profile", "", "asc") : array()
        );
    }

    public function postDashboard($req, $res) {
        if ($this->currentRole() === "student") return array("studentCourses" => $this->studentCourses(new \stdClass(), $res), "role" => "student");
        if (!$this->requireTeacher($res)) return null;
        $lessons = $this->withoutDeleted($this->filterManagedLessons($this->rows($this->lessonNs, "", "asc")));
        $lessonIds=array();foreach($lessons as $lesson)$lessonIds[strval($lesson->id)]=true;
        $quizzes = array();foreach($this->withoutDeleted($this->rows($this->quizNs,"","desc")) as $quiz)if(isset($lessonIds[strval($quiz->lesson_id)]))$quizzes[]=$quiz;
        $progress = $this->rows($this->progressNs, "", "desc");
        $submissions = $this->rows($this->submissionNs, "", "desc");
        $managedProgress=array();foreach($progress as $row)if(isset($lessonIds[strval($row->lesson_id)]))$managedProgress[]=$row;$managedAttempts=array();foreach($this->rows($this->attemptNs,"","desc") as $attempt)if(isset($lessonIds[strval($attempt->lesson_id)]))$managedAttempts[]=$attempt;$pending = 0; foreach ($submissions as $row) if (isset($lessonIds[strval(isset($row->lesson_id)?$row->lesson_id:0)])&&(!isset($row->status) || in_array(strtolower($row->status), array("submitted", "submitted_late", "pending", "pending_manual_marking")))) $pending++;
        return array("role" => $this->currentRole(), "stats" => array(
            "lessons" => count($lessons), "published" => $this->countStatus($lessons, "published"),
            "quizzes" => count($quizzes), "active_students" => $this->distinctCount($managedProgress, "student_id"), "pending_marking" => $pending
        ), "recentLessons" => array_slice(array_reverse($lessons), 0, 6), "recentAttempts" => array_slice($managedAttempts, 0, 6));
    }

    public function postListLessons($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);return $this->filterManagedLessons($this->visibleRows($this->listObject($body,$this->lessonNs,array("id","course_id","subject_id","status"),array("title","description"),"asc"),$body)); }
    public function postSaveLesson($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $item = $this->body($req);
        if (empty($item->subject_id) || empty($item->title)) return $this->error($res, "Subject and lesson title are required.");
        $subject = $this->byId($this->subjectNs, $item->subject_id);
        if (!$subject || empty($subject->course_id)) return $this->error($res, "Select a valid subject assigned to a course.");
        if (!$this->canManageSubject($subject)) return $this->error($res, "You are not authorized to manage this subject.");
        $existing = !empty($item->id) ? $this->byId($this->lessonNs, $item->id) : null;
        if (!empty($item->id) && !$existing) return $this->error($res, "Lesson not found.");
        if ($existing && !$this->canManageLesson($existing)) return $this->error($res, "You are not authorized to update this lesson.");
        if (($existing && $this->isDeleted($existing)) || (isset($item->status) && strtolower($item->status) === "deleted")) return $this->error($res, "Restore a deleted lesson before editing it.");
        $item->course_id = $subject->course_id;
        if (!isset($item->lesson_order) || intval($item->lesson_order) < 1) $item->lesson_order = count($this->withoutDeleted($this->rows($this->lessonNs, "subject_id:" . intval($item->subject_id), "asc"))) + 1;
        if (empty($item->status)) $item->status = "draft";
        if (!isset($item->passing_mark)) $item->passing_mark = 70;
        $isFree = !isset($item->is_free) || LessonRules::truthy($item->is_free);
        $creditPoints = isset($item->required_credit_points) ? $item->required_credit_points : 0;
        if (!LessonRules::validCreditRequirement($isFree, $creditPoints)) return $this->error($res, "A non-free lesson requires a whole credit-point value of at least 1.");
        $item->is_free = $isFree ? "true" : "false";
        $item->required_credit_points = $isFree ? 0 : intval($creditPoints);
        $item->updated_at = date("Y-m-d H:i:s");
        if (empty($item->id)) { $profile = $this->currentProfile(); $item->created_by = $profile->id; $item->created_at = $item->updated_at; }
        elseif ($existing) { $item->created_by = isset($existing->created_by)?$existing->created_by:0; $item->created_at = isset($existing->created_at)?$existing->created_at:$item->updated_at; }
        $saved = $this->persist($this->lessonNs, $item, $res);
        if ($saved && strtolower($saved->status) === "published") $this->notifyCourse($saved->course_id, "lesson-published", "New lesson published: " . $saved->title, "lesson", $saved->id);
        return $saved;
    }
    public function postDeleteLesson($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$lesson=$this->byId($this->lessonNs,isset($body->id)?$body->id:0);if(!$lesson)return $this->error($res,"Lesson not found.");if(!$this->canManageLesson($lesson))return $this->error($res,"You are not authorized to delete this lesson.");$lesson->updated_at=date("Y-m-d H:i:s");return $this->softDelete($this->lessonNs,$lesson,$res); }
    public function postRestoreLesson($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$lesson=$this->byId($this->lessonNs,isset($body->id)?$body->id:0);if(!$lesson||!$this->canManageLesson($lesson))return $this->error($res,"A manageable deleted lesson is required.");$lesson->updated_at=date("Y-m-d H:i:s");return $this->restore($this->lessonNs,$lesson,"draft",$res); }
    public function postReorderLessons($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req); $items = isset($body->lessons) ? $body->lessons : array(); $saved = array();$validated=array();$seen=array();
        $subjectId = 0;
        foreach ($items as $item) {
            $stored = isset($item->id) ? $this->byId($this->lessonNs, $item->id) : null;
            if (!$stored || empty($stored->subject_id) || $this->isDeleted($stored)) return $this->error($res, "Every reordered lesson must be active and belong to a subject.");
            if (!$this->canManageLesson($stored)) return $this->error($res, "You are not authorized to reorder this subject.");
            if ($subjectId === 0) $subjectId = intval($stored->subject_id);
            if (intval($stored->subject_id) !== $subjectId) return $this->error($res, "Lessons can only be reordered within the same subject.");
            if(isset($seen[strval($stored->id)]))return $this->error($res,"A lesson cannot appear twice in the order.");$seen[strval($stored->id)]=true;$validated[]=$stored;
        }
        if($subjectId>0&&count($items)!==count($this->withoutDeleted($this->rows($this->lessonNs,"subject_id:".$subjectId,"asc"))))return $this->error($res,"Submit the complete active subject lesson list when reordering.");
        foreach($validated as $index=>$stored){$stored->lesson_order=$index+1;$saved[]=$this->persist($this->lessonNs,$stored,$res);}
        return $saved;
    }

    public function postListContent($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);return $this->filterManagedChildren($this->visibleRows($this->listObject($body,$this->contentNs,array("id","lesson_id","content_type","status"),array("title","body"),"asc"),$body),"lesson_id"); }
    public function postSaveContent($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title)) return $this->error($res, "Lesson and content title are required.");
        $lesson=$this->byId($this->lessonNs,$item->lesson_id);if(!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"An active manageable lesson is required.");$existing=!empty($item->id)?$this->byId($this->contentNs,$item->id):null;if(($existing&&$this->isDeleted($existing))||(isset($item->status)&&strtolower($item->status)==="deleted"))return $this->error($res,"Restore deleted material before editing it.");
        if(isset($item->url)&&trim(strval($item->url))!==""&&!$this->safeResourceReference($item->url))return $this->error($res,"Only HTTPS or approved uploaded resource references are accepted.");
        if(isset($item->content_type)&&strtolower(strval($item->content_type))==="google_drive"){$embed=$this->googleDriveEmbedUrl(isset($item->embed_url)?$item->embed_url:"");if($embed==="")return $this->error($res,"Paste a valid Google Drive, Docs, Sheets, or Slides sharing link or iframe code.");$item->embed_url=$embed;}else $item->embed_url="";
        $item->body = $this->sanitizeRichText(isset($item->body) ? $item->body : "");
        return $this->persist($this->contentNs, $item, $res);
    }
    public function postDeleteContent($req, $res) { return $this->softDeleteManagedChild($this->contentNs,$this->body($req),$res,"lesson_id"); }
    public function postRestoreContent($req, $res) { return $this->restoreManagedChild($this->contentNs,$this->body($req),$res,"lesson_id","published"); }
    public function postListVideos($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);return $this->filterManagedChildren($this->visibleRows($this->listObject($body,$this->videoNs,array("id","lesson_id","provider","status"),array("title","video_url","transcript"),"asc"),$body),"lesson_id"); }
    public function postSaveVideo($req, $res) {
        if (!$this->requireTeacher($res)) return null; $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title) || (empty($item->video_url) && empty($item->media_reference))) return $this->error($res, "Lesson, title, and a video URL or media reference are required.");
        $lesson=$this->byId($this->lessonNs,$item->lesson_id);if(!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"An active manageable lesson is required.");$existing=!empty($item->id)?$this->byId($this->videoNs,$item->id):null;if(($existing&&$this->isDeleted($existing))||(isset($item->status)&&strtolower($item->status)==="deleted"))return $this->error($res,"Restore a deleted video before editing it.");
        if (empty($item->provider)) $item->provider = $this->videoProvider(isset($item->video_url) ? $item->video_url : "");
        if(!$this->validVideoReference($item))return $this->error($res,"The video URL or media reference is not valid for the selected provider.");
        if (empty($item->status)) $item->status = "published";
        return $this->persist($this->videoNs, $item, $res);
    }
    public function postDeleteVideo($req, $res) { return $this->softDeleteManagedChild($this->videoNs,$this->body($req),$res,"lesson_id"); }
    public function postRestoreVideo($req, $res) { return $this->restoreManagedChild($this->videoNs,$this->body($req),$res,"lesson_id","published"); }

    public function postFetchVideoMetadata($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req);
        $provider = strtolower(trim(isset($body->provider) ? $body->provider : ""));
        $url = trim(isset($body->video_url) ? $body->video_url : "");
        if (!in_array($provider, array("youtube", "facebook"), true)) return $this->error($res, "Automatic metadata is available for YouTube and Facebook only.");
        if (!$this->safeHttpUrl($url)) return $this->error($res, "Enter a valid HTTPS video URL for the selected provider.");
        if ($provider === "youtube") return $this->youtubeMetadata($url, $res);
        return $this->facebookMetadata($url, $res);
    }

    public function postGetProviderSettings($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        return array(
            "encryption_ready" => $this->providerSecret() !== "",
            "required_environment_variable" => "DAVVAG_PROVIDER_SECRET",
            "providers" => array(
                "youtube" => $this->safeProviderConnection("youtube"),
                "facebook" => $this->safeProviderConnection("facebook")
            )
        );
    }

    public function postSaveProviderSettings($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        if ($this->providerSecret() === "") return $this->error($res, "Set DAVVAG_PROVIDER_SECRET on the server before saving provider credentials.");
        $body = $this->body($req);
        $provider = strtolower(trim(isset($body->provider) ? $body->provider : ""));
        if (!in_array($provider, array("youtube", "facebook"), true)) return $this->error($res, "Unsupported provider.");
        $item = $this->providerConnection($provider);
        if (!$item) { $item = new \stdClass(); $item->provider = $provider;$item->connection_scope="tenant"; $item->created_by = $this->currentProfile()->id; $item->created_at = date("Y-m-d H:i:s"); }
        foreach (array("account_name", "account_id", "page_id", "client_id") as $field) if (isset($body->{$field})) $item->{$field} = trim(strval($body->{$field}));
        $secretFields = array("client_secret"=>"client_secret_enc", "api_key"=>"api_key_enc", "access_token"=>"access_token_enc", "refresh_token"=>"refresh_token_enc");
        foreach ($secretFields as $source => $target) if (isset($body->{$source}) && trim(strval($body->{$source})) !== "" && strval($body->{$source}) !== "********") $item->{$target} = $this->encryptProviderSecret(strval($body->{$source}));
        $item->status = $this->hasProviderCredential($item) ? "connected" : "configured";
        $item->last_error = ""; $item->updated_at = date("Y-m-d H:i:s");
        $saved = $this->persist($this->providerNs, $item, $res);
        return $saved ? $this->safeProviderRow($saved) : null;
    }

    public function postDisconnectProvider($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req); $provider = strtolower(trim(isset($body->provider) ? $body->provider : ""));
        $item = $this->providerConnection($provider); if (!$item) return array("provider"=>$provider, "status"=>"disconnected");
        foreach (array("client_secret_enc","api_key_enc","access_token_enc","refresh_token_enc") as $field) $item->{$field} = null;
        $item->status = "disconnected"; $item->account_name = ""; $item->account_id = ""; $item->last_error = ""; $item->updated_at = date("Y-m-d H:i:s");
        return $this->safeProviderRow($this->persist($this->providerNs, $item, $res));
    }

    public function postTestProvider($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req); $provider = strtolower(trim(isset($body->provider) ? $body->provider : ""));
        $item = $this->providerConnection($provider); if (!$item) return $this->error($res, "Configure this provider first.");
        $result = $provider === "youtube" ? $this->testYouTubeConnection($item) : ($provider === "facebook" ? $this->testFacebookConnection($item) : null);
        if (!$result) return $this->error($res, "Unsupported provider.");
        $item->last_tested_at = date("Y-m-d H:i:s"); $item->last_error = $result->success ? "" : $result->message; $item->status = $result->success ? "connected" : "error"; $this->persist($this->providerNs, $item, $res);
        if (!$result->success) return $this->error($res, $result->message);
        return array("provider"=>$provider, "status"=>"connected", "message"=>$result->message);
    }

    public function postStartProviderOAuth($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req); $provider = strtolower(trim(isset($body->provider) ? $body->provider : ""));
        $item = $this->providerConnection($provider); if (!$item) return $this->error($res, "Save the provider client configuration before connecting an account.");
        $clientId = isset($item->client_id) ? trim($item->client_id) : ""; $clientSecret = $this->providerValue($item, "client_secret_enc");
        if ($clientId === "" || $clientSecret === "") return $this->error($res, "Client ID and client secret are required before OAuth connection.");
        $state = bin2hex(random_bytes(24)); $redirect = $this->providerCallbackUrl();
        if (!isset($_SESSION)) session_start();
        $_SESSION["lesson_manager_oauth_" . $state] = array("provider"=>$provider, "created"=>time(), "redirect_uri"=>$redirect,"profile_id"=>$this->currentProfile()->id);
        if ($provider === "youtube") {
            $params = array("client_id"=>$clientId,"redirect_uri"=>$redirect,"response_type"=>"code","access_type"=>"offline","prompt"=>"consent","include_granted_scopes"=>"true","scope"=>"https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl","state"=>$state);
            return array("authorize_url"=>"https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params));
        }
        if ($provider === "facebook") {
            $params = array("client_id"=>$clientId,"redirect_uri"=>$redirect,"response_type"=>"code","scope"=>"pages_show_list,pages_read_engagement,pages_read_user_content","state"=>$state);
            return array("authorize_url"=>$this->facebookDialogUrl() . "?" . http_build_query($params));
        }
        return $this->error($res, "Unsupported provider.");
    }

    public function getProviderOAuthCallback($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $state = isset($_GET["state"]) ? strval($_GET["state"]) : ""; $code = isset($_GET["code"]) ? strval($_GET["code"]) : "";
        if (!isset($_SESSION)) session_start(); $key = "lesson_manager_oauth_" . $state;
        if ($state === "" || !isset($_SESSION[$key])) return $this->error($res, "OAuth state is missing or expired.");
        $stateData = $_SESSION[$key]; unset($_SESSION[$key]);
        if (empty($stateData["created"]) || time() - intval($stateData["created"]) > 900) return $this->error($res, "OAuth state has expired.");
        if(empty($stateData["profile_id"])||intval($stateData["profile_id"])!==intval($this->currentProfile()->id))return $this->error($res,"OAuth callback ownership could not be verified.");
        if ($code === "") return $this->error($res, isset($_GET["error_description"]) ? $_GET["error_description"] : "Provider authorization was cancelled.");
        $provider = $stateData["provider"]; $item = $this->providerConnection($provider); if (!$item) return $this->error($res, "Provider configuration no longer exists.");
        $fields = array("client_id"=>$item->client_id,"client_secret"=>$this->providerValue($item,"client_secret_enc"),"code"=>$code,"redirect_uri"=>$stateData["redirect_uri"]);
        if ($provider === "youtube") { $fields["grant_type"] = "authorization_code"; $token = $this->curlRequest("https://oauth2.googleapis.com/token", "POST", $fields, array()); }
        else { $token = $this->curlRequest($this->facebookGraphUrl("oauth/access_token"), "GET", $fields, array()); }
        if (!$token->success || !isset($token->data["access_token"])) return $this->error($res, "OAuth token exchange failed: " . $token->message);
        if($provider==="facebook"){$long=$this->curlRequest($this->facebookGraphUrl("oauth/access_token"),"GET",array("grant_type"=>"fb_exchange_token","client_id"=>$item->client_id,"client_secret"=>$this->providerValue($item,"client_secret_enc"),"fb_exchange_token"=>$token->data["access_token"]),array());if($long->success&&!empty($long->data["access_token"]))$token=$long;}
        $item->access_token_enc = $this->encryptProviderSecret($token->data["access_token"]);
        if (isset($token->data["refresh_token"])) $item->refresh_token_enc = $this->encryptProviderSecret($token->data["refresh_token"]);
        if (isset($token->data["expires_in"])) $item->expires_at = date("Y-m-d H:i:s", time() + intval($token->data["expires_in"]));
        if (isset($token->data["scope"])) $item->scopes = preg_split('/[\s,]+/', trim(strval($token->data["scope"])), -1, PREG_SPLIT_NO_EMPTY);
        if ($provider === "youtube") {
            $identity = $this->curlRequest("https://www.googleapis.com/youtube/v3/channels", "GET", array("part"=>"snippet", "mine"=>"true"), array("Authorization: Bearer " . $token->data["access_token"]));
            if ($identity->success && !empty($identity->data["items"][0])) { $channel=$identity->data["items"][0]; if(isset($channel["id"]))$item->account_id=$channel["id"]; if(isset($channel["snippet"]["title"]))$item->account_name=$channel["snippet"]["title"]; }
        } else {
            $pages = $this->curlRequest($this->facebookGraphUrl("me/accounts"), "GET", array("fields"=>"id,name,access_token", "access_token"=>$token->data["access_token"]), array());
            if ($pages->success && !empty($pages->data["data"])) { $page=$pages->data["data"][0]; if(!empty($item->page_id))foreach($pages->data["data"] as $candidate)if(isset($candidate["id"])&&strval($candidate["id"])===strval($item->page_id)){$page=$candidate;break;} if(isset($page["id"])){$item->page_id=$page["id"];$item->account_id=$page["id"];}if(isset($page["name"]))$item->account_name=$page["name"];if(isset($page["access_token"]))$item->access_token_enc=$this->encryptProviderSecret($page["access_token"]); }
        }
        $item->status = "connected"; $item->last_error = ""; $item->updated_at = date("Y-m-d H:i:s"); $this->persist($this->providerNs, $item, $res);
        header("Location: " . $this->appBaseUrl() . "/#/app/lesson-manager/settings?oauth=" . rawurlencode($provider));
        return array("provider"=>$provider,"status"=>"connected");
    }

    public function postListQuizzes($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);return $this->filterManagedQuizRows($this->visibleRows($this->listObject($body,$this->quizNs,array("id","lesson_id","status"),array("title"),"desc"),$body)); }
    public function postSaveQuiz($req, $res) {
        if (!$this->requireTeacher($res)) return null; $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title)) return $this->error($res, "Lesson and quiz title are required.");
        $lesson=$this->byId($this->lessonNs,$item->lesson_id);if(!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"An active manageable lesson is required.");$existing=!empty($item->id)?$this->byId($this->quizNs,$item->id):null;if(($existing&&$this->isDeleted($existing))||(isset($item->status)&&strtolower($item->status)==="deleted"))return $this->error($res,"Restore a deleted quiz before editing it.");
        if(isset($item->passing_percentage)&&(floatval($item->passing_percentage)<0||floatval($item->passing_percentage)>100))return $this->error($res,"Passing percentage must be between 0 and 100.");
        if(isset($item->attempt_limit)&&intval($item->attempt_limit)<0)return $this->error($res,"Attempt limit cannot be negative.");
        if(isset($item->time_limit_minutes)&&intval($item->time_limit_minutes)<0)return $this->error($res,"Time limit cannot be negative.");
        if (!isset($item->passing_percentage)) $item->passing_percentage = 70;
        if (!isset($item->attempt_limit)) $item->attempt_limit = 3;
        if (empty($item->status)) $item->status = "draft";
        if (empty($item->created_at)) { $item->created_at = date("Y-m-d H:i:s"); $item->created_by = $this->currentProfile()->id; }
        $saved = $this->persist($this->quizNs, $item, $res);
        if($saved)$saved=$this->syncQuizAssessment($saved,$lesson,$res);
        return $saved;
    }
    public function postDeleteQuiz($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$quiz=$this->byId($this->quizNs,isset($body->id)?$body->id:0);if(!$quiz)return $this->error($res,"Quiz not found.");$lesson=$this->byId($this->lessonNs,$quiz->lesson_id);if(!$lesson||!$this->canManageLesson($lesson))return $this->error($res,"You are not authorized to delete this quiz.");$quiz=$this->softDelete($this->quizNs,$quiz,$res);if($quiz)$quiz=$this->syncQuizAssessment($quiz,$lesson,$res);return $quiz; }
    public function postRestoreQuiz($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$quiz=$this->byId($this->quizNs,isset($body->id)?$body->id:0);$lesson=$quiz?$this->byId($this->lessonNs,$quiz->lesson_id):null;if(!$quiz||!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"A deleted quiz under an active manageable lesson is required.");$quiz=$this->restore($this->quizNs,$quiz,"draft",$res);if($quiz)$quiz=$this->syncQuizAssessment($quiz,$lesson,$res);return $quiz; }
    public function postListQuestions($req, $res) {
        $body = $this->body($req);
        if (!$this->isTeacher()) {
            if (empty($body->quiz_id)) return $this->error($res, "quiz_id is required.");
            $quiz = $this->byId($this->quizNs, $body->quiz_id); $lesson = $quiz ? $this->byId($this->lessonNs, $quiz->lesson_id) : null; $student = $this->currentProfile();
            if (!$quiz || strtolower($quiz->status) !== "published" || !$lesson || !$this->canAccessCourse($lesson->course_id, $student->id) || !$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Quiz access denied.");
        }
        $rows = $this->visibleRows($this->listObject($body, $this->questionNs, array("id","quiz_id","question_type","difficulty","status"), array("question_text","explanation"), "asc"),$body);
        if ($this->isTeacher()) return $this->filterManagedQuestionRows($rows);
        if(LessonRules::truthy(isset($quiz->random_questions)?$quiz->random_questions:false))shuffle($rows);
        foreach($rows as $row)if(LessonRules::truthy(isset($quiz->random_answers)?$quiz->random_answers:false)&&isset($row->options)&&is_array($row->options))shuffle($row->options);
        foreach ($rows as $row) { unset($row->correct_answer); unset($row->explanation); unset($row->negative_marks); }
        return $rows;
    }
    public function postSaveQuestion($req, $res) { if(!$this->requireTeacher($res))return null;$item=$this->body($req);if(empty($item->quiz_id)||empty($item->question_text))return $this->error($res,"Quiz and question text are required.");$quiz=$this->byId($this->quizNs,$item->quiz_id);$lesson=$quiz?$this->byId($this->lessonNs,$quiz->lesson_id):null;if(!$quiz||$this->isDeleted($quiz)||!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"An active manageable quiz is required.");$existing=!empty($item->id)?$this->byId($this->questionNs,$item->id):null;if(($existing&&$this->isDeleted($existing))||(isset($item->status)&&strtolower($item->status)==="deleted"))return $this->error($res,"Restore a deleted question before editing it.");if(!in_array(isset($item->question_type)?$item->question_type:"",array("multiple_choice","true_false","multiple_answer","fill_blank","short_answer"),true))return $this->error($res,"Unsupported question type.");if(floatval(isset($item->marks)?$item->marks:0)<=0)return $this->error($res,"Question marks must be greater than zero.");if(empty($item->status))$item->status="active";$saved=$this->persist($this->questionNs,$item,$res);if($saved)$this->syncQuizAssessment($quiz,$lesson,$res);return $saved; }
    public function postDeleteQuestion($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$question=$this->byId($this->questionNs,isset($body->id)?$body->id:0);$quiz=$question?$this->byId($this->quizNs,$question->quiz_id):null;$lesson=$quiz?$this->byId($this->lessonNs,$quiz->lesson_id):null;if(!$question||!$lesson||!$this->canManageLesson($lesson))return $this->error($res,"A manageable question is required.");$deleted=$this->softDelete($this->questionNs,$question,$res);if($deleted)$this->syncQuizAssessment($quiz,$lesson,$res);return $deleted; }
    public function postRestoreQuestion($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);$question=$this->byId($this->questionNs,isset($body->id)?$body->id:0);$quiz=$question?$this->byId($this->quizNs,$question->quiz_id):null;$lesson=$quiz?$this->byId($this->lessonNs,$quiz->lesson_id):null;if(!$question||!$quiz||$this->isDeleted($quiz)||!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"A deleted question under an active manageable quiz is required.");$restored=$this->restore($this->questionNs,$question,"active",$res);if($restored)$this->syncQuizAssessment($quiz,$lesson,$res);return $restored; }

    public function postListQuizAgents($req,$res) {
        if(!$this->requireTeacher($res))return null;
        try{$service=$this->creatorService();$result=$service->getListAgents(null,null);return isset($result->agents)?$result->agents:array();}
        catch(\Exception $e){return $this->error($res,"AI agents could not be loaded: ".$e->getMessage());}
    }

    public function postGenerateQuiz($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req);
        if (empty($body->lesson_id)) return $this->error($res, "Select a lesson first.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson || $this->isDeleted($lesson)) return $this->error($res, "An active lesson is required.");
        if(!$this->canManageLesson($lesson))return $this->error($res,"You are not authorized to generate a quiz for this lesson.");
        $agentCode=trim(isset($body->agent_code)?strval($body->agent_code):"");if($agentCode==="")return $this->error($res,"Select a saved AI quiz agent.");
        $source = isset($lesson->description) ? strval($lesson->description) : "";
        foreach ($this->withoutDeleted($this->rows($this->contentNs, "lesson_id:" . intval($lesson->id), "asc")) as $row){$source.="\nCONTENT ".(isset($row->title)?$row->title:"").": ".(isset($row->body)?$this->plainText($row->body):"");if(isset($row->url)&&$row->url){$source.="\nRESOURCE: ".$row->url;$resourceText=$this->uploadedResourceText($row->url);if($resourceText!=="")$source.="\nEXTRACTED RESOURCE TEXT: ".$resourceText;}}
        foreach ($this->withoutDeleted($this->rows($this->videoNs, "lesson_id:" . intval($lesson->id), "asc")) as $row) $source .= " " . (isset($row->transcript) ? $this->plainText($row->transcript) : "");
        if (isset($body->notes)) $source .= " " . $body->notes;
        $limit=isset($body->question_count)?max(1,min(20,intval($body->question_count))):5;if(strlen(trim($source))<40)return $this->error($res,"Add sufficient lesson material or transcript before generating a quiz.");
        $prompt=$this->quizGenerationPrompt($lesson,$source,$limit);
        $profile=$this->currentProfile();$flowInput=new \stdClass();$flowInput->agentCode=$agentCode;$flowInput->message=$prompt;$flowInput->profile=array("profileId"=>strval($profile->id),"name"=>isset($profile->name)?$profile->name:"Teacher","sourceApp"=>"lesson-manager");$flowInput->sessionId="lesson-quiz-".intval($lesson->id)."-".bin2hex(random_bytes(4));$flowInput->flow=array("flowCode"=>"lesson-manager-generate-quiz","name"=>"Lesson quiz generation");$flowInput->connector=array("code"=>"lesson-manager","label"=>"Lesson Manager");$flowInput->payload=array("lesson_id"=>$lesson->id,"question_count"=>$limit);
        try{$execution=\DavvagFlow::Execute("lesson-manager","generate-quiz",$flowInput);$agentResult=isset($execution->outData->agentResult)?$execution->outData->agentResult:null;$reply=$agentResult&&isset($agentResult->reply)?$agentResult->reply:"";$generated=$this->decodeAgentJson($reply);}catch(\Exception $e){return $this->error($res,"AI quiz generation failed: ".$e->getMessage());}
        if(!$generated||!isset($generated->questions)||!is_array($generated->questions)||count($generated->questions)===0)return $this->error($res,"The AI agent did not return valid quiz JSON.");
        $quiz=new \stdClass();$quiz->lesson_id=$lesson->id;$quiz->title=isset($body->title)&&trim($body->title)!==""?$body->title:(isset($generated->title)?$generated->title:$lesson->title." knowledge check");$quiz->instructions=isset($generated->instructions)?$generated->instructions:"Answer every question.";$quiz->passing_percentage=isset($body->passing_percentage)?max(0,min(100,floatval($body->passing_percentage))):70;$quiz->attempt_limit=3;$quiz->time_limit_minutes=15;$quiz->random_questions="true";$quiz->random_answers="true";$quiz->negative_marking="false";$quiz->status="draft";$quiz->created_by=$profile->id;$quiz->created_at=date("Y-m-d H:i:s");$quiz=$this->persist($this->quizNs,$quiz,$res);if(!$quiz)return null;
        $questions=array();$allowed=array("multiple_choice","true_false","multiple_answer","fill_blank","short_answer");foreach(array_slice($generated->questions,0,$limit) as $index=>$draft){if(!is_object($draft))continue;$type=isset($draft->question_type)&&in_array($draft->question_type,$allowed,true)?$draft->question_type:"multiple_choice";if(empty($draft->question_text))continue;$q=new \stdClass();$q->quiz_id=$quiz->id;$q->question_type=$type;$q->question_text=trim($draft->question_text);$q->options=isset($draft->options)&&is_array($draft->options)?$draft->options:array();$q->correct_answer=isset($draft->correct_answer)?$draft->correct_answer:"";$q->explanation=isset($draft->explanation)?$draft->explanation:"";$q->difficulty=isset($draft->difficulty)&&in_array($draft->difficulty,array("easy","medium","hard"),true)?$draft->difficulty:"medium";$q->marks=max(.5,floatval(isset($draft->marks)?$draft->marks:1));$q->negative_marks=max(0,floatval(isset($draft->negative_marks)?$draft->negative_marks:0));$q->sort_order=$index+1;$q->requires_manual_marking=$type==="short_answer"?"true":"false";$q->status="active";$questions[]=$this->persist($this->questionNs,$q,$res);}
        if(count($questions)===0){$quiz->status="invalid";$this->persist($this->quizNs,$quiz,$res);return $this->error($res,"The AI response contained no usable questions.");}
        return array("quiz"=>$quiz,"questions"=>$questions,"source_characters"=>strlen($source),"generator"=>"ai-agent-creator","agent_code"=>$agentCode,"workflow"=>"lesson-manager/generate-quiz");
    }

    public function postStudentCourses($req, $res) { return $this->studentCourses($this->body($req),$res); }
    public function postLearningCourse($req, $res) {
        $body = $this->body($req); if (empty($body->course_id)) return $this->error($res, "course_id is required.");
        $student = $this->requestedStudent($body); if(!$this->validProfile($student))return $this->error($res,"An active profile is required.");if (!$this->canAccessCourse($body->course_id, $student->id)) return $this->error($res, "This course is not assigned to you.");
        $course = $this->byId($this->courseNs, $body->course_id);if(!$course)return $this->error($res,"Course not found."); $lessons = $this->rows($this->lessonNs, "course_id:" . intval($body->course_id), "asc"); usort($lessons, array($this, "sortLessonsBySubject"));
        $progress = $this->rows($this->progressNs, "course_id:" . intval($body->course_id) . ",student_id:" . intval($student->id), "asc"); $progressMap = $this->mapBy($progress, "lesson_id");
        $subjects = $this->mapBy($this->rows($this->subjectNs, "course_id:" . intval($body->course_id), "asc"), "id");
        $previousMet = true; $activeSubjectId = null; $out = array();
        foreach ($lessons as $lesson) {
            if ($this->isDeleted($lesson)) continue;
            if (isset($lesson->status) && strtolower($lesson->status) !== "published" && !$this->isTeacher()) continue;
            if (empty($lesson->subject_id)) continue;
            if ($activeSubjectId === null || strval($activeSubjectId) !== strval($lesson->subject_id)) { $activeSubjectId = $lesson->subject_id; $previousMet = true; }
            $p = isset($progressMap[strval($lesson->id)]) ? $progressMap[strval($lesson->id)] : null;
            $available = empty($lesson->available_at) || strtotime($lesson->available_at) <= time();$progressionUnlocked=$previousMet&&$available;$paidUnlocked=$this->hasPaidLessonAccess($lesson,$student->id); $unlocked = $this->isTeacher() || ($progressionUnlocked && $paidUnlocked);
            $reason = ""; if (!$available) $reason = "Available on " . $lesson->available_at; elseif (!$previousMet) $reason = "Complete the previous lesson requirements.";elseif(!$paidUnlocked)$reason="Unlock for ".intval($lesson->required_credit_points)." credits.";
            $entry = clone $lesson; $entry->subject = isset($subjects[strval($lesson->subject_id)]) ? $subjects[strval($lesson->subject_id)] : null; $entry->progress = $p; $entry->unlocked = $unlocked; $entry->progression_unlocked=$progressionUnlocked;$entry->credit_locked=!$this->isTeacher()&&$progressionUnlocked&&!$paidUnlocked;$entry->lock_reason = $reason;
            $entry->content = $unlocked ? $this->safeContentRows($this->publishedRows($this->contentNs, "lesson_id:" . intval($lesson->id))) : array();
            $entry->videos = $unlocked ? $this->safeVideoRows($this->publishedRows($this->videoNs, "lesson_id:" . intval($lesson->id))) : array();
            $entry->quizzes = $unlocked ? $this->studentQuizRows($lesson->id,$student->id) : array();
            $entry->assignmentRules = $unlocked ? $this->studentAssignmentRules($lesson->id,$student->id) : array(); $out[] = $entry;
            $previousMet = !LessonRules::truthy(isset($lesson->progression_enabled) ? $lesson->progression_enabled : true) || LessonRules::requirementsMet($lesson, $p);
        }
        return array("course" => $course, "student" => $student, "lessons" => $out);
    }

    public function postStartLesson($req, $res) {
        $body=$this->body($req);$lesson=!empty($body->lesson_id)?$this->byId($this->lessonNs,$body->lesson_id):null;$student=$this->requestedStudent($body);
        if(!$lesson||$this->isDeleted($lesson)||!$this->validProfile($student))return$this->error($res,"A valid active lesson and learner profile are required.");
        if(!$this->canAccessCourse($lesson->course_id,$student->id)||!$this->progressionUnlockedFor($lesson,$student->id))return$this->error($res,"Complete the previous lesson requirements before opening this lesson.");
        if(!$this->isTeacher()&&!$this->hasPaidLessonAccess($lesson,$student->id)){try{$this->creditLedger()->unlockLesson($student->id,$lesson->id,intval($lesson->required_credit_points),array("description"=>"Unlock lesson: ".strval($lesson->title)));}catch(\Throwable$e){return$this->error($res,$e->getMessage());}}
        return $this->touchProgress($body,"viewed",$res);
    }
    public function postCompleteActivity($req, $res) {
        $body = $this->body($req); if (empty($body->lesson_id) || empty($body->activity)) return $this->error($res, "lesson_id and activity are required.");
        $allowed = array("reading"=>"reading_completed", "video"=>"video_completed"); if (!isset($allowed[$body->activity])) return $this->error($res, "Unsupported activity.");
        return $this->touchProgress($body, $allowed[$body->activity], $res);
    }

    public function postStartQuiz($req,$res) {
        $body=$this->body($req);if(empty($body->quiz_id))return $this->error($res,"quiz_id is required.");
        $quiz=$this->byId($this->quizNs,$body->quiz_id);if(!$quiz||strtolower(isset($quiz->status)?$quiz->status:"")!=="published")return $this->error($res,"Quiz is not available.");
        $lesson=$this->byId($this->lessonNs,$quiz->lesson_id);$student=$this->requestedStudent($body);if(!$lesson||!$this->validProfile($student))return $this->error($res,"An active learner profile is required.");
        if(!$this->canAccessCourse($lesson->course_id,$student->id)||!$this->lessonUnlockedFor($lesson,$student->id))return $this->error($res,"Complete the previous lesson requirements before opening this quiz.");
        $attempts=$this->rows($this->attemptNs,"quiz_id:".intval($quiz->id).",student_id:".intval($student->id),"desc");$active=null;foreach($attempts as $existing){if(isset($existing->status)&&$existing->status==="in_progress"){$limit=intval(isset($quiz->time_limit_minutes)?$quiz->time_limit_minutes:0);if($limit<=0||strtotime($existing->started_at)+($limit*60)>=time()){$active=$existing;break;}$existing->status="timed_out";$existing->completed_at=date("Y-m-d H:i:s");$this->persist($this->attemptNs,$existing,$res);}}
        if(!$active){if(intval(isset($quiz->attempt_limit)?$quiz->attempt_limit:0)>0&&count($attempts)>=intval($quiz->attempt_limit))return $this->error($res,"Attempt limit reached.");$active=new \stdClass();$active->quiz_id=$quiz->id;$active->lesson_id=$lesson->id;$active->course_id=$lesson->course_id;$active->student_id=$student->id;$active->student_name=$student->name;$active->attempt_number=count($attempts)+1;$active->answers=new \stdClass();$active->marks=0;$active->max_mark=$this->quizMaxMark($quiz->id);$active->percentage=0;$active->passed="false";$active->status="in_progress";$active->started_at=date("Y-m-d H:i:s");$active=$this->persist($this->attemptNs,$active,$res);}
        $progress=$this->progress($lesson->course_id,$lesson->id,$student);$progress->quiz_started="true";if(empty($progress->quiz_started_at))$progress->quiz_started_at=date("Y-m-d H:i:s");$this->finishProgress($lesson,$progress,$res);
        return array("attempt"=>$active,"questions"=>$this->studentQuestionRows($quiz),"server_time"=>date("c"),"deadline"=>intval(isset($quiz->time_limit_minutes)?$quiz->time_limit_minutes:0)>0?date("c",strtotime($active->started_at)+intval($quiz->time_limit_minutes)*60):null);
    }

    public function postSubmitQuiz($req, $res) {
        $body = $this->body($req); if (empty($body->quiz_id)||empty($body->attempt_id)) return $this->error($res, "quiz_id and attempt_id are required.");
        $quiz = $this->byId($this->quizNs, $body->quiz_id); if (!$quiz || strtolower($quiz->status) !== "published") return $this->error($res, "Quiz is not available.");
        $lesson = $this->byId($this->lessonNs, $quiz->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        $student = $this->requestedStudent($body);if(!$this->validProfile($student))return $this->error($res,"An active profile is required."); if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied.");
        if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements before opening this quiz.");
        $attempt=$this->byId($this->attemptNs,$body->attempt_id);if(!$attempt||strval($attempt->quiz_id)!==strval($quiz->id)||strval($attempt->student_id)!==strval($student->id)||strtolower(isset($attempt->status)?$attempt->status:"")!=="in_progress")return $this->error($res,"The active quiz attempt is invalid or already submitted.");
        $timeLimit=intval(isset($quiz->time_limit_minutes)?$quiz->time_limit_minutes:0);if($timeLimit>0&&strtotime($attempt->started_at)+($timeLimit*60)<time()){$attempt->status="timed_out";$attempt->completed_at=date("Y-m-d H:i:s");$this->persist($this->attemptNs,$attempt,$res);return $this->error($res,"The quiz time limit has expired.");}
        $answers = isset($body->answers) ? $body->answers : new \stdClass(); $questions = $this->withoutDeleted($this->rows($this->questionNs, "quiz_id:" . intval($quiz->id), "asc")); $score = 0; $max = 0; $manual = false;
        foreach ($questions as $q) { $max += floatval(isset($q->marks) ? $q->marks : 1); $answer = $this->answerFor($answers, $q->id); $earned = LessonRules::scoreQuestion($q, $answer, LessonRules::truthy($quiz->negative_marking)); if ($earned === null) $manual = true; else $score += $earned; }
        $score = max(0, $score); $percentage = $max > 0 ? round(($score / $max) * 100, 2) : 0; $passed = !$manual && $percentage >= floatval($quiz->passing_percentage);
        $attempt->answers = $answers; $attempt->automatic_marks=$score;$attempt->manual_marks=0;$attempt->marks = $score; $attempt->max_mark = $max; $attempt->percentage = $percentage; $attempt->passed = $passed ? "true" : "false"; $attempt->status = $manual ? "pending_manual_marking" : ($passed ? "passed" : "failed"); $attempt->completed_at = date("Y-m-d H:i:s");
        $attempt = $this->persist($this->attemptNs, $attempt, $res);if(!$manual)$this->saveQuizMark($quiz, $lesson, $student, $attempt, $res);
        $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->quiz_completed = $manual ? "false" : "true";if(!$manual)$progress->quiz_completed_at=date("Y-m-d H:i:s"); $progress->quiz_passed = $passed ? "true" : "false"; $progress->quiz_attempts = $attempt->attempt_number; $progress->quiz_mark = $score; $this->finishProgress($lesson, $progress, $res);
        $event=$manual?"quiz-submitted":($passed?"quiz-passed":"quiz-failed");$message=$manual?"Quiz submitted for teacher marking: ".$quiz->title:(($passed?"Passed: ":"Attempt completed: ").$quiz->title);$this->queueNotification($student,$event,$message,"quiz",$quiz->id);
        return array("attempt" => $attempt, "passed" => $passed, "manual_marking" => $manual, "next_unlocked" => $passed && LessonRules::requirementsMet($lesson, $progress));
    }

    public function postListQuizAttempts($req,$res){if(!$this->requireTeacher($res))return null;$body=$this->body($req);$rows=$this->listObject($body,$this->attemptNs,array("quiz_id","lesson_id","course_id","student_id","status"),array("student_name","feedback"),"desc");$out=array();foreach($rows as $attempt){$lesson=$this->byId($this->lessonNs,$attempt->lesson_id);if(!$lesson||!$this->canManageLesson($lesson))continue;if(isset($body->subject_id)&&$body->subject_id&&strval($lesson->subject_id)!==strval($body->subject_id))continue;if(isset($body->date_from)&&$body->date_from&&strtotime($attempt->started_at)<strtotime($body->date_from))continue;if(isset($body->date_to)&&$body->date_to&&strtotime($attempt->started_at)>strtotime($body->date_to." 23:59:59"))continue;if(isset($body->pass_status)&&$body->pass_status!==""){$passed=LessonRules::truthy(isset($attempt->passed)?$attempt->passed:false);if(($body->pass_status==="passed")!==$passed)continue;}$item=clone $attempt;$item->lesson=$lesson;$item->quiz=$this->byId($this->quizNs,$attempt->quiz_id);$item->quiz_title=$item->quiz&&isset($item->quiz->title)?$item->quiz->title:"Quiz #".$attempt->quiz_id;$item->total_marks=isset($attempt->marks)?$attempt->marks:0;$item->max_marks=isset($attempt->max_mark)?$attempt->max_mark:0;$item->questions=$this->rows($this->questionNs,"quiz_id:".intval($attempt->quiz_id),"asc");$item->manual_max_marks=0;foreach($item->questions as $question)if(LessonRules::truthy(isset($question->requires_manual_marking)?$question->requires_manual_marking:false)||$question->question_type==="short_answer")$item->manual_max_marks+=floatval(isset($question->marks)?$question->marks:1);$out[]=$item;}return $out;}

    public function postReviewQuizAttempt($req,$res){if(!$this->requireTeacher($res))return null;$body=$this->body($req);$attempt=$this->byId($this->attemptNs,isset($body->attempt_id)?$body->attempt_id:0);if(!$attempt||$attempt->status!=="pending_manual_marking")return $this->error($res,"A pending manual quiz attempt is required.");$lesson=$this->byId($this->lessonNs,$attempt->lesson_id);$quiz=$this->byId($this->quizNs,$attempt->quiz_id);if(!$lesson||!$quiz||!$this->canManageLesson($lesson))return $this->error($res,"You are not authorized to review this attempt.");$automatic=floatval(isset($attempt->automatic_marks)?$attempt->automatic_marks:$attempt->marks);$manualMax=0;foreach($this->rows($this->questionNs,"quiz_id:".intval($quiz->id),"asc") as $q)if(LessonRules::truthy(isset($q->requires_manual_marking)?$q->requires_manual_marking:false)||$q->question_type==="short_answer")$manualMax+=floatval(isset($q->marks)?$q->marks:1);$manual=max(0,floatval(isset($body->manual_marks)?$body->manual_marks:0));if($manual>$manualMax)return $this->error($res,"Manual marks cannot exceed ".$manualMax.".");$attempt->manual_marks=$manual;$attempt->marks=$automatic+$manual;$attempt->percentage=floatval($attempt->max_mark)>0?round(($attempt->marks/$attempt->max_mark)*100,2):0;$passed=$attempt->percentage>=floatval($quiz->passing_percentage);$attempt->passed=$passed?"true":"false";$attempt->status=$passed?"passed":"failed";$attempt->feedback=isset($body->feedback)?trim(strval($body->feedback)):"";$profile=$this->currentProfile();$attempt->reviewed_by=$profile->id;$attempt->reviewed_at=date("Y-m-d H:i:s");$attempt=$this->persist($this->attemptNs,$attempt,$res);$student=$this->profile($attempt->student_id);$this->saveQuizMark($quiz,$lesson,$student,$attempt,$res);$progress=$this->progress($lesson->course_id,$lesson->id,$student);$progress->quiz_completed="true";$progress->quiz_passed=$passed?"true":"false";$progress->quiz_attempts=max(intval($progress->quiz_attempts),intval($attempt->attempt_number));$progress->quiz_mark=$attempt->marks;$this->finishProgress($lesson,$progress,$res);$this->queueNotification($student,$passed?"quiz-passed":"quiz-failed","Your manually reviewed quiz score is ".$attempt->percentage."%.","quiz",$quiz->id);return $attempt;}

    public function postOverrideLesson($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->lesson_id) || empty($body->student_id)) return $this->error($res, "Lesson and student are required.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        $student = $this->profile($body->student_id); $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->override_unlocked = "true"; $progress->teacher_approved = "true"; return $this->finishProgress($lesson, $progress, $res);
    }

    public function postListAssignmentRules($req, $res) { if(!$this->requireTeacher($res))return null;$body=$this->body($req);return $this->filterManagedChildren($this->visibleRows($this->listObject($body,$this->ruleNs,array("id","lesson_id","assignment_id","status"),array("allowed_formats"),"desc"),$body),"lesson_id"); }
    public function postSaveAssignmentRule($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->lesson_id)) return $this->error($res, "Lesson is required.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson)) return $this->error($res, "An active manageable lesson is required.");$existing=!empty($body->id)?$this->byId($this->ruleNs,$body->id):null;if(($existing&&$this->isDeleted($existing))||(isset($body->status)&&strtolower($body->status)==="deleted"))return $this->error($res,"Restore a deleted assignment link before editing it.");
        if (empty($body->assignment_id) && isset($body->assignment)) { $assignment = $body->assignment; $assignment->subject_id = $lesson->subject_id; if (empty($assignment->class_grade_id)) $assignment->class_grade_id = 0; if (empty($assignment->status)) $assignment->status = "published"; $assignment->created_by = $this->currentProfile()->id; $assignment->created_at = date("Y-m-d H:i:s"); $assignment = $this->persist($this->assignmentNs, $assignment, $res); if ($assignment) $body->assignment_id = $assignment->id; unset($body->assignment); }
        if (empty($body->assignment_id)) return $this->error($res, "Assignment is required.");$assignment=$this->byId($this->assignmentNs,$body->assignment_id);if(!$assignment||strval($assignment->subject_id)!==strval($lesson->subject_id))return $this->error($res,"The assignment must belong to the lesson subject.");if(!isset($body->max_submissions)||intval($body->max_submissions)<1)$body->max_submissions=1;if(!isset($body->max_file_size_mb)||intval($body->max_file_size_mb)<1)$body->max_file_size_mb=10;if(empty($body->allowed_formats))$body->allowed_formats="pdf,doc,docx,jpg,jpeg,png,mp4,mp3,zip";if(empty($body->status))$body->status="active";$body->supporting_files=$this->validatedSupportingFiles(isset($body->supporting_files)?$body->supporting_files:array(),$res);if($body->supporting_files===null)return null;$saved=$this->persist($this->ruleNs,$body,$res);if($saved)$this->notifyCourse($lesson->course_id,"assignment-requested","Assignment available: ".$assignment->title,"assignment",$assignment->id);return $saved;
    }
    public function postDeleteAssignmentRule($req,$res){return $this->softDeleteManagedChild($this->ruleNs,$this->body($req),$res,"lesson_id");}
    public function postRestoreAssignmentRule($req,$res){return $this->restoreManagedChild($this->ruleNs,$this->body($req),$res,"lesson_id","active");}
    public function postSubmitAssignment($req, $res) {
        $body = $this->body($req); if (empty($body->assignment_id) || empty($body->lesson_id)) return $this->error($res, "Assignment and lesson are required."); $student = $this->requestedStudent($body);if(!$this->validProfile($student))return $this->error($res,"An active profile is required."); $lesson = $this->byId($this->lessonNs, $body->lesson_id);
        if (!$lesson || $this->isDeleted($lesson)) return $this->error($res, "Active lesson not found.");
        if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied.");
        if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements before submitting this assignment.");
        $assignment=$this->byId($this->assignmentNs,$body->assignment_id);$rule=$this->findOne($this->ruleNs,"lesson_id:".intval($lesson->id).",assignment_id:".intval($body->assignment_id));if(!$assignment||!$rule||$this->isDeleted($rule)||strval($assignment->subject_id)!==strval($lesson->subject_id))return $this->error($res,"This assignment is not linked to the selected lesson.");if(isset($assignment->status)&&strtolower($assignment->status)!=="published")return $this->error($res,"Assignment is not available.");
        $existing = $this->rows($this->submissionNs, "assignment_id:" . intval($body->assignment_id) . ",lesson_id:".intval($lesson->id).",student_id:" . intval($student->id), "desc");
        if(count($existing)>0&&!LessonRules::truthy(isset($rule->allow_resubmission)?$rule->allow_resubmission:false))return $this->error($res,"Resubmission is not allowed for this assignment.");
        if ($rule && intval($rule->max_submissions) > 0 && count($existing) >= intval($rule->max_submissions)) return $this->error($res, "Submission limit reached.");
        $late=!empty($assignment->due_at)&&strtotime($assignment->due_at)<time();if($late&&!LessonRules::truthy(isset($rule->allow_late)?$rule->allow_late:false))return $this->error($res,"The assignment due date has passed and late submissions are disabled.");
        $files=$this->validatedSubmissionFiles(isset($body->files)?$body->files:array(),$rule,$res);if($files===null)return null;$content=trim(isset($body->content)?strval($body->content):"");$external=trim(isset($body->file_url)?strval($body->file_url):"");if($external!==""&&!$this->safeHttpUrl($external))return $this->error($res,"External submission links must use HTTPS.");$type=strtolower(isset($rule->submission_type)?$rule->submission_type:"file_and_text");if(strpos($type,"file")!==false&&count($files)===0&&$external==="")return $this->error($res,"Upload at least one permitted file.");if(strpos($type,"text")!==false&&$content==="")return $this->error($res,"A written response is required.");
        $submission = new \stdClass(); $submission->assignment_id = $body->assignment_id;$submission->course_id=$lesson->course_id;$submission->lesson_id=$lesson->id;$submission->attempt_number=count($existing)+1; $submission->student_id = $student->id; $submission->student_name = $student->name; $submission->content = $content; $submission->file_url = $external; $submission->files=$files;$submission->submitted_at = date("Y-m-d H:i:s"); $submission->status = $late?"submitted_late":"submitted";if($late&&isset($assignment->late_penalty_per_day)){$days=max(1,ceil((time()-strtotime($assignment->due_at))/86400));$submission->late_penalty=$days*floatval($assignment->late_penalty_per_day);} $submission = $this->persist($this->submissionNs, $submission, $res);
        $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->assignment_submitted = "true";$progress->assignment_submitted_at=date("Y-m-d H:i:s"); $this->finishProgress($lesson, $progress, $res); $this->notifyTeachersForLesson($lesson,"assignment-submitted", $student->name . " submitted an assignment.", "submission", $submission->id); return $submission;
    }

    public function postReviewSubmission($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->submission_id)) return $this->error($res, "submission_id is required."); $submission = $this->byId($this->submissionNs, $body->submission_id); if (!$submission) return $this->error($res, "Submission not found.");
        $assignment = $this->byId($this->assignmentNs, $submission->assignment_id); $rule = $this->findOne($this->ruleNs, "lesson_id:".intval(isset($submission->lesson_id)?$submission->lesson_id:0).",assignment_id:" . intval($submission->assignment_id));$lesson=$rule?$this->byId($this->lessonNs,$rule->lesson_id):null;if(!$assignment||!$rule||!$lesson||!$this->canManageLesson($lesson))return $this->error($res,"You are not authorized to review this submission."); $profile = $this->currentProfile(); $marks = isset($body->marks) ? floatval($body->marks) : 0;if($marks<0||$marks>floatval($assignment->max_mark))return $this->error($res,"Marks must be between 0 and ".$assignment->max_mark."."); $pass = $marks >= floatval($rule->passing_mark);
        $decision=isset($body->status)?strtolower($body->status):($pass?"graded":"resubmission_requested");if(!in_array($decision,array("graded","resubmission_requested"),true))return $this->error($res,"Unsupported review decision.");$submission->marks = $marks;$submission->passed=$pass?"true":"false"; $submission->feedback = isset($body->feedback) ? trim(strval($body->feedback)) : ""; $submission->status = $decision; $submission->graded_by = $profile->id; $submission->graded_at = date("Y-m-d H:i:s"); $submission = $this->persist($this->submissionNs, $submission, $res);
        $mark = $this->findOne($this->markNs, "assignment_id:" . intval($assignment->id) . ",student_id:" . intval($submission->student_id)); if (!$mark) $mark = new \stdClass(); $mark->assignment_id = $assignment->id; $mark->submission_id = $submission->id; $mark->class_grade_id = isset($assignment->class_grade_id) ? $assignment->class_grade_id : 0; $mark->subject_id = isset($assignment->subject_id) ? $assignment->subject_id : 0; $mark->student_id = $submission->student_id; $mark->student_name = $submission->student_name; $mark->marks = $marks; $mark->max_mark = $assignment->max_mark; $mark->graded_by = $profile->id; $mark->graded_at = date("Y-m-d H:i:s"); $mark->note = $submission->feedback; $this->persist($this->markNs, $mark, $res);
        if ($rule) { if ($lesson) { $student = $this->profile($submission->student_id); $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->assignment_mark = $marks; $progress->assignment_passed = $pass ? "true" : "false";$progress->assignment_reviewed_at=date("Y-m-d H:i:s"); if (isset($body->approve) && LessonRules::truthy($body->approve)&&$pass) $progress->teacher_approved = "true"; $this->finishProgress($lesson, $progress, $res); } }
        $this->queueNotification($this->profile($submission->student_id), $pass ? "marks-awarded" : "resubmission-requested", "Your assignment was reviewed. Mark: " . $marks, "submission", $submission->id); return $submission;
    }

    public function postSubmissionsReport($req, $res) {
        if(!$this->requireTeacher($res))return null;
        $body = $this->body($req); $rows = $this->listObject($body, $this->submissionNs, array("assignment_id","student_id","status"), array("student_name","content","feedback"), "desc"); $out = array();
        foreach ($rows as $row) { $assignment = $this->byId($this->assignmentNs, $row->assignment_id);$rule = $this->findOne($this->ruleNs, "lesson_id:".intval(isset($row->lesson_id)?$row->lesson_id:0).",assignment_id:" . intval($row->assignment_id));if(!$rule)$rule=$this->findOne($this->ruleNs,"assignment_id:".intval($row->assignment_id));$lesson=$rule?$this->byId($this->lessonNs,$rule->lesson_id):null;if(!$lesson||!$this->canManageLesson($lesson))continue; if (isset($body->course_id) && $body->course_id && strval($lesson->course_id)!==strval($body->course_id)) continue; if (isset($body->subject_id) && $body->subject_id && strval($lesson->subject_id)!==strval($body->subject_id)) continue; if (isset($body->lesson_id) && $body->lesson_id && strval($lesson->id) !== strval($body->lesson_id)) continue;if(isset($body->date_from)&&$body->date_from&&strtotime($row->submitted_at)<strtotime($body->date_from))continue;if(isset($body->date_to)&&$body->date_to&&strtotime($row->submitted_at)>strtotime($body->date_to." 23:59:59"))continue;if(isset($body->pass_status)&&$body->pass_status!==""){$isPass=floatval(isset($row->marks)?$row->marks:0)>=floatval($rule->passing_mark);if(($body->pass_status==="passed")!==$isPass)continue;} $item = clone $row; $item->assignment = $assignment; $item->rule = $rule;$item->lesson=$lesson; $out[] = $item; }
        return $out;
    }
    public function postProgressReport($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); $rows = $this->listObject($body, $this->progressNs, array("course_id","lesson_id","student_id"), array("student_name"), "desc"); $lessons = $this->mapBy($this->rows($this->lessonNs, "", "asc"), "id"); $out = array();
        foreach ($rows as $row) { $item = clone $row; $item->lesson = isset($lessons[strval($row->lesson_id)]) ? $lessons[strval($row->lesson_id)] : null;if(!$item->lesson||!$this->canManageLesson($item->lesson))continue; if (isset($body->subject_id) && $body->subject_id && strval($item->lesson->subject_id) !== strval($body->subject_id)) continue;if(isset($body->teacher_id)&&$body->teacher_id&&!$this->lessonMatchesTeacher($item->lesson,$body->teacher_id))continue;$activity=!empty($row->last_viewed_at)?$row->last_viewed_at:(isset($row->completed_at)?$row->completed_at:"");if(isset($body->date_from)&&$body->date_from&&($activity===""||strtotime($activity)<strtotime($body->date_from)))continue;if(isset($body->date_to)&&$body->date_to&&($activity===""||strtotime($activity)>strtotime($body->date_to." 23:59:59")))continue;if(isset($body->completion_status)&&$body->completion_status!==""){$complete=LessonRules::truthy(isset($row->lesson_completed)?$row->lesson_completed:false);$started=!empty($row->last_viewed_at)||!empty($row->started_at);$status=$complete?"completed":($started?"in_progress":"not_started");if($status!==$body->completion_status)continue;} $item->completion_percentage = $this->progressPercentage($item->lesson, $row); $out[] = $item; } return $out;
    }

    public function postResultsReport($req,$res){
        if(!$this->requireTeacher($res))return null;$body=$this->body($req);$enrollments=$this->rows($this->enrollmentNs,"","desc");$out=array();
        foreach($enrollments as $enrollment){
            if(!$this->isActiveEnrollment($enrollment))continue;$courseId=$this->courseIdForEnrollment($enrollment);if($courseId<=0)continue;
            if(isset($body->course_id)&&$body->course_id&&strval($courseId)!==strval($body->course_id))continue;if(isset($body->student_id)&&$body->student_id&&strval($enrollment->student_id)!==strval($body->student_id))continue;
            $course=$this->byId($this->courseNs,$courseId);if(!$course||!$this->canManageCourse($courseId))continue;if(isset($body->teacher_id)&&$body->teacher_id&&!$this->courseHasTeacher($courseId,$body->teacher_id))continue;
            if(isset($body->search)&&trim($body->search)!==""){$student=$this->profile($enrollment->student_id);if(strpos(strtolower(isset($student->name)?$student->name:""),strtolower(trim($body->search)))===false)continue;}
            $summary=$this->courseLearningSummary($course,$this->profile($enrollment->student_id),$enrollment);$totals=$this->courseMarkTotals($enrollment->student_id,$courseId);$summary->total_marks=$totals->earned;$summary->maximum_marks=$totals->maximum;$summary->percentage=$totals->maximum>0?round(($totals->earned/$totals->maximum)*100,2):0;$summary->grade=$this->gradeForPercentage($summary->percentage);
            if(isset($body->completion_status)&&$body->completion_status!==""&&$summary->course_status!==$body->completion_status)continue;
            if((isset($body->date_from)&&$body->date_from)||(isset($body->date_to)&&$body->date_to)){$activity=$this->latestCourseActivity($enrollment->student_id,$courseId);if(!$activity)continue;if(isset($body->date_from)&&$body->date_from&&strtotime($activity)<strtotime($body->date_from))continue;if(isset($body->date_to)&&$body->date_to&&strtotime($activity)>strtotime($body->date_to." 23:59:59"))continue;}
            $out[]=$summary;
        }
        return $out;
    }

    public function postSeedDemo($req, $res) {
        if (!$this->requireTeacher($res)) return null; $courses = array();foreach($this->rows($this->courseNs,"","asc") as $candidate)if($this->canManageCourse($candidate->id))$courses[]=$candidate; if (count($courses) === 0) return $this->error($res, "Create a course in Course Manager first."); $course = $courses[0];
        $subjects = array();foreach($this->rows($this->subjectNs,"course_id:".intval($course->id),"asc") as $candidate)if($this->canManageSubject($candidate))$subjects[]=$candidate; if (count($subjects) === 0) return $this->error($res, "Create a subject under the course in Course Manager first."); $subject = $subjects[0];
        if ($this->findOne($this->lessonNs, "subject_id:" . intval($subject->id) . ",title:Welcome to the subject")) return $this->postDashboard($req, $res);
        $lesson = new \stdClass(); $lesson->course_id = $course->id; $lesson->subject_id = $subject->id; $lesson->title = "Welcome to the subject"; $lesson->description = "Understand the subject learning goals, lesson structure, and how to complete each activity."; $lesson->lesson_order = 1; $lesson->passing_mark = 70; $lesson->status = "published"; $lesson->progression_enabled = "true"; $lesson->is_free = "true"; $lesson->required_credit_points = 0; $lesson->require_reading = "true"; $lesson->require_video = "false"; $lesson->require_quiz = "false"; $lesson->require_assignment = "false"; $lesson->require_teacher_approval = "false"; $lesson->created_at = date("Y-m-d H:i:s"); $lesson->updated_at = $lesson->created_at; $lesson = $this->persist($this->lessonNs, $lesson, $res);
        $content = new \stdClass(); $content->lesson_id = $lesson->id; $content->content_type = "article"; $content->title = "Getting started"; $content->body = "Welcome. Read this introduction, explore the resources, and mark the lesson as read when you are ready to continue."; $content->sort_order = 1; $content->is_required = "true"; $content->status = "published"; $this->persist($this->contentNs, $content, $res); return $this->postDashboard($req, $res);
    }

    private function studentCourses($body,$res=null) {
        $student = $this->requestedStudent($body); $out = array();
        if(!$this->validProfile($student)){if($res)$res->SetError("An active profile is required.");return array();}
        if ($this->isTeacher() && (!isset($body->student_id) || intval($body->student_id) <= 0)) {
            foreach ($this->rows($this->courseNs, "", "asc") as $course) if($this->canManageCourse($course->id))$out[] = $this->courseLearningSummary($course, $student, null);
            return $out;
        }
        $enrollments = $this->rows($this->enrollmentNs, "student_id:" . intval($student->id), "desc"); $seen = array();
        foreach ($enrollments as $enrollment) { if (!$this->isActiveEnrollment($enrollment)) continue; $courseId = $this->courseIdForEnrollment($enrollment); if ($courseId <= 0 || isset($seen[strval($courseId)])) continue;if($this->isTeacher()&&!$this->canManageCourse($courseId))continue; $seen[strval($courseId)] = true; $course = $this->byId($this->courseNs, $courseId); if (!$course) continue; $out[] = $this->courseLearningSummary($course, $student, $enrollment); }
        return $out;
    }

    private function courseLearningSummary($course, $student, $enrollment) {
        $courseLessons = $this->publishedRows($this->lessonNs, "course_id:" . intval($course->id)); $lessons = array();
        foreach ($courseLessons as $courseLesson) if (!empty($courseLesson->subject_id)) $lessons[] = $courseLesson;
        usort($lessons, array($this, "sortLessonsBySubject"));
        $progress = $this->rows($this->progressNs, "course_id:" . intval($course->id) . ",student_id:" . intval($student->id), "asc");
        $completed = 0; $quizPending = 0; $assignmentPending = 0; $current = null;
        foreach ($lessons as $lesson) { $p = $this->findIn($progress, "lesson_id", $lesson->id); if ($p && LessonRules::truthy($p->lesson_completed)) $completed++; elseif ($current === null) $current = $lesson; if (LessonRules::truthy(isset($lesson->require_quiz)?$lesson->require_quiz:false) && (!$p || !LessonRules::truthy($p->quiz_passed))) $quizPending++; if (LessonRules::truthy(isset($lesson->require_assignment)?$lesson->require_assignment:false) && (!$p || !LessonRules::truthy($p->assignment_passed))) $assignmentPending++; }
        $totals=$this->courseMarkTotals($student->id,$course->id);$earned=$totals->earned;
        $teacherNames=array();foreach($this->rows($this->subjectNs,"course_id:".intval($course->id),"asc") as $subject)if(!empty($subject->teacher_id)){$teacher=$this->profile($subject->teacher_id);if(isset($teacher->name))$teacherNames[$teacher->name]=true;}
        $item = clone $course; $item->enrollment = $enrollment;$item->student_id=isset($student->id)?$student->id:0;$item->student_name=isset($student->name)?$student->name:"Unknown learner"; $item->completion_percentage = LessonRules::completionPercent($completed, count($lessons)); $item->completed_lessons = $completed; $item->total_lessons = count($lessons); $item->current_lesson = $current; $item->pending_quizzes = $quizPending; $item->pending_assignments = $assignmentPending; $item->total_marks = $earned;$item->teacher_names=array_keys($teacherNames); $item->course_status = count($lessons)>0 && $completed===count($lessons)?"completed":($completed>0?"in_progress":"not_started"); return $item;
    }

    private function touchProgress($body, $activity, $res) { if (empty($body->lesson_id)) return $this->error($res, "lesson_id is required."); $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson||$this->isDeleted($lesson)) return $this->error($res, "Active lesson not found."); $student = $this->requestedStudent($body);if(!$this->validProfile($student))return $this->error($res,"An active profile is required."); if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied."); if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements first."); $progress = $this->progress($lesson->course_id, $lesson->id, $student); if ($activity === "viewed") { if (empty($progress->started_at)) $progress->started_at = date("Y-m-d H:i:s"); $progress->last_viewed_at = date("Y-m-d H:i:s"); } else $progress->{$activity} = "true"; return $this->finishProgress($lesson, $progress, $res); }
    private function progress($courseId, $lessonId, $student) { $p = $this->findOne($this->progressNs, "lesson_id:" . intval($lessonId) . ",student_id:" . intval($student->id)); if ($p) return $p; $p = new \stdClass(); $p->course_id=$courseId; $p->lesson_id=$lessonId; $p->student_id=$student->id; $p->student_name=$student->name; $p->quiz_attempts=0; return $p; }
    private function finishProgress($lesson, $progress, $res) { $complete = LessonRules::requirementsMet($lesson, $progress); $wasComplete = LessonRules::truthy(isset($progress->lesson_completed)?$progress->lesson_completed:false); $progress->lesson_completed = $complete ? "true" : "false"; if ($complete && empty($progress->completed_at)) $progress->completed_at = date("Y-m-d H:i:s");if($complete&&!$wasComplete)$progress->next_unlocked_at=date("Y-m-d H:i:s"); $saved = $this->persist($this->progressNs, $progress, $res); if ($complete && !$wasComplete){$student=$this->profile($progress->student_id);$this->queueNotification($student, "next-lesson-unlocked", "Lesson completed. Your next lesson is now available.", "lesson", $lesson->id);if($this->courseCompletedFor($lesson->course_id,$progress->student_id))$this->queueNotification($student,"course-completed","Course completed.","course",$lesson->course_id);} return $saved; }
    private function saveQuizMark($quiz,$lesson,$student,$attempt,$res) { $mark = $this->findOne($this->markNs, "assessment_id:" . intval(isset($quiz->assessment_id)?$quiz->assessment_id:0) . ",student_id:" . intval($student->id)); if (!$mark) $mark = new \stdClass(); $mark->assessment_id=isset($quiz->assessment_id)?$quiz->assessment_id:0; $mark->assignment_id=0; $mark->submission_id=0; $mark->class_grade_id=0; $mark->subject_id=isset($lesson->subject_id)?$lesson->subject_id:0; $mark->student_id=$student->id; $mark->student_name=$student->name; $mark->marks=$attempt->marks; $mark->max_mark=$attempt->max_mark; $mark->weight=0; $mark->graded_by=0; $mark->graded_at=date("Y-m-d H:i:s"); $mark->note="Lesson quiz attempt " . $attempt->attempt_number; $this->persist($this->markNs,$mark,$res); }
    private function syncQuizAssessment($quiz,$lesson,$res){$published=strtolower(isset($quiz->status)?$quiz->status:"")==="published";$assessment=!empty($quiz->assessment_id)?$this->byId($this->assessmentNs,$quiz->assessment_id):null;if(!$published&&!$assessment)return $quiz;if(!$assessment)$assessment=new \stdClass();$assessment->class_grade_id=0;$assessment->subject_id=isset($lesson->subject_id)?$lesson->subject_id:0;$assessment->title=$quiz->title;$assessment->assessment_type="lesson_quiz";$assessment->max_mark=$this->quizMaxMark($quiz->id);$assessment->weight=0;$assessment->status=$published?"active":"inactive";$assessment=$this->persist($this->assessmentNs,$assessment,$res);if($assessment&&empty($quiz->assessment_id)){$quiz->assessment_id=$assessment->id;$quiz=$this->persist($this->quizNs,$quiz,$res);}return $quiz;}

    private function teacherSave($req,$res,$ns,$required,$message) { if (!$this->requireTeacher($res)) return null; $item=$this->body($req); foreach($required as $field) if(empty($item->{$field})) return $this->error($res,$message); return $this->persist($ns,$item,$res); }
    private function listBody($req,$ns,$fields,$search,$sorting) { return $this->listObject($this->body($req),$ns,$fields,$search,$sorting); }
    private function listObject($body,$ns,$fields,$search,$sorting) { $parts=array(); foreach($fields as $field) if(isset($body->{$field}) && $body->{$field}!=="" && $body->{$field}!==null) $parts[]=$field.":".$this->clean($body->{$field}); $rows=$this->rows($ns,implode(",",$parts),$sorting); if(!isset($body->search)||trim($body->search)==="") return $rows; $needle=strtolower(trim($body->search)); $out=array(); foreach($rows as $row) foreach($search as $field) if(isset($row->{$field}) && strpos(strtolower($this->plainText($row->{$field})),$needle)!==false){$out[]=$row;break;} return $out; }
    private function rows($ns,$query,$sorting="desc") { $result=\SOSSData::Query($ns,$query,null,$sorting,2000,0); return $result->success?$result->result:array(); }
    private function persist($ns,$item,$res) { $update=isset($item->id)&&intval($item->id)>0; $result=$update?\SOSSData::Update($ns,$item):\SOSSData::Insert($ns,$item); if(!$result->success){$res->SetError(isset($result->message)?$result->message:"Save failed.");return null;} if(!$update&&isset($result->result->generatedId))$item->id=$result->result->generatedId; return $item; }
    private function softDelete($ns,$item,$res){if(!$this->requireTeacher($res))return null;if(empty($item->id))return $this->error($res,"id is required.");if($this->isDeleted($item))return $item;$item->status="deleted";return $this->persist($ns,$item,$res);}
    private function restore($ns,$item,$status,$res){if(!$this->requireTeacher($res))return null;if(empty($item->id)||!$this->isDeleted($item))return $this->error($res,"A deleted record is required.");$item->status=$status;return $this->persist($ns,$item,$res);}
    private function body($req){$data=$req->Body(true);return isset($data)?$data:new \stdClass();}
    private function byId($ns,$id){return empty($id)?null:$this->findOne($ns,"id:".intval($id));}
    private function findOne($ns,$query){$rows=$this->rows($ns,$query,"desc");return count($rows)>0?$rows[0]:null;}
    private function isDeleted($item){return is_object($item)&&isset($item->status)&&strtolower(strval($item->status))==="deleted";}
    private function withoutDeleted($rows){$out=array();foreach($rows as $row)if(!$this->isDeleted($row))$out[]=$row;return $out;}
    private function visibleRows($rows,$body){$include=isset($body->include_deleted)&&LessonRules::truthy($body->include_deleted);$only=isset($body->deleted_only)&&LessonRules::truthy($body->deleted_only);if($only||(isset($body->status)&&strtolower(strval($body->status))==="deleted"))$include=true;$out=array();foreach($rows as $row){$deleted=$this->isDeleted($row);if($only&&!$deleted)continue;if(!$include&&$deleted)continue;$out[]=$row;}return $out;}
    private function publishedRows($ns,$prefix){return $this->rows($ns,$prefix . ($prefix?",":"") . "status:published","asc");}
    private function error($res,$message){$res->SetError($message);return null;}
    private function clean($value){return str_replace(array(",",":")," ",trim(strval($value)));}
    private function plainText($value){if(is_object($value)||is_array($value))$value=json_encode($value);return trim(strip_tags(strval($value)));}
    private function countStatus($rows,$status){$n=0;foreach($rows as $row)if(isset($row->status)&&strtolower($row->status)===strtolower($status))$n++;return $n;}
    private function distinctCount($rows,$field){$seen=array();foreach($rows as $row)if(isset($row->{$field}))$seen[strval($row->{$field})]=true;return count($seen);}
    private function mapBy($rows,$field){$map=array();foreach($rows as $row)if(isset($row->{$field}))$map[strval($row->{$field})]=$row;return $map;}
    private function findIn($rows,$field,$value){foreach($rows as $row)if(isset($row->{$field})&&strval($row->{$field})===strval($value))return $row;return null;}
    public function sortLessons($a,$b){return intval(isset($a->lesson_order)?$a->lesson_order:0)-intval(isset($b->lesson_order)?$b->lesson_order:0);}
    public function sortLessonsBySubject($a,$b){$subjectCompare=intval(isset($a->subject_id)?$a->subject_id:0)-intval(isset($b->subject_id)?$b->subject_id:0);return $subjectCompare!==0?$subjectCompare:$this->sortLessons($a,$b);}
    private function quizMaxMark($id){$n=0;foreach($this->withoutDeleted($this->rows($this->questionNs,"quiz_id:".intval($id),"asc")) as $q)$n+=floatval(isset($q->marks)?$q->marks:1);return $n;}
    private function answerFor($answers,$id){if(is_object($answers)&&isset($answers->{strval($id)}))return $answers->{strval($id)};if(is_array($answers)&&isset($answers[strval($id)]))return $answers[strval($id)];return "";}
    private function sourceSentences($source){$source=preg_replace('/\s+/',' ',trim($source));$chunks=preg_split('/(?<=[.!?])\s+/',$source);$out=array();foreach($chunks as $s)if(strlen($s)>=25&&strlen($s)<=450)$out[]=$s;return $out;}
    private function keyword($sentence){preg_match_all('/[A-Za-z][A-Za-z\-]{4,}/',$sentence,$matches);if(empty($matches[0]))return "lesson";usort($matches[0],function($a,$b){return strlen($b)-strlen($a);});return $matches[0][0];}
    private function videoProvider($url){$url=strtolower($url);if(strpos($url,"youtube.com")!==false||strpos($url,"youtu.be")!==false)return "youtube";if(strpos($url,"facebook.com")!==false)return "facebook";if(strpos($url,"cloudflarestream.com")!==false||strpos($url,"videodelivery.net")!==false)return "cloudflare";return "direct";}
    private function progressPercentage($lesson,$p){if(!$lesson)return 0;$total=0;$done=0;$map=array("require_reading"=>"reading_completed","require_video"=>"video_completed","require_quiz"=>"quiz_passed","require_assignment"=>"assignment_passed","require_teacher_approval"=>"teacher_approved");foreach($map as $r=>$s)if(LessonRules::truthy(isset($lesson->{$r})?$lesson->{$r}:false)){$total++;if(LessonRules::truthy(isset($p->{$s})?$p->{$s}:false))$done++;}return $total?LessonRules::completionPercent($done,$total):(LessonRules::truthy(isset($p->lesson_completed)?$p->lesson_completed:false)?100:0);}
    private function assignmentInCourse($assignment,$courseId){$subjects=$this->rows($this->subjectNs,"course_id:".intval($courseId),"asc");foreach($subjects as $s)if(strval($s->id)===strval($assignment->subject_id))return true;return false;}
    private function markBelongsToCourse($mark,$courseId){if(!empty($mark->assignment_id)){ $assignment=$this->byId($this->assignmentNs,$mark->assignment_id); if($assignment&&$this->assignmentInCourse($assignment,$courseId))return true;}if(!empty($mark->assessment_id)){foreach($this->rows($this->quizNs,"assessment_id:".intval($mark->assessment_id),"desc") as $quiz){$lesson=$this->byId($this->lessonNs,$quiz->lesson_id);if($lesson&&strval($lesson->course_id)===strval($courseId))return true;}}if(!empty($mark->subject_id)){foreach($this->rows($this->subjectNs,"course_id:".intval($courseId),"asc") as $subject)if(strval($subject->id)===strval($mark->subject_id))return true;}return false;}
    private function courseMarkTotals($studentId,$courseId){$earned=0;$maximum=0;$seen=array();foreach($this->rows($this->markNs,"student_id:".intval($studentId),"desc") as $mark){if(!$this->markBelongsToCourse($mark,$courseId))continue;$key=!empty($mark->assignment_id)?"assignment:".$mark->assignment_id:(!empty($mark->assessment_id)?"assessment:".$mark->assessment_id:"mark:".(isset($mark->id)?$mark->id:count($seen)));if(isset($seen[$key]))continue;$seen[$key]=true;$earned+=floatval(isset($mark->marks)?$mark->marks:0);$maximum+=floatval(isset($mark->max_mark)?$mark->max_mark:0);}$out=new \stdClass();$out->earned=$earned;$out->maximum=$maximum;return $out;}
    private function latestCourseActivity($studentId,$courseId){$latest=0;foreach($this->rows($this->progressNs,"course_id:".intval($courseId).",student_id:".intval($studentId),"desc") as $progress){foreach(array("last_viewed_at","completed_at","assignment_reviewed_at","quiz_completed_at") as $field)if(!empty($progress->{$field}))$latest=max($latest,strtotime($progress->{$field}));}return $latest?date("Y-m-d H:i:s",$latest):null;}
    private function courseCompletedFor($courseId,$studentId){$lessons=$this->publishedRows($this->lessonNs,"course_id:".intval($courseId));if(!count($lessons))return false;foreach($lessons as $lesson){$progress=$this->findOne($this->progressNs,"lesson_id:".intval($lesson->id).",student_id:".intval($studentId));if(!$progress||!LessonRules::truthy(isset($progress->lesson_completed)?$progress->lesson_completed:false))return false;}return true;}

    private function sanitizeRichText($html) {
        $html = strval($html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|svg|math)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><h1><h2><h3><h4><strong><b><em><i><u><s><strike><ol><ul><li><a><img><blockquote><pre><code><hr><span><div>');
        $html = preg_replace('/\s(on[a-z]+|style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace_callback('/\s(href|src)\s*=\s*(["\'])(.*?)\2/i', function($match) {
            $url = trim(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));
            if ($url === '' || strpos($url, '#') === 0 || preg_match('#^(https?:|mailto:|tel:)#i', $url) || $this->safeResourceReference($url)) return ' ' . strtolower($match[1]) . '=' . $match[2] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $match[2];
            return '';
        }, $html);
        return trim($html);
    }
    private function safeContentRows($rows) { foreach($rows as $row){if(isset($row->body))$row->body=$this->sanitizeRichText($row->body);if(isset($row->embed_url)){$embed=$this->googleDriveEmbedUrl($row->embed_url);if($embed!=="")$row->embed_url=$embed;else unset($row->embed_url);}}return $rows; }
    private function safeVideoRows($rows){$out=array();foreach($rows as $row)if($this->validVideoReference($row))$out[]=$row;return $out;}

    private function safeResourceReference($value){$value=trim(strval($value));return $this->safeHttpUrl($value)||preg_match('#^components/(dock|davvag-cms-v7)/soss-uploader/service/get/[A-Za-z0-9_-]+/[A-Za-z0-9._-]+$#',$value);}
    private function googleDriveEmbedUrl($value){
        $value=trim(strval($value));if($value==="")return "";
        if(strpos($value,"<")!==false){if(!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i',$value,$match))return "";$value=$match[2];}
        $value=html_entity_decode(trim($value),ENT_QUOTES,'UTF-8');$parts=parse_url($value);if(!is_array($parts)||strtolower(isset($parts['scheme'])?$parts['scheme']:"")!=="https"||empty($parts['host']))return "";
        $host=strtolower($parts['host']);if(strpos($host,"www.")===0)$host=substr($host,4);$path=isset($parts['path'])?$parts['path']:"";$id="";
        if($host==="drive.google.com"){
            if(preg_match('#^/file/d/([A-Za-z0-9_-]{10,})#',$path,$match))$id=$match[1];
            elseif(isset($parts['query'])){parse_str($parts['query'],$query);if(isset($query['id'])&&preg_match('/^[A-Za-z0-9_-]{10,}$/',strval($query['id'])))$id=strval($query['id']);}
            return $id!==""?"https://drive.google.com/file/d/".$id."/preview":"";
        }
        if($host==="docs.google.com"&&preg_match('#^/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,})#',$path,$match)){
            $mode=$match[1]==="presentation"?"embed":"preview";return "https://docs.google.com/".$match[1]."/d/".$match[2]."/".$mode;
        }
        if($host==="docs.google.com"&&preg_match('#^/forms/d/e/([A-Za-z0-9_-]{10,})/viewform#',$path,$match))return "https://docs.google.com/forms/d/e/".$match[1]."/viewform?embedded=true";
        return "";
    }
    private function validVideoReference($item){$provider=strtolower(isset($item->provider)?$item->provider:"");$url=trim(isset($item->video_url)?strval($item->video_url):"");$media=trim(isset($item->media_reference)?strval($item->media_reference):"");if($provider==="youtube")return $this->youtubeVideoId($url)!=="";if($provider==="facebook")return $this->facebookVideoId($url)!=="";if($provider==="cloudflare"){$p=parse_url($url);return $this->safeHttpUrl($url)&&isset($p["host"])&&preg_match('/(^|\.)(cloudflarestream\.com|videodelivery\.net)$/i',$p["host"]);}if($provider==="local")return $media!==""&&$this->safeResourceReference($media);return $provider==="direct"&&$this->safeHttpUrl($url);}

    private function filterManagedLessons($rows){$out=array();foreach($rows as $row)if($this->canManageLesson($row))$out[]=$row;return $out;}
    private function filterManagedChildren($rows,$lessonField){$out=array();foreach($rows as $row){$lesson=isset($row->{$lessonField})?$this->byId($this->lessonNs,$row->{$lessonField}):null;if($lesson&&!$this->isDeleted($lesson)&&$this->canManageLesson($lesson))$out[]=$row;}return $out;}
    private function filterManagedQuizRows($rows){$out=array();foreach($rows as $row){$lesson=$this->byId($this->lessonNs,$row->lesson_id);if($lesson&&!$this->isDeleted($lesson)&&$this->canManageLesson($lesson))$out[]=$row;}return $out;}
    private function filterManagedQuestionRows($rows){$out=array();foreach($rows as $row){$quiz=$this->byId($this->quizNs,$row->quiz_id);$lesson=$quiz?$this->byId($this->lessonNs,$quiz->lesson_id):null;if($lesson&&!$this->isDeleted($lesson)&&$this->canManageLesson($lesson))$out[]=$row;}return $out;}
    private function softDeleteManagedChild($ns,$body,$res,$lessonField){if(!$this->requireTeacher($res))return null;$item=$this->byId($ns,isset($body->id)?$body->id:0);$lesson=$item&&isset($item->{$lessonField})?$this->byId($this->lessonNs,$item->{$lessonField}):null;if(!$item||!$lesson||!$this->canManageLesson($lesson))return $this->error($res,"A manageable record is required.");return $this->softDelete($ns,$item,$res);}
    private function restoreManagedChild($ns,$body,$res,$lessonField,$status){if(!$this->requireTeacher($res))return null;$item=$this->byId($ns,isset($body->id)?$body->id:0);$lesson=$item&&isset($item->{$lessonField})?$this->byId($this->lessonNs,$item->{$lessonField}):null;if(!$item||!$lesson||$this->isDeleted($lesson)||!$this->canManageLesson($lesson))return $this->error($res,"A deleted record under an active manageable lesson is required.");return $this->restore($ns,$item,$status,$res);}

    private function canManageSubject($subject){if(!$subject||!$this->isTeacher())return false;if($this->isAdmin()||$this->currentRole()==="staff")return true;$profile=$this->currentProfile();return empty($subject->teacher_id)||($this->validProfile($profile)&&strval($subject->teacher_id)===strval($profile->id));}
    private function canManageLesson($lesson){$subject=$lesson&&!empty($lesson->subject_id)?$this->byId($this->subjectNs,$lesson->subject_id):null;return $subject&&strval($subject->course_id)===strval($lesson->course_id)&&$this->canManageSubject($subject);}
    private function canManageCourse($courseId){if($this->isAdmin()||$this->currentRole()==="staff")return true;foreach($this->rows($this->subjectNs,"course_id:".intval($courseId),"asc") as $subject)if($this->canManageSubject($subject))return true;return false;}
    private function lessonMatchesTeacher($lesson,$teacherId){$subject=$lesson?$this->byId($this->subjectNs,$lesson->subject_id):null;return $subject&&isset($subject->teacher_id)&&strval($subject->teacher_id)===strval($teacherId);}
    private function courseHasTeacher($courseId,$teacherId){foreach($this->rows($this->subjectNs,"course_id:".intval($courseId),"asc") as $subject)if(isset($subject->teacher_id)&&strval($subject->teacher_id)===strval($teacherId))return true;return false;}

    private function studentAssignmentRules($lessonId,$studentId){$out=array();foreach($this->withoutDeleted($this->rows($this->ruleNs,"lesson_id:".intval($lessonId),"asc")) as $rule){$assignment=$this->byId($this->assignmentNs,$rule->assignment_id);if(!$assignment||strtolower(isset($assignment->status)?$assignment->status:"")!=="published")continue;$item=clone $rule;$item->assignment=$assignment;$item->submissions=$this->rows($this->submissionNs,"lesson_id:".intval($lessonId).",assignment_id:".intval($rule->assignment_id).",student_id:".intval($studentId),"desc");$item->latest_submission=count($item->submissions)?$item->submissions[0]:null;$out[]=$item;}return $out;}
    private function studentQuizRows($lessonId,$studentId){$out=array();foreach($this->publishedRows($this->quizNs,"lesson_id:".intval($lessonId)) as $quiz){$item=clone $quiz;$attempts=$this->rows($this->attemptNs,"quiz_id:".intval($quiz->id).",student_id:".intval($studentId),"desc");$item->latest_attempt=count($attempts)?$attempts[0]:null;$item->attempts_used=count($attempts);$out[]=$item;}return $out;}

    private function studentQuestionRows($quiz){$rows=$this->withoutDeleted($this->rows($this->questionNs,"quiz_id:".intval($quiz->id),"asc"));if(LessonRules::truthy(isset($quiz->random_questions)?$quiz->random_questions:false))shuffle($rows);foreach($rows as $row){if(LessonRules::truthy(isset($quiz->random_answers)?$quiz->random_answers:false)&&isset($row->options)&&is_array($row->options))shuffle($row->options);unset($row->correct_answer);unset($row->explanation);unset($row->negative_marks);}return $rows;}

    private function validatedSubmissionFiles($files,$rule,$res){if(is_object($files))$files=get_object_vars($files);if(!is_array($files))return $this->error($res,"Uploaded files are invalid.");$allowed=array_filter(array_map('trim',explode(',',strtolower(strval(isset($rule->allowed_formats)?$rule->allowed_formats:"")))));$max=max(1,intval(isset($rule->max_file_size_mb)?$rule->max_file_size_mb:10))*1048576;$out=array();foreach($files as $file){if(is_array($file))$file=(object)$file;if(!is_object($file)||empty($file->media_reference)||empty($file->name))return $this->error($res,"Every upload requires a valid media reference and original name.");$reference=trim($file->media_reference);if(!preg_match('#^components/(dock|davvag-cms-v7)/soss-uploader/service/get/lesson_assignment_submission/([A-Za-z0-9._-]+)$#',$reference,$match))return $this->error($res,"An uploaded file reference is invalid.");$extension=strtolower(pathinfo($file->name,PATHINFO_EXTENSION));if(!in_array($extension,$allowed,true))return $this->error($res,"File type .".$extension." is not allowed.");$path=$this->uploadedMediaPath("lesson_assignment_submission",$match[2]);if(!$path||!file_exists($path))return $this->error($res,"An uploaded file could not be verified.");$size=filesize($path);if($size>$max)return $this->error($res,"An uploaded file exceeds the maximum size.");$mime=function_exists('mime_content_type')?mime_content_type($path):"application/octet-stream";$entry=new \stdClass();$entry->name=basename($file->name);$entry->media_reference=$reference;$entry->mime_type=$mime;$entry->size_bytes=$size;$out[]=$entry;}return $out;}
    private function validatedSupportingFiles($files,$res){if(is_object($files))$files=get_object_vars($files);if(!is_array($files))return $this->error($res,"Assignment supporting files are invalid.");$out=array();foreach($files as $file){if(is_array($file))$file=(object)$file;if(!is_object($file)||empty($file->name)||empty($file->media_reference)||!preg_match('#^components/(dock|davvag-cms-v7)/soss-uploader/service/get/lesson_assignment_support/([A-Za-z0-9._-]+)$#',$file->media_reference,$match))return $this->error($res,"An assignment supporting file is invalid.");$path=$this->uploadedMediaPath("lesson_assignment_support",$match[2]);if(!$path||!file_exists($path))return $this->error($res,"An assignment supporting file could not be verified.");$entry=new \stdClass();$entry->name=basename($file->name);$entry->media_reference=$file->media_reference;$entry->size_bytes=filesize($path);$out[]=$entry;}return $out;}
    private function uploadedMediaPath($namespace,$name){if(!defined("MEDIA_FOLDER")||!defined("DATASTORE_DOMAIN"))return null;if(!preg_match('/^[A-Za-z0-9_-]+$/',$namespace)||!preg_match('/^[A-Za-z0-9._-]+$/',$name))return null;$base=rtrim(MEDIA_FOLDER,"/\\").DIRECTORY_SEPARATOR.DATASTORE_DOMAIN.DIRECTORY_SEPARATOR.$namespace;$path=$base.DIRECTORY_SEPARATOR.$name;$baseReal=realpath($base);$pathReal=realpath($path);return $baseReal&&$pathReal&&strpos($pathReal,$baseReal.DIRECTORY_SEPARATOR)===0?$pathReal:null;}
    private function uploadedResourceText($reference){if(!preg_match('#^components/(dock|davvag-cms-v7)/soss-uploader/service/get/lesson_content_resource/([A-Za-z0-9._-]+)$#',trim(strval($reference)),$match))return "";$path=$this->uploadedMediaPath("lesson_content_resource",$match[2]);if(!$path||!file_exists($path)||filesize($path)>5242880)return "";$extension=strtolower(pathinfo($match[2],PATHINFO_EXTENSION));if(in_array($extension,array("txt","md","csv","json","xml","html","htm"),true))return substr($this->plainText(file_get_contents($path)),0,30000);if($extension==="docx"&&class_exists("ZipArchive")){$zip=new \ZipArchive();if($zip->open($path)===true){$xml=$zip->getFromName("word/document.xml");$zip->close();if($xml!==false)return substr($this->plainText(str_replace(array("</w:p>","</w:tr>")," ",$xml)),0,30000);}}if($extension==="pdf"){$raw=file_get_contents($path);preg_match_all('/\((?:\\.|[^\\()])*\)/s',$raw,$matches);$parts=array();foreach(array_slice($matches[0],0,3000) as $part){$part=substr($part,1,-1);$part=preg_replace_callback('/\\([0-7]{1,3})/',function($m){return chr(octdec($m[1]));},$part);$parts[]=str_replace(array('\\n','\\r','\\t','\\(', '\\)','\\\\'),array(' ',' ',' ','(',')','\\'),$part);}return substr($this->plainText(implode(" ",$parts)),0,30000);}return "";}

    private function creatorService(){if(!defined("TENANT_RESOURCE_LOCATION"))throw new \Exception("The active tenant is unavailable.");$file=TENANT_RESOURCE_LOCATION."/apps/ai-agent-creator/services/creator-api/service.php";if(!file_exists($file))throw new \Exception("ai-agent-creator is not installed.");require_once($file);if(!class_exists("\\ai_agent_creator\\CreatorService"))throw new \Exception("ai-agent-creator could not be loaded.");return new \ai_agent_creator\CreatorService();}
    private function quizGenerationPrompt($lesson,$source,$count){return "Create an accurate editable lesson quiz from the supplied source. Return JSON only, with no markdown, using this shape: {\"title\":\"...\",\"instructions\":\"...\",\"questions\":[{\"question_type\":\"multiple_choice|true_false|multiple_answer|fill_blank|short_answer\",\"question_text\":\"...\",\"options\":[\"...\"],\"correct_answer\":\"string or array\",\"explanation\":\"...\",\"difficulty\":\"easy|medium|hard\",\"marks\":1,\"negative_marks\":0}]}. Generate exactly ".$count." questions. Every objective answer must be supported by the source. Use short_answer only when manual marking is appropriate. Lesson: ".$lesson->title."\nSOURCE:\n".substr($source,0,60000);}
    private function decodeAgentJson($reply){$text=trim(strval($reply));$text=preg_replace('/^```(?:json)?\s*/i','',$text);$text=preg_replace('/\s*```$/','',$text);$start=strpos($text,'{');$end=strrpos($text,'}');if($start===false||$end===false||$end<$start)return null;$decoded=json_decode(substr($text,$start,$end-$start+1));return json_last_error()===JSON_ERROR_NONE?$decoded:null;}

    private function gradeForPercentage($percentage){foreach($this->rows("course_manager_grading_scale","","asc") as $scale){if(isset($scale->active)&&!LessonRules::truthy($scale->active))continue;if($percentage>=floatval($scale->min_mark)&&$percentage<=floatval($scale->max_mark))return isset($scale->grade_letter)?$scale->grade_letter:(isset($scale->label)?$scale->label:"");}return $percentage>=50?"Pass":"Fail";}

    private function providerConnection($provider) { return in_array($provider,array("youtube","facebook"),true) ? $this->findOne($this->providerNs,"provider:".$this->clean($provider)) : null; }
    private function safeProviderConnection($provider) { $item=$this->providerConnection($provider); return $item ? $this->safeProviderRow($item) : array("provider"=>$provider,"status"=>"disconnected","has_client_secret"=>false,"has_api_key"=>false,"has_access_token"=>false,"has_refresh_token"=>false); }
    private function safeProviderRow($item) {
        if (!$item) return null; $safe=new \stdClass();
        foreach(array("id","provider","connection_scope","account_name","account_id","page_id","client_id","status","expires_at","last_tested_at","last_error","updated_at") as $field) if(isset($item->{$field})) $safe->{$field}=$item->{$field};
        $safe->has_client_secret=$this->providerValue($item,"client_secret_enc")!==""; $safe->has_api_key=$this->providerValue($item,"api_key_enc")!==""; $safe->has_access_token=$this->providerValue($item,"access_token_enc")!==""; $safe->has_refresh_token=$this->providerValue($item,"refresh_token_enc")!=="";
        return $safe;
    }
    private function hasProviderCredential($item) { return $this->providerValue($item,"api_key_enc")!=="" || $this->providerValue($item,"access_token_enc")!==""; }
    private function providerSecret() { $value=getenv("DAVVAG_PROVIDER_SECRET"); if($value!==false&&trim($value)!=="")return trim($value); return defined("DAVVAG_PROVIDER_SECRET")?trim(strval(DAVVAG_PROVIDER_SECRET)):""; }
    private function encryptProviderSecret($plain) {
        $key=hash('sha256',$this->providerSecret(),true); $iv=random_bytes(12); $tag=""; $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        return array("v"=>1,"iv"=>base64_encode($iv),"tag"=>base64_encode($tag),"data"=>base64_encode($cipher));
    }
    private function providerValue($item,$field) {
        if(!$item||!isset($item->{$field})||$this->providerSecret()==="")return ""; $payload=$item->{$field};
        if(is_string($payload)){$decoded=json_decode($payload,true);if(is_array($decoded))$payload=$decoded;}
        if(is_object($payload))$payload=get_object_vars($payload); if(!is_array($payload)||empty($payload["iv"])||empty($payload["tag"])||!isset($payload["data"]))return "";
        $plain=openssl_decrypt(base64_decode($payload["data"]),'aes-256-gcm',hash('sha256',$this->providerSecret(),true),OPENSSL_RAW_DATA,base64_decode($payload["iv"]),base64_decode($payload["tag"])); return $plain===false?"":$plain;
    }

    private function safeHttpUrl($url) { $parts=parse_url($url); return is_array($parts)&&isset($parts["scheme"],$parts["host"])&&strtolower($parts["scheme"])==="https"; }
    private function youtubeVideoId($url) {
        $p=parse_url($url); if(!is_array($p)||empty($p["host"]))return ""; $host=strtolower($p["host"]); $id="";
        if($host==="youtu.be"||$host==="www.youtu.be")$id=trim(isset($p["path"])?$p["path"]:"","/");
        elseif(preg_match('/(^|\.)youtube\.com$/',$host)){if(isset($p["query"])){parse_str($p["query"],$q);if(isset($q["v"]))$id=$q["v"];}if($id===""&&isset($p["path"])&&preg_match('#/(shorts|embed|live)/([^/?]+)#',$p["path"],$m))$id=$m[2];}
        return preg_match('/^[A-Za-z0-9_-]{6,20}$/',$id)?$id:"";
    }
    private function facebookVideoId($url) {
        $p=parse_url($url); if(!is_array($p)||empty($p["host"])||!preg_match('/(^|\.)facebook\.com$/',strtolower($p["host"])))return ""; $path=isset($p["path"])?$p["path"]:"";
        foreach(array('#/videos/(\d+)#','#/reel/(\d+)#','#/watch/?$#') as $pattern)if(preg_match($pattern,$path,$m)&&isset($m[1]))return $m[1]; if(isset($p["query"])){parse_str($p["query"],$q);if(isset($q["v"])&&ctype_digit(strval($q["v"])))return strval($q["v"]);} return "";
    }

    private function youtubeMetadata($url,$res) {
        $id=$this->youtubeVideoId($url); if($id==="")return $this->error($res,"The YouTube URL does not contain a valid video ID.");
        $out=array("provider"=>"youtube","video_id"=>$id,"title"=>"","thumbnail_url"=>"https://i.ytimg.com/vi/".$id."/hqdefault.jpg","transcript"=>"","duration_seconds"=>0,"messages"=>array());
        $oembed=$this->curlRequest("https://www.youtube.com/oembed","GET",array("format"=>"json","url"=>$url),array()); if($oembed->success){if(isset($oembed->data["title"]))$out["title"]=$oembed->data["title"];if(isset($oembed->data["thumbnail_url"]))$out["thumbnail_url"]=$oembed->data["thumbnail_url"];}else $out["messages"][]="YouTube public metadata was unavailable.";
        $connection=$this->providerConnection("youtube"); if(!$connection){$out["messages"][]="Connect YouTube in Settings for official metadata and available captions.";return $out;}
        $this->refreshYouTubeAccessToken($connection); $apiKey=$this->providerValue($connection,"api_key_enc"); $token=$this->providerValue($connection,"access_token_enc"); $params=array("part"=>"snippet,contentDetails","id"=>$id);if($apiKey!=="")$params["key"]=$apiKey;
        if($apiKey!==""||$token!==""){$headers=$token!==""?array("Authorization: Bearer ".$token):array();$video=$this->curlRequest("https://www.googleapis.com/youtube/v3/videos","GET",$params,$headers);if($video->success&&!empty($video->data["items"][0])){$item=$video->data["items"][0];$snippet=isset($item["snippet"])?$item["snippet"]:array();if(!empty($snippet["title"]))$out["title"]=$snippet["title"];if(!empty($snippet["thumbnails"])){$thumbs=$snippet["thumbnails"];foreach(array("maxres","standard","high","medium","default") as $key)if(isset($thumbs[$key]["url"])){$out["thumbnail_url"]=$thumbs[$key]["url"];break;}}if(isset($item["contentDetails"]["duration"]))$out["duration_seconds"]=$this->isoDurationSeconds($item["contentDetails"]["duration"]);}else $out["messages"][]="The connected YouTube API did not return video details.";}
        if($token!==""){$caption=$this->youtubeTranscript($id,$token);if($caption->success)$out["transcript"]=$caption->text;else $out["messages"][]=$caption->message;}else $out["messages"][]="An OAuth connection is required to request owner-accessible YouTube captions.";
        return $out;
    }
    private function facebookMetadata($url,$res) {
        $id=$this->facebookVideoId($url); $connection=$this->providerConnection("facebook"); if(!$connection)return $this->error($res,"Connect Facebook in Settings before fetching video metadata."); $token=$this->providerValue($connection,"access_token_enc"); if($token==="")return $this->error($res,"The Facebook connection has no access token.");
        if($id==="")return $this->error($res,"The Facebook URL does not expose a supported numeric video or reel ID.");
        $response=$this->curlRequest($this->facebookGraphUrl(rawurlencode($id)),"GET",array("fields"=>"id,title,description,length,thumbnails","access_token"=>$token),array()); if(!$response->success)return $this->error($res,"Facebook metadata request failed: ".$response->message);
        $d=$response->data;$thumb="";if(isset($d["thumbnails"]["data"])&&is_array($d["thumbnails"]["data"]))foreach($d["thumbnails"]["data"] as $t)if(!empty($t["uri"])){$thumb=$t["uri"];if(!empty($t["is_preferred"]))break;}
        return array("provider"=>"facebook","video_id"=>$id,"title"=>isset($d["title"])?$d["title"]:(isset($d["description"])?$d["description"]:""),"thumbnail_url"=>$thumb,"transcript"=>"","duration_seconds"=>isset($d["length"])?intval($d["length"]):0,"messages"=>array("Facebook did not expose transcript text for this video. You can enter it manually."));
    }
    private function youtubeTranscript($id,$token) {
        $list=$this->curlRequest("https://www.googleapis.com/youtube/v3/captions","GET",array("part"=>"snippet","videoId"=>$id),array("Authorization: Bearer ".$token)); if(!$list->success||empty($list->data["items"]))return (object)array("success"=>false,"text"=>"","message"=>"No downloadable YouTube caption track was available for this account.");
        $captionId=$list->data["items"][0]["id"];$download=$this->curlRequest("https://www.googleapis.com/youtube/v3/captions/".rawurlencode($captionId),"GET",array("tfmt"=>"srt"),array("Authorization: Bearer ".$token)); if(!$download->success)return (object)array("success"=>false,"text"=>"","message"=>"The selected YouTube caption track could not be downloaded.");
        $text=preg_replace('/^\d+\s*$/m','',$download->raw);$text=preg_replace('/\d{2}:\d{2}:\d{2}[,.]\d{3}\s+-->\s+\d{2}:\d{2}:\d{2}[,.]\d{3}/','',$text);$text=preg_replace('/\s+/',' ',strip_tags($text));return (object)array("success"=>trim($text)!=="","text"=>trim($text),"message"=>trim($text)===""?"The YouTube caption track was empty.":"");
    }
    private function isoDurationSeconds($duration){try{$i=new \DateInterval($duration);return intval($i->d)*86400+intval($i->h)*3600+intval($i->i)*60+intval($i->s);}catch(\Exception $e){return 0;}}

    private function testYouTubeConnection($item) { $this->refreshYouTubeAccessToken($item);$key=$this->providerValue($item,"api_key_enc");$token=$this->providerValue($item,"access_token_enc");if($key===""&&$token==="")return (object)array("success"=>false,"message"=>"Add an API key or complete OAuth connection.");$p=array("part"=>"id","id"=>"dQw4w9WgXcQ");if($key!=="")$p["key"]=$key;$r=$this->curlRequest("https://www.googleapis.com/youtube/v3/videos","GET",$p,$token!==""?array("Authorization: Bearer ".$token):array());return (object)array("success"=>$r->success,"message"=>$r->success?"YouTube API connection succeeded.":"YouTube API connection failed: ".$r->message); }
    private function testFacebookConnection($item) { $token=$this->providerValue($item,"access_token_enc");if($token==="")return (object)array("success"=>false,"message"=>"Complete OAuth or add a valid Facebook access token.");$r=$this->curlRequest($this->facebookGraphUrl("me"),"GET",array("fields"=>"id,name","access_token"=>$token),array());if($r->success){if(isset($r->data["name"]))$item->account_name=$r->data["name"];if(isset($r->data["id"]))$item->account_id=$r->data["id"];}return (object)array("success"=>$r->success,"message"=>$r->success?"Facebook API connection succeeded.":"Facebook API connection failed: ".$r->message); }
    private function facebookGraphVersion(){ $value=getenv("DAVVAG_FACEBOOK_GRAPH_VERSION");$value=$value!==false?trim($value):"";if($value==="")$value="v26.0";return preg_match('/^v\d+\.\d+$/',$value)?$value:"v26.0"; }
    private function facebookGraphUrl($path){return "https://graph.facebook.com/".$this->facebookGraphVersion()."/".ltrim($path,"/");}
    private function facebookDialogUrl(){return "https://www.facebook.com/".$this->facebookGraphVersion()."/dialog/oauth";}
    private function refreshYouTubeAccessToken($item) { if(!$item||empty($item->expires_at)||strtotime($item->expires_at)>time()+120)return;$refresh=$this->providerValue($item,"refresh_token_enc");$secret=$this->providerValue($item,"client_secret_enc");if($refresh===""||empty($item->client_id)||$secret==="")return;$r=$this->curlRequest("https://oauth2.googleapis.com/token","POST",array("client_id"=>$item->client_id,"client_secret"=>$secret,"refresh_token"=>$refresh,"grant_type"=>"refresh_token"),array());if($r->success&&!empty($r->data["access_token"])){$item->access_token_enc=$this->encryptProviderSecret($r->data["access_token"]);$item->expires_at=date("Y-m-d H:i:s",time()+intval(isset($r->data["expires_in"])?$r->data["expires_in"]:3600));$item->status="connected";$item->last_error="";$dummy=new LessonManagerMemoryResponse();$this->persist($this->providerNs,$item,$dummy);}}

    private function curlRequest($url,$method,$fields,$headers) {
        $out=(object)array("success"=>false,"status"=>0,"data"=>array(),"raw"=>"","message"=>"");if(!function_exists('curl_init')){$out->message="The PHP cURL extension is unavailable.";return $out;}$method=strtoupper($method);if($method==="GET"&&count($fields))$url.=(strpos($url,'?')===false?'?':'&').http_build_query($fields);
        $ch=curl_init($url);$all=array("Accept: application/json");foreach($headers as $h)$all[]=$h;curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$all));if($method==="POST"){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));$all[]="Content-Type: application/x-www-form-urlencoded";curl_setopt($ch,CURLOPT_HTTPHEADER,$all);} $raw=curl_exec($ch);$status=intval(curl_getinfo($ch,CURLINFO_HTTP_CODE));$error=curl_error($ch);curl_close($ch);$out->raw=$raw===false?"":$raw;$out->status=$status;$decoded=json_decode($out->raw,true);if(is_array($decoded))$out->data=$decoded;$out->success=$error===""&&$status>=200&&$status<300;if(!$out->success){$out->message=$error!==""?$error:(isset($out->data["error"]["message"])?$out->data["error"]["message"]:(isset($out->data["error_description"])?$out->data["error_description"]:"HTTP ".$status));}return $out;
    }
    private function providerCallbackUrl(){return $this->appBaseUrl()."/components/lesson-manager/api/service/ProviderOAuthCallback";}
    private function appBaseUrl(){if(isset($_SERVER["HTTP_X_FORWARDED_PROTO"]))$scheme=trim(explode(',',$_SERVER["HTTP_X_FORWARDED_PROTO"])[0]);else $scheme=isset($_SERVER["HTTPS"])&&$_SERVER["HTTPS"]!=="off"?"https":"http";$host=isset($_SERVER["HTTP_HOST"])?$_SERVER["HTTP_HOST"]:"localhost";$uri=isset($_SERVER["REQUEST_URI"])?$_SERVER["REQUEST_URI"]:"";$position=strpos($uri,"/components/");$base=$position===false?"":substr($uri,0,$position);return $scheme."://".$host.rtrim($base,"/");}

    private function isActiveEnrollment($enrollment){return !isset($enrollment->status)||strtolower($enrollment->status)==="active";}
    private function courseIdForEnrollment($enrollment){if(isset($enrollment->course_id)&&intval($enrollment->course_id)>0)return intval($enrollment->course_id);if(!empty($enrollment->class_grade_id)){ $classGrade=$this->byId($this->classNs,$enrollment->class_grade_id); if($classGrade&&isset($classGrade->course_id))return intval($classGrade->course_id);}return 0;}
    private function canAccessCourse($courseId,$studentId){if($this->isTeacher())return true;if(intval($studentId)<1)return false;$rows=$this->rows($this->enrollmentNs,"student_id:".intval($studentId),"desc");foreach($rows as $r)if($this->isActiveEnrollment($r)&&intval($this->courseIdForEnrollment($r))===intval($courseId))return true;return false;}
    private function lessonUnlockedFor($target,$studentId){return$this->isTeacher()||($this->progressionUnlockedFor($target,$studentId)&&$this->hasPaidLessonAccess($target,$studentId));}
    private function progressionUnlockedFor($target,$studentId){if($this->isTeacher())return true;if(!$target||empty($target->subject_id))return false;if(isset($target->status)&&strtolower($target->status)!=="published")return false;$lessons=$this->rows($this->lessonNs,"subject_id:".intval($target->subject_id),"asc");usort($lessons,array($this,"sortLessons"));$previousMet=true;foreach($lessons as$lesson){if(isset($lesson->status)&&strtolower($lesson->status)!=="published")continue;$available=empty($lesson->available_at)||strtotime($lesson->available_at)<=time();if(strval($lesson->id)===strval($target->id))return$previousMet&&$available;$progress=$this->findOne($this->progressNs,"lesson_id:".intval($lesson->id).",student_id:".intval($studentId));$previousMet=!LessonRules::truthy(isset($lesson->progression_enabled)?$lesson->progression_enabled:true)||LessonRules::requirementsMet($lesson,$progress);}return false;}
    private function hasPaidLessonAccess($lesson,$studentId){if($this->isTeacher()||!$lesson||!isset($lesson->is_free)||LessonRules::truthy($lesson->is_free)||intval(isset($lesson->required_credit_points)?$lesson->required_credit_points:0)<1)return true;try{return$this->creditLedger()->hasLessonUnlock($studentId,$lesson->id)!==null;}catch(\Throwable$e){return false;}}
    private function creditLedger(){if(!class_exists("\\davvag_credit_points\\CreditLedgerService"))throw new \Exception("Credit Points is unavailable. Ask an administrator to install and configure it.");return new \davvag_credit_points\CreditLedgerService();}
    private function requestedStudent($body){if($this->isTeacher()&&isset($body->student_id)&&intval($body->student_id)>0)return $this->profile($body->student_id);return $this->currentProfile();}
    private function profile($id){$row=$this->byId("profile",$id);$out=new \stdClass();$out->id=intval($id);$out->name=$row&&isset($row->name)?$row->name:"Student #".$id;if($row&&isset($row->email))$out->email=$row->email;return $out;}
    private function currentProfile(){ $out=new \stdClass();$out->id=0;$out->name="Current user";if(class_exists("\\Profile")){$stored=\Profile::getUserProfile();$profile=is_object($stored)&&isset($stored->profile)?$stored->profile:$stored;if(is_object($profile)&&isset($profile->id)&&intval($profile->id)>0)return $profile;}return $out; }
    private function currentRole(){if(defined("GROUPID")){$g=strtolower(GROUPID);if($g==="sysadmin")return "admin";if($g==="web_user")return "student";return $g;}if(class_exists("\\Auth")){$u=\Auth::Autendicate();if(isset($u->group))return strtolower($u->group);}return "anonymous";}
    private function isTeacher(){return in_array($this->currentRole(),array("admin","sysadmin","staff","teacher"),true);}
    private function isAdmin(){return in_array($this->currentRole(),array("admin","sysadmin"),true);}
    private function validProfile($profile){return is_object($profile)&&isset($profile->id)&&intval($profile->id)>0;}
    private function requireTeacher($res){if(!$this->isTeacher()){$res->SetError("Teacher or administrator permission is required.");return false;}if(!$this->validProfile($this->currentProfile())){$res->SetError("An active profile is required.");return false;}return true;}
    private function notifyCourse($courseId,$event,$message,$type,$id){foreach($this->rows($this->enrollmentNs,"","desc") as $e)if($this->isActiveEnrollment($e)&&intval($this->courseIdForEnrollment($e))===intval($courseId))$this->queueNotification($this->profile($e->student_id),$event,$message,$type,$id);}
    private function notifyTeachersForLesson($lesson,$event,$message,$type,$id){$subject=$lesson?$this->byId($this->subjectNs,$lesson->subject_id):null;if($subject&&!empty($subject->teacher_id))$this->queueNotification($this->profile($subject->teacher_id),$event,$message,$type,$id);}
    private function queueNotification($profile,$event,$message,$type,$id){if(!$this->validProfile($profile))return;$existing=$this->findOne($this->notificationNs,"profile_id:".intval($profile->id).",event_type:".$this->clean($event).",entity_type:".$this->clean($type).",entity_id:".intval($id).",status:queued");if($existing)return;$n=new \stdClass();$n->entity_type=$type;$n->entity_id=$id;$n->profile_id=$profile->id;$n->profile_name=isset($profile->name)?$profile->name:"";$n->email=isset($profile->email)?$profile->email:"";$n->event_type=$event;$n->message=$message;$n->status="queued";$n->created_at=date("Y-m-d H:i:s");$dummy=new LessonManagerMemoryResponse();$this->persist($this->notificationNs,$n,$dummy);}
}

class LessonManagerMemoryResponse { private $error=null; public function SetError($error){$this->error=$error;} public function GetError(){return $this->error;} }
?>
