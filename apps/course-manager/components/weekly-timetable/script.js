WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        classGrades: [],
        subjects: [],
        rooms: [],
        slots: [],
        weekdays: [],
        timeRows: [],
        week_start: "",
        week_end: "",
        filters: {
            class_grade_id: "",
            subject_id: "",
            room_id: "",
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
            openQuickAdd: openQuickAdd,
            openSlotPopupForCell: openSlotPopupForCell,
            editSlot: editSlot,
            openAttendanceForSelection: openAttendanceForSelection,
            goAttendance: goAttendance,
            slotsFor: slotsFor,
            slotStartsFor: slotStartsFor,
            classGradeName: classGradeName,
            subjectTitle: subjectTitle,
            roomName: roomName,
            rangeLabel: rangeLabel,
            timeLabel: timeLabel,
            nextTimeLabel: nextTimeLabel,
            cellClass: cellClass,
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
        api.services.ListClassrooms({}).then(function (response) {
            bindData.rooms = response.success ? (response.result || []) : [];
        });
    }

    function loadWeek() {
        clearMessages();
        bindData.loading = true;
        api.services.ListTimetable({pageSize: 2000, sorting: "asc"}).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                bindData.week_start = mondayInput(parseDateInput(bindData.filters.week_start) || new Date());
                bindData.week_end = weekEndFor(bindData.week_start);
                bindData.filters.week_start = bindData.week_start;
                bindData.weekdays = defaultWeekdays(bindData.week_start);
                bindData.slots = filterWeekSlots(response.result || []);
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

    function openQuickAdd() {
        openSlotPopup({
            class_grade_id: bindData.filters.class_grade_id || "",
            subject_id: bindData.filters.subject_id || "",
            room_id: bindData.filters.room_id || "",
            date: bindData.week_start || bindData.filters.week_start || "",
            time: "07:00"
        });
    }

    function openSlotPopupForCell(day, time) {
        openSlotPopup({
            class_grade_id: bindData.filters.class_grade_id || "",
            subject_id: bindData.filters.subject_id || "",
            room_id: bindData.filters.room_id || "",
            date: day && day.date ? day.date : "",
            time: time || ""
        });
    }

    function editSlot(slot) {
        openSlotPopup(clone(slot || {}));
    }

    function openSlotPopup(data) {
        var popup = exports.getShellComponent("app_popup");
        if (!popup || !popup.open) {
            setError("Slot popup is not loaded.");
            return;
        }
        popup.open("course-manager", "slot-popup", data || {}, function (result, instance) {
            if (instance && instance.close) {
                instance.close();
            }
            if (result && result.action) {
                setInfo(result.action === "deleted" ? "Slot deleted." : "Slot saved.");
                loadWeek();
            }
        }, data && data.id ? "Edit Slot" : "Add Slot", true, true);
    }

    function slotsFor(day, time) {
        var dayKey = day && day.date ? day.date : "";
        var out = [];
        bindData.slots.forEach(function (slot) {
            if (dateKey(slot.start_at) !== dayKey) {
                return;
            }
            if (slotOccupiesTime(slot, time)) {
                out.push(slot);
            }
        });
        return out;
    }

    function slotStartsFor(day, time) {
        var dayKey = day && day.date ? day.date : "";
        var out = [];
        bindData.slots.forEach(function (slot) {
            if (dateKey(slot.start_at) === dayKey && slotStartsInTimeRow(slot, time)) {
                out.push(slot);
            }
        });
        return out;
    }

    function buildTimeRows(slots) {
        var rows = [];
        var hour;
        for (hour = 7; hour <= 23; hour++) {
            rows.push(pad(hour) + ":00");
        }
        return rows;
    }

    function filterWeekSlots(slots) {
        var start = bindData.week_start || bindData.filters.week_start;
        var end = bindData.week_end || weekEndFor(start);
        var out = [];
        slots.forEach(function (slot) {
            if (!slotMatchesFilters(slot)) {
                return;
            }
            if (!slotWithinWeek(slot, start, end)) {
                return;
            }
            out.push(normalizeSlotDates(slot));
        });
        out.sort(function (left, right) {
            var a = sortableDateTime(left.start_at);
            var b = sortableDateTime(right.start_at);
            if (a === b) {
                return 0;
            }
            return a < b ? -1 : 1;
        });
        return out;
    }

    function slotMatchesFilters(slot) {
        if (bindData.filters.class_grade_id && String(slot.class_grade_id) !== String(bindData.filters.class_grade_id)) {
            return false;
        }
        if (bindData.filters.subject_id && String(slot.subject_id) !== String(bindData.filters.subject_id)) {
            return false;
        }
        if (bindData.filters.room_id && String(slot.room_id) !== String(bindData.filters.room_id)) {
            return false;
        }
        return true;
    }

    function slotWithinWeek(slot, weekStart, weekEnd) {
        var key = dateKey(slot && slot.start_at ? slot.start_at : "");
        return !!key && key >= weekStart && key <= weekEnd;
    }

    function normalizeSlotDates(slot) {
        var out = clone(slot);
        out.start_at = normalizeDateTimeValue(out.start_at);
        out.end_at = normalizeDateTimeValue(out.end_at);
        return out;
    }

    function normalizeDateTimeValue(value) {
        var parsed = parseDateTimeValue(value);
        return parsed ? formatDateTimeStorage(parsed) : String(value || "");
    }

    function sortableDateTime(value) {
        var parsed = parseDateTimeValue(value);
        return parsed ? parsed.getTime() : 0;
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

    function roomName(id) {
        var value = "";
        bindData.rooms.forEach(function (room) {
            if (String(room.id) === String(id)) {
                value = (room.code ? room.code + " - " : "") + room.name;
            }
        });
        return value || id || "";
    }

    function rangeLabel(slot) {
        return timeLabel(timeKey(slot.start_at)) + " - " + timeLabel(timeKey(slot.end_at));
    }

    function timeLabel(time) {
        var minutes = timeToMinutes(time);
        if (minutes === null) {
            return time || "";
        }
        return formatMinutesLabel(minutes);
    }

    function nextTimeLabel(time) {
        var minutes = timeToMinutes(time);
        if (minutes === null) {
            return "";
        }
        return formatMinutesLabel(minutes + 60);
    }

    function cellClass(day, time) {
        var classes = [];
        var baseDayClass = dayClass(day);
        if (baseDayClass) {
            classes.push(baseDayClass);
        }
        if (slotsFor(day, time).length) {
            classes.push("is-slot-active");
        }
        if (slotStartsFor(day, time).length) {
            classes.push("is-slot-start");
        }
        return classes.join(" ");
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
        bindData.filters.room_id = params.roomId || params.room_id || bindData.filters.room_id;
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

    function parseDateTimeValue(value) {
        if (!value) {
            return null;
        }
        var text = String(value).trim();
        var match = text.match(/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (match) {
            return new Date(parseInt(match[1], 10), parseInt(match[2], 10) - 1, parseInt(match[3], 10), parseInt(match[4], 10), parseInt(match[5], 10), parseInt(match[6] || "0", 10));
        }
        match = text.match(/^(\d{2})-(\d{2})-(\d{4})[T\s](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (match) {
            return new Date(parseInt(match[3], 10), parseInt(match[1], 10) - 1, parseInt(match[2], 10), parseInt(match[4], 10), parseInt(match[5], 10), parseInt(match[6] || "0", 10));
        }
        var parsed = new Date(text);
        return isNaN(parsed.getTime()) ? null : parsed;
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

    function weekEndFor(weekStart) {
        var start = parseDateInput(weekStart);
        if (!start) {
            return "";
        }
        var end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        return formatDateInput(end);
    }

    function formatDateInput(date) {
        var month = String(date.getMonth() + 1);
        var day = String(date.getDate());
        return date.getFullYear() + "-" + pad(month) + "-" + pad(day);
    }

    function formatDateTimeStorage(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + " " + pad(date.getHours()) + ":" + pad(date.getMinutes()) + ":" + pad(date.getSeconds());
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

    function slotOccupiesTime(slot, time) {
        var startMinutes = timeToMinutes(timeKey(slot.start_at));
        var endMinutes = timeToMinutes(timeKey(slot.end_at));
        var rowStart = timeToMinutes(time);
        var rowEnd = rowStart === null ? null : rowStart + 60;
        if (startMinutes === null || endMinutes === null || rowStart === null || rowEnd === null) {
            return false;
        }
        if (endMinutes <= startMinutes) {
            endMinutes = startMinutes + 60;
        }
        return startMinutes < rowEnd && endMinutes > rowStart;
    }

    function slotStartsInTimeRow(slot, time) {
        var startMinutes = timeToMinutes(timeKey(slot.start_at));
        var rowStart = timeToMinutes(time);
        var rowEnd = rowStart === null ? null : rowStart + 60;
        if (startMinutes === null || rowStart === null || rowEnd === null) {
            return false;
        }
        return startMinutes >= rowStart && startMinutes < rowEnd;
    }

    function timeToMinutes(value) {
        if (!value) {
            return null;
        }
        var parts = String(value).split(":");
        if (parts.length < 2) {
            return null;
        }
        return (parseInt(parts[0], 10) * 60) + parseInt(parts[1], 10);
    }

    function formatMinutesLabel(totalMinutes) {
        var normalized = totalMinutes % (24 * 60);
        if (normalized < 0) {
            normalized += 24 * 60;
        }
        var hours = Math.floor(normalized / 60);
        var minutes = normalized % 60;
        var meridiem = hours >= 12 ? "PM" : "AM";
        var displayHours = hours % 12;
        if (displayHours === 0) {
            displayHours = 12;
        }
        return displayHours + ":" + pad(minutes) + " " + meridiem;
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
        link.href = "components/course-manager/course-style/file/course-manager.css?v=1.1";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message) {
        bindData.errors.push(message);
    }

    function clearMessages() {
        bindData.errors = [];
        bindData.info = [];
    }

    function setInfo(message) {
        bindData.info.push(message);
    }
});
