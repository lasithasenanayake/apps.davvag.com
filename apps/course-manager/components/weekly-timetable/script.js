WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        classGrades: [],
        subjects: [],
        slots: [],
        weekdays: [],
        timeRows: [],
        week_start: "",
        week_end: "",
        filters: {
            class_grade_id: "",
            subject_id: "",
            week_start: ""
        }
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadWeek,
            loadWeek: loadWeek,
            previousWeek: previousWeek,
            nextWeek: nextWeek,
            navigate: navigate,
            openAttendanceForSelection: openAttendanceForSelection,
            goAttendance: goAttendance,
            slotsFor: slotsFor,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            rangeLabel: rangeLabel,
            timeLabel: timeLabel,
            dayClass: dayClass,
            statusClass: statusClass
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
        bindData.filters.week_start = mondayInput(new Date());
        applyRouteParams(readRouteParams());
        loadLookups();
        loadWeek();
    }

    function loadLookups() {
        api.services.ListClassGrades({}).then(function (response) {
            bindData.classGrades = response.success ? (response.result || []) : [];
        });
        api.services.ListSubjects({}).then(function (response) {
            bindData.subjects = response.success ? (response.result || []) : [];
        });
    }

    function loadWeek() {
        clearMessages();
        bindData.loading = true;
        api.services.WeeklyTimetable(clone(bindData.filters)).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                var result = response.result || {};
                bindData.week_start = result.week_start || bindData.filters.week_start;
                bindData.week_end = result.week_end || "";
                bindData.filters.week_start = bindData.week_start;
                bindData.weekdays = result.weekdays || defaultWeekdays(bindData.week_start);
                bindData.slots = result.slots || [];
                bindData.timeRows = buildTimeRows(bindData.slots);
            } else {
                setError(response.result && response.result.message ? response.result.message : "Weekly timetable load failed.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Weekly timetable load failed.");
        });
    }

    function previousWeek() {
        shiftWeek(-7);
    }

    function nextWeek() {
        shiftWeek(7);
    }

    function shiftWeek(days) {
        var base = parseDateInput(bindData.filters.week_start) || new Date();
        base.setDate(base.getDate() + days);
        bindData.filters.week_start = mondayInput(base);
        loadWeek();
    }

    function openAttendanceForSelection() {
        var params = [];
        if (bindData.filters.class_grade_id) {
            params.push("classGradeId=" + encodeURIComponent(bindData.filters.class_grade_id));
        }
        if (bindData.filters.subject_id) {
            params.push("subjectId=" + encodeURIComponent(bindData.filters.subject_id));
        }
        navigate("../attendance" + (params.length ? "?" + params.join("&") : ""));
    }

    function goAttendance(slot) {
        var params = [];
        if (slot && slot.id) {
            params.push("slotId=" + encodeURIComponent(slot.id));
        }
        if (slot && slot.class_grade_id) {
            params.push("classGradeId=" + encodeURIComponent(slot.class_grade_id));
        }
        if (slot && slot.subject_id) {
            params.push("subjectId=" + encodeURIComponent(slot.subject_id));
        }
        navigate("../attendance" + (params.length ? "?" + params.join("&") : ""));
    }

    function slotsFor(day, time) {
        var dayKey = day && day.date ? day.date : "";
        var out = [];
        bindData.slots.forEach(function (slot) {
            if (dateKey(slot.start_at) === dayKey && timeKey(slot.start_at) === time) {
                out.push(slot);
            }
        });
        return out;
    }

    function buildTimeRows(slots) {
        var seen = {};
        var rows = [];
        slots.forEach(function (slot) {
            var key = timeKey(slot.start_at);
            if (key && !seen[key]) {
                seen[key] = true;
                rows.push(key);
            }
        });
        if (rows.length === 0) {
            rows = ["08:00", "09:00", "10:00", "11:00", "12:00", "13:00", "14:00", "15:00", "16:00", "17:00"];
        }
        rows.sort();
        return rows;
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

    function rangeLabel(slot) {
        return timeLabel(timeKey(slot.start_at)) + " - " + timeLabel(timeKey(slot.end_at));
    }

    function timeLabel(time) {
        return time || "";
    }

    function dayClass(day) {
        var parsed = parseDateInput(day && day.date ? day.date : "");
        if (!parsed) {
            return "";
        }
        var dow = parsed.getDay();
        return dow === 0 || dow === 6 ? "is-weekend" : "";
    }

    function statusClass(status) {
        return "cm-pill " + String(status || "scheduled").toLowerCase();
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

    function applyRouteParams(params) {
        bindData.filters.class_grade_id = params.classGradeId || params.class_grade_id || bindData.filters.class_grade_id;
        bindData.filters.subject_id = params.subjectId || params.subject_id || bindData.filters.subject_id;
        bindData.filters.week_start = params.weekStart || params.week_start || bindData.filters.week_start;
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

    function mondayInput(date) {
        var d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        var day = d.getDay();
        var diff = d.getDate() - day + (day === 0 ? -6 : 1);
        d.setDate(diff);
        return formatDateInput(d);
    }

    function parseDateInput(value) {
        if (!value) {
            return null;
        }
        var parts = String(value).split("-");
        if (parts.length < 3) {
            return null;
        }
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }

    function defaultWeekdays(weekStart) {
        var start = parseDateInput(weekStart) || new Date();
        var labels = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
        var days = [];
        for (var i = 0; i < 7; i++) {
            var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            days.push({
                label: labels[i],
                date: formatDateInput(d)
            });
        }
        return days;
    }

    function formatDateInput(date) {
        var month = String(date.getMonth() + 1);
        var day = String(date.getDate());
        return date.getFullYear() + "-" + pad(month) + "-" + pad(day);
    }

    function dateKey(value) {
        if (!value) {
            return "";
        }
        var text = String(value);
        var iso = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
        if (iso) {
            return iso[1] + "-" + pad(iso[2]) + "-" + pad(iso[3]);
        }
        var us = text.match(/^(\d{1,2})-(\d{1,2})-(\d{4})/);
        if (us) {
            return us[3] + "-" + pad(us[1]) + "-" + pad(us[2]);
        }
        var parsed = new Date(text);
        if (!isNaN(parsed.getTime())) {
            return formatDateInput(parsed);
        }
        return "";
    }

    function timeKey(value) {
        if (!value) {
            return "";
        }
        var text = String(value);
        var match = text.match(/(\d{1,2}):(\d{2})/);
        if (!match) {
            return "";
        }
        return pad(match[1]) + ":" + match[2];
    }

    function pad(value) {
        value = String(value);
        return value.length === 1 ? "0" + value : value;
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

    function clearMessages() {
        bindData.errors = [];
        bindData.info = [];
    }
});
