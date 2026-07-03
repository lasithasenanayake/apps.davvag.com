WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var routeData = {};

    var bindData = {
        errors: [],
        info: [],
        saving: false,
        classGrades: [],
        subjects: [],
        rooms: [],
        profiles: [],
        form: emptySlot()
    };

    exports.vue = {
        data: bindData,
        methods: {
            saveSlot: saveSlot,
            deleteSlot: deleteSlot,
            selectTeacherProfile: selectTeacherProfile,
            selectRoom: selectRoom,
            profileName: profileName,
            roomName: roomName,
            canDelete: canDelete
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
        routeData = handler && handler.getInputData ? (handler.getInputData() || {}) : {};
        bindData.form = formFromInput(routeData);

        if (!api) {
            setError("Course Manager service is not loaded.");
            return;
        }

        loadLookups();
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
        api.services.ListProfiles({}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        });
    }

    function saveSlot() {
        clearMessages();
        var payload = clone(bindData.form);
        payload.start_at = toStorageDateTime(payload.start_at);
        payload.end_at = toStorageDateTime(payload.end_at);
        payload.teacher_name = profileName(payload.teacher_id) || payload.teacher_name || "";

        if (!payload.class_grade_id || !payload.subject_id || !payload.start_at || !payload.end_at) {
            setError("Class grade, subject, start, and end are required.");
            return;
        }

        bindData.saving = true;
        api.services.SaveTimetable(payload).then(function (response) {
            bindData.saving = false;
            if (response.success) {
                exports.Complete({
                    action: "saved",
                    slot: response.result || payload
                });
            } else {
                setError(response.result && response.result.message ? response.result.message : "Slot save failed.");
            }
        }).error(function () {
            bindData.saving = false;
            setError("Slot save failed.");
        });
    }

    function deleteSlot() {
        clearMessages();
        if (!bindData.form.id) {
            return;
        }
        if (!window.confirm("Delete this timetable slot?")) {
            return;
        }
        bindData.saving = true;
        api.services.DeleteTimetable({id: bindData.form.id}).then(function (response) {
            bindData.saving = false;
            if (response.success) {
                exports.Complete({
                    action: "deleted",
                    slot: {id: bindData.form.id}
                });
            } else {
                setError(response.result && response.result.message ? response.result.message : "Slot delete failed.");
            }
        }).error(function () {
            bindData.saving = false;
            setError("Slot delete failed.");
        });
    }

    function selectTeacherProfile() {
        openProfilePopup(function (profile) {
            bindData.form.teacher_id = profile.id;
            bindData.form.teacher_name = profile.name || "";
            rememberProfile(profile);
        });
    }

    function selectRoom() {
        openRoomPopup(function (room) {
            bindData.form.room_id = room.id;
            rememberRoom(room);
        });
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
                onSelect(selected);
            }
            if (instance && instance.close) {
                instance.close();
            }
        }, "Select Profile", true, true);
    }

    function openRoomPopup(onSelect) {
        var popup = exports.getShellComponent("app_popup");
        if (!popup || !popup.open) {
            setError("Classroom popup is not loaded.");
            return;
        }
        popup.open("course-manager", "classroom-list-popup", {}, function (room, instance) {
            var selected = normalizeRoom(room);
            if (selected && selected.id) {
                onSelect(selected);
            }
            if (instance && instance.close) {
                instance.close();
            }
        }, "Select Classroom", true, true);
    }

    function formFromInput(input) {
        var form = emptySlot();
        Object.keys(form).forEach(function (key) {
            if (typeof input[key] !== "undefined" && input[key] !== null) {
                form[key] = input[key];
            }
        });
        form.start_at = toDateTimeLocal(form.start_at);
        form.end_at = toDateTimeLocal(form.end_at);
        if (!form.end_at && form.start_at) {
            form.end_at = addMinutesLocal(form.start_at, 60);
        }
        if (!form.start_at && input.date && input.time) {
            form.start_at = input.date + "T" + input.time;
            form.end_at = addMinutesLocal(form.start_at, 60);
        }
        return form;
    }

    function emptySlot() {
        return {
            id: "",
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

    function addMinutesLocal(value, minutes) {
        var parsed = parseFlexibleDateTime(value);
        if (!parsed) {
            return "";
        }
        parsed.setMinutes(parsed.getMinutes() + minutes);
        return formatDateTimeLocal(parsed);
    }

    function toDateTimeLocal(value) {
        var parsed = parseFlexibleDateTime(value);
        return parsed ? formatDateTimeLocal(parsed) : "";
    }

    function toStorageDateTime(value) {
        var parsed = parseFlexibleDateTime(value);
        return parsed ? formatDateTimeStorage(parsed) : "";
    }

    function parseFlexibleDateTime(value) {
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

    function formatDateTimeLocal(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + "T" + pad(date.getHours()) + ":" + pad(date.getMinutes());
    }

    function formatDateTimeStorage(date) {
        return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate()) + " " + pad(date.getHours()) + ":" + pad(date.getMinutes()) + ":" + pad(date.getSeconds());
    }

    function profileName(id) {
        var value = "";
        bindData.profiles.forEach(function (profile) {
            if (String(profile.id) === String(id)) {
                value = profile.name || "";
            }
        });
        return value || bindData.form.teacher_name || "";
    }

    function roomName(id) {
        var value = "";
        bindData.rooms.forEach(function (room) {
            if (String(room.id) === String(id)) {
                value = (room.code ? room.code + " - " : "") + room.name;
            }
        });
        return value || "";
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

    function normalizeRoom(room) {
        if (room && room.id) {
            return room;
        }
        if (room && room.room && room.room.id) {
            return room.room;
        }
        if (room && room.result && room.result.id) {
            return room.result;
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

    function rememberRoom(room) {
        var found = false;
        bindData.rooms.forEach(function (item, index) {
            if (String(item.id) === String(room.id)) {
                bindData.rooms[index] = room;
                found = true;
            }
        });
        if (!found) {
            bindData.rooms.push(room);
        }
    }

    function canDelete() {
        return !!bindData.form.id;
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
});
