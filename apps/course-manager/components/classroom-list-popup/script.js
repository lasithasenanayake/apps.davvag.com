WEBDOCK.component().register(function (exports) {
    var api;
    var bindData = {
        search: "",
        rooms: [],
        message: "Search for a classroom to select it."
    };

    var vueData = {
        data: bindData,
        methods: {
            searchRooms: searchRooms,
            selectRoom: selectRoom,
            roomMeta: roomMeta,
            roomBadgeClass: roomBadgeClass
        },
        onReady: function () {
            initialize();
        }
    };

    exports.vue = vueData;
    exports.onReady = function () {};

    function initialize() {
        ensureStyles();
        api = exports.getComponent("api");
        if (!api) {
            bindData.message = "Course Manager service is not loaded.";
            return;
        }
        searchRooms();
    }

    function searchRooms() {
        bindData.message = "Loading classrooms...";
        api.services.ListClassrooms({search: bindData.search}).then(function (response) {
            if (response.success) {
                bindData.rooms = response.result || [];
                bindData.message = bindData.rooms.length ? "" : "No classrooms found.";
            } else {
                bindData.rooms = [];
                bindData.message = response.result && response.result.message ? response.result.message : "Could not load classrooms.";
            }
        }).error(function () {
            bindData.rooms = [];
            bindData.message = "Could not load classrooms.";
        });
    }

    function selectRoom(room) {
        exports.Complete(room);
    }

    function roomMeta(room) {
        var parts = [];
        if (room && room.capacity) {
            parts.push(room.capacity + " seats");
        }
        if (room && room.location) {
            parts.push(room.location);
        }
        return parts.join(" | ");
    }

    function roomBadgeClass(status) {
        return "cm-pill " + String(status || "active").toLowerCase();
    }

    function ensureStyles() {
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
});
