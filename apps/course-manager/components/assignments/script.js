WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        assignments: [],
        submissions: [],
        classGrades: [],
        subjects: [],
        profiles: [],
        assignmentForm: emptyAssignment(),
        submissionForm: emptySubmission(),
        gradeForm: emptyGrade()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadAll,
            navigate: navigate,
            selectAssignment: selectAssignment,
            saveAssignment: saveAssignment,
            deleteAssignment: deleteAssignment,
            selectSubmission: selectSubmission,
            submitAssignment: submitAssignment,
            selectSubmissionStudent: selectSubmissionStudent,
            deleteSubmission: deleteSubmission,
            gradeSubmission: gradeSubmission,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            profileName: profileName,
            assignmentTitle: assignmentTitle,
            statusClass: statusClass,
            clearAssignment: clearAssignment
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyAssignment() {
        return {class_grade_id: "", subject_id: "", title: "", description: "", due_at: "", max_mark: 100, late_penalty_per_day: 0, status: "draft"};
    }

    function emptySubmission() {
        return {assignment_id: "", student_id: "", student_name: "", content: "", file_url: ""};
    }

    function emptyGrade() {
        return {submission_id: "", marks: "", feedback: ""};
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
        api.services.ListAssignments({}).then(function (response) {
            bindData.assignments = response.success ? (response.result || []) : [];
        });
        api.services.ListSubmissions({}).then(function (response) {
            bindData.submissions = response.success ? (response.result || []) : [];
        });
        api.services.ListClassGrades({}).then(function (response) {
            bindData.classGrades = response.success ? (response.result || []) : [];
        });
        api.services.ListSubjects({}).then(function (response) {
            bindData.subjects = response.success ? (response.result || []) : [];
        });
        api.services.ListProfiles({}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        });
    }

    function selectAssignment(assignment) {
        bindData.assignmentForm = clone(assignment);
        bindData.submissionForm.assignment_id = assignment.id;
    }

    function clearAssignment() {
        bindData.assignmentForm = emptyAssignment();
    }

    function saveAssignment() {
        clearMessages();
        api.services.CreateAssignment(clone(bindData.assignmentForm)).then(function (response) {
            if (response.success) {
                setInfo("Assignment saved.");
                bindData.assignmentForm = emptyAssignment();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Assignment save failed.");
            }
        }).error(function () {
            setError("Assignment save failed.");
        });
    }

    function deleteAssignment(assignment) {
        if (!assignment || !assignment.id) {
            return;
        }
        if (!confirmDelete(assignment.title || "this assignment")) {
            return;
        }
        clearMessages();
        api.services.DeleteAssignment(assignment).then(function (response) {
            if (response.success) {
                setInfo("Assignment deleted.");
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Assignment delete failed.");
            }
        }).error(function () {
            setError("Assignment delete failed.");
        });
    }

    function selectSubmission(submission) {
        bindData.submissionForm = clone(submission);
        bindData.gradeForm.submission_id = submission.id;
        bindData.gradeForm.marks = submission.marks || "";
        bindData.gradeForm.feedback = submission.feedback || "";
    }

    function submitAssignment() {
        clearMessages();
        bindData.submissionForm.student_name = profileName(bindData.submissionForm.student_id);
        api.services.SubmitAssignment(clone(bindData.submissionForm)).then(function (response) {
            if (response.success) {
                setInfo("Submission saved.");
                bindData.submissionForm = emptySubmission();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Submission save failed.");
            }
        }).error(function () {
            setError("Submission save failed.");
        });
    }

    function selectSubmissionStudent() {
        openProfilePopup(function (profile) {
            bindData.submissionForm.student_id = profile.id;
            bindData.submissionForm.student_name = profile.name || "";
        });
    }

    function deleteSubmission(submission) {
        if (!submission || !submission.id) {
            return;
        }
        var label = submission.student_name || profileName(submission.student_id) || "this submission";
        if (label !== "this submission") {
            label += " submission";
        }
        if (!confirmDelete(label)) {
            return;
        }
        clearMessages();
        api.services.DeleteSubmission(submission).then(function (response) {
            if (response.success) {
                setInfo("Submission deleted.");
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Submission delete failed.");
            }
        }).error(function () {
            setError("Submission delete failed.");
        });
    }

    function confirmDelete(label) {
        return window.confirm("Are you sure you want to delete " + label + "? This cannot be undone.");
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

    function gradeSubmission() {
        clearMessages();
        api.services.GradeSubmission(clone(bindData.gradeForm)).then(function (response) {
            if (response.success) {
                setInfo("Submission graded.");
                bindData.gradeForm = emptyGrade();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Grade save failed.");
            }
        }).error(function () {
            setError("Grade save failed.");
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

    function profileName(id) {
        var value = "";
        bindData.profiles.forEach(function (profile) {
            if (String(profile.id) === String(id)) {
                value = profile.name;
            }
        });
        return value || id || "";
    }

    function assignmentTitle(id) {
        var value = "";
        bindData.assignments.forEach(function (assignment) {
            if (String(assignment.id) === String(id)) {
                value = assignment.title;
            }
        });
        return value || id;
    }

    function statusClass(status) {
        return "cm-pill " + String(status || "draft").toLowerCase();
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
