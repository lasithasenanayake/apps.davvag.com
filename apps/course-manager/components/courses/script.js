WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        tab: "courses",
        search: "",
        courses: [],
        subjects: [],
        classGrades: [],
        enrollments: [],
        profiles: [],
        courseForm: emptyCourse(),
        subjectForm: emptySubject(),
        classGradeForm: emptyClassGrade(),
        enrollmentForm: emptyEnrollment(),
        bulkCsv: ""
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadAll,
            setTab: setTab,
            navigate: navigate,
            selectCourse: selectCourse,
            saveCourse: saveCourse,
            deleteCourse: deleteCourse,
            selectSubject: selectSubject,
            saveSubject: saveSubject,
            deleteSubject: deleteSubject,
            selectSubjectTeacher: selectSubjectTeacher,
            selectSubjectProduct: selectSubjectProduct,
            mapProductToSubject: mapProductToSubject,
            clearSubjectProduct: clearSubjectProduct,
            selectClassGrade: selectClassGrade,
            saveClassGrade: saveClassGrade,
            deleteClassGrade: deleteClassGrade,
            selectEnrollment: selectEnrollment,
            saveEnrollment: saveEnrollment,
            deleteEnrollment: deleteEnrollment,
            selectEnrollmentStudent: selectEnrollmentStudent,
            importEnrollments: importEnrollments,
            resetForms: resetForms,
            courseTitle: courseTitle,
            classGradeName: classGradeName,
            profileName: profileName,
            productLabel: productLabel,
            statusClass: statusClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyCourse() {
        return {code: "", title: "", description: "", duration_weeks: 12, status: "active"};
    }

    function emptySubject() {
        return {
            course_id: "",
            code: "",
            title: "",
            teacher_id: "",
            product_id: "",
            product_code: "",
            product_title: "",
            product_price: "",
            product_currency_code: "",
            credits: 3,
            is_core: "true",
            status: "active"
        };
    }

    function emptyClassGrade() {
        return {course_id: "", name: "", start_date: "", end_date: "", capacity: 30, room_id: "", status: "active"};
    }

    function emptyEnrollment() {
        return {class_grade_id: "", course_id: "", student_id: "", student_name: "", student_email: "", status: "active"};
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
        loadCourses();
        loadSubjects();
        loadClassGrades();
        loadEnrollments();
        loadProfiles();
    }

    function loadCourses() {
        api.services.ListCourses({search: bindData.search}).then(function (response) {
            bindData.courses = response.success ? (response.result || []) : [];
        });
    }

    function loadSubjects() {
        api.services.ListSubjects({search: bindData.search}).then(function (response) {
            bindData.subjects = response.success ? (response.result || []) : [];
        });
    }

    function loadClassGrades() {
        api.services.ListClassGrades({search: bindData.search}).then(function (response) {
            bindData.classGrades = response.success ? (response.result || []) : [];
        });
    }

    function loadEnrollments() {
        api.services.ListEnrollments({search: bindData.search}).then(function (response) {
            bindData.enrollments = response.success ? (response.result || []) : [];
        });
    }

    function loadProfiles() {
        api.services.ListProfiles({search: ""}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        });
    }

    function setTab(tab) {
        bindData.tab = tab;
    }

    function selectCourse(course) {
        bindData.courseForm = clone(course);
        bindData.subjectForm.course_id = course.id;
        bindData.classGradeForm.course_id = course.id;
    }

    function saveCourse() {
        save("SaveCourse", bindData.courseForm, loadCourses, emptyCourse, "courseForm");
    }

    function deleteCourse(course) {
        remove("DeleteCourse", course, loadCourses);
    }

    function selectSubject(subject) {
        bindData.subjectForm = clone(subject);
    }

    function saveSubject() {
        save("SaveSubject", bindData.subjectForm, loadSubjects, emptySubject, "subjectForm");
    }

    function deleteSubject(subject) {
        remove("DeleteSubject", subject, loadSubjects);
    }

    function selectSubjectTeacher() {
        openProfilePopup(function (profile) {
            bindData.subjectForm.teacher_id = profile.id;
        });
    }

    function selectSubjectProduct() {
        openProductPopup(function (product) {
            applyProductToSubject(bindData.subjectForm, product);
        });
    }

    function mapProductToSubject(subject) {
        if (!subject || !subject.id) {
            return;
        }
        openProductPopup(function (product) {
            var payload = clone(subject);
            applyProductToSubject(payload, product);
            clearMessages();
            api.services.SaveSubject(payload).then(function (response) {
                if (response.success) {
                    setInfo("Product mapped to subject.");
                    loadSubjects();
                    bindData.subjectForm = clone(response.result || payload);
                } else {
                    setError(response.result && response.result.message ? response.result.message : "Product mapping failed.");
                }
            }).error(function () {
                setError("Product mapping failed.");
            });
        });
    }

    function clearSubjectProduct() {
        bindData.subjectForm.product_id = "";
        bindData.subjectForm.product_code = "";
        bindData.subjectForm.product_title = "";
        bindData.subjectForm.product_price = "";
        bindData.subjectForm.product_currency_code = "";
    }

    function selectClassGrade(classGrade) {
        bindData.classGradeForm = clone(classGrade);
    }

    function saveClassGrade() {
        save("SaveClassGrade", bindData.classGradeForm, loadClassGrades, emptyClassGrade, "classGradeForm");
    }

    function deleteClassGrade(classGrade) {
        remove("DeleteClassGrade", classGrade, loadClassGrades);
    }

    function selectEnrollment(enrollment) {
        bindData.enrollmentForm = clone(enrollment);
    }

    function saveEnrollment() {
        save("SaveEnrollment", bindData.enrollmentForm, loadEnrollments, emptyEnrollment, "enrollmentForm");
    }

    function deleteEnrollment(enrollment) {
        remove("DeleteEnrollment", enrollment, loadEnrollments);
    }

    function selectEnrollmentStudent() {
        openProfilePopup(function (profile) {
            bindData.enrollmentForm.student_id = profile.id;
            bindData.enrollmentForm.student_name = profile.name || "";
            bindData.enrollmentForm.student_email = profile.email || "";
        });
    }

    function importEnrollments() {
        clearMessages();
        api.services.BulkImportEnrollments({csv: bindData.bulkCsv}).then(function (response) {
            if (response.success) {
                var result = response.result || {};
                setInfo((result.saved || []).length + " enrollments imported.");
                if ((result.errors || []).length > 0) {
                    setError((result.errors || []).length + " rows failed.");
                }
                bindData.bulkCsv = "";
                loadEnrollments();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Import failed.");
            }
        }).error(function () {
            setError("Import failed.");
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
        var label = item.title || item.name || item.code || item.product_title || item.student_name || "this item";
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

    function openProductPopup(onSelect) {
        var popup = exports.getShellComponent("app_popup");
        if (!popup || !popup.open) {
            setError("Product popup is not loaded.");
            return;
        }
        popup.open("productapp-v2", "frmproduct-list-popup", {}, function (product, instance) {
            var selected = normalizeProduct(product);
            if (selected && selected.product_id) {
                onSelect(selected);
            }
            if (instance && instance.close) {
                instance.close();
            }
        }, "Select Product", true, true);
    }

    function normalizeProduct(product) {
        if (product && product.product_id) {
            return product;
        }
        if (product && product.itemid) {
            return {
                product_id: product.itemid,
                product_code: String(product.itemid),
                product_title: product.name || "",
                product_price: product.price || 0,
                product_currency_code: product.currencycode || ""
            };
        }
        return null;
    }

    function applyProductToSubject(subject, product) {
        subject.product_id = product.product_id || "";
        subject.product_code = product.product_code || product.product_id || "";
        subject.product_title = product.product_title || "";
        subject.product_price = product.product_price || "";
        subject.product_currency_code = product.product_currency_code || "";
    }

    function resetForms() {
        bindData.courseForm = emptyCourse();
        bindData.subjectForm = emptySubject();
        bindData.classGradeForm = emptyClassGrade();
        bindData.enrollmentForm = emptyEnrollment();
    }

    function courseTitle(id) {
        var value = "";
        bindData.courses.forEach(function (course) {
            if (String(course.id) === String(id)) {
                value = course.code + " - " + course.title;
            }
        });
        return value || id;
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

    function profileName(id) {
        var value = "";
        bindData.profiles.forEach(function (profile) {
            if (String(profile.id) === String(id)) {
                value = profile.name;
            }
        });
        return value || id;
    }

    function productLabel(subject) {
        if (!subject || !subject.product_id) {
            return "No product mapped";
        }
        return "#" + subject.product_id + " " + (subject.product_title || subject.product_code || "");
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
