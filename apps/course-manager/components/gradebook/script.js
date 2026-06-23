WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        assessments: [],
        marks: [],
        scales: [],
        classGrades: [],
        subjects: [],
        profiles: [],
        assessmentForm: emptyAssessment(),
        markForm: emptyMark(),
        scaleForm: emptyScale(),
        compute: {marks: "", result: null},
        finalRequest: {student_id: "", class_grade_id: "", subject_id: "", result: null}
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadAll,
            navigate: navigate,
            selectAssessment: selectAssessment,
            saveAssessment: saveAssessment,
            deleteAssessment: deleteAssessment,
            selectMark: selectMark,
            saveMark: saveMark,
            deleteMark: deleteMark,
            selectMarkStudent: selectMarkStudent,
            selectScale: selectScale,
            saveScale: saveScale,
            deleteScale: deleteScale,
            computeGrade: computeGrade,
            recomputeGrades: recomputeGrades,
            finalGrade: finalGrade,
            selectFinalStudent: selectFinalStudent,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            profileName: profileName,
            statusClass: statusClass,
            clearForms: clearForms
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyAssessment() {
        return {class_grade_id: "", subject_id: "", title: "", assessment_type: "quiz", max_mark: 100, weight: 0, due_at: "", status: "active"};
    }

    function emptyMark() {
        return {assessment_id: "", class_grade_id: "", subject_id: "", student_id: "", student_name: "", marks: "", max_mark: 100, weight: 0, note: ""};
    }

    function emptyScale() {
        return {min_mark: "", max_mark: "", grade_letter: "", grade_point: "", active: "true", label: "Default"};
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
        api.services.ListAssessments({}).then(function (response) {
            bindData.assessments = response.success ? (response.result || []) : [];
        });
        api.services.ListMarks({}).then(function (response) {
            bindData.marks = response.success ? (response.result || []) : [];
        });
        api.services.ListGradingScales({}).then(function (response) {
            bindData.scales = response.success ? (response.result || []) : [];
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

    function selectAssessment(assessment) {
        bindData.assessmentForm = clone(assessment);
        bindData.markForm.assessment_id = assessment.id;
        bindData.markForm.class_grade_id = assessment.class_grade_id;
        bindData.markForm.subject_id = assessment.subject_id;
        bindData.markForm.max_mark = assessment.max_mark;
        bindData.markForm.weight = assessment.weight;
    }

    function saveAssessment() {
        save("SaveAssessment", bindData.assessmentForm, loadAll, emptyAssessment, "assessmentForm");
    }

    function deleteAssessment(assessment) {
        remove("DeleteAssessment", assessment, loadAll);
    }

    function selectMark(mark) {
        bindData.markForm = clone(mark);
    }

    function saveMark() {
        bindData.markForm.student_name = profileName(bindData.markForm.student_id);
        save("SaveMark", bindData.markForm, loadAll, emptyMark, "markForm");
    }

    function deleteMark(mark) {
        remove("DeleteMark", mark, loadAll);
    }

    function selectMarkStudent() {
        openProfilePopup(function (profile) {
            bindData.markForm.student_id = profile.id;
            bindData.markForm.student_name = profile.name || "";
        });
    }

    function selectScale(scale) {
        bindData.scaleForm = clone(scale);
    }

    function saveScale() {
        save("CreateGradingScale", bindData.scaleForm, loadAll, emptyScale, "scaleForm");
    }

    function deleteScale(scale) {
        remove("DeleteGradingScale", scale, loadAll);
    }

    function computeGrade() {
        clearMessages();
        api.services.ComputeGrade({marks: bindData.compute.marks}).then(function (response) {
            if (response.success) {
                bindData.compute.result = response.result;
            } else {
                bindData.compute.result = null;
                setError(response.result && response.result.message ? response.result.message : "No scale matched.");
            }
        }).error(function () {
            setError("Grade compute failed.");
        });
    }

    function recomputeGrades() {
        clearMessages();
        api.services.RecomputeGrades({}).then(function (response) {
            if (response.success) {
                setInfo((response.result.updated || 0) + " marks updated.");
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Recompute failed.");
            }
        }).error(function () {
            setError("Recompute failed.");
        });
    }

    function finalGrade() {
        clearMessages();
        api.services.FinalGrade(clone(bindData.finalRequest)).then(function (response) {
            if (response.success) {
                bindData.finalRequest.result = response.result;
            } else {
                setError(response.result && response.result.message ? response.result.message : "Final grade failed.");
            }
        }).error(function () {
            setError("Final grade failed.");
        });
    }

    function selectFinalStudent() {
        openProfilePopup(function (profile) {
            bindData.finalRequest.student_id = profile.id;
        });
    }

    function save(method, form, reload, emptyFactory, formKey) {
        clearMessages();
        api.services[method](clone(form)).then(function (response) {
            if (response.success) {
                bindData[formKey] = emptyFactory();
                setInfo("Saved.");
                reload();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Save failed.");
            }
        }).error(function () {
            setError("Save failed.");
        });
    }

    function remove(method, item, reload) {
        if (!item || !item.id) {
            return;
        }
        if (!confirmDelete(item)) {
            return;
        }
        clearMessages();
        api.services[method](item).then(function (response) {
            if (response.success) {
                setInfo("Deleted.");
                reload();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Delete failed.");
            }
        }).error(function () {
            setError("Delete failed.");
        });
    }

    function confirmDelete(item) {
        var label = item.title || item.grade_letter || item.student_name || "this item";
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

    function clearForms() {
        bindData.assessmentForm = emptyAssessment();
        bindData.markForm = emptyMark();
        bindData.scaleForm = emptyScale();
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

    function statusClass(status) {
        return "cm-pill " + String(status || "active").toLowerCase();
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
