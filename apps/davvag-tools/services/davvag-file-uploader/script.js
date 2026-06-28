
WEBDOCK.component().register(function(exports) {
    var modalId = "davvagfileupload";
    var $modal = null;
    var $progressBar = null;
    var $closeButton = null;

    exports.initialize = function() {
        createModal();
    };

    exports.close = function() {
        hideModal();
    };

    exports.upload = function(newfiles, classname, id, cb) {
        uploadFiles(newfiles, classname, id, cb, "upload");
    };

    exports.upload_uncompressed = function(newfiles, classname, id, cb) {
        uploadFiles(newfiles, classname, id, cb, "upload_uncompressed");
    };

    function createModal() {
        clearModal();
        $("body").append([
            "<div id='", modalId, "' class='modal fade davvag-file-upload-modal' tabindex='-1' role='dialog' aria-labelledby='davvagfileupload-title' aria-hidden='true'>",
                "<div class='modal-dialog' role='document'>",
                    "<div class='modal-content'>",
                        "<div class='modal-header'>",
                            "<h5 id='davvagfileupload-title' class='modal-title'>Uploading, please wait</h5>",
                        "</div>",
                        "<div id='davvagfileupload-body' class='modal-body'>",
                            "<div class='progress'>",
                                "<div id='davvagfileupload-progress' class='progress-bar progress-bar-striped progress-bar-animated' role='progressbar' aria-valuenow='0' aria-valuemin='0' aria-valuemax='100' style='width: 0%'>0%</div>",
                            "</div>",
                            "<div id='davvagfileupload-status' class='davvag-file-upload-status'></div>",
                        "</div>",
                        "<div class='modal-footer'>",
                            "<button type='button' id='davvagfileupload-close' class='btn btn-secondary' data-dismiss='modal' data-bs-dismiss='modal'>Close</button>",
                        "</div>",
                    "</div>",
                "</div>",
            "</div>"
        ].join(""));

        $modal = $("#" + modalId);
        $progressBar = $("#davvagfileupload-progress");
        $closeButton = $("#davvagfileupload-close");
        $closeButton.off("click.davvagFileUpload").on("click.davvagFileUpload", function(event) {
            event.preventDefault();
            hideModal();
        });
        resetModal();
    }

    function clearModal() {
        var modal = document.getElementById(modalId);
        if (modal) {
            $(modal).modal("hide");
            modal.remove();
        }
    }

    function resetModal() {
        ensureModal();
        $("#davvagfileupload-title").text("Uploading, please wait");
        $("#davvagfileupload-status").empty();
        $progressBar.width("0%").attr("aria-valuenow", 0).text("0%");
        $closeButton.css("visibility", "hidden");
    }

    function ensureModal() {
        if (!$modal || $modal.length === 0 || document.getElementById(modalId) === null) {
            createModal();
        }
    }

    function uploadFiles(newfiles, classname, id, cb, methodName) {
        var callback = typeof cb === "function" ? cb : function() {};
        var files = normalizeFiles(newfiles);
        if (files.length === 0) {
            callback(newfiles);
            return;
        }

        resetModal();
        $modal.modal({ backdrop: "static", keyboard: false });

        var uploaderInstance = exports.getShellComponent("soss-uploader");
        if (!uploaderInstance || !uploaderInstance.services || typeof uploaderInstance.services[methodName] !== "function") {
            complete(files.length, files.length, "Upload service is not available.");
            callback(newfiles);
            return;
        }

        var completed = 0;
        var failed = 0;
        files.forEach(function(file) {
            var filename = buildFileName(id, file);
            var request = uploaderInstance.services[methodName](file, classname, filename);
            attachUploadHandlers(request, function(result) {
                markFile(file, true, result);
                completed++;
                updateProgress(completed, files.length);
                if (completed === files.length) {
                    complete(completed, failed, "");
                    callback(newfiles);
                }
            }, function(error) {
                markFile(file, false, error);
                completed++;
                failed++;
                updateProgress(completed, files.length);
                if (completed === files.length) {
                    complete(completed, failed, "");
                    callback(newfiles);
                }
            });
        });
    }

    function attachUploadHandlers(request, onSuccess, onError) {
        var handled = false;
        function success(result) {
            if (handled) {
                return;
            }
            handled = true;
            onSuccess(result);
        }
        function fail(error) {
            if (handled) {
                return;
            }
            handled = true;
            onError(error);
        }

        if (!request) {
            fail("Upload request was not created.");
            return;
        }

        try {
            var next = null;
            if (typeof request.success === "function") {
                next = request.success(success);
            } else if (typeof request.then === "function") {
                next = request.then(success);
            } else if (typeof request.done === "function") {
                next = request.done(success);
            }
            attachFailureHandler(next || request, fail);
            if (typeof request.success !== "function" && typeof request.then !== "function" && typeof request.done !== "function") {
                fail("Upload request is not awaitable.");
            }
        } catch (error) {
            fail(error);
        }
    }

    function attachFailureHandler(request, fail) {
        if (!request) {
            return;
        }
        if (typeof request.error === "function") {
            request.error(fail);
        } else if (typeof request.fail === "function") {
            request.fail(fail);
        } else if (typeof request.catch === "function") {
            request.catch(fail);
        }
    }

    function normalizeFiles(files) {
        if (!files) {
            return [];
        }
        if (files.name && files.size !== undefined) {
            return [files];
        }
        return Array.prototype.slice.call(files);
    }

    function buildFileName(id, file) {
        var name = file && file.name ? file.name : "file";
        if (file && file.uploadName !== undefined && file.uploadName !== null && String(file.uploadName) !== "") {
            return String(file.uploadName);
        }
        return id !== undefined && id !== null && String(id) !== "" ? String(id) + "-" + name : name;
    }

    function markFile(file, status, result) {
        if (!file) {
            return;
        }
        file.status = status;
        file.result = status ? result : null;
        file.error = status ? null : result;
    }

    function updateProgress(completed, total) {
        var percent = total > 0 ? Math.round((completed / total) * 100) : 100;
        var percentage = percent + "%";
        $progressBar.width(percentage).attr("aria-valuenow", percent).text(percentage);
    }

    function complete(completed, failed, message) {
        $("#davvagfileupload-title").text(failed > 0 ? "Upload completed with errors" : "Upload completed");
        $("#davvagfileupload-status").text(message || (failed > 0
            ? failed + " of " + completed + " file(s) failed to upload."
            : "You may close this window. Upload completed successfully."));
        $closeButton.css("visibility", "visible");
    }

    function hideModal() {
        if (!$modal || $modal.length === 0) {
            return;
        }

        if (typeof $modal.modal === "function") {
            $modal.modal("hide");
        } else if (window.bootstrap && window.bootstrap.Modal) {
            var modalApi = window.bootstrap.Modal;
            var instance = null;
            if (typeof modalApi.getInstance === "function") {
                instance = modalApi.getInstance($modal[0]);
            }
            if (!instance && typeof modalApi.getOrCreateInstance === "function") {
                instance = modalApi.getOrCreateInstance($modal[0]);
            }
            if (!instance && typeof modalApi === "function") {
                instance = new modalApi($modal[0]);
            }
            if (instance && typeof instance.hide === "function") {
                instance.hide();
            }
        }

        setTimeout(function() {
            if (!$modal || $modal.length === 0 || !$modal.is(":visible")) {
                return;
            }
            $modal.removeClass("show").hide().attr("aria-hidden", "true").removeAttr("aria-modal");
            $(".modal-backdrop").remove();
            $("body").removeClass("modal-open").css("padding-right", "");
        }, 150);
    }
});
