<?php
require_once(__DIR__ . "/../services/api/service.php");

use course_manager\CourseManagerRules;

$failures = 0;

function assertTrue($condition, $message) {
    global $failures;
    if (!$condition) {
        $failures++;
        echo "FAIL: " . $message . PHP_EOL;
    }
}

function scale($min, $max, $letter, $point, $active = true) {
    $row = new stdClass();
    $row->min_mark = $min;
    $row->max_mark = $max;
    $row->grade_letter = $letter;
    $row->grade_point = $point;
    $row->active = $active ? "true" : "false";
    return $row;
}

$scales = array(
    scale(0, 69.99, "F", 0),
    scale(70, 79.99, "B", 3),
    scale(80, 89.99, "A", 3.7),
    scale(90, 100, "A+", 4)
);

assertTrue(CourseManagerRules::timeRangesOverlap("2026-07-01 09:00", "2026-07-01 10:00", "2026-07-01 09:30", "2026-07-01 10:30"), "overlapping timetable ranges are detected");
assertTrue(!CourseManagerRules::timeRangesOverlap("2026-07-01 09:00", "2026-07-01 10:00", "2026-07-01 10:00", "2026-07-01 11:00"), "touching timetable ranges are allowed");
assertTrue(CourseManagerRules::gradeForMark(87, $scales)->grade_letter === "A", "grade scale selects A");
assertTrue(CourseManagerRules::gradeForMark(95, $scales)->grade_letter === "A+", "grade scale selects A+");
assertTrue(CourseManagerRules::capacityAvailable(30, 29, false), "capacity allows one remaining seat");
assertTrue(!CourseManagerRules::capacityAvailable(30, 30, false), "capacity blocks full cohort");
assertTrue(CourseManagerRules::capacityAvailable(30, 30, true), "capacity allows editing existing active enrollment");
assertTrue(CourseManagerRules::latePenalty(100, "2026-07-01 17:00", "2026-07-03 09:00", 5) === 10.0, "late penalty rounds up by day");

if ($failures === 0) {
    echo "CourseManagerRules tests passed." . PHP_EOL;
}

exit($failures > 0 ? 1 : 0);
?>
