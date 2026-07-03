WEBDOCK.component().register(function (exports) {
    var api;
    var handler;
    var uploader;
    var currentLayoutObjectUrl = "";

    var bindData = {
        errors: [],
        info: [],
        loading: false,
        search: "",
        rooms: [],
        roomForm: emptyRoom(),
        selectedRoomId: 0,
        pendingLayoutFile: null,
        layoutPreview: "",
        layoutPreviewKind: "",
        uploading: false
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: function () {
                loadRooms(false);
            },
            searchRooms: loadRooms,
            selectRoom: selectRoom,
            clearForm: clearForm,
            saveRoom: saveRoom,
            deleteRoom: deleteRoom,
            navigate: navigate,
            onLayoutPicked: onLayoutPicked,
            clearLayout: clearLayout,
            roomBadgeClass: roomBadgeClass,
            roomTypeLabel: roomTypeLabel,
            layoutUrl: layoutUrl,
            hasLayoutPreview: hasLayoutPreview,
            isImageLayout: isImageLayout
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function initialize() {
        ensureStyles();
        api = exports.getComponent("api");
        handler = exports.getShellComponent("soss-routes");
        uploader = exports.getShellComponent("soss-uploader");

        if (!api) {
            setError("Course Manager service is not loaded.");
            return;
        }

        loadRooms();
    }

    function loadRooms(preserveMessages) {
        if (!preserveMessages) {
            clearMessages();
        }
        bindData.loading = true;
        api.services.ListClassrooms({search: bindData.search}).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                bindData.rooms = response.result || [];
            } else {
                setError(response.result && response.result.message ? response.result.message : "Could not load classrooms.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Could not load classrooms.");
        });
    }

    function selectRoom(room) {
        clearLayoutObjectUrl();
        bindData.roomForm = clone(room || emptyRoom());
        bindData.selectedRoomId = bindData.roomForm.id || 0;
        bindData.pendingLayoutFile = null;
        bindData.layoutPreview = bindData.roomForm.layout_url || "";
        bindData.layoutPreviewKind = previewKindFromUrl(bindData.layoutPreview);
    }

    function clearForm() {
        clearLayoutObjectUrl();
        bindData.roomForm = emptyRoom();
        bindData.selectedRoomId = 0;
        bindData.pendingLayoutFile = null;
        bindData.layoutPreview = "";
        bindData.layoutPreviewKind = "";
    }

    function saveRoom() {
        clearMessages();
        var room = clone(bindData.roomForm);

        if (!room.code || !room.name) {
            setError("Room code and name are required.");
            return;
        }

        room.capacity = room.capacity === "" || room.capacity === null || typeof room.capacity === "undefined" ? 0 : parseInt(room.capacity, 10);
        if (isNaN(room.capacity)) {
            room.capacity = 0;
        }
        room.location = room.location || "";
        room.type = room.type || "room";
        room.status = room.status || "active";

        bindData.uploading = true;
        api.services.SaveClassroom(room).then(function (response) {
            if (!response.success) {
                bindData.uploading = false;
                setError(response.result && response.result.message ? response.result.message : "Room save failed.");
                return;
            }

            var saved = response.result || room;
            bindData.roomForm = clone(saved);
            bindData.selectedRoomId = saved.id || 0;

            if (!bindData.pendingLayoutFile) {
                bindData.layoutPreview = bindData.roomForm.layout_url;
                bindData.layoutPreviewKind = previewKindFromUrl(bindData.layoutPreview);
                bindData.uploading = false;
                setInfo("Room saved.");
                loadRooms(true);
                return;
            }

            var targetUrl = layoutUrl(saved.id);
            bindData.roomForm.layout_url = targetUrl;
            persistLayoutReference(clone(bindData.roomForm), function () {
                uploadLayout(saved.id, function () {
                    clearLayoutObjectUrl();
                    bindData.roomForm.layout_url = targetUrl + "?v=" + new Date().getTime();
                    bindData.layoutPreview = bindData.roomForm.layout_url;
                    bindData.layoutPreviewKind = previewKindFromUrl(targetUrl);
                    bindData.pendingLayoutFile = null;
                    bindData.uploading = false;
                    setInfo("Room saved and layout uploaded.");
                    loadRooms(true);
                }, function (message) {
                    bindData.uploading = false;
                    setError(message || "Layout upload failed.");
                    loadRooms(true);
                });
            }, function (message) {
                bindData.uploading = false;
                setError(message || "Could not update layout link.");
            });
        }).error(function () {
            bindData.uploading = false;
            setError("Room save failed.");
        });
    }

    function persistLayoutReference(room, onSuccess, onError) {
        api.services.SaveClassroom(room).then(function (response) {
            if (response.success) {
                onSuccess(response.result || room);
            } else {
                onError(response.result && response.result.message ? response.result.message : "Could not update layout link.");
            }
        }).error(function () {
            onError("Could not update layout link.");
        });
    }

    function uploadLayout(roomId, onSuccess, onError) {
        if (!bindData.pendingLayoutFile) {
            onSuccess();
            return;
        }

        if (uploader && uploader.services && uploader.services.uploadFile) {
            uploader.services.uploadFile(bindData.pendingLayoutFile, "course_manager_classroom", roomId)
                .then(function () {
                    onSuccess();
                })
                .error(function () {
                    onError("Layout upload failed.");
                });
            return;
        }

        if (uploader && uploader.upload) {
            var file = bindData.pendingLayoutFile;
            file.name = roomId;
            uploader.upload([file], "course_manager_classroom", null, function () {
                onSuccess();
            });
            return;
        }

        onError("Layout uploader is not available.");
    }

    function deleteRoom(room) {
        if (!room || !room.id) {
            return;
        }
        if (!window.confirm("Delete " + (room.name || room.code || "this room") + "?")) {
            return;
        }
        clearMessages();
        api.services.DeleteClassroom(room).then(function (response) {
            if (response.success) {
                if (bindData.selectedRoomId === room.id) {
                    clearForm();
                }
                setInfo("Room deleted.");
                loadRooms(true);
            } else {
                setError(response.result && response.result.message ? response.result.message : "Room delete failed.");
            }
        }).error(function () {
            setError("Room delete failed.");
        });
    }

    function onLayoutPicked(event) {
        var files = event && event.target ? event.target.files : null;
        if (!files || !files.length) {
            return;
        }
        var file = files[0];
        bindData.pendingLayoutFile = file;
        bindData.layoutPreviewKind = file.type && file.type.indexOf("image/") === 0 ? "image" : "file";
        bindData.layoutPreview = createObjectPreview(file);
    }

    function clearLayout() {
        bindData.pendingLayoutFile = null;
        bindData.layoutPreview = bindData.roomForm.layout_url || "";
        bindData.layoutPreviewKind = previewKindFromUrl(bindData.layoutPreview);
    }

    function layoutUrl(roomId) {
        return roomId ? "components/dock/soss-uploader/service/get/course_manager_classroom/" + roomId : "";
    }

    function roomBadgeClass(status) {
        return "cm-pill " + String(status || "active").toLowerCase();
    }

    function roomTypeLabel(type) {
        return type ? String(type) : "room";
    }

    function hasLayoutPreview() {
        return !!bindData.layoutPreview;
    }

    function isImageLayout() {
        return bindData.layoutPreviewKind === "image";
    }

    function navigate(path) {
        if (handler && handler.appNavigate) {
            handler.appNavigate(path);
        } else {
            if (path.indexOf("../") === 0) {
                window.location.hash = "#/app/course-manager/" + path.substring(3);
            } else {
                window.location.hash = "#/app/course-manager" + path;
            }
        }
    }

    function emptyRoom() {
        return {
            code: "",
            name: "",
            capacity: 30,
            location: "",
            type: "room",
            status: "active",
            layout_url: ""
        };
    }

    function previewKindFromUrl(url) {
        var value = String(url || "").toLowerCase();
        if (!value) {
            return "";
        }
        if (value.indexOf(".pdf") !== -1) {
            return "file";
        }
        return "image";
    }

    function createObjectPreview(file) {
        clearLayoutObjectUrl();
        if (window.URL && window.URL.createObjectURL) {
            currentLayoutObjectUrl = window.URL.createObjectURL(file);
            return currentLayoutObjectUrl;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
            bindData.layoutPreview = e.target.result;
        };
        reader.readAsDataURL(file);
        return "";
    }

    function clearLayoutObjectUrl() {
        if (currentLayoutObjectUrl && window.URL && window.URL.revokeObjectURL) {
            window.URL.revokeObjectURL(currentLayoutObjectUrl);
        }
        currentLayoutObjectUrl = "";
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function ensureStyles() {
        if (document.getElementById("course-manager-common-css")) {
            if (!document.getElementById("course-manager-classrooms-css")) {
                injectClassroomCss();
            }
            return;
        }

        var common = document.createElement("link");
        common.id = "course-manager-common-css";
        common.rel = "stylesheet";
        common.type = "text/css";
        common.href = "components/course-manager/course-style/file/course-manager.css?v=0.7";
        document.getElementsByTagName("head")[0].appendChild(common);
        injectClassroomCss();
    }

    function injectClassroomCss() {
        if (document.getElementById("course-manager-classrooms-css")) {
            return;
        }
        var link = document.createElement("link");
        link.id = "course-manager-classrooms-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/course-manager/classrooms/file/classrooms.css?v=0.1";
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
