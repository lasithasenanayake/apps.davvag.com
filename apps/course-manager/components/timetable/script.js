WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        slots: [],
        classGrades: [],
        subjects: [],
        rooms: [],
        profiles: [],
        attendance: [],
        form: emptySlot(),
        attendanceForm: emptyAttendance()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadAll,
            navigate: navigate,
            selectSlot: selectSlot,
            saveSlot: saveSlot,
            deleteSlot: deleteSlot,
            selectTeacherProfile: selectTeacherProfile,
            saveAttendance: saveAttendance,
            selectAttendanceSlot: selectAttendanceSlot,
            selectAttendanceStudent: selectAttendanceStudent,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            roomName: roomName,
            profileName: profileName,
            checkInCode: checkInCode,
            statusClass: statusClass,
            clearSlot: clearSlot
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptySlot() {
        return {
            class_grade_id: "",
            subject_id: "",
            teacher_id: "",
            teacher_name: "",
            room_id: "",
            start_at: "",
            end_at: "",
            is_online: "false",
            online_link: "",
            recurrence_rule: "",
            status: "scheduled",
            override_conflict: "false",
            override_reason: ""
        };
    }

    function emptyAttendance() {
        return {
            timetable_slot_id: "",
            class_grade_id: "",
            subject_id: "",
            student_id: "",
            student_name: "",
            status: "present",
            note: ""
        };
    }

    function initialize() {
        ensureCourseStyles();
        api = exports.getComponent("api");
        handler = exports.getShellComponent("soss-routes");
        if (!api) {
            setError("Course Manager service is not loaded.");
            return;
        }
        loadAll();
    }

    function loadAll() {
        clearMessages();
        api.services.ListTimetable({}).then(function (response) {
            bindData.slots = response.success ? (response.result || []) : [];
        });
        api.services.ListClassGrades({}).then(function (response) {
            bindData.classGrades = response.success ? (response.result || []) : [];
        });
        api.services.ListSubjects({}).then(function (response) {
            bindData.subjects = response.success ? (response.result || []) : [];
        });
        api.services.ListClassrooms({}).then(function (response) {
            bindData.rooms = response.success ? (response.result || []) : [];
        });
        api.services.ListProfiles({}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        });
        api.services.ListAttendance({}).then(function (response) {
            bindData.attendance = response.success ? (response.result || []) : [];
        });
    }

    function selectSlot(slot) {
        bindData.form = clone(slot);
        bindData.attendanceForm.timetable_slot_id = slot.id;
        bindData.attendanceForm.class_grade_id = slot.class_grade_id;
        bindData.attendanceForm.subject_id = slot.subject_id;
    }

    function clearSlot() {
        bindData.form = emptySlot();
    }

    function saveSlot() {
        clearMessages();
        var payload = clone(bindData.form);
        payload.teacher_name = profileName(payload.teacher_id);
        api.services.CreateTimetable(payload).then(function (response) {
            if (response.success) {
                setInfo("Slot saved.");
                bindData.form = emptySlot();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Slot save failed.");
            }
        }).error(function () {
            setError("Slot save failed.");
        });
    }

    function deleteSlot(slot) {
        if (!slot || !slot.id) {
            return;
        }
        if (!confirmDelete(slot)) {
            return;
        }
        clearMessages();
        api.services.DeleteTimetable(slot).then(function (response) {
            if (response.success) {
                setInfo("Slot deleted.");
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Slot delete failed.");
            }
        }).error(function () {
            setError("Slot delete failed.");
        });
    }

    function selectTeacherProfile() {
        openProfilePopup(function (profile) {
            bindData.form.teacher_id = profile.id;
            bindData.form.teacher_name = profile.name || "";
        });
    }

    function confirmDelete(slot) {
        var label = subjectTitle(slot.subject_id) || "this timetable slot";
        return window.confirm("Are you sure you want to delete " + label + "? This cannot be undone.");
    }

    function selectAttendanceSlot(slot) {
        bindData.attendanceForm.timetable_slot_id = slot.id;
        bindData.attendanceForm.class_grade_id = slot.class_grade_id;
        bindData.attendanceForm.subject_id = slot.subject_id;
    }

    function selectAttendanceStudent() {
        openProfilePopup(function (profile) {
            bindData.attendanceForm.student_id = profile.id;
            bindData.attendanceForm.student_name = profile.name || "";
        });
    }

    function saveAttendance() {
        clearMessages();
        api.services.RecordAttendance(clone(bindData.attendanceForm)).then(function (response) {
            if (response.success) {
                setInfo("Attendance saved.");
                bindData.attendanceForm = emptyAttendance();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Attendance save failed.");
            }
        }).error(function () {
            setError("Attendance save failed.");
        });
    }

    function classGradeName(id) {
        var value = "";
        bindData.classGrades.forEach(function (classGrade) {
            if (String(classGrade.id) === String(id)) {
                value = classGrade.name;
            }
        });
        return value || id;
    }

    function subjectTitle(id) {
        var value = "";
        bindData.subjects.forEach(function (subject) {
            if (String(subject.id) === String(id)) {
                value = subject.code + " - " + subject.title;
            }
        });
        return value || id;
    }

    function roomName(id) {
        var value = "";
        bindData.rooms.forEach(function (room) {
            if (String(room.id) === String(id)) {
                value = room.code + " - " + room.name;
            }
        });
        return value || id;
    }

    function profileName(id) {
        var value = "";
        bindData.profiles.forEach(function (profile) {
            if (String(profile.id) === String(id)) {
                value = profile.name;
            }
        });
        return value || id || "";
    }

    function checkInCode(slot) {
        return slot && slot.id ? "cm-slot-" + slot.id : "";
    }

    function openProfilePopup(onSelect) {
        var popup = exports.getShellComponent("app_popup");
        if (!popup || !popup.open) {
            setError("Profile popup is not loaded.");
            return;
        }
        popup.open("profileapp", "frmprofile-list-popup", {}, function (profile, instance) {
            var selected = normalizeProfile(profile);
            if (selected && selected.id) {
                rememberProfile(selected);
                onSelect(selected);
            }
            if (instance && instance.close) {
                instance.close();
            }
        }, "Select Profile", true, true);
    }

    function normalizeProfile(profile) {
        if (profile && profile.id) {
            return profile;
        }
        if (profile && profile.profile && profile.profile.id) {
            return profile.profile;
        }
        if (profile && profile.result && profile.result.id) {
            return profile.result;
        }
        return null;
    }

    function rememberProfile(profile) {
        var found = false;
        bindData.profiles.forEach(function (item, index) {
            if (String(item.id) === String(profile.id)) {
                bindData.profiles[index] = profile;
                found = true;
            }
        });
        if (!found) {
            bindData.profiles.push(profile);
        }
    }

    function statusClass(status) {
        return "cm-pill " + String(status || "scheduled").toLowerCase();
    }

    function navigate(path) {
        if (handler && handler.appNavigate) {
            handler.appNavigate(path);
        } else {
            window.location.hash = "#/app/course-manager" + (path.indexOf("../") === 0 ? "/" + path.substring(3) : path);
        }
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
