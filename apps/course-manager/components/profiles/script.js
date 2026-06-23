WEBDOCK.component().register(function (exports) {
    var api;
    var handler;

    var bindData = {
        errors: [],
        info: [],
        search: "",
        profiles: [],
        rooms: [],
        attendance: [],
        roomForm: emptyRoom(),
        exportResult: null
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadAll,
            navigate: navigate,
            searchProfiles: loadProfiles,
            selectRoom: selectRoom,
            saveRoom: saveRoom,
            deleteRoom: deleteRoom,
            exportAttendance: exportAttendance,
            statusClass: statusClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function emptyRoom() {
        return {code: "", name: "", capacity: 30, location: "", type: "room", status: "active"};
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
        loadProfiles();
        api.services.ListClassrooms({}).then(function (response) {
            bindData.rooms = response.success ? (response.result || []) : [];
        });
        api.services.ListAttendance({}).then(function (response) {
            bindData.attendance = response.success ? (response.result || []) : [];
        });
    }

    function loadProfiles() {
        api.services.ListProfiles({search: bindData.search}).then(function (response) {
            bindData.profiles = response.success ? (response.result || []) : [];
        });
    }

    function selectRoom(room) {
        bindData.roomForm = clone(room);
    }

    function saveRoom() {
        clearMessages();
        api.services.SaveClassroom(clone(bindData.roomForm)).then(function (response) {
            if (response.success) {
                setInfo("Room saved.");
                bindData.roomForm = emptyRoom();
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Room save failed.");
            }
        }).error(function () {
            setError("Room save failed.");
        });
    }

    function deleteRoom(room) {
        if (!room || !room.id) {
            return;
        }
        if (!confirmDelete(room)) {
            return;
        }
        clearMessages();
        api.services.DeleteClassroom(room).then(function (response) {
            if (response.success) {
                setInfo("Room deleted.");
                loadAll();
            } else {
                setError(response.result && response.result.message ? response.result.message : "Room delete failed.");
            }
        }).error(function () {
            setError("Room delete failed.");
        });
    }

    function confirmDelete(room) {
        var label = room.name || room.code || "this room";
        return window.confirm("Are you sure you want to delete " + label + "? This cannot be undone.");
    }

    function exportAttendance() {
        clearMessages();
        api.services.ExportAttendanceCsv({}).then(function (response) {
            if (response.success) {
                bindData.exportResult = response.result;
                setInfo("CSV ready.");
            } else {
                setError(response.result && response.result.message ? response.result.message : "CSV export failed.");
            }
        }).error(function () {
            setError("CSV export failed.");
        });
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
