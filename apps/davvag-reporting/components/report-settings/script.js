WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var routes;
    var defaults = {
        headerHtml: "",
        footerHtml: '<div style="text-align:right;font-size:10px;color:#777;">Page @pageNumber of @pageCount</div>'
    };

    function find(selector) {
        return root.find(selector);
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: response && response.result ? response.result : "DAVVAG service call failed."
            };
        }
        if (response.result && response.result.success === false) {
            return response.result;
        }
        return response.result || {};
    }

    function setStatus(message, tone) {
        var status = find("[data-settings-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function navigate(path) {
        path = path || "/";
        if (path.charAt(0) !== "/") {
            path = "/" + path;
        }

        var baseUrl = location.protocol + "//" + location.host + location.pathname + (location.search || "");
        if ((location.hash || "").indexOf("#/app/davvag-reporting") === 0) {
            window.location.href = baseUrl + "#/app/davvag-reporting" + path;
            return;
        }
        window.location.href = baseUrl + "#" + path;
    }

    function loadSettings() {
        if (!api || !api.services || !api.services.GetPdfSettings) {
            setStatus("Report API is not loaded.", "error");
            return;
        }
        setStatus("Loading settings.", "muted");
        api.services.GetPdfSettings().then(function(response) {
            var result = serviceResult(response);
            if (result.success === false) {
                setStatus(result.message || "Unable to load settings.", "error");
                return;
            }
            populate(result);
            setStatus("Settings loaded.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to load settings.", "error");
        });
    }

    function populate(settings) {
        find("[data-pdf-header]").val(settings.headerHtml || "");
        find("[data-pdf-footer]").val(settings.footerHtml || "");
        renderPreview();
    }

    function collectSettings() {
        return {
            headerHtml: find("[data-pdf-header]").val() || "",
            footerHtml: find("[data-pdf-footer]").val() || ""
        };
    }

    function saveSettings() {
        setStatus("Saving settings.", "muted");
        api.services.SavePdfSettings(collectSettings()).then(function(response) {
            var result = serviceResult(response);
            if (result.success === false) {
                setStatus(result.message || "Unable to save settings.", "error");
                return;
            }
            populate(result);
            setStatus("PDF settings saved.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to save settings.", "error");
        });
    }

    function renderPreview() {
        find("[data-preview-header]").html(templatePreview(find("[data-pdf-header]").val()));
        find("[data-preview-footer]").html(templatePreview(find("[data-pdf-footer]").val()));
    }

    function templatePreview(html) {
        return (html || "")
            .replace(/@reportTitle/g, "Sales Example")
            .replace(/@reportCode/g, "sales-example")
            .replace(/@namespace/g, "rpt_sales_example")
            .replace(/@generatedAt/g, "2026-06-29 00:00:00 UTC")
            .replace(/@pageNumber/g, "1")
            .replace(/@pageCount/g, "4");
    }

    function bindEvents() {
        find("[data-settings-back]").on("click", function() {
            navigate("/reports");
        });
        find("[data-settings-reset]").on("click", function() {
            populate(defaults);
            setStatus("Defaults restored in the editor. Save to apply them.", "muted");
        });
        find("[data-settings-save]").on("click", saveSettings);
        find("[data-pdf-header], [data-pdf-footer]").on("input", renderPreview);
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("report-api");
        routes = exports.getShellComponent("soss-routes");
        bindEvents();
        loadSettings();
    };
});
