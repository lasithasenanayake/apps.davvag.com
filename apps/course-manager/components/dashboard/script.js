WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        stats: {},
        courses: [],
        classGrades: [],
        capacityRows: [],
        timetable: [],
        assignments: [],
        quickCourse: emptyCourse()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: initialize,
            seedSample: seedSample,
            saveQuickCourse: saveQuickCourse,
            navigate: navigate,
            statusClass: statusClass,
            courseTitle: courseTitle,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyCourse() {
        return {
            code: "",
            title: "",
            description: "",
            duration_weeks: 12,
            status: "active"
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
        loadDashboard();
    }

    function loadDashboard() {
        bindData.loading = true;
        clearMessages();
        api.services.Dashboard({}).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                var result = response.result || {};
                bindData.stats = result.stats || {};
                bindData.courses = result.courses || [];
                bindData.classGrades = result.classGrades || [];
                bindData.capacityRows = result.capacityRows || [];
                bindData.timetable = result.timetable || [];
                bindData.assignments = result.assignments || [];
            } else {
                setError("Dashboard load failed.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Dashboard load failed.");
        });
    }

    function seedSample() {
        clearMessages();
        api.services.SeedSampleData({}).then(function (response) {
            if (response.success) {
                setInfo("Seed data ready.");
                var result = response.result || {};
                bindData.stats = result.stats || {};
                bindData.courses = result.courses || [];
                bindData.classGrades = result.classGrades || [];
                bindData.capacityRows = result.capacityRows || [];
                bindData.timetable = result.timetable || [];
                bindData.assignments = result.assignments || [];
            } else {
                setError(response.result && response.result.message ? response.result.message : "Seed failed.");
            }
        }).error(function () {
            setError("Seed failed.");
        });
    }

    function saveQuickCourse() {
        clearMessages();
        if (!bindData.quickCourse.code || !bindData.quickCourse.title) {
            setError("Course code and title are required.");
            return;
        }
        api.services.CreateCourse(clone(bindData.quickCourse)).then(function (response) {
            if (response.success) {
                bindData.quickCourse = emptyCourse();
                setInfo("Course saved.");
                loadDashboard();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Course save failed.");
            }
        }).error(function () {
            setError("Course save failed.");
        });
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

    function courseTitle(courseId) {
        var value = "";
        bindData.courses.forEach(function (course) {
            if (String(course.id) === String(courseId)) {
                value = course.code + " - " + course.title;
            }
        });
        return value || courseId;
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
        return id || "";
    }

    function statusClass(status) {
        return "cm-pill " + String(status || "active").toLowerCase();
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
