<?php
namespace course_manager;

if (defined("PLUGIN_PATH")) {
    if (file_exists(PLUGIN_PATH . "/sossdata/SOSSData.php")) {
        require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");
    }
    if (file_exists(PLUGIN_PATH . "/auth/auth.php")) {
        require_once(PLUGIN_PATH . "/auth/auth.php");
    }
    if (defined("PLUGIN_PATH_LOCAL") && file_exists(PLUGIN_PATH_LOCAL . "/profile/profile.php")) {
        require_once(PLUGIN_PATH_LOCAL . "/profile/profile.php");
    }
}
if (defined("TENANT_RESOURCE_LOCATION")) {
    require_once(TENANT_RESOURCE_LOCATION . "/apps/currency-configuration/services/currency-configuration-handler/service.php");
}

class CourseManagerRules {
    public static function timeRangesOverlap($leftStart, $leftEnd, $rightStart, $rightEnd) {
        $ls = strtotime($leftStart);
        $le = strtotime($leftEnd);
        $rs = strtotime($rightStart);
        $re = strtotime($rightEnd);
        if ($ls === false || $le === false || $rs === false || $re === false) {
            return false;
        }
        return $ls < $re && $rs < $le;
    }

    public static function gradeForMark($mark, $scales) {
        $mark = floatval($mark);
        $best = null;
        foreach ($scales as $scale) {
            $active = true;
            if (isset($scale->active)) {
                $active = $scale->active === true || $scale->active === "true" || $scale->active === "1" || $scale->active === 1;
            }
            if (!$active) {
                continue;
            }
            $min = isset($scale->min_mark) ? floatval($scale->min_mark) : 0;
            $max = isset($scale->max_mark) ? floatval($scale->max_mark) : 0;
            if ($mark >= $min && $mark <= $max) {
                if ($best === null || $min > floatval($best->min_mark)) {
                    $best = $scale;
                }
            }
        }
        return $best;
    }

    public static function capacityAvailable($capacity, $activeCount, $isExisting) {
        $capacity = intval($capacity);
        if ($capacity <= 0) {
            return true;
        }
        return $isExisting ? $activeCount <= $capacity : $activeCount < $capacity;
    }

    public static function latePenalty($rawMark, $dueAt, $submittedAt, $penaltyPerDay) {
        $due = strtotime($dueAt);
        $submitted = strtotime($submittedAt);
        if ($due === false || $submitted === false || $submitted <= $due) {
            return 0;
        }
        $daysLate = ceil(($submitted - $due) / 86400);
        $penalty = floatval($penaltyPerDay) * $daysLate;
        return min(floatval($rawMark), $penalty);
    }
}

class ApiService {
    private $courseNamespace = "course_manager_course";
    private $subjectNamespace = "course_manager_subject";
    private $productNamespace = "course_manager_product";
    private $classGradeNamespace = "course_manager_classgrade";
    private $enrollmentNamespace = "course_manager_enrollment";
    private $timetableNamespace = "course_manager_timetable";
    private $classroomNamespace = "course_manager_classroom";
    private $attendanceNamespace = "course_manager_attendance";
    private $assignmentNamespace = "course_manager_assignment";
    private $submissionNamespace = "course_manager_submission";
    private $assessmentNamespace = "course_manager_assessment";
    private $markNamespace = "course_manager_mark";
    private $gradingScaleNamespace = "course_manager_grading_scale";
    private $notificationNamespace = "course_manager_notification";

    public function getEndpointCatalog($req, $res) {
        return $this->endpointCatalog();
    }

    public function postEndpointCatalog($req, $res) {
        return $this->endpointCatalog();
    }

    public function getDashboard($req, $res) {
        return $this->dashboardData();
    }

    public function postDashboard($req, $res) {
        return $this->dashboardData();
    }

    public function postSeedSampleData($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }

        $course = $this->findOne($this->courseNamespace, "code:CS101");
        if ($course === null) {
            $course = new \stdClass();
            $course->code = "CS101";
            $course->title = "Intro to Computer Science";
            $course->description = "Foundation course for programming and discrete math.";
            $course->duration_weeks = 12;
            $course->status = "active";
            $course = $this->persistObject($this->courseNamespace, "id", $course, $res);
        }

        $courseId = isset($course->id) ? $course->id : 0;
        $subjects = array(
            array("CS101-PRG", "Programming 101", 3),
            array("CS101-MTH", "Discrete Math", 2)
        );
        foreach ($subjects as $row) {
            if ($this->findOne($this->subjectNamespace, "code:" . $row[0]) === null) {
                $subject = new \stdClass();
                $subject->code = $row[0];
                $subject->title = $row[1];
                $subject->course_id = $courseId;
                $subject->credits = $row[2];
                $subject->is_core = "true";
                $subject->status = "active";
                $this->persistObject($this->subjectNamespace, "id", $subject, $res);
            }
        }

        if ($this->findOne($this->classGradeNamespace, "name:CS101 - Batch A") === null) {
            $classGrade = new \stdClass();
            $classGrade->name = "CS101 - Batch A";
            $classGrade->course_id = $courseId;
            $classGrade->start_date = "2026-07-01";
            $classGrade->end_date = "2026-09-23";
            $classGrade->capacity = 30;
            $classGrade->status = "active";
            $this->persistObject($this->classGradeNamespace, "id", $classGrade, $res);
        }

        $scaleRows = array(
            array(90, 100, "A+", 4.0),
            array(80, 89.99, "A", 3.7),
            array(70, 79.99, "B", 3.0),
            array(0, 69.99, "F", 0.0)
        );
        $existingScales = $this->rows($this->gradingScaleNamespace, "", "asc", 100, 0);
        if (count($existingScales) === 0) {
            foreach ($scaleRows as $row) {
                $scale = new \stdClass();
                $scale->min_mark = $row[0];
                $scale->max_mark = $row[1];
                $scale->grade_letter = $row[2];
                $scale->grade_point = $row[3];
                $scale->active = "true";
                $scale->label = "Default";
                $this->persistObject($this->gradingScaleNamespace, "id", $scale, $res);
            }
        }

        return $this->dashboardData();
    }

    public function postListProfiles($req, $res) {
        $body = $this->body($req);
        $rows = $this->rows("profile", "", "asc", 1000, 0);
        return $this->filterRows($rows, $body, array("name", "email", "catogory", "category", "organization"));
    }

    public function getListCourses($req, $res) {
        return $this->listFromQuery($req, $this->courseNamespace, array("id", "code", "title", "status"), array("code", "title", "status"));
    }

    public function postListCourses($req, $res) {
        return $this->listFromBody($req, $this->courseNamespace, array("id", "code", "title", "status"), array("code", "title", "status"));
    }

    public function postCreateCourse($req, $res) {
        return $this->postSaveCourse($req, $res);
    }

    public function postSaveCourse($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $course = $this->body($req);
        if (empty($course->code) || empty($course->title)) {
            $res->SetError("Course code and title are required.");
            return null;
        }
        $course->code = strtoupper(trim($course->code));
        $course->title = trim($course->title);
        $course->status = empty($course->status) ? "active" : $course->status;
        return $this->persistObject($this->courseNamespace, "id", $course, $res);
    }

    public function postDeleteCourse($req, $res) {
        return $this->deleteObject($res, $this->courseNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListSubjects($req, $res) {
        return $this->listFromBody($req, $this->subjectNamespace, array("id", "course_id", "code", "title", "teacher_id", "product_id", "product_code", "product_title", "status"), array("code", "title", "product_code", "product_title", "status"));
    }

    public function postSaveSubject($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $subject = $this->body($req);
        if (empty($subject->code) || empty($subject->title) || empty($subject->course_id)) {
            $res->SetError("Subject code, title, and course are required.");
            return null;
        }
        $subject->code = strtoupper(trim($subject->code));
        $subject->status = empty($subject->status) ? "active" : $subject->status;
        $subject->product_id = isset($subject->product_id) && $subject->product_id !== "" ? intval($subject->product_id) : 0;
        $subject->product_code = isset($subject->product_code) ? trim($subject->product_code) : "";
        $subject->product_title = isset($subject->product_title) ? trim($subject->product_title) : "";
        $subject->product_price = isset($subject->product_price) && $subject->product_price !== "" ? floatval($subject->product_price) : 0;
        $subject->product_currency_code = isset($subject->product_currency_code) ? trim($subject->product_currency_code) : "";
        if ($subject->product_id > 0 || $subject->product_price > 0) {
            try {
                $currency = new \currency_configuration\CurrencyConfigurationService();
                $subject->product_currency_code = $currency->resolveCurrencyCode($subject->product_currency_code);
            } catch (\Exception $error) {
                $res->SetError($error->getMessage());
                return null;
            }
        }
        return $this->persistObject($this->subjectNamespace, "id", $subject, $res);
    }

    public function postDeleteSubject($req, $res) {
        return $this->deleteObject($res, $this->subjectNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListProducts($req, $res) {
        return $this->listFromBody($req, $this->productNamespace, array("id", "course_id", "product_id", "product_code", "product_title", "status"), array("product_code", "product_title", "status"));
    }

    public function postProductCatalog($req, $res) {
        $body = $this->body($req);
        $rows = $this->rows("products", "", "desc", 1000, 0);
        $items = $this->filterRows($rows, $body, array("itemid", "name", "caption", "keywords", "catogory", "uom", "invType", "showonstore"));
        $out = array();
        foreach ($items as $item) {
            array_push($out, $this->catalogProduct($item));
        }
        return $out;
    }

    public function postSaveProduct($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $product = $this->body($req);
        if (empty($product->course_id) || empty($product->product_title)) {
            $res->SetError("Course and product title are required.");
            return null;
        }
        $product->status = empty($product->status) ? "active" : $product->status;
        return $this->persistObject($this->productNamespace, "id", $product, $res);
    }

    public function postDeleteProduct($req, $res) {
        return $this->deleteObject($res, $this->productNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListClassGrades($req, $res) {
        return $this->listFromBody($req, $this->classGradeNamespace, array("id", "course_id", "name", "status"), array("name", "status"));
    }

    public function postSaveClassGrade($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $classGrade = $this->body($req);
        if (empty($classGrade->name) || empty($classGrade->course_id)) {
            $res->SetError("Class grade name and course are required.");
            return null;
        }
        $classGrade->capacity = isset($classGrade->capacity) ? intval($classGrade->capacity) : 0;
        $classGrade->status = empty($classGrade->status) ? "active" : $classGrade->status;
        return $this->persistObject($this->classGradeNamespace, "id", $classGrade, $res);
    }

    public function postDeleteClassGrade($req, $res) {
        return $this->deleteObject($res, $this->classGradeNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListEnrollments($req, $res) {
        return $this->listFromBody($req, $this->enrollmentNamespace, array("id", "class_grade_id", "course_id", "student_id", "status"), array("student_name", "student_email", "status"));
    }

    public function postSaveEnrollment($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        return $this->saveEnrollmentInternal($this->body($req), $res);
    }

    public function postDeleteEnrollment($req, $res) {
        return $this->deleteObject($res, $this->enrollmentNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postBulkImportEnrollments($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $body = $this->body($req);
        $items = array();
        if (isset($body->rows) && is_array($body->rows)) {
            $items = $body->rows;
        } elseif (isset($body->csv)) {
            $items = $this->parseEnrollmentCsv($body->csv, $body);
        }

        $out = new \stdClass();
        $out->saved = array();
        $out->errors = array();
        foreach ($items as $index => $item) {
            $tmpRes = new CourseManagerMemoryResponse();
            $saved = $this->saveEnrollmentInternal($item, $tmpRes);
            if ($tmpRes->GetError() !== null) {
                array_push($out->errors, array("row" => $index + 1, "message" => $tmpRes->GetError()));
            } else {
                array_push($out->saved, $saved);
            }
        }
        return $out;
    }

    public function postListClassrooms($req, $res) {
        return $this->listFromBody($req, $this->classroomNamespace, array("id", "code", "name", "capacity", "location", "type", "status", "layout_url"), array("code", "name", "location", "type", "status"));
    }

    public function postSaveClassroom($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $room = $this->body($req);
        if (empty($room->code) || empty($room->name)) {
            $res->SetError("Room code and name are required.");
            return null;
        }
        $room->code = strtoupper(trim($room->code));
        $room->status = empty($room->status) ? "active" : $room->status;
        $room->capacity = isset($room->capacity) && $room->capacity !== "" ? intval($room->capacity) : 0;
        $room->location = isset($room->location) ? trim($room->location) : "";
        $room->type = isset($room->type) && $room->type !== "" ? trim($room->type) : "room";
        $room->layout_url = isset($room->layout_url) ? trim($room->layout_url) : "";
        return $this->persistObject($this->classroomNamespace, "id", $room, $res);
    }

    public function postDeleteClassroom($req, $res) {
        return $this->deleteObject($res, $this->classroomNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListTimetable($req, $res) {
        return $this->listFromBody($req, $this->timetableNamespace, array("id", "class_grade_id", "subject_id", "teacher_id", "room_id", "status"), array("teacher_name", "online_link", "status"));
    }

    public function postWeeklyTimetable($req, $res) {
        $body = $this->body($req);
        $weekStart = $this->weekStartDate(isset($body->week_start) ? $body->week_start : "");
        $weekEnd = date("Y-m-d", strtotime($weekStart . " +6 days"));
        $query = $this->buildQuery($body, array("class_grade_id", "subject_id", "teacher_id", "room_id", "status"));
        $rows = $this->rows($this->timetableNamespace, $query, "asc", 2000, 0);
        $slots = array();
        foreach ($rows as $slot) {
            if ($this->slotStartsInWeek($slot, $weekStart, $weekEnd)) {
                array_push($slots, $slot);
            }
        }
        usort($slots, array($this, "compareSlotsByStart"));

        $out = new \stdClass();
        $out->week_start = $weekStart;
        $out->week_end = $weekEnd;
        $out->weekdays = $this->weekdaysFor($weekStart);
        $out->slots = $slots;
        return $out;
    }

    public function postCreateTimetable($req, $res) {
        return $this->postSaveTimetable($req, $res);
    }

    public function postSaveTimetable($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        return $this->saveTimetableInternal($this->body($req), $res);
    }

    public function postDeleteTimetable($req, $res) {
        return $this->deleteObject($res, $this->timetableNamespace, $this->body($req), array("admin", "staff"));
    }

    public function postListAttendance($req, $res) {
        return $this->listFromBody($req, $this->attendanceNamespace, array("id", "timetable_slot_id", "class_grade_id", "subject_id", "student_id", "status"), array("student_name", "status", "source"));
    }

    public function postAttendanceRoster($req, $res) {
        $body = $this->body($req);
        $slot = null;
        if (!empty($body->timetable_slot_id)) {
            $slot = $this->getById($this->timetableNamespace, $body->timetable_slot_id);
            if ($slot === null) {
                $res->SetError("Timetable slot was not found.");
                return null;
            }
            $body->class_grade_id = empty($body->class_grade_id) ? $slot->class_grade_id : $body->class_grade_id;
            $body->subject_id = empty($body->subject_id) ? $slot->subject_id : $body->subject_id;
        }
        if (empty($body->class_grade_id)) {
            $res->SetError("Cohort is required.");
            return null;
        }

        $enrollments = $this->rows($this->enrollmentNamespace, "class_grade_id:" . intval($body->class_grade_id), "asc", 2000, 0);
        $attendanceByStudent = $this->attendanceByStudent($body);
        $students = array();
        foreach ($enrollments as $enrollment) {
            if (isset($enrollment->status) && strtolower($enrollment->status) !== "active") {
                continue;
            }
            if (empty($enrollment->student_id)) {
                continue;
            }
            $studentId = intval($enrollment->student_id);
            $existing = isset($attendanceByStudent[$studentId]) ? $attendanceByStudent[$studentId] : null;
            $status = $existing !== null && isset($existing->status) ? strtolower($existing->status) : "present";

            $student = new \stdClass();
            $student->student_id = $studentId;
            $student->student_name = $this->studentNameFromEnrollment($enrollment);
            $student->student_email = isset($enrollment->student_email) ? $enrollment->student_email : "";
            $student->attendance_id = $existing !== null && isset($existing->id) ? $existing->id : "";
            $student->status = $status;
            $student->present = $status !== "absent";
            $student->note = $existing !== null && isset($existing->note) ? $existing->note : "";
            array_push($students, $student);
        }

        $out = new \stdClass();
        $out->slot = $slot;
        $out->class_grade_id = isset($body->class_grade_id) ? $body->class_grade_id : "";
        $out->subject_id = isset($body->subject_id) ? $body->subject_id : "";
        $out->students = $students;
        return $out;
    }

    public function postRecordAttendance($req, $res) {
        return $this->postSaveAttendance($req, $res);
    }

    public function postSaveAttendance($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        return $this->saveAttendanceInternal($this->body($req), $res, "manual");
    }

    public function postBulkRecordAttendance($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $body = $this->body($req);
        if (empty($body->timetable_slot_id)) {
            $res->SetError("Timetable slot is required.");
            return null;
        }
        $slot = $this->getById($this->timetableNamespace, $body->timetable_slot_id);
        if ($slot === null) {
            $res->SetError("Timetable slot was not found.");
            return null;
        }
        if (!isset($body->rows) || !is_array($body->rows)) {
            $res->SetError("Attendance rows are required.");
            return null;
        }

        $out = new \stdClass();
        $out->saved = array();
        $out->errors = array();
        foreach ($body->rows as $index => $row) {
            if (empty($row->student_id)) {
                array_push($out->errors, array("row" => $index + 1, "message" => "Student is required."));
                continue;
            }
            $attendance = new \stdClass();
            if (!empty($row->id)) {
                $attendance->id = $row->id;
            }
            $attendance->timetable_slot_id = $body->timetable_slot_id;
            $attendance->class_grade_id = empty($body->class_grade_id) ? $slot->class_grade_id : $body->class_grade_id;
            $attendance->subject_id = empty($body->subject_id) ? $slot->subject_id : $body->subject_id;
            $attendance->student_id = $row->student_id;
            $attendance->student_name = isset($row->student_name) ? $row->student_name : "";
            $attendance->status = isset($row->status) && $row->status !== "" ? strtolower($row->status) : "present";
            $attendance->note = isset($row->note) ? $row->note : "";

            $tmpRes = new CourseManagerMemoryResponse();
            $saved = $this->saveAttendanceInternal($attendance, $tmpRes, "manual");
            if ($tmpRes->GetError() !== null || $saved === null) {
                array_push($out->errors, array("row" => $index + 1, "message" => $tmpRes->GetError() !== null ? $tmpRes->GetError() : "Attendance save failed."));
            } else {
                array_push($out->saved, $saved);
            }
        }
        return $out;
    }

    public function postQrCheckIn($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher", "student"))) {
            return null;
        }
        $body = $this->body($req);
        if (empty($body->timetable_slot_id) || empty($body->student_id)) {
            $res->SetError("Timetable slot and student are required.");
            return null;
        }
        $slot = $this->getById($this->timetableNamespace, $body->timetable_slot_id);
        if ($slot === null) {
            $res->SetError("Timetable slot was not found.");
            return null;
        }
        $expected = $this->slotCheckInCode($slot);
        if (!isset($body->check_in_code) || $body->check_in_code !== $expected) {
            $res->SetError("Check-in code is not valid.");
            return null;
        }
        if (!$this->isWithinAttendanceWindow($slot)) {
            $res->SetError("Attendance window is closed.");
            return null;
        }
        $body->class_grade_id = $slot->class_grade_id;
        $body->subject_id = $slot->subject_id;
        $body->status = empty($body->status) ? "present" : $body->status;
        return $this->saveAttendanceInternal($body, $res, "qr");
    }

    public function getExportAttendanceCsv($req, $res) {
        return $this->exportAttendanceCsv($this->queryAsBody($req));
    }

    public function postExportAttendanceCsv($req, $res) {
        return $this->exportAttendanceCsv($this->body($req));
    }

    public function postListAssignments($req, $res) {
        return $this->listFromBody($req, $this->assignmentNamespace, array("id", "class_grade_id", "subject_id", "status"), array("title", "status"));
    }

    public function postCreateAssignment($req, $res) {
        return $this->postSaveAssignment($req, $res);
    }

    public function postSaveAssignment($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $assignment = $this->body($req);
        if (empty($assignment->class_grade_id) || empty($assignment->subject_id) || empty($assignment->title)) {
            $res->SetError("Class grade, subject, and title are required.");
            return null;
        }
        $profile = $this->currentProfile();
        $assignment->created_by = empty($assignment->created_by) ? $profile->id : $assignment->created_by;
        $assignment->created_at = empty($assignment->created_at) ? date("Y-m-d H:i:s") : $assignment->created_at;
        $assignment->status = empty($assignment->status) ? "draft" : $assignment->status;
        $assignment->max_mark = isset($assignment->max_mark) ? floatval($assignment->max_mark) : 100;
        $assignment->late_penalty_per_day = isset($assignment->late_penalty_per_day) ? floatval($assignment->late_penalty_per_day) : 0;
        return $this->persistObject($this->assignmentNamespace, "id", $assignment, $res);
    }

    public function postDeleteAssignment($req, $res) {
        return $this->deleteObject($res, $this->assignmentNamespace, $this->body($req), array("admin", "staff", "teacher"));
    }

    public function postListSubmissions($req, $res) {
        return $this->listFromBody($req, $this->submissionNamespace, array("id", "assignment_id", "student_id", "status"), array("student_name", "status", "grade_letter"));
    }

    public function postSubmitAssignment($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher", "student"))) {
            return null;
        }
        $submission = $this->body($req);
        if (empty($submission->assignment_id) || empty($submission->student_id)) {
            $res->SetError("Assignment and student are required.");
            return null;
        }
        if (empty($submission->content) && empty($submission->file_url)) {
            $res->SetError("Submission content or file URL is required.");
            return null;
        }
        $assignment = $this->getById($this->assignmentNamespace, $submission->assignment_id);
        $submission->submitted_at = empty($submission->submitted_at) ? date("Y-m-d H:i:s") : $submission->submitted_at;
        $late = $assignment !== null && !empty($assignment->due_at) && strtotime($submission->submitted_at) > strtotime($assignment->due_at);
        $submission->status = $late ? "late" : "submitted";
        $existing = $this->findOne($this->submissionNamespace, "assignment_id:" . $submission->assignment_id . ",student_id:" . $submission->student_id);
        if ($existing !== null && empty($submission->id)) {
            $submission->id = $existing->id;
        }
        return $this->persistObject($this->submissionNamespace, "id", $submission, $res);
    }

    public function postSaveSubmission($req, $res) {
        return $this->postSubmitAssignment($req, $res);
    }

    public function postDeleteSubmission($req, $res) {
        return $this->deleteObject($res, $this->submissionNamespace, $this->body($req), array("admin", "staff", "teacher"));
    }

    public function postGradeSubmission($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $body = $this->body($req);
        if (empty($body->submission_id) || !isset($body->marks)) {
            $res->SetError("Submission and marks are required.");
            return null;
        }
        $submission = $this->getById($this->submissionNamespace, $body->submission_id);
        if ($submission === null) {
            $res->SetError("Submission was not found.");
            return null;
        }
        $assignment = $this->getById($this->assignmentNamespace, $submission->assignment_id);
        $rawMark = floatval($body->marks);
        $maxMark = $assignment !== null && isset($assignment->max_mark) ? floatval($assignment->max_mark) : 100;
        $penalty = 0;
        if ($assignment !== null && isset($assignment->late_penalty_per_day)) {
            $penalty = CourseManagerRules::latePenalty($rawMark, $assignment->due_at, $submission->submitted_at, $assignment->late_penalty_per_day);
        }
        $finalMark = max(0, $rawMark - $penalty);
        $percent = $maxMark > 0 ? ($finalMark / $maxMark) * 100 : $finalMark;
        $grade = $this->gradeFor($percent);
        $profile = $this->currentProfile();

        $submission->marks = $finalMark;
        $submission->late_penalty = $penalty;
        $submission->grade_letter = $grade !== null ? $grade->grade_letter : "";
        $submission->grade_point = $grade !== null ? $grade->grade_point : 0;
        $submission->feedback = isset($body->feedback) ? $body->feedback : "";
        $submission->graded_by = $profile->id;
        $submission->graded_at = date("Y-m-d H:i:s");
        $submission->status = "graded";
        $this->persistObject($this->submissionNamespace, "id", $submission, $res);
        if ($res->GetError() !== null) {
            return null;
        }

        $mark = $this->findOne($this->markNamespace, "submission_id:" . $submission->id);
        if ($mark === null) {
            $mark = new \stdClass();
        }
        $mark->submission_id = $submission->id;
        $mark->assignment_id = $submission->assignment_id;
        $mark->assessment_id = 0;
        $mark->class_grade_id = $assignment !== null ? $assignment->class_grade_id : 0;
        $mark->subject_id = $assignment !== null ? $assignment->subject_id : 0;
        $mark->student_id = $submission->student_id;
        $mark->student_name = isset($submission->student_name) ? $submission->student_name : "";
        $mark->marks = $finalMark;
        $mark->max_mark = $maxMark;
        $mark->weight = 0;
        $mark->grade_letter = $submission->grade_letter;
        $mark->grade_point = $submission->grade_point;
        $mark->graded_by = $profile->id;
        $mark->graded_at = $submission->graded_at;
        $mark->note = isset($body->feedback) ? $body->feedback : "";
        $this->persistObject($this->markNamespace, "id", $mark, $res);

        $out = new \stdClass();
        $out->submission = $submission;
        $out->mark = $mark;
        return $out;
    }

    public function postListAssessments($req, $res) {
        return $this->listFromBody($req, $this->assessmentNamespace, array("id", "class_grade_id", "subject_id", "status"), array("title", "assessment_type", "status"));
    }

    public function postSaveAssessment($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $assessment = $this->body($req);
        if (empty($assessment->class_grade_id) || empty($assessment->subject_id) || empty($assessment->title)) {
            $res->SetError("Class grade, subject, and title are required.");
            return null;
        }
        $assessment->max_mark = isset($assessment->max_mark) ? floatval($assessment->max_mark) : 100;
        $assessment->weight = isset($assessment->weight) ? floatval($assessment->weight) : 0;
        $assessment->status = empty($assessment->status) ? "active" : $assessment->status;
        return $this->persistObject($this->assessmentNamespace, "id", $assessment, $res);
    }

    public function postDeleteAssessment($req, $res) {
        return $this->deleteObject($res, $this->assessmentNamespace, $this->body($req), array("admin", "staff", "teacher"));
    }

    public function postListMarks($req, $res) {
        return $this->listFromBody($req, $this->markNamespace, array("id", "assessment_id", "assignment_id", "submission_id", "class_grade_id", "subject_id", "student_id"), array("student_name", "grade_letter"));
    }

    public function postSaveMark($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $mark = $this->body($req);
        if (empty($mark->student_id) || !isset($mark->marks)) {
            $res->SetError("Student and marks are required.");
            return null;
        }
        if (!empty($mark->assessment_id)) {
            $assessment = $this->getById($this->assessmentNamespace, $mark->assessment_id);
            if ($assessment !== null) {
                $mark->class_grade_id = empty($mark->class_grade_id) ? $assessment->class_grade_id : $mark->class_grade_id;
                $mark->subject_id = empty($mark->subject_id) ? $assessment->subject_id : $mark->subject_id;
                $mark->max_mark = empty($mark->max_mark) ? $assessment->max_mark : $mark->max_mark;
                $mark->weight = empty($mark->weight) ? $assessment->weight : $mark->weight;
            }
        }
        $maxMark = isset($mark->max_mark) && floatval($mark->max_mark) > 0 ? floatval($mark->max_mark) : 100;
        $percent = (floatval($mark->marks) / $maxMark) * 100;
        $grade = $this->gradeFor($percent);
        $profile = $this->currentProfile();
        $mark->max_mark = $maxMark;
        $mark->grade_letter = $grade !== null ? $grade->grade_letter : "";
        $mark->grade_point = $grade !== null ? $grade->grade_point : 0;
        $mark->graded_by = empty($mark->graded_by) ? $profile->id : $mark->graded_by;
        $mark->graded_at = empty($mark->graded_at) ? date("Y-m-d H:i:s") : $mark->graded_at;
        return $this->persistObject($this->markNamespace, "id", $mark, $res);
    }

    public function postDeleteMark($req, $res) {
        return $this->deleteObject($res, $this->markNamespace, $this->body($req), array("admin", "staff", "teacher"));
    }

    public function postListGradingScales($req, $res) {
        return $this->listFromBody($req, $this->gradingScaleNamespace, array("id", "active", "grade_letter", "label"), array("grade_letter", "label"));
    }

    public function postCreateGradingScale($req, $res) {
        return $this->postSaveGradingScale($req, $res);
    }

    public function postSaveGradingScale($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff"))) {
            return null;
        }
        $scale = $this->body($req);
        if (!isset($scale->min_mark) || !isset($scale->max_mark) || empty($scale->grade_letter)) {
            $res->SetError("Minimum mark, maximum mark, and grade are required.");
            return null;
        }
        if (floatval($scale->min_mark) > floatval($scale->max_mark)) {
            $res->SetError("Minimum mark must be less than or equal to maximum mark.");
            return null;
        }
        $scale->active = isset($scale->active) ? $scale->active : "true";
        return $this->persistObject($this->gradingScaleNamespace, "id", $scale, $res);
    }

    public function postDeleteGradingScale($req, $res) {
        return $this->deleteObject($res, $this->gradingScaleNamespace, $this->body($req), array("admin", "staff"));
    }

    public function getComputeGrade($req, $res) {
        $query = $req->Query();
        if (!isset($query->marks)) {
            $res->SetError("marks is required.");
            return null;
        }
        return $this->gradeResult(floatval($query->marks));
    }

    public function postComputeGrade($req, $res) {
        $body = $this->body($req);
        if (!isset($body->marks)) {
            $res->SetError("marks is required.");
            return null;
        }
        return $this->gradeResult(floatval($body->marks));
    }

    public function postRecomputeGrades($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $marks = $this->rows($this->markNamespace, "", "desc", 1000, 0);
        $updated = 0;
        foreach ($marks as $mark) {
            $maxMark = isset($mark->max_mark) && floatval($mark->max_mark) > 0 ? floatval($mark->max_mark) : 100;
            $percent = (floatval($mark->marks) / $maxMark) * 100;
            $grade = $this->gradeFor($percent);
            if ($grade !== null) {
                $mark->grade_letter = $grade->grade_letter;
                $mark->grade_point = $grade->grade_point;
                \SOSSData::Update($this->markNamespace, $mark);
                $updated++;
            }
        }
        $out = new \stdClass();
        $out->updated = $updated;
        return $out;
    }

    public function postFinalGrade($req, $res) {
        $body = $this->body($req);
        if (empty($body->student_id)) {
            $res->SetError("Student is required.");
            return null;
        }
        $query = "student_id:" . intval($body->student_id);
        $marks = $this->rows($this->markNamespace, $query, "desc", 1000, 0);
        $marks = $this->filterByExact($marks, $body, array("class_grade_id", "subject_id"));
        $totalWeight = 0;
        $weightedTotal = 0;
        $plainTotal = 0;
        $plainCount = 0;
        foreach ($marks as $mark) {
            $maxMark = isset($mark->max_mark) && floatval($mark->max_mark) > 0 ? floatval($mark->max_mark) : 100;
            $percent = (floatval($mark->marks) / $maxMark) * 100;
            $weight = isset($mark->weight) ? floatval($mark->weight) : 0;
            if ($weight > 0) {
                $weightedTotal += $percent * $weight;
                $totalWeight += $weight;
            } else {
                $plainTotal += $percent;
                $plainCount++;
            }
        }
        $final = 0;
        if ($totalWeight > 0) {
            $final = $weightedTotal / $totalWeight;
        } elseif ($plainCount > 0) {
            $final = $plainTotal / $plainCount;
        }
        $grade = $this->gradeFor($final);
        $out = new \stdClass();
        $out->student_id = intval($body->student_id);
        $out->marks = $marks;
        $out->final_mark = round($final, 2);
        $out->grade_letter = $grade !== null ? $grade->grade_letter : "";
        $out->grade_point = $grade !== null ? $grade->grade_point : 0;
        return $out;
    }

    public function postQueueNotification($req, $res) {
        if (!$this->requireRole($res, array("admin", "staff", "teacher"))) {
            return null;
        }
        $notification = $this->body($req);
        $notification->status = empty($notification->status) ? "queued" : $notification->status;
        $notification->created_at = empty($notification->created_at) ? date("Y-m-d H:i:s") : $notification->created_at;
        return $this->persistObject($this->notificationNamespace, "id", $notification, $res);
    }

    private function endpointCatalog() {
        return array(
            array("method" => "GET", "path" => "/components/course-manager/api/service/EndpointCatalog"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/Dashboard"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/ListCourses"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveCourse"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveSubject"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/ProductCatalog"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveClassGrade"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveEnrollment"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/BulkImportEnrollments"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/CreateTimetable"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/WeeklyTimetable"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/AttendanceRoster"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/RecordAttendance"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/BulkRecordAttendance"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/ExportAttendanceCsv"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/CreateAssignment"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SubmitAssignment"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/GradeSubmission"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveAssessment"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/SaveMark"),
            array("method" => "POST", "path" => "/components/course-manager/api/service/CreateGradingScale"),
            array("method" => "GET", "path" => "/components/course-manager/api/service/ComputeGrade?marks=87")
        );
    }

    private function dashboardData() {
        $courses = $this->rows($this->courseNamespace, "", "desc", 500, 0);
        $classGrades = $this->rows($this->classGradeNamespace, "", "desc", 500, 0);
        $enrollments = $this->rows($this->enrollmentNamespace, "", "desc", 1000, 0);
        $timetable = $this->rows($this->timetableNamespace, "", "asc", 1000, 0);
        $assignments = $this->rows($this->assignmentNamespace, "", "desc", 500, 0);
        $submissions = $this->rows($this->submissionNamespace, "", "desc", 500, 0);
        $marks = $this->rows($this->markNamespace, "", "desc", 1000, 0);

        $stats = new \stdClass();
        $stats->courses = count($courses);
        $stats->class_grades = count($classGrades);
        $stats->active_enrollments = $this->countStatus($enrollments, "active");
        $stats->scheduled_slots = $this->countStatus($timetable, "scheduled");
        $stats->open_assignments = count($assignments);
        $stats->graded_submissions = $this->countStatus($submissions, "graded");

        $capacityRows = array();
        foreach ($classGrades as $classGrade) {
            $row = new \stdClass();
            $row->class_grade = $classGrade;
            $row->active_enrollments = $this->countEnrollmentsForClass($enrollments, $classGrade->id, 0);
            $row->capacity = isset($classGrade->capacity) ? intval($classGrade->capacity) : 0;
            $row->remaining = $row->capacity > 0 ? max(0, $row->capacity - $row->active_enrollments) : null;
            array_push($capacityRows, $row);
        }

        $out = new \stdClass();
        $out->stats = $stats;
        $out->courses = array_slice($courses, 0, 8);
        $out->classGrades = array_slice($classGrades, 0, 8);
        $out->capacityRows = $capacityRows;
        $out->timetable = array_slice($timetable, 0, 12);
        $out->assignments = array_slice($assignments, 0, 8);
        $out->marks = array_slice($marks, 0, 8);
        return $out;
    }

    private function saveEnrollmentInternal($enrollment, $res) {
        if (empty($enrollment->class_grade_id) || empty($enrollment->student_id)) {
            $res->SetError("Class grade and student are required.");
            return null;
        }
        $enrollment->status = empty($enrollment->status) ? "active" : strtolower($enrollment->status);
        $enrollment->enrolled_at = empty($enrollment->enrolled_at) ? date("Y-m-d H:i:s") : $enrollment->enrolled_at;
        if (empty($enrollment->course_id)) {
            $classGrade = $this->getById($this->classGradeNamespace, $enrollment->class_grade_id);
            if ($classGrade !== null && isset($classGrade->course_id)) {
                $enrollment->course_id = $classGrade->course_id;
            }
        }

        $duplicate = $this->findOne($this->enrollmentNamespace, "class_grade_id:" . intval($enrollment->class_grade_id) . ",student_id:" . intval($enrollment->student_id) . ",status:active");
        if ($duplicate !== null && (empty($enrollment->id) || intval($duplicate->id) !== intval($enrollment->id))) {
            $res->SetError("Student is already actively enrolled in this class grade.");
            return null;
        }

        if ($enrollment->status === "active") {
            $classGrade = $this->getById($this->classGradeNamespace, $enrollment->class_grade_id);
            if ($classGrade !== null) {
                $activeRows = $this->rows($this->enrollmentNamespace, "class_grade_id:" . intval($enrollment->class_grade_id), "desc", 1000, 0);
                $existingId = empty($enrollment->id) ? 0 : intval($enrollment->id);
                $activeCount = $this->countEnrollmentsForClass($activeRows, $enrollment->class_grade_id, $existingId);
                $isExisting = $existingId > 0;
                if (!CourseManagerRules::capacityAvailable($classGrade->capacity, $activeCount, $isExisting)) {
                    $res->SetError("Class grade capacity has been reached.");
                    return null;
                }
            }
        }
        return $this->persistObject($this->enrollmentNamespace, "id", $enrollment, $res);
    }

    private function catalogProduct($item) {
        $out = new \stdClass();
        $out->product_id = isset($item->itemid) ? $item->itemid : (isset($item->id) ? $item->id : 0);
        $out->product_code = strval($out->product_id);
        $out->product_title = isset($item->name) ? $item->name : "";
        $out->product_price = isset($item->price) ? $item->price : 0;
        $out->product_currency_code = isset($item->currencycode) ? $item->currencycode : "";
        $out->caption = isset($item->caption) ? strip_tags($item->caption) : "";
        $out->keywords = isset($item->keywords) ? $item->keywords : "";
        $out->category = isset($item->catogory) ? $item->catogory : "";
        $out->uom = isset($item->uom) ? $item->uom : "";
        $out->image = "";
        if (!empty($item->imgurl) && !empty($out->product_id)) {
            $out->image = "components/dock/soss-uploader/service/get/products/" . $out->product_id . "-" . $item->imgurl;
        }
        return $out;
    }

    private function saveTimetableInternal($slot, $res) {
        if (empty($slot->class_grade_id) || empty($slot->subject_id) || empty($slot->start_at) || empty($slot->end_at)) {
            $res->SetError("Class grade, subject, start, and end are required.");
            return null;
        }
        $slot->start_at = $this->normalizeDateTimeValue($slot->start_at);
        $slot->end_at = $this->normalizeDateTimeValue($slot->end_at);
        if (strtotime($slot->end_at) <= strtotime($slot->start_at)) {
            $res->SetError("End time must be after start time.");
            return null;
        }
        $slot->status = empty($slot->status) ? "scheduled" : $slot->status;
        $conflicts = $this->timetableConflicts($slot);
        $override = isset($slot->override_conflict) && ($slot->override_conflict === true || $slot->override_conflict === "true" || $slot->override_conflict === "1" || $slot->override_conflict === 1);
        if (count($conflicts) > 0 && !$override) {
            $res->SetError("Timetable conflict: " . $this->conflictMessage($conflicts));
            return null;
        }
        if (count($conflicts) > 0 && $override && !$this->isAllowed(array("admin"))) {
            $res->SetError("Only admins can override timetable conflicts.");
            return null;
        }
        $saved = $this->persistObject($this->timetableNamespace, "id", $slot, $res);
        if ($saved !== null && $this->truthy(isset($saved->is_online) ? $saved->is_online : false) && !empty($saved->online_link)) {
            $this->queueTimetableNotifications($saved);
        }
        return $saved;
    }

    private function saveAttendanceInternal($attendance, $res, $source) {
        if (empty($attendance->timetable_slot_id) || empty($attendance->student_id)) {
            $res->SetError("Timetable slot and student are required.");
            return null;
        }
        $slot = $this->getById($this->timetableNamespace, $attendance->timetable_slot_id);
        if ($slot !== null) {
            $attendance->class_grade_id = empty($attendance->class_grade_id) ? $slot->class_grade_id : $attendance->class_grade_id;
            $attendance->subject_id = empty($attendance->subject_id) ? $slot->subject_id : $attendance->subject_id;
        }
        $profile = $this->currentProfile();
        $attendance->recorded_by = empty($attendance->recorded_by) ? $profile->id : $attendance->recorded_by;
        $attendance->recorded_at = empty($attendance->recorded_at) ? date("Y-m-d H:i:s") : $attendance->recorded_at;
        $attendance->source = empty($attendance->source) ? $source : $attendance->source;
        $attendance->status = empty($attendance->status) ? "present" : strtolower($attendance->status);
        $existing = $this->findOne($this->attendanceNamespace, "timetable_slot_id:" . intval($attendance->timetable_slot_id) . ",student_id:" . intval($attendance->student_id));
        if ($existing !== null && empty($attendance->id)) {
            $attendance->id = $existing->id;
        }
        return $this->persistObject($this->attendanceNamespace, "id", $attendance, $res);
    }

    private function weekStartDate($value) {
        $normalized = $this->normalizeDateTimeValue($value);
        $timestamp = empty($normalized) ? time() : strtotime($normalized);
        if ($timestamp === false) {
            $timestamp = time();
        }
        $day = intval(date("N", $timestamp));
        $start = strtotime("-" . ($day - 1) . " days", $timestamp);
        return date("Y-m-d", $start);
    }

    private function weekdaysFor($weekStart) {
        $labels = array("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun");
        $days = array();
        $start = strtotime($weekStart);
        for ($i = 0; $i < 7; $i++) {
            $day = new \stdClass();
            $day->label = $labels[$i];
            $day->date = date("Y-m-d", strtotime("+" . $i . " days", $start));
            array_push($days, $day);
        }
        return $days;
    }

    private function slotStartsInWeek($slot, $weekStart, $weekEnd) {
        if (empty($slot->start_at)) {
            return false;
        }
        $slotStart = strtotime($this->normalizeDateTimeValue($slot->start_at));
        if ($slotStart === false) {
            return false;
        }
        $start = strtotime($weekStart . " 00:00:00");
        $end = strtotime($weekEnd . " 23:59:59");
        return $slotStart >= $start && $slotStart <= $end;
    }

    private function compareSlotsByStart($left, $right) {
        $leftTime = empty($left->start_at) ? 0 : strtotime($this->normalizeDateTimeValue($left->start_at));
        $rightTime = empty($right->start_at) ? 0 : strtotime($this->normalizeDateTimeValue($right->start_at));
        if ($leftTime === $rightTime) {
            return 0;
        }
        return $leftTime < $rightTime ? -1 : 1;
    }

    private function normalizeDateTimeValue($value) {
        if (empty($value)) {
            return "";
        }
        $text = trim(strval($value));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/', $text, $matches)) {
            return $matches[1] . "-" . $matches[2] . "-" . $matches[3] . " " . $matches[4] . ":" . $matches[5] . ":" . (isset($matches[6]) && $matches[6] !== "" ? $matches[6] : "00");
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/', $text, $matches)) {
            return $matches[3] . "-" . $matches[1] . "-" . $matches[2] . " " . $matches[4] . ":" . $matches[5] . ":" . (isset($matches[6]) && $matches[6] !== "" ? $matches[6] : "00");
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? $text : date("Y-m-d H:i:s", $timestamp);
    }

    private function attendanceByStudent($body) {
        if (empty($body->timetable_slot_id)) {
            return array();
        }
        $query = "timetable_slot_id:" . intval($body->timetable_slot_id);
        $rows = $this->rows($this->attendanceNamespace, $query, "desc", 2000, 0);
        $out = array();
        foreach ($rows as $row) {
            if (!empty($body->subject_id) && isset($row->subject_id) && intval($row->subject_id) !== intval($body->subject_id)) {
                continue;
            }
            if (!empty($body->timetable_slot_id) && isset($row->timetable_slot_id) && intval($row->timetable_slot_id) !== intval($body->timetable_slot_id)) {
                continue;
            }
            if (!empty($body->class_grade_id) && isset($row->class_grade_id) && intval($row->class_grade_id) !== intval($body->class_grade_id)) {
                continue;
            }
            if (!isset($row->student_id)) {
                continue;
            }
            $out[intval($row->student_id)] = $row;
        }
        return $out;
    }

    private function studentNameFromEnrollment($enrollment) {
        if (isset($enrollment->student_name) && trim($enrollment->student_name) !== "") {
            return $enrollment->student_name;
        }
        if (!empty($enrollment->student_id)) {
            $profile = $this->getById("profile", $enrollment->student_id);
            if ($profile !== null) {
                if (isset($profile->name) && trim($profile->name) !== "") {
                    return $profile->name;
                }
                if (isset($profile->email) && trim($profile->email) !== "") {
                    return $profile->email;
                }
            }
            return "Student " . $enrollment->student_id;
        }
        return "Student";
    }

    private function exportAttendanceCsv($body) {
        $rows = $this->listFromObject($body, $this->attendanceNamespace, array("id", "timetable_slot_id", "class_grade_id", "subject_id", "student_id", "status"), array("student_name", "status", "source"));
        $csvRows = array();
        array_push($csvRows, $this->csvLine(array("id", "timetable_slot_id", "class_grade_id", "student_id", "student_name", "status", "recorded_at", "source", "note")));
        foreach ($rows as $row) {
            array_push($csvRows, $this->csvLine(array(
                isset($row->id) ? $row->id : "",
                isset($row->timetable_slot_id) ? $row->timetable_slot_id : "",
                isset($row->class_grade_id) ? $row->class_grade_id : "",
                isset($row->student_id) ? $row->student_id : "",
                isset($row->student_name) ? $row->student_name : "",
                isset($row->status) ? $row->status : "",
                isset($row->recorded_at) ? $row->recorded_at : "",
                isset($row->source) ? $row->source : "",
                isset($row->note) ? $row->note : ""
            )));
        }
        $out = new \stdClass();
        $out->fileName = "course-manager-attendance.csv";
        $out->csv = implode("\n", $csvRows);
        return $out;
    }

    private function listFromQuery($req, $namespace, $allowedFilters, $searchFields) {
        return $this->listFromObject($this->queryAsBody($req), $namespace, $allowedFilters, $searchFields);
    }

    private function listFromBody($req, $namespace, $allowedFilters, $searchFields) {
        return $this->listFromObject($this->body($req), $namespace, $allowedFilters, $searchFields);
    }

    private function listFromObject($body, $namespace, $allowedFilters, $searchFields) {
        $query = "";
        if (isset($body->query)) {
            $query = $this->sanitizeQuery($body->query, $allowedFilters);
        } else {
            $query = $this->buildQuery($body, $allowedFilters);
        }
        $pageSize = isset($body->pageSize) ? intval($body->pageSize) : 1000;
        $fromPage = isset($body->fromPage) ? intval($body->fromPage) : 0;
        $sorting = isset($body->sorting) ? $body->sorting : "desc";
        $rows = $this->rows($namespace, $query, $sorting, $pageSize, $fromPage);
        return $this->filterRows($rows, $body, $searchFields);
    }

    private function rows($namespace, $query, $sorting, $pageSize, $fromPage) {
        $result = \SOSSData::Query($namespace, $query, null, $sorting, $pageSize, $fromPage);
        return $result->success ? $result->result : array();
    }

    private function persistObject($namespace, $primaryKey, $data, $res) {
        $isUpdate = isset($data->{$primaryKey}) && intval($data->{$primaryKey}) > 0;
        $result = $isUpdate ? \SOSSData::Update($namespace, $data) : \SOSSData::Insert($namespace, $data);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Save failed.");
            return null;
        }
        if (!$isUpdate && isset($result->result->generatedId)) {
            $data->{$primaryKey} = $result->result->generatedId;
        }
        return $data;
    }

    private function deleteObject($res, $namespace, $data, $roles) {
        if (!$this->requireRole($res, $roles)) {
            return null;
        }
        if (empty($data->id)) {
            $res->SetError("id is required.");
            return null;
        }
        $result = \SOSSData::Delete($namespace, $data);
        if (!$result->success) {
            $res->SetError(isset($result->message) ? $result->message : "Delete failed.");
            return null;
        }
        return $data;
    }

    private function getById($namespace, $id) {
        if (empty($id)) {
            return null;
        }
        return $this->findOne($namespace, "id:" . intval($id));
    }

    private function findOne($namespace, $query) {
        $result = \SOSSData::Query($namespace, $query, null, "desc", 1, 0);
        if ($result->success && count($result->result) > 0) {
            return $result->result[0];
        }
        return null;
    }

    private function timetableConflicts($slot) {
        $conflicts = array();
        $seen = array();
        if (!empty($slot->teacher_id)) {
            $rows = $this->rows($this->timetableNamespace, "teacher_id:" . intval($slot->teacher_id), "desc", 1000, 0);
            $this->appendSlotConflicts($conflicts, $seen, $slot, $rows, "teacher");
        }
        if (!empty($slot->room_id)) {
            $rows = $this->rows($this->timetableNamespace, "room_id:" . intval($slot->room_id), "desc", 1000, 0);
            $this->appendSlotConflicts($conflicts, $seen, $slot, $rows, "room");
        }
        return $conflicts;
    }

    private function appendSlotConflicts(&$conflicts, &$seen, $slot, $rows, $type) {
        $currentId = isset($slot->id) ? intval($slot->id) : 0;
        foreach ($rows as $row) {
            if ($currentId > 0 && isset($row->id) && intval($row->id) === $currentId) {
                continue;
            }
            if (isset($row->status) && strtolower($row->status) === "cancelled") {
                continue;
            }
            if (CourseManagerRules::timeRangesOverlap($slot->start_at, $slot->end_at, $row->start_at, $row->end_at)) {
                $key = $type . ":" . (isset($row->id) ? $row->id : count($conflicts));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $conflict = new \stdClass();
                    $conflict->type = $type;
                    $conflict->slot = $row;
                    array_push($conflicts, $conflict);
                }
            }
        }
    }

    private function conflictMessage($conflicts) {
        $parts = array();
        foreach ($conflicts as $conflict) {
            $start = isset($conflict->slot->start_at) ? $conflict->slot->start_at : "";
            array_push($parts, $conflict->type . " at " . $start);
        }
        return implode("; ", $parts);
    }

    private function queueTimetableNotifications($slot) {
        if (empty($slot->class_grade_id)) {
            return;
        }
        $enrollments = $this->rows($this->enrollmentNamespace, "class_grade_id:" . intval($slot->class_grade_id), "desc", 1000, 0);
        $items = array();
        foreach ($enrollments as $enrollment) {
            if (isset($enrollment->status) && strtolower($enrollment->status) !== "active") {
                continue;
            }
            $notification = new \stdClass();
            $notification->entity_type = "timetable";
            $notification->entity_id = isset($slot->id) ? $slot->id : 0;
            $notification->profile_id = isset($enrollment->student_id) ? $enrollment->student_id : 0;
            $notification->profile_name = isset($enrollment->student_name) ? $enrollment->student_name : "";
            $notification->email = isset($enrollment->student_email) ? $enrollment->student_email : "";
            $notification->event_type = "online-session";
            $notification->message = "Online session scheduled: " . $slot->online_link;
            $notification->status = "queued";
            $notification->created_at = date("Y-m-d H:i:s");
            array_push($items, $notification);
        }
        if (count($items) > 0) {
            \SOSSData::Insert($this->notificationNamespace, $items);
        }
    }

    private function gradeResult($marks) {
        $grade = $this->gradeFor($marks);
        if ($grade === null) {
            return null;
        }
        $out = new \stdClass();
        $out->marks = $marks;
        $out->grade_letter = $grade->grade_letter;
        $out->grade_point = $grade->grade_point;
        $out->scale = $grade;
        return $out;
    }

    private function gradeFor($marks) {
        $scales = $this->rows($this->gradingScaleNamespace, "", "asc", 200, 0);
        return CourseManagerRules::gradeForMark($marks, $scales);
    }

    private function parseEnrollmentCsv($csv, $body) {
        $items = array();
        $lines = preg_split("/\r\n|\n|\r/", $csv);
        foreach ($lines as $index => $line) {
            if (trim($line) === "") {
                continue;
            }
            $cols = str_getcsv($line);
            if ($index === 0 && isset($cols[0]) && strtolower(trim($cols[0])) === "student_id") {
                continue;
            }
            $item = new \stdClass();
            $item->student_id = isset($cols[0]) ? intval($cols[0]) : 0;
            $item->student_name = isset($cols[1]) ? $cols[1] : "";
            $item->student_email = isset($cols[2]) ? $cols[2] : "";
            $item->class_grade_id = isset($cols[3]) && trim($cols[3]) !== "" ? intval($cols[3]) : (isset($body->class_grade_id) ? intval($body->class_grade_id) : 0);
            $item->course_id = isset($body->course_id) ? intval($body->course_id) : 0;
            $item->status = isset($cols[4]) && trim($cols[4]) !== "" ? trim($cols[4]) : "active";
            array_push($items, $item);
        }
        return $items;
    }

    private function isWithinAttendanceWindow($slot) {
        if (empty($slot->start_at) || empty($slot->end_at)) {
            return false;
        }
        $now = time();
        $start = strtotime($slot->start_at) - (30 * 60);
        $end = strtotime($slot->end_at) + (120 * 60);
        return $now >= $start && $now <= $end;
    }

    private function slotCheckInCode($slot) {
        return "cm-slot-" . (isset($slot->id) ? $slot->id : "0");
    }

    private function countEnrollmentsForClass($enrollments, $classGradeId, $excludeId) {
        $count = 0;
        foreach ($enrollments as $enrollment) {
            if (isset($enrollment->class_grade_id) && intval($enrollment->class_grade_id) !== intval($classGradeId)) {
                continue;
            }
            if ($excludeId > 0 && isset($enrollment->id) && intval($enrollment->id) === intval($excludeId)) {
                continue;
            }
            if (!isset($enrollment->status) || strtolower($enrollment->status) === "active") {
                $count++;
            }
        }
        return $count;
    }

    private function countStatus($rows, $status) {
        $count = 0;
        foreach ($rows as $row) {
            if (isset($row->status) && strtolower($row->status) === strtolower($status)) {
                $count++;
            }
        }
        return $count;
    }

    private function filterByExact($rows, $body, $fields) {
        $out = array();
        foreach ($rows as $row) {
            $keep = true;
            foreach ($fields as $field) {
                if (isset($body->{$field}) && $body->{$field} !== "" && isset($row->{$field}) && strval($row->{$field}) !== strval($body->{$field})) {
                    $keep = false;
                    break;
                }
            }
            if ($keep) {
                array_push($out, $row);
            }
        }
        return $out;
    }

    private function filterRows($rows, $body, $searchFields) {
        if (!isset($body->search) || trim($body->search) === "") {
            return $rows;
        }
        $needle = strtolower(trim($body->search));
        $out = array();
        foreach ($rows as $row) {
            foreach ($searchFields as $field) {
                if (isset($row->{$field}) && strpos(strtolower(strval($row->{$field})), $needle) !== false) {
                    array_push($out, $row);
                    break;
                }
            }
        }
        return $out;
    }

    private function buildQuery($body, $allowedFields) {
        $parts = array();
        foreach ($allowedFields as $field) {
            if (isset($body->{$field}) && $body->{$field} !== "" && $body->{$field} !== null) {
                array_push($parts, $field . ":" . $this->cleanQueryValue($body->{$field}));
            }
        }
        return implode(",", $parts);
    }

    private function sanitizeQuery($query, $allowedFields) {
        $parts = array();
        $chunks = explode(",", $query);
        foreach ($chunks as $chunk) {
            $field = explode(":", $chunk, 2);
            if (count($field) === 2 && in_array($field[0], $allowedFields)) {
                array_push($parts, $field[0] . ":" . $this->cleanQueryValue($field[1]));
            }
        }
        return implode(",", $parts);
    }

    private function cleanQueryValue($value) {
        return str_replace(array(",", ":"), " ", trim(strval($value)));
    }

    private function csvLine($values) {
        $out = array();
        foreach ($values as $value) {
            array_push($out, '"' . str_replace('"', '""', strval($value)) . '"');
        }
        return implode(",", $out);
    }

    private function truthy($value) {
        return $value === true || $value === 1 || $value === "1" || $value === "true" || $value === "yes";
    }

    private function queryAsBody($req) {
        $query = $req->Query();
        $body = new \stdClass();
        foreach ($query as $key => $value) {
            $body->{$key} = $value;
        }
        return $body;
    }

    private function body($req) {
        $data = $req->Body(true);
        return isset($data) ? $data : new \stdClass();
    }

    private function requireRole($res, $roles) {
        if ($this->isAllowed($roles)) {
            return true;
        }
        $res->SetError("You do not have permission for this Course Manager action.");
        return false;
    }

    private function isAllowed($roles) {
        $role = $this->currentRole();
        if ($role === "admin" || $role === "sysadmin") {
            return true;
        }
        return in_array($role, $roles);
    }

    private function currentRole() {
        if (defined("GROUPID")) {
            $group = strtolower(GROUPID);
            if ($group === "sysadmin") {
                return "admin";
            }
            if ($group === "web_user") {
                return "student";
            }
            return $group;
        }
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
            if (isset($user->group)) {
                return strtolower($user->group);
            }
        }
        return "anonymous";
    }

    private function currentProfile() {
        $out = new \stdClass();
        $out->id = 0;
        $out->name = "Unknown";
        if (class_exists("\\Profile")) {
            $profile = \Profile::getUserProfile();
            if (isset($profile->profile) && isset($profile->profile->id)) {
                $out->id = $profile->profile->id;
                $out->name = isset($profile->profile->name) ? $profile->profile->name : "Unknown";
                return $out;
            }
        }
        if (class_exists("\\Auth")) {
            $user = \Auth::Autendicate();
            if (isset($user->userid)) {
                $profileResult = \SOSSData::Query("profile", "linkeduserid:" . $user->userid);
                if ($profileResult->success && count($profileResult->result) > 0) {
                    $out->id = $profileResult->result[0]->id;
                    $out->name = isset($profileResult->result[0]->name) ? $profileResult->result[0]->name : "Unknown";
                    return $out;
                }
                $out->name = isset($user->email) ? $user->email : "Unknown";
            }
        }
        return $out;
    }
}

class CourseManagerMemoryResponse {
    private $error = null;

    public function SetError($error) {
        $this->error = $error;
    }

    public function GetError() {
        return $this->error;
    }
}
?>
