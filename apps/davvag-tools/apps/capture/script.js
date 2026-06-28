WEBDOCK.component().register(function(exports) {
    var root = null;
    var player = null;
    var snapshotCanvas = null;
    var stream = null;

    var bindData = {
        cameraReady: false,
        capturing: false,
        submitErrors: [],
        submitInfo: []
    };

    exports.vue = {
        data: bindData,
        methods: {
            capture: capture,
            retry: startCamera,
            cancel: cancel
        },
        onReady: function() {
            initialize();
        }
    };

    exports.onReady = function() {};
    exports.onDestroy = stopCamera;

    function initialize() {
        root = exports.renderDiv ? $(exports.renderDiv) : $("[data-capture-root]").last();
        player = root.find("[data-capture-player]")[0];
        snapshotCanvas = root.find("[data-capture-snapshot]")[0];
        startCamera();
    }

    function startCamera() {
        clearMessages();
        bindData.capturing = true;
        bindData.cameraReady = false;
        stopCamera();

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            bindData.capturing = false;
            setError("Camera capture is not supported by this browser.");
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(mediaStream) {
                stream = mediaStream;
                if (player) {
                    player.srcObject = stream;
                }
                bindData.cameraReady = true;
                bindData.capturing = false;
            })
            .catch(function() {
                bindData.capturing = false;
                setError("Could not access the camera. Please check browser permissions and try again.");
            });
    }

    function capture() {
        clearMessages();
        if (!player || !snapshotCanvas || !stream) {
            setError("Camera is not ready yet.");
            return;
        }

        var width = player.videoWidth || snapshotCanvas.width || 320;
        var height = player.videoHeight || snapshotCanvas.height || 240;
        snapshotCanvas.width = width;
        snapshotCanvas.height = height;

        var context = snapshotCanvas.getContext("2d");
        context.drawImage(player, 0, 0, width, height);
        var dataUrl = snapshotCanvas.toDataURL("image/png");
        stopCamera();

        if (typeof exports.Complete === "function") {
            exports.Complete(dataUrl);
        }
    }

    function cancel() {
        stopCamera();
        if (typeof exports.Complete === "function") {
            exports.Complete(null);
        }
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(function(track) {
                track.stop();
            });
            stream = null;
        }
        if (player) {
            player.srcObject = null;
        }
        bindData.cameraReady = false;
    }

    function setError(message) {
        bindData.submitErrors = [message];
        bindData.submitInfo = [];
    }

    function clearMessages() {
        bindData.submitErrors = [];
        bindData.submitInfo = [];
    }
});
