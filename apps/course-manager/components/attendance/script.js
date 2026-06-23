WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        saving: false,
        classGrades: [],
        subjects: [],
        slots: [],
        students: [],
        selectedSlot: null,
        filters: {
            timetable_slot_id: "",
            class_grade_id: "",
            subject_id: ""
        }
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadRoster,
            navigate: navigate,
            loadRoster: loadRoster,
            saveAttendance: saveAttendance,
            applySlot: applySlot,
            visibleSlots: visibleSlots,
            markAllPresent: markAllPresent,
            syncStatus: syncStatus,
            syncPresent: syncPresent,
            openWeekly: openWeekly,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            slotLabel: slotLabel,
            attendanceClass: attendanceClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function initialize() {
        ensureCourseStyles();
        api = exports.getComponent("api");
        handler = exports.getShellComponent("soss-routes");
        if (!api) {
            setError("Course Manager service is not loaded.");
            return;
        }
        applyRouteParams(readRouteParams());
        loadLookups();
        loadRoster();
    }

    function loadLookups() {
        api.services.ListClassGrades({}).then(function (response) {
            bindData.classGrades = response.success ? (response.result || []) : [];
        });
        api.services.ListSubjects({}).then(function (response) {
            bindData.subjects = response.success ? (response.result || []) : [];
        });
        api.services.ListTimetable({}).then(function (response) {
            bindData.slots = response.success ? (response.result || []) : [];
            applySlot(false);
        });
    }

    function loadRoster() {
        clearMessages();
        if (!bindData.filters.class_grade_id && !bindData.filters.timetable_slot_id) {
            bindData.students = [];
            return;
        }
        bindData.loading = true;
        api.services.AttendanceRoster(clone(bindData.filters)).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                var result = response.result || {};
                bindData.selectedSlot = result.slot || bindData.selectedSlot;
                bindData.students = result.students || [];
                if (result.class_grade_id) {
                    bindData.filters.class_grade_id = result.class_grade_id;
                }
                if (result.subject_id) {
                    bindData.filters.subject_id = result.subject_id;
                }
            } else {
                setError(response.result && response.result.message ? response.result.message : "Attendance roster load failed.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Attendance roster load failed.");
        });
    }

    function saveAttendance() {
        clearMessages();
        if (!bindData.filters.timetable_slot_id) {
            setError("Select a time slot before saving attendance.");
            return;
        }
        bindData.saving = true;
        api.services.BulkRecordAttendance({
            timetable_slot_id: bindData.filters.timetable_slot_id,
            class_grade_id: bindData.filters.class_grade_id,
            subject_id: bindData.filters.subject_id,
            rows: bindData.students.map(function (student) {
                return {
                    id: student.attendance_id || "",
                    student_id: student.student_id,
                    student_name: student.student_name,
                    status: student.present ? (student.status || "present") : "absent",
                    note: student.note || ""
                };
            })
        }).then(function (response) {
            bindData.saving = false;
            if (response.success) {
                var result = response.result || {};
                setInfo("Attendance saved for " + (result.saved ? result.saved.length : 0) + " students.");
                if (result.errors && result.errors.length) {
                    result.errors.forEach(function (row) {
                        setError(row.message || "A row failed to save.");
                    });
                }
                loadRoster();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Attendance save failed.");
            }
        }).error(function () {
            bindData.saving = false;
            setError("Attendance save failed.");
        });
    }

    function applySlot(shouldLoad) {
        var slot = findSlot(bindData.filters.timetable_slot_id);
        bindData.selectedSlot = slot;
        if (slot) {
            bindData.filters.class_grade_id = slot.class_grade_id || bindData.filters.class_grade_id;
            bindData.filters.subject_id = slot.subject_id || bindData.filters.subject_id;
        }
        if (shouldLoad !== false) {
            loadRoster();
        }
    }

    function visibleSlots() {
        return bindData.slots.filter(function (slot) {
            if (bindData.filters.class_grade_id && String(slot.class_grade_id) !== String(bindData.filters.class_grade_id)) {
                return false;
            }
            if (bindData.filters.subject_id && String(slot.subject_id) !== String(bindData.filters.subject_id)) {
                return false;
            }
            return true;
        });
    }

    function markAllPresent() {
        bindData.students.forEach(function (student) {
            student.present = true;
            student.status = "present";
        });
    }

    function syncStatus(student) {
        student.status = student.present ? (student.status === "absent" ? "present" : student.status || "present") : "absent";
    }

    function syncPresent(student) {
        student.present = student.status !== "absent";
    }

    function openWeekly() {
        var params = [];
        if (bindData.filters.class_grade_id) {
            params.push("classGradeId=" + encodeURIComponent(bindData.filters.class_grade_id));
        }
        if (bindData.filters.subject_id) {
            params.push("subjectId=" + encodeURIComponent(bindData.filters.subject_id));
        }
        navigate("../weekly-timetable" + (params.length ? "?" + params.join("&") : ""));
    }

    function classGradeName(id) {
        var value = "";
        bindData.classGrades.forEach(function (classGrade) {
            if (String(classGrade.id) === String(id)) {
                value = classGrade.name;
            }
        });
        return value || id || "";
    }

    function subjectTitle(id) {
        var value = "";
        bindData.subjects.forEach(function (subject) {
            if (String(subject.id) === String(id)) {
                value = (subject.code ? subject.code + " - " : "") + subject.title;
            }
        });
        return value || id || "";
    }

    function slotLabel(slot) {
        if (!slot) {
            return "";
        }
        return (slot.start_at || "") + " | " + classGradeName(slot.class_grade_id) + " | " + subjectTitle(slot.subject_id);
    }

    function attendanceClass(status) {
        return "cm-pill " + String(status || "present").toLowerCase();
    }

    function findSlot(id) {
        var selected = null;
        bindData.slots.forEach(function (slot) {
            if (String(slot.id) === String(id)) {
                selected = slot;
            }
        });
        return selected;
    }

    function applyRouteParams(params) {
        bindData.filters.timetable_slot_id = params.slotId || params.timetable_slot_id || "";
        bindData.filters.class_grade_id = params.classGradeId || params.class_grade_id || "";
        bindData.filters.subject_id = params.subjectId || params.subject_id || "";
    }

    function readRouteParams() {
        var href = window.location.href;
        var queryIndex = href.indexOf("?");
        var out = {};
        if (queryIndex < 0) {
            return out;
        }
        var raw = href.substring(queryIndex + 1).split("#")[0];
        raw.split("&").forEach(function (pair) {
            var parts = pair.split("=");
            if (parts.length === 2) {
                out[decodeURIComponent(parts[0])] = decodeURIComponent(parts[1].replace(/\+/g, " "));
            }
        });
        return out;
    }

    function navigate(path) {
        if (handler && handler.appNavigate) {
            handler.appNavigate(path);
        } else {
            window.location.hash = "#/app/course-manager" + normalizePath(path);
        }
    }

    function normalizePath(path) {
        return path.indexOf("../") === 0 ? "/" + path.substring(3) : path;
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function ensureCourseStyles() {
        if (document.getElementById("course-manager-common-css")) {
            return;
        }
        var link = document.createElement("link");
        link.id = "course-manager-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/course-manager/course-style/file/course-manager.css?v=0.7";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message) {
        bindData.errors.push(message);
    }

    function setInfo(message) {
        bindData.info.push(message);
    }

    function clearMessages() {
        bindData.errors = [];
        bindData.info = [];
    }
});
