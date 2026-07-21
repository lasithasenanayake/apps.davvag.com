<?php
namespace lesson_manager;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) require_once(PLUGIN_PATH . "/auth/auth.php");
    if (defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
}

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
        return array(
            "role" => $this->currentRole(), "profile" => $this->currentProfile(),
            "courses" => $this->rows($this->courseNs, "", "asc"),
            "subjects" => $this->rows($this->subjectNs, "", "asc"),
            "classGrades" => $this->rows($this->classNs, "", "asc"),
            "assignments" => $this->rows($this->assignmentNs, "", "desc"),
            "profiles" => $this->isTeacher() ? $this->rows("profile", "", "asc") : array()
        );
    }

    public function postDashboard($req, $res) {
        $lessons = $this->rows($this->lessonNs, "", "asc");
        $quizzes = $this->rows($this->quizNs, "", "desc");
        $progress = $this->rows($this->progressNs, "", "desc");
        $submissions = $this->rows($this->submissionNs, "", "desc");
        $pending = 0; foreach ($submissions as $row) if (!isset($row->status) || in_array(strtolower($row->status), array("submitted", "pending"))) $pending++;
        if ($this->currentRole() === "student") return array("studentCourses" => $this->studentCourses(new \stdClass()), "role" => "student");
        return array("role" => $this->currentRole(), "stats" => array(
            "lessons" => count($lessons), "published" => $this->countStatus($lessons, "published"),
            "quizzes" => count($quizzes), "active_students" => $this->distinctCount($progress, "student_id"), "pending_marking" => $pending
        ), "recentLessons" => array_slice(array_reverse($lessons), 0, 6), "recentAttempts" => array_slice($this->rows($this->attemptNs, "", "desc"), 0, 6));
    }

    public function postListLessons($req, $res) { return $this->listBody($req, $this->lessonNs, array("id","course_id","subject_id","status"), array("title","description"), "asc"); }
    public function postSaveLesson($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $item = $this->body($req);
        if (empty($item->subject_id) || empty($item->title)) return $this->error($res, "Subject and lesson title are required.");
        $subject = $this->byId($this->subjectNs, $item->subject_id);
        if (!$subject || empty($subject->course_id)) return $this->error($res, "Select a valid subject assigned to a course.");
        $item->course_id = $subject->course_id;
        if (!isset($item->lesson_order) || intval($item->lesson_order) < 1) $item->lesson_order = count($this->rows($this->lessonNs, "subject_id:" . intval($item->subject_id), "asc")) + 1;
        if (empty($item->status)) $item->status = "draft";
        if (!isset($item->passing_mark)) $item->passing_mark = 70;
        $item->updated_at = date("Y-m-d H:i:s");
        if (empty($item->id)) { $profile = $this->currentProfile(); $item->created_by = $profile->id; $item->created_at = $item->updated_at; }
        $saved = $this->persist($this->lessonNs, $item, $res);
        if ($saved && strtolower($saved->status) === "published") $this->notifyCourse($saved->course_id, "lesson-published", "New lesson published: " . $saved->title, "lesson", $saved->id);
        return $saved;
    }
    public function postDeleteLesson($req, $res) { return $this->delete($this->lessonNs, $this->body($req), $res); }
    public function postReorderLessons($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $body = $this->body($req); $items = isset($body->lessons) ? $body->lessons : array(); $saved = array();
        $subjectId = 0;
        foreach ($items as $index => $item) {
            $stored = isset($item->id) ? $this->byId($this->lessonNs, $item->id) : null;
            if (!$stored || empty($stored->subject_id)) return $this->error($res, "Every reordered lesson must belong to a subject.");
            if ($subjectId === 0) $subjectId = intval($stored->subject_id);
            if (intval($stored->subject_id) !== $subjectId) return $this->error($res, "Lessons can only be reordered within the same subject.");
            $stored->lesson_order = $index + 1; $saved[] = $this->persist($this->lessonNs, $stored, $res);
        }
        return $saved;
    }

    public function postListContent($req, $res) { return $this->listBody($req, $this->contentNs, array("id","lesson_id","content_type","status"), array("title","body"), "asc"); }
    public function postSaveContent($req, $res) {
        if (!$this->requireTeacher($res)) return null;
        $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title)) return $this->error($res, "Lesson and content title are required.");
        $item->body = $this->sanitizeRichText(isset($item->body) ? $item->body : "");
        return $this->persist($this->contentNs, $item, $res);
    }
    public function postDeleteContent($req, $res) { return $this->delete($this->contentNs, $this->body($req), $res); }
    public function postListVideos($req, $res) { return $this->listBody($req, $this->videoNs, array("id","lesson_id","provider","status"), array("title","video_url","transcript"), "asc"); }
    public function postSaveVideo($req, $res) {
        if (!$this->requireTeacher($res)) return null; $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title) || (empty($item->video_url) && empty($item->media_reference))) return $this->error($res, "Lesson, title, and a video URL or media reference are required.");
        if (empty($item->provider)) $item->provider = $this->videoProvider(isset($item->video_url) ? $item->video_url : "");
        if (empty($item->status)) $item->status = "published";
        return $this->persist($this->videoNs, $item, $res);
    }
    public function postDeleteVideo($req, $res) { return $this->delete($this->videoNs, $this->body($req), $res); }

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
        if (!$item) { $item = new \stdClass(); $item->provider = $provider; $item->created_by = $this->currentProfile()->id; $item->created_at = date("Y-m-d H:i:s"); }
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
        $_SESSION["lesson_manager_oauth_" . $state] = array("provider"=>$provider, "created"=>time(), "redirect_uri"=>$redirect);
        if ($provider === "youtube") {
            $params = array("client_id"=>$clientId,"redirect_uri"=>$redirect,"response_type"=>"code","access_type"=>"offline","prompt"=>"consent","include_granted_scopes"=>"true","scope"=>"https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl","state"=>$state);
            return array("authorize_url"=>"https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params));
        }
        if ($provider === "facebook") {
            $params = array("client_id"=>$clientId,"redirect_uri"=>$redirect,"response_type"=>"code","scope"=>"pages_show_list,pages_read_engagement,pages_read_user_content","state"=>$state);
            return array("authorize_url"=>"https://www.facebook.com/v19.0/dialog/oauth?" . http_build_query($params));
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
        if ($code === "") return $this->error($res, isset($_GET["error_description"]) ? $_GET["error_description"] : "Provider authorization was cancelled.");
        $provider = $stateData["provider"]; $item = $this->providerConnection($provider); if (!$item) return $this->error($res, "Provider configuration no longer exists.");
        $fields = array("client_id"=>$item->client_id,"client_secret"=>$this->providerValue($item,"client_secret_enc"),"code"=>$code,"redirect_uri"=>$stateData["redirect_uri"]);
        if ($provider === "youtube") { $fields["grant_type"] = "authorization_code"; $token = $this->curlRequest("https://oauth2.googleapis.com/token", "POST", $fields, array()); }
        else { $token = $this->curlRequest("https://graph.facebook.com/v19.0/oauth/access_token", "GET", $fields, array()); }
        if (!$token->success || !isset($token->data["access_token"])) return $this->error($res, "OAuth token exchange failed: " . $token->message);
        $item->access_token_enc = $this->encryptProviderSecret($token->data["access_token"]);
        if (isset($token->data["refresh_token"])) $item->refresh_token_enc = $this->encryptProviderSecret($token->data["refresh_token"]);
        if (isset($token->data["expires_in"])) $item->expires_at = date("Y-m-d H:i:s", time() + intval($token->data["expires_in"]));
        if (isset($token->data["scope"])) $item->scopes = preg_split('/[\s,]+/', trim(strval($token->data["scope"])), -1, PREG_SPLIT_NO_EMPTY);
        if ($provider === "youtube") {
            $identity = $this->curlRequest("https://www.googleapis.com/youtube/v3/channels", "GET", array("part"=>"snippet", "mine"=>"true"), array("Authorization: Bearer " . $token->data["access_token"]));
            if ($identity->success && !empty($identity->data["items"][0])) { $channel=$identity->data["items"][0]; if(isset($channel["id"]))$item->account_id=$channel["id"]; if(isset($channel["snippet"]["title"]))$item->account_name=$channel["snippet"]["title"]; }
        } else {
            $pages = $this->curlRequest("https://graph.facebook.com/v19.0/me/accounts", "GET", array("fields"=>"id,name,access_token", "access_token"=>$token->data["access_token"]), array());
            if ($pages->success && !empty($pages->data["data"])) { $page=$pages->data["data"][0]; if(!empty($item->page_id))foreach($pages->data["data"] as $candidate)if(isset($candidate["id"])&&strval($candidate["id"])===strval($item->page_id)){$page=$candidate;break;} if(isset($page["id"])){$item->page_id=$page["id"];$item->account_id=$page["id"];}if(isset($page["name"]))$item->account_name=$page["name"];if(isset($page["access_token"]))$item->access_token_enc=$this->encryptProviderSecret($page["access_token"]); }
        }
        $item->status = "connected"; $item->last_error = ""; $item->updated_at = date("Y-m-d H:i:s"); $this->persist($this->providerNs, $item, $res);
        header("Location: " . $this->appBaseUrl() . "/#/app/lesson-manager/settings?oauth=" . rawurlencode($provider));
        return array("provider"=>$provider,"status"=>"connected");
    }

    public function postListQuizzes($req, $res) { return $this->listBody($req, $this->quizNs, array("id","lesson_id","status"), array("title"), "desc"); }
    public function postSaveQuiz($req, $res) {
        if (!$this->requireTeacher($res)) return null; $item = $this->body($req);
        if (empty($item->lesson_id) || empty($item->title)) return $this->error($res, "Lesson and quiz title are required.");
        if (!isset($item->passing_percentage)) $item->passing_percentage = 70;
        if (!isset($item->attempt_limit)) $item->attempt_limit = 3;
        if (empty($item->status)) $item->status = "draft";
        if (empty($item->created_at)) { $item->created_at = date("Y-m-d H:i:s"); $item->created_by = $this->currentProfile()->id; }
        $saved = $this->persist($this->quizNs, $item, $res);
        if ($saved && strtolower($saved->status) === "published") {
            $lesson = $this->byId($this->lessonNs, $saved->lesson_id);
            $assessment = empty($saved->assessment_id) ? new \stdClass() : $this->byId($this->assessmentNs, $saved->assessment_id);
            if (!$assessment) $assessment = new \stdClass();
            $assessment->class_grade_id = 0; $assessment->subject_id = $lesson && isset($lesson->subject_id) ? $lesson->subject_id : 0;
            $assessment->title = $saved->title; $assessment->assessment_type = "lesson_quiz"; $assessment->max_mark = $this->quizMaxMark($saved->id); $assessment->weight = 0; $assessment->status = "active";
            $assessment = $this->persist($this->assessmentNs, $assessment, $res); if ($assessment && empty($saved->assessment_id)) { $saved->assessment_id = $assessment->id; $saved = $this->persist($this->quizNs, $saved, $res); }
        }
        return $saved;
    }
    public function postDeleteQuiz($req, $res) { return $this->delete($this->quizNs, $this->body($req), $res); }
    public function postListQuestions($req, $res) {
        $body = $this->body($req);
        if (!$this->isTeacher()) {
            if (empty($body->quiz_id)) return $this->error($res, "quiz_id is required.");
            $quiz = $this->byId($this->quizNs, $body->quiz_id); $lesson = $quiz ? $this->byId($this->lessonNs, $quiz->lesson_id) : null; $student = $this->currentProfile();
            if (!$quiz || strtolower($quiz->status) !== "published" || !$lesson || !$this->canAccessCourse($lesson->course_id, $student->id) || !$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Quiz access denied.");
        }
        $rows = $this->listObject($body, $this->questionNs, array("id","quiz_id","question_type","difficulty"), array("question_text","explanation"), "asc");
        if ($this->isTeacher()) return $rows;
        foreach ($rows as $row) { unset($row->correct_answer); unset($row->explanation); unset($row->negative_marks); }
        return $rows;
    }
    public function postSaveQuestion($req, $res) { return $this->teacherSave($req, $res, $this->questionNs, array("quiz_id","question_text"), "Quiz and question text are required."); }
    public function postDeleteQuestion($req, $res) { return $this->delete($this->questionNs, $this->body($req), $res); }

    public function postGenerateQuiz($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req);
        if (empty($body->lesson_id)) return $this->error($res, "Select a lesson first.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        $quiz = new \stdClass(); $quiz->lesson_id = $lesson->id; $quiz->title = isset($body->title) && $body->title ? $body->title : $lesson->title . " knowledge check";
        $quiz->passing_percentage = isset($body->passing_percentage) ? $body->passing_percentage : 70; $quiz->attempt_limit = 3; $quiz->time_limit_minutes = 15;
        $quiz->random_questions = "true"; $quiz->random_answers = "true"; $quiz->negative_marking = "false"; $quiz->status = "draft";
        $quiz = $this->persist($this->quizNs, $quiz, $res); if (!$quiz) return null;
        $source = isset($lesson->description) ? strval($lesson->description) : "";
        foreach ($this->rows($this->contentNs, "lesson_id:" . intval($lesson->id), "asc") as $row) $source .= " " . (isset($row->body) ? $this->plainText($row->body) : "");
        foreach ($this->rows($this->videoNs, "lesson_id:" . intval($lesson->id), "asc") as $row) $source .= " " . (isset($row->transcript) ? $this->plainText($row->transcript) : "");
        if (isset($body->notes)) $source .= " " . $body->notes;
        $sentences = $this->sourceSentences($source); if (count($sentences) === 0) $sentences[] = $lesson->title . " is the focus of this lesson.";
        $limit = isset($body->question_count) ? max(1, min(20, intval($body->question_count))) : 5; $questions = array();
        for ($i = 0; $i < $limit; $i++) {
            $sentence = $sentences[$i % count($sentences)]; $question = new \stdClass(); $question->quiz_id = $quiz->id; $question->sort_order = $i + 1; $question->marks = 1; $question->negative_marks = 0; $question->difficulty = $i < 2 ? "easy" : ($i < 4 ? "medium" : "hard"); $question->requires_manual_marking = "false";
            if ($i % 3 === 0) { $question->question_type = "true_false"; $question->question_text = "True or false: " . $sentence; $question->options = array("True", "False"); $question->correct_answer = "True"; }
            elseif ($i % 3 === 1) { $keyword = $this->keyword($sentence); $question->question_type = "fill_blank"; $question->question_text = str_ireplace($keyword, "_____", $sentence); $question->options = array(); $question->correct_answer = $keyword; }
            else { $question->question_type = "multiple_choice"; $question->question_text = "Which statement is supported by the lesson?"; $question->options = array($sentence, "This topic is unrelated to the lesson.", "The lesson provides no information about this topic.", "None of the above."); $question->correct_answer = $sentence; }
            $question->explanation = "Generated from lesson material: " . $sentence; $questions[] = $this->persist($this->questionNs, $question, $res);
        }
        return array("quiz" => $quiz, "questions" => $questions, "source_characters" => strlen($source), "generator" => "lesson-material-draft");
    }

    public function postStudentCourses($req, $res) { return $this->studentCourses($this->body($req)); }
    public function postLearningCourse($req, $res) {
        $body = $this->body($req); if (empty($body->course_id)) return $this->error($res, "course_id is required.");
        $student = $this->requestedStudent($body); if (!$this->canAccessCourse($body->course_id, $student->id)) return $this->error($res, "This course is not assigned to you.");
        $course = $this->byId($this->courseNs, $body->course_id); $lessons = $this->rows($this->lessonNs, "course_id:" . intval($body->course_id), "asc"); usort($lessons, array($this, "sortLessonsBySubject"));
        $progress = $this->rows($this->progressNs, "course_id:" . intval($body->course_id) . ",student_id:" . intval($student->id), "asc"); $progressMap = $this->mapBy($progress, "lesson_id");
        $subjects = $this->mapBy($this->rows($this->subjectNs, "course_id:" . intval($body->course_id), "asc"), "id");
        $previousMet = true; $activeSubjectId = null; $out = array();
        foreach ($lessons as $lesson) {
            if (isset($lesson->status) && strtolower($lesson->status) !== "published" && !$this->isTeacher()) continue;
            if (empty($lesson->subject_id)) continue;
            if ($activeSubjectId === null || strval($activeSubjectId) !== strval($lesson->subject_id)) { $activeSubjectId = $lesson->subject_id; $previousMet = true; }
            $p = isset($progressMap[strval($lesson->id)]) ? $progressMap[strval($lesson->id)] : null;
            $available = empty($lesson->available_at) || strtotime($lesson->available_at) <= time(); $unlocked = $this->isTeacher() || ($previousMet && $available);
            $reason = ""; if (!$available) $reason = "Available on " . $lesson->available_at; elseif (!$previousMet) $reason = "Complete the previous lesson requirements.";
            $entry = clone $lesson; $entry->subject = isset($subjects[strval($lesson->subject_id)]) ? $subjects[strval($lesson->subject_id)] : null; $entry->progress = $p; $entry->unlocked = $unlocked; $entry->lock_reason = $reason;
            $entry->content = $unlocked ? $this->safeContentRows($this->rows($this->contentNs, "lesson_id:" . intval($lesson->id), "asc")) : array();
            $entry->videos = $unlocked ? $this->rows($this->videoNs, "lesson_id:" . intval($lesson->id), "asc") : array();
            $entry->quizzes = $unlocked ? $this->publishedRows($this->quizNs, "lesson_id:" . intval($lesson->id)) : array();
            $entry->assignmentRules = $unlocked ? $this->rows($this->ruleNs, "lesson_id:" . intval($lesson->id), "asc") : array(); $out[] = $entry;
            $previousMet = !LessonRules::truthy(isset($lesson->progression_enabled) ? $lesson->progression_enabled : true) || LessonRules::requirementsMet($lesson, $p);
        }
        return array("course" => $course, "student" => $student, "lessons" => $out);
    }

    public function postStartLesson($req, $res) { $body = $this->body($req); return $this->touchProgress($body, "viewed", $res); }
    public function postCompleteActivity($req, $res) {
        $body = $this->body($req); if (empty($body->lesson_id) || empty($body->activity)) return $this->error($res, "lesson_id and activity are required.");
        $allowed = array("reading"=>"reading_completed", "video"=>"video_completed"); if (!isset($allowed[$body->activity])) return $this->error($res, "Unsupported activity.");
        return $this->touchProgress($body, $allowed[$body->activity], $res);
    }

    public function postSubmitQuiz($req, $res) {
        $body = $this->body($req); if (empty($body->quiz_id)) return $this->error($res, "quiz_id is required.");
        $quiz = $this->byId($this->quizNs, $body->quiz_id); if (!$quiz || strtolower($quiz->status) !== "published") return $this->error($res, "Quiz is not available.");
        $lesson = $this->byId($this->lessonNs, $quiz->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        $student = $this->requestedStudent($body); if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied.");
        if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements before opening this quiz.");
        $attempts = $this->rows($this->attemptNs, "quiz_id:" . intval($quiz->id) . ",student_id:" . intval($student->id), "desc");
        if (intval($quiz->attempt_limit) > 0 && count($attempts) >= intval($quiz->attempt_limit)) return $this->error($res, "Attempt limit reached.");
        $answers = isset($body->answers) ? $body->answers : new \stdClass(); $questions = $this->rows($this->questionNs, "quiz_id:" . intval($quiz->id), "asc"); $score = 0; $max = 0; $manual = false;
        foreach ($questions as $q) { $max += floatval(isset($q->marks) ? $q->marks : 1); $answer = $this->answerFor($answers, $q->id); $earned = LessonRules::scoreQuestion($q, $answer, LessonRules::truthy($quiz->negative_marking)); if ($earned === null) $manual = true; else $score += $earned; }
        $score = max(0, $score); $percentage = $max > 0 ? round(($score / $max) * 100, 2) : 0; $passed = !$manual && $percentage >= floatval($quiz->passing_percentage);
        $attempt = new \stdClass(); $attempt->quiz_id = $quiz->id; $attempt->lesson_id = $lesson->id; $attempt->course_id = $lesson->course_id; $attempt->student_id = $student->id; $attempt->student_name = $student->name;
        $attempt->attempt_number = count($attempts) + 1; $attempt->answers = $answers; $attempt->marks = $score; $attempt->max_mark = $max; $attempt->percentage = $percentage; $attempt->passed = $passed ? "true" : "false"; $attempt->status = $manual ? "pending_manual_marking" : ($passed ? "passed" : "failed"); $attempt->started_at = isset($body->started_at) ? $body->started_at : date("Y-m-d H:i:s"); $attempt->completed_at = date("Y-m-d H:i:s");
        $attempt = $this->persist($this->attemptNs, $attempt, $res); $this->saveQuizMark($quiz, $lesson, $student, $attempt, $res);
        $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->quiz_completed = $manual ? "false" : "true"; $progress->quiz_passed = $passed ? "true" : "false"; $progress->quiz_attempts = $attempt->attempt_number; $progress->quiz_mark = $score; $this->finishProgress($lesson, $progress, $res);
        $this->queueNotification($student, $passed ? "quiz-passed" : "quiz-failed", ($passed ? "Passed: " : "Attempt completed: ") . $quiz->title, "quiz", $quiz->id);
        return array("attempt" => $attempt, "passed" => $passed, "manual_marking" => $manual, "next_unlocked" => $passed && LessonRules::requirementsMet($lesson, $progress));
    }

    public function postOverrideLesson($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->lesson_id) || empty($body->student_id)) return $this->error($res, "Lesson and student are required.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        $student = $this->profile($body->student_id); $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->override_unlocked = "true"; $progress->teacher_approved = "true"; return $this->finishProgress($lesson, $progress, $res);
    }

    public function postListAssignmentRules($req, $res) { return $this->listBody($req, $this->ruleNs, array("id","lesson_id","assignment_id"), array("allowed_formats"), "desc"); }
    public function postSaveAssignmentRule($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->lesson_id)) return $this->error($res, "Lesson is required.");
        $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found.");
        if (empty($body->assignment_id) && isset($body->assignment)) { $assignment = $body->assignment; $assignment->subject_id = $lesson->subject_id; if (empty($assignment->class_grade_id)) $assignment->class_grade_id = 0; if (empty($assignment->status)) $assignment->status = "published"; $assignment->created_by = $this->currentProfile()->id; $assignment->created_at = date("Y-m-d H:i:s"); $assignment = $this->persist($this->assignmentNs, $assignment, $res); if ($assignment) $body->assignment_id = $assignment->id; unset($body->assignment); }
        if (empty($body->assignment_id)) return $this->error($res, "Assignment is required."); return $this->persist($this->ruleNs, $body, $res);
    }
    public function postSubmitAssignment($req, $res) {
        $body = $this->body($req); if (empty($body->assignment_id) || empty($body->lesson_id)) return $this->error($res, "Assignment and lesson are required."); $student = $this->requestedStudent($body); $lesson = $this->byId($this->lessonNs, $body->lesson_id);
        if (!$lesson) return $this->error($res, "Lesson not found.");
        if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied.");
        if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements before submitting this assignment.");
        $existing = $this->rows($this->submissionNs, "assignment_id:" . intval($body->assignment_id) . ",student_id:" . intval($student->id), "desc"); $rule = $this->findOne($this->ruleNs, "assignment_id:" . intval($body->assignment_id));
        if ($rule && intval($rule->max_submissions) > 0 && count($existing) >= intval($rule->max_submissions)) return $this->error($res, "Submission limit reached.");
        $submission = new \stdClass(); $submission->assignment_id = $body->assignment_id; $submission->student_id = $student->id; $submission->student_name = $student->name; $submission->content = isset($body->content) ? $body->content : ""; $submission->file_url = isset($body->file_url) ? $body->file_url : ""; $submission->submitted_at = date("Y-m-d H:i:s"); $submission->status = "submitted"; $submission = $this->persist($this->submissionNs, $submission, $res);
        $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->assignment_submitted = "true"; $this->finishProgress($lesson, $progress, $res); $this->notifyTeachers("assignment-submitted", $student->name . " submitted an assignment.", "submission", $submission->id); return $submission;
    }

    public function postReviewSubmission($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); if (empty($body->submission_id)) return $this->error($res, "submission_id is required."); $submission = $this->byId($this->submissionNs, $body->submission_id); if (!$submission) return $this->error($res, "Submission not found.");
        $assignment = $this->byId($this->assignmentNs, $submission->assignment_id); $rule = $this->findOne($this->ruleNs, "assignment_id:" . intval($submission->assignment_id)); $profile = $this->currentProfile(); $marks = isset($body->marks) ? floatval($body->marks) : 0; $pass = $rule ? $marks >= floatval($rule->passing_mark) : $marks >= (floatval($assignment->max_mark) * .5);
        $submission->marks = $marks; $submission->feedback = isset($body->feedback) ? $body->feedback : ""; $submission->status = isset($body->status) ? $body->status : ($pass ? "graded" : "resubmission_requested"); $submission->graded_by = $profile->id; $submission->graded_at = date("Y-m-d H:i:s"); $submission = $this->persist($this->submissionNs, $submission, $res);
        $mark = $this->findOne($this->markNs, "submission_id:" . intval($submission->id)); if (!$mark) $mark = new \stdClass(); $mark->assignment_id = $assignment->id; $mark->submission_id = $submission->id; $mark->class_grade_id = isset($assignment->class_grade_id) ? $assignment->class_grade_id : 0; $mark->subject_id = isset($assignment->subject_id) ? $assignment->subject_id : 0; $mark->student_id = $submission->student_id; $mark->student_name = $submission->student_name; $mark->marks = $marks; $mark->max_mark = $assignment->max_mark; $mark->graded_by = $profile->id; $mark->graded_at = date("Y-m-d H:i:s"); $mark->note = $submission->feedback; $this->persist($this->markNs, $mark, $res);
        if ($rule) { $lesson = $this->byId($this->lessonNs, $rule->lesson_id); if ($lesson) { $student = $this->profile($submission->student_id); $progress = $this->progress($lesson->course_id, $lesson->id, $student); $progress->assignment_mark = $marks; $progress->assignment_passed = $pass ? "true" : "false"; if (isset($body->approve) && LessonRules::truthy($body->approve)) $progress->teacher_approved = "true"; $this->finishProgress($lesson, $progress, $res); } }
        $this->queueNotification($this->profile($submission->student_id), $pass ? "marks-awarded" : "resubmission-requested", "Your assignment was reviewed. Mark: " . $marks, "submission", $submission->id); return $submission;
    }

    public function postSubmissionsReport($req, $res) {
        $body = $this->body($req); $rows = $this->listObject($body, $this->submissionNs, array("assignment_id","student_id","status"), array("student_name","content","feedback"), "desc"); $out = array();
        foreach ($rows as $row) { $assignment = $this->byId($this->assignmentNs, $row->assignment_id); if (isset($body->course_id) && $body->course_id && (!$assignment || !$this->assignmentInCourse($assignment, $body->course_id))) continue; if (isset($body->subject_id) && $body->subject_id && (!$assignment || strval($assignment->subject_id) !== strval($body->subject_id))) continue; $rule = $this->findOne($this->ruleNs, "assignment_id:" . intval($row->assignment_id)); if (isset($body->lesson_id) && $body->lesson_id && (!$rule || strval($rule->lesson_id) !== strval($body->lesson_id))) continue; $item = clone $row; $item->assignment = $assignment; $item->rule = $rule; $out[] = $item; }
        return $out;
    }
    public function postProgressReport($req, $res) {
        if (!$this->requireTeacher($res)) return null; $body = $this->body($req); $rows = $this->listObject($body, $this->progressNs, array("course_id","lesson_id","student_id"), array("student_name"), "desc"); $lessons = $this->mapBy($this->rows($this->lessonNs, "", "asc"), "id"); $out = array();
        foreach ($rows as $row) { $item = clone $row; $item->lesson = isset($lessons[strval($row->lesson_id)]) ? $lessons[strval($row->lesson_id)] : null; if (isset($body->subject_id) && $body->subject_id && (!$item->lesson || strval($item->lesson->subject_id) !== strval($body->subject_id))) continue; $item->completion_percentage = $this->progressPercentage($item->lesson, $row); $out[] = $item; } return $out;
    }

    public function postSeedDemo($req, $res) {
        if (!$this->requireTeacher($res)) return null; $courses = $this->rows($this->courseNs, "", "asc"); if (count($courses) === 0) return $this->error($res, "Create a course in Course Manager first."); $course = $courses[0];
        $subjects = $this->rows($this->subjectNs, "course_id:" . intval($course->id), "asc"); if (count($subjects) === 0) return $this->error($res, "Create a subject under the course in Course Manager first."); $subject = $subjects[0];
        if ($this->findOne($this->lessonNs, "subject_id:" . intval($subject->id) . ",title:Welcome to the subject")) return $this->postDashboard($req, $res);
        $lesson = new \stdClass(); $lesson->course_id = $course->id; $lesson->subject_id = $subject->id; $lesson->title = "Welcome to the subject"; $lesson->description = "Understand the subject learning goals, lesson structure, and how to complete each activity."; $lesson->lesson_order = 1; $lesson->passing_mark = 70; $lesson->status = "published"; $lesson->progression_enabled = "true"; $lesson->require_reading = "true"; $lesson->require_video = "false"; $lesson->require_quiz = "false"; $lesson->require_assignment = "false"; $lesson->require_teacher_approval = "false"; $lesson->created_at = date("Y-m-d H:i:s"); $lesson->updated_at = $lesson->created_at; $lesson = $this->persist($this->lessonNs, $lesson, $res);
        $content = new \stdClass(); $content->lesson_id = $lesson->id; $content->content_type = "article"; $content->title = "Getting started"; $content->body = "Welcome. Read this introduction, explore the resources, and mark the lesson as read when you are ready to continue."; $content->sort_order = 1; $content->is_required = "true"; $content->status = "published"; $this->persist($this->contentNs, $content, $res); return $this->postDashboard($req, $res);
    }

    private function studentCourses($body) {
        $student = $this->requestedStudent($body); $out = array();
        if ($this->isTeacher() && (!isset($body->student_id) || intval($body->student_id) <= 0)) {
            foreach ($this->rows($this->courseNs, "", "asc") as $course) $out[] = $this->courseLearningSummary($course, $student, null);
            return $out;
        }
        $enrollments = $this->rows($this->enrollmentNs, "student_id:" . intval($student->id), "desc"); $seen = array();
        foreach ($enrollments as $enrollment) { if (!$this->isActiveEnrollment($enrollment)) continue; $courseId = $this->courseIdForEnrollment($enrollment); if ($courseId <= 0 || isset($seen[strval($courseId)])) continue; $seen[strval($courseId)] = true; $course = $this->byId($this->courseNs, $courseId); if (!$course) continue; $out[] = $this->courseLearningSummary($course, $student, $enrollment); }
        return $out;
    }

    private function courseLearningSummary($course, $student, $enrollment) {
        $courseLessons = $this->publishedRows($this->lessonNs, "course_id:" . intval($course->id)); $lessons = array();
        foreach ($courseLessons as $courseLesson) if (!empty($courseLesson->subject_id)) $lessons[] = $courseLesson;
        usort($lessons, array($this, "sortLessonsBySubject"));
        $progress = $this->rows($this->progressNs, "course_id:" . intval($course->id) . ",student_id:" . intval($student->id), "asc");
        $completed = 0; $quizPending = 0; $assignmentPending = 0; $current = null;
        foreach ($lessons as $lesson) { $p = $this->findIn($progress, "lesson_id", $lesson->id); if ($p && LessonRules::truthy($p->lesson_completed)) $completed++; elseif ($current === null) $current = $lesson; if (LessonRules::truthy(isset($lesson->require_quiz)?$lesson->require_quiz:false) && (!$p || !LessonRules::truthy($p->quiz_passed))) $quizPending++; if (LessonRules::truthy(isset($lesson->require_assignment)?$lesson->require_assignment:false) && (!$p || !LessonRules::truthy($p->assignment_passed))) $assignmentPending++; }
        $marks = $this->rows($this->markNs, "student_id:" . intval($student->id), "desc"); $earned = 0;
        foreach ($marks as $m) if ($this->markBelongsToCourse($m, $course->id)) $earned += floatval(isset($m->marks)?$m->marks:0);
        $item = clone $course; $item->enrollment = $enrollment; $item->completion_percentage = LessonRules::completionPercent($completed, count($lessons)); $item->completed_lessons = $completed; $item->total_lessons = count($lessons); $item->current_lesson = $current; $item->pending_quizzes = $quizPending; $item->pending_assignments = $assignmentPending; $item->total_marks = $earned; $item->course_status = count($lessons)>0 && $completed===count($lessons)?"completed":($completed>0?"in_progress":"not_started"); return $item;
    }

    private function touchProgress($body, $activity, $res) { if (empty($body->lesson_id)) return $this->error($res, "lesson_id is required."); $lesson = $this->byId($this->lessonNs, $body->lesson_id); if (!$lesson) return $this->error($res, "Lesson not found."); $student = $this->requestedStudent($body); if (!$this->canAccessCourse($lesson->course_id, $student->id)) return $this->error($res, "Course access denied."); if (!$this->lessonUnlockedFor($lesson, $student->id)) return $this->error($res, "Complete the previous lesson requirements first."); $progress = $this->progress($lesson->course_id, $lesson->id, $student); if ($activity === "viewed") { if (empty($progress->started_at)) $progress->started_at = date("Y-m-d H:i:s"); $progress->last_viewed_at = date("Y-m-d H:i:s"); } else $progress->{$activity} = "true"; return $this->finishProgress($lesson, $progress, $res); }
    private function progress($courseId, $lessonId, $student) { $p = $this->findOne($this->progressNs, "lesson_id:" . intval($lessonId) . ",student_id:" . intval($student->id)); if ($p) return $p; $p = new \stdClass(); $p->course_id=$courseId; $p->lesson_id=$lessonId; $p->student_id=$student->id; $p->student_name=$student->name; $p->quiz_attempts=0; return $p; }
    private function finishProgress($lesson, $progress, $res) { $complete = LessonRules::requirementsMet($lesson, $progress); $wasComplete = LessonRules::truthy(isset($progress->lesson_completed)?$progress->lesson_completed:false); $progress->lesson_completed = $complete ? "true" : "false"; if ($complete && empty($progress->completed_at)) $progress->completed_at = date("Y-m-d H:i:s"); $saved = $this->persist($this->progressNs, $progress, $res); if ($complete && !$wasComplete) $this->queueNotification($this->profile($progress->student_id), "next-lesson-unlocked", "Lesson completed. Your next lesson is now available.", "lesson", $lesson->id); return $saved; }
    private function saveQuizMark($quiz,$lesson,$student,$attempt,$res) { $mark = $this->findOne($this->markNs, "assessment_id:" . intval(isset($quiz->assessment_id)?$quiz->assessment_id:0) . ",student_id:" . intval($student->id)); if (!$mark) $mark = new \stdClass(); $mark->assessment_id=isset($quiz->assessment_id)?$quiz->assessment_id:0; $mark->assignment_id=0; $mark->submission_id=0; $mark->class_grade_id=0; $mark->subject_id=isset($lesson->subject_id)?$lesson->subject_id:0; $mark->student_id=$student->id; $mark->student_name=$student->name; $mark->marks=$attempt->marks; $mark->max_mark=$attempt->max_mark; $mark->weight=0; $mark->graded_by=0; $mark->graded_at=date("Y-m-d H:i:s"); $mark->note="Lesson quiz attempt " . $attempt->attempt_number; $this->persist($this->markNs,$mark,$res); }

    private function teacherSave($req,$res,$ns,$required,$message) { if (!$this->requireTeacher($res)) return null; $item=$this->body($req); foreach($required as $field) if(empty($item->{$field})) return $this->error($res,$message); return $this->persist($ns,$item,$res); }
    private function listBody($req,$ns,$fields,$search,$sorting) { return $this->listObject($this->body($req),$ns,$fields,$search,$sorting); }
    private function listObject($body,$ns,$fields,$search,$sorting) { $parts=array(); foreach($fields as $field) if(isset($body->{$field}) && $body->{$field}!=="" && $body->{$field}!==null) $parts[]=$field.":".$this->clean($body->{$field}); $rows=$this->rows($ns,implode(",",$parts),$sorting); if(!isset($body->search)||trim($body->search)==="") return $rows; $needle=strtolower(trim($body->search)); $out=array(); foreach($rows as $row) foreach($search as $field) if(isset($row->{$field}) && strpos(strtolower($this->plainText($row->{$field})),$needle)!==false){$out[]=$row;break;} return $out; }
    private function rows($ns,$query,$sorting="desc") { $result=\SOSSData::Query($ns,$query,null,$sorting,2000,0); return $result->success?$result->result:array(); }
    private function persist($ns,$item,$res) { $update=isset($item->id)&&intval($item->id)>0; $result=$update?\SOSSData::Update($ns,$item):\SOSSData::Insert($ns,$item); if(!$result->success){$res->SetError(isset($result->message)?$result->message:"Save failed.");return null;} if(!$update&&isset($result->result->generatedId))$item->id=$result->result->generatedId; return $item; }
    private function delete($ns,$item,$res) { if(!$this->requireTeacher($res))return null;if(empty($item->id))return $this->error($res,"id is required.");$result=\SOSSData::Delete($ns,$item);if(!$result->success)return $this->error($res,isset($result->message)?$result->message:"Delete failed.");return $item; }
    private function body($req){$data=$req->Body(true);return isset($data)?$data:new \stdClass();}
    private function byId($ns,$id){return empty($id)?null:$this->findOne($ns,"id:".intval($id));}
    private function findOne($ns,$query){$rows=$this->rows($ns,$query,"desc");return count($rows)>0?$rows[0]:null;}
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
    private function quizMaxMark($id){$n=0;foreach($this->rows($this->questionNs,"quiz_id:".intval($id),"asc") as $q)$n+=floatval(isset($q->marks)?$q->marks:1);return $n;}
    private function answerFor($answers,$id){if(is_object($answers)&&isset($answers->{strval($id)}))return $answers->{strval($id)};if(is_array($answers)&&isset($answers[strval($id)]))return $answers[strval($id)];return "";}
    private function sourceSentences($source){$source=preg_replace('/\s+/',' ',trim($source));$chunks=preg_split('/(?<=[.!?])\s+/',$source);$out=array();foreach($chunks as $s)if(strlen($s)>=25&&strlen($s)<=450)$out[]=$s;return $out;}
    private function keyword($sentence){preg_match_all('/[A-Za-z][A-Za-z\-]{4,}/',$sentence,$matches);if(empty($matches[0]))return "lesson";usort($matches[0],function($a,$b){return strlen($b)-strlen($a);});return $matches[0][0];}
    private function videoProvider($url){$url=strtolower($url);if(strpos($url,"youtube.com")!==false||strpos($url,"youtu.be")!==false)return "youtube";if(strpos($url,"facebook.com")!==false)return "facebook";if(strpos($url,"cloudflarestream.com")!==false||strpos($url,"videodelivery.net")!==false)return "cloudflare";return "direct";}
    private function progressPercentage($lesson,$p){if(!$lesson)return 0;$total=0;$done=0;$map=array("require_reading"=>"reading_completed","require_video"=>"video_completed","require_quiz"=>"quiz_passed","require_assignment"=>"assignment_passed","require_teacher_approval"=>"teacher_approved");foreach($map as $r=>$s)if(LessonRules::truthy(isset($lesson->{$r})?$lesson->{$r}:false)){$total++;if(LessonRules::truthy(isset($p->{$s})?$p->{$s}:false))$done++;}return $total?LessonRules::completionPercent($done,$total):(LessonRules::truthy(isset($p->lesson_completed)?$p->lesson_completed:false)?100:0);}
    private function assignmentInCourse($assignment,$courseId){$subjects=$this->rows($this->subjectNs,"course_id:".intval($courseId),"asc");foreach($subjects as $s)if(strval($s->id)===strval($assignment->subject_id))return true;return false;}
    private function markBelongsToCourse($mark,$courseId){if(!empty($mark->assignment_id)){ $assignment=$this->byId($this->assignmentNs,$mark->assignment_id); if($assignment&&$this->assignmentInCourse($assignment,$courseId))return true;}if(!empty($mark->assessment_id)){foreach($this->rows($this->quizNs,"assessment_id:".intval($mark->assessment_id),"desc") as $quiz){$lesson=$this->byId($this->lessonNs,$quiz->lesson_id);if($lesson&&strval($lesson->course_id)===strval($courseId))return true;}}if(!empty($mark->subject_id)){foreach($this->rows($this->subjectNs,"course_id:".intval($courseId),"asc") as $subject)if(strval($subject->id)===strval($mark->subject_id))return true;}return false;}

    private function sanitizeRichText($html) {
        $html = strval($html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button|svg|math)[^>]*>.*?</\1>#is', '', $html);
        $html = strip_tags($html, '<p><br><h1><h2><h3><h4><strong><b><em><i><u><s><strike><ol><ul><li><a><img><blockquote><pre><code><hr><span><div>');
        $html = preg_replace('/\s(on[a-z]+|style|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace_callback('/\s(href|src)\s*=\s*(["\'])(.*?)\2/i', function($match) {
            $url = trim(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));
            if ($url === '' || strpos($url, '#') === 0 || preg_match('#^(https?:|mailto:|tel:)#i', $url)) return ' ' . strtolower($match[1]) . '=' . $match[2] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $match[2];
            return '';
        }, $html);
        return trim($html);
    }
    private function safeContentRows($rows) { foreach($rows as $row)if(isset($row->body))$row->body=$this->sanitizeRichText($row->body);return $rows; }

    private function providerConnection($provider) { return in_array($provider,array("youtube","facebook"),true) ? $this->findOne($this->providerNs,"provider:".$this->clean($provider)) : null; }
    private function safeProviderConnection($provider) { $item=$this->providerConnection($provider); return $item ? $this->safeProviderRow($item) : array("provider"=>$provider,"status"=>"disconnected","has_client_secret"=>false,"has_api_key"=>false,"has_access_token"=>false,"has_refresh_token"=>false); }
    private function safeProviderRow($item) {
        if (!$item) return null; $safe=new \stdClass();
        foreach(array("id","provider","account_name","account_id","page_id","client_id","status","expires_at","last_tested_at","last_error","updated_at") as $field) if(isset($item->{$field})) $safe->{$field}=$item->{$field};
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
        $response=$this->curlRequest("https://graph.facebook.com/v19.0/".rawurlencode($id),"GET",array("fields"=>"id,title,description,length,thumbnails","access_token"=>$token),array()); if(!$response->success)return $this->error($res,"Facebook metadata request failed: ".$response->message);
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
    private function testFacebookConnection($item) { $token=$this->providerValue($item,"access_token_enc");if($token==="")return (object)array("success"=>false,"message"=>"Complete OAuth or add a valid Facebook access token.");$r=$this->curlRequest("https://graph.facebook.com/v19.0/me","GET",array("fields"=>"id,name","access_token"=>$token),array());if($r->success){if(isset($r->data["name"]))$item->account_name=$r->data["name"];if(isset($r->data["id"]))$item->account_id=$r->data["id"];}return (object)array("success"=>$r->success,"message"=>$r->success?"Facebook API connection succeeded.":"Facebook API connection failed: ".$r->message); }
    private function refreshYouTubeAccessToken($item) { if(!$item||empty($item->expires_at)||strtotime($item->expires_at)>time()+120)return;$refresh=$this->providerValue($item,"refresh_token_enc");$secret=$this->providerValue($item,"client_secret_enc");if($refresh===""||empty($item->client_id)||$secret==="")return;$r=$this->curlRequest("https://oauth2.googleapis.com/token","POST",array("client_id"=>$item->client_id,"client_secret"=>$secret,"refresh_token"=>$refresh,"grant_type"=>"refresh_token"),array());if($r->success&&!empty($r->data["access_token"])){$item->access_token_enc=$this->encryptProviderSecret($r->data["access_token"]);$item->expires_at=date("Y-m-d H:i:s",time()+intval(isset($r->data["expires_in"])?$r->data["expires_in"]:3600));$item->status="connected";$item->last_error="";$dummy=new LessonManagerMemoryResponse();$this->persist($this->providerNs,$item,$dummy);}}

    private function curlRequest($url,$method,$fields,$headers) {
        $out=(object)array("success"=>false,"status"=>0,"data"=>array(),"raw"=>"","message"=>"");if(!function_exists('curl_init')){$out->message="The PHP cURL extension is unavailable.";return $out;}$method=strtoupper($method);if($method==="GET"&&count($fields))$url.=(strpos($url,'?')===false?'?':'&').http_build_query($fields);
        $ch=curl_init($url);$all=array("Accept: application/json");foreach($headers as $h)$all[]=$h;curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$all));if($method==="POST"){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));$all[]="Content-Type: application/x-www-form-urlencoded";curl_setopt($ch,CURLOPT_HTTPHEADER,$all);} $raw=curl_exec($ch);$status=intval(curl_getinfo($ch,CURLINFO_HTTP_CODE));$error=curl_error($ch);curl_close($ch);$out->raw=$raw===false?"":$raw;$out->status=$status;$decoded=json_decode($out->raw,true);if(is_array($decoded))$out->data=$decoded;$out->success=$error===""&&$status>=200&&$status<300;if(!$out->success){$out->message=$error!==""?$error:(isset($out->data["error"]["message"])?$out->data["error"]["message"]:(isset($out->data["error_description"])?$out->data["error_description"]:"HTTP ".$status));}return $out;
    }
    private function providerCallbackUrl(){return $this->appBaseUrl()."/components/lesson-manager/api/service/ProviderOAuthCallback";}
    private function appBaseUrl(){if(isset($_SERVER["HTTP_X_FORWARDED_PROTO"]))$scheme=trim(explode(',',$_SERVER["HTTP_X_FORWARDED_PROTO"])[0]);else $scheme=isset($_SERVER["HTTPS"])&&$_SERVER["HTTPS"]!=="off"?"https":"http";$host=isset($_SERVER["HTTP_HOST"])?$_SERVER["HTTP_HOST"]:"localhost";$uri=isset($_SERVER["REQUEST_URI"])?$_SERVER["REQUEST_URI"]:"";$position=strpos($uri,"/components/");$base=$position===false?"":substr($uri,0,$position);return $scheme."://".$host.rtrim($base,"/");}

    private function isActiveEnrollment($enrollment){return !isset($enrollment->status)||strtolower($enrollment->status)==="active";}
    private function courseIdForEnrollment($enrollment){if(isset($enrollment->course_id)&&intval($enrollment->course_id)>0)return intval($enrollment->course_id);if(!empty($enrollment->class_grade_id)){ $classGrade=$this->byId($this->classNs,$enrollment->class_grade_id); if($classGrade&&isset($classGrade->course_id))return intval($classGrade->course_id);}return 0;}
    private function canAccessCourse($courseId,$studentId){if($this->isTeacher())return true;$rows=$this->rows($this->enrollmentNs,"student_id:".intval($studentId),"desc");foreach($rows as $r)if($this->isActiveEnrollment($r)&&intval($this->courseIdForEnrollment($r))===intval($courseId))return true;return false;}
    private function lessonUnlockedFor($target,$studentId){if($this->isTeacher())return true;if(!$target||empty($target->subject_id))return false;if(isset($target->status)&&strtolower($target->status)!=="published")return false;$lessons=$this->rows($this->lessonNs,"subject_id:".intval($target->subject_id),"asc");usort($lessons,array($this,"sortLessons"));$previousMet=true;foreach($lessons as $lesson){if(isset($lesson->status)&&strtolower($lesson->status)!=="published")continue;$available=empty($lesson->available_at)||strtotime($lesson->available_at)<=time();if(strval($lesson->id)===strval($target->id))return $previousMet&&$available;$progress=$this->findOne($this->progressNs,"lesson_id:".intval($lesson->id).",student_id:".intval($studentId));$previousMet=!LessonRules::truthy(isset($lesson->progression_enabled)?$lesson->progression_enabled:true)||LessonRules::requirementsMet($lesson,$progress);}return false;}
    private function requestedStudent($body){if($this->isTeacher()&&isset($body->student_id)&&intval($body->student_id)>0)return $this->profile($body->student_id);return $this->currentProfile();}
    private function profile($id){$row=$this->byId("profile",$id);$out=new \stdClass();$out->id=intval($id);$out->name=$row&&isset($row->name)?$row->name:"Student #".$id;if($row&&isset($row->email))$out->email=$row->email;return $out;}
    private function currentProfile(){ $out=new \stdClass();$out->id=0;$out->name="Current user";if(class_exists("\\Profile")){$p=\Profile::getUserProfile();if(isset($p->profile)&&isset($p->profile->id)){return $p->profile;}}if(class_exists("\\Auth")){$u=\Auth::Autendicate();if(isset($u->userid)){$rows=$this->rows("profile","linkeduserid:".$this->clean($u->userid),"desc");if(count($rows)>0)return $rows[0];if(isset($u->email))$out->name=$u->email;}}return $out; }
    private function currentRole(){if(defined("GROUPID")){$g=strtolower(GROUPID);if($g==="sysadmin")return "admin";if($g==="web_user")return "student";return $g;}if(class_exists("\\Auth")){$u=\Auth::Autendicate();if(isset($u->group))return strtolower($u->group);}return "anonymous";}
    private function isTeacher(){return in_array($this->currentRole(),array("admin","sysadmin","staff","teacher"));}
    private function requireTeacher($res){if($this->isTeacher())return true;$res->SetError("Teacher or administrator permission is required.");return false;}
    private function notifyCourse($courseId,$event,$message,$type,$id){foreach($this->rows($this->enrollmentNs,"course_id:".intval($courseId),"desc") as $e)if(!isset($e->status)||strtolower($e->status)==="active")$this->queueNotification($this->profile($e->student_id),$event,$message,$type,$id);}
    private function notifyTeachers($event,$message,$type,$id){/* Course Manager has no teacher enrolment table; queue a system notification. */$p=new \stdClass();$p->id=0;$p->name="Teaching team";$this->queueNotification($p,$event,$message,$type,$id);}
    private function queueNotification($profile,$event,$message,$type,$id){$n=new \stdClass();$n->entity_type=$type;$n->entity_id=$id;$n->profile_id=isset($profile->id)?$profile->id:0;$n->profile_name=isset($profile->name)?$profile->name:"";$n->email=isset($profile->email)?$profile->email:"";$n->event_type=$event;$n->message=$message;$n->status="queued";$n->created_at=date("Y-m-d H:i:s");$dummy=new LessonManagerMemoryResponse();$this->persist($this->notificationNs,$n,$dummy);}
}

class LessonManagerMemoryResponse { private $error=null; public function SetError($error){$this->error=$error;} public function GetError(){return $this->error;} }
?>
