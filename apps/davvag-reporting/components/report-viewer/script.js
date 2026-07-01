WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var routes;
    var report = null;
    var rows = [];
    var columns = [];

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
        return response.result;
    }

    function setStatus(message, tone) {
        var status = find("[data-view-status]");
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

    function loadReport(code) {
        if (!code) {
            setStatus("Select a report from the report list.", "error");
            return;
        }
        setStatus("Loading report.", "muted");
        api.services.GetReport({ code: code }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to load report.", "error");
                return;
            }
            report = result;
            renderReportShell();
            runReport();
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to load report.", "error");
        });
    }

    function renderReportShell() {
        find("[data-view-title]").text(report.title || report.code);
        find("[data-view-meta]").text((report.code || "") + " / " + (report.namespace || ""));
        find("[data-namespace]").text(report.namespace || "-");
        find("[data-email-subject]").val("DAVVAG report: " + (report.title || report.code));
        renderFilters();
    }

    function renderFilters() {
        var container = find("[data-view-filters]");
        container.empty();
        var params = Array.isArray(report.parameters) ? report.parameters : [];
        if (!params.length) {
            container.append($("<div>").addClass("davvag-reporting__empty").text("No filters."));
            return;
        }
        params.forEach(function(param) {
            var inputType = param.type === "number" ? "number" : (param.type === "date" ? "date" : (param.type === "email" ? "email" : "text"));
            var field = $("<label>").addClass("davvag-reporting__field");
            field.append($("<span>").text(param.label || param.name));
            field.append($("<input>").attr({
                type: inputType,
                "data-filter-name": param.name
            }).addClass("form-control").val(param.defaultValue || ""));
            container.append(field);
        });
    }

    function collectParameters() {
        var params = {};
        find("[data-filter-name]").each(function() {
            params[$(this).attr("data-filter-name")] = $(this).val();
        });
        return params;
    }

    function runReport() {
        if (!report) {
            return;
        }
        setStatus("Running report.", "muted");
        api.services.RunReport({
            code: report.code,
            parameters: collectParameters()
        }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Report failed.", "error");
                return;
            }
            rows = result.rows || [];
            columns = result.columns || [];
            if (!columns.length && rows.length) {
                Object.keys(rows[0]).forEach(function(key) {
                    columns.push({ field: key, label: labelFromName(key) });
                });
            }
            renderGrid();
            renderChart();
            find("[data-row-count]").text(rows.length);
            find("[data-column-count]").text(columns.length);
            setStatus("Report loaded.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Report failed.", "error");
        });
    }

    function renderGrid() {
        var output = find("[data-grid-output]");
        output.empty();
        if (!rows.length) {
            output.append($("<div>").addClass("davvag-reporting__empty").text("No rows returned."));
            return;
        }
        var table = $("<table>").addClass("davvag-reporting__table");
        var head = $("<tr>");
        columns.forEach(function(column) {
            head.append($("<th>").text(column.label || column.field));
        });
        table.append($("<thead>").append(head));
        var body = $("<tbody>");
        rows.forEach(function(row) {
            var tr = $("<tr>");
            columns.forEach(function(column) {
                tr.append($("<td>").text(valueFor(row, column.field)));
            });
            body.append(tr);
        });
        table.append(body);
        output.append(table);
    }

    function renderChart() {
        var panel = find("[data-chart-panel]");
        var output = find("[data-chart-output]");
        var chart = report && report.chart ? report.chart : {};
        output.empty();
        if (!chart.enabled) {
            panel.hide();
            return;
        }
        panel.show();
        var yField = Array.isArray(chart.yFields) && chart.yFields.length ? chart.yFields[0] : "";
        if (!chart.xField || !yField || !rows.length) {
            output.append($("<div>").addClass("davvag-reporting__empty").text("No chart data."));
            return;
        }
        var max = 0;
        rows.forEach(function(row) {
            max = Math.max(max, numberValue(valueFor(row, yField)));
        });
        if (!max) {
            output.append($("<div>").addClass("davvag-reporting__empty").text("No numeric chart values."));
            return;
        }
        rows.slice(0, 20).forEach(function(row) {
            var value = numberValue(valueFor(row, yField));
            var width = Math.max(2, Math.round((value / max) * 100));
            output.append($("<div>").addClass("davvag-reporting__bar-row")
                .append($("<div>").addClass("davvag-reporting__bar-label").attr("title", valueFor(row, chart.xField)).text(valueFor(row, chart.xField)))
                .append($("<div>").addClass("davvag-reporting__bar-track").append($("<div>").addClass("davvag-reporting__bar-fill").css("width", width + "%")))
                .append($("<div>").text(formatNumber(value))));
        });
    }

    function exportReport(format) {
        if (!report) {
            return;
        }
        setStatus("Preparing " + format.toUpperCase() + " export.", "muted");
        api.services.ExportReport({
            code: report.code,
            parameters: collectParameters(),
            format: format
        }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Export failed.", "error");
                return;
            }
            downloadExport(result);
            setStatus(result.note || "Export ready.", result.note ? "muted" : "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Export failed.", "error");
        });
    }

    function downloadExport(result) {
        var binary = atob(result.content || "");
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        var blob = new Blob([bytes], { type: result.mime || "application/octet-stream" });
        var url = URL.createObjectURL(blob);
        var link = document.createElement("a");
        link.href = url;
        link.download = result.filename || "report-export";
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function emailReport() {
        if (!report) {
            return;
        }
        setStatus("Sending report email.", "muted");
        api.services.EmailReport({
            code: report.code,
            parameters: collectParameters(),
            format: find("[data-email-format]").val(),
            toName: find("[data-email-name]").val(),
            toEmail: find("[data-email-address]").val(),
            subject: find("[data-email-subject]").val(),
            message: find("[data-email-message]").val()
        }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Email failed.", "error");
                return;
            }
            setStatus("Report email sent.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Email failed.", "error");
        });
    }

    function valueFor(row, field) {
        if (!row || typeof row[field] === "undefined" || row[field] === null) {
            return "";
        }
        if (typeof row[field] === "object") {
            return JSON.stringify(row[field]);
        }
        return row[field];
    }

    function labelFromName(name) {
        return (name || "").replace(/[_-]+/g, " ").replace(/\b\w/g, function(match) {
            return match.toUpperCase();
        });
    }

    function routeCode(value) {
        return decodeURIComponent(value || "").split("/")[0];
    }

    function numberValue(value) {
        var parsed = parseFloat(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function formatNumber(value) {
        if (value.toLocaleString) {
            return value.toLocaleString();
        }
        return String(value);
    }

    function bindEvents() {
        find("[data-view-back]").on("click", function() {
            navigate("/reports");
        });
        find("[data-view-edit]").on("click", function() {
            if (report) {
                navigate("/designer?code=" + encodeURIComponent(report.code));
            }
        });
        find("[data-view-run]").on("click", runReport);
        find("[data-export-format]").on("click", function() {
            exportReport($(this).attr("data-export-format"));
        });
        find("[data-email-toggle]").on("click", function() {
            find("[data-email-panel]").toggleClass("is-open");
        });
        find("[data-email-send]").on("click", emailReport);
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("report-api");
        routes = exports.getShellComponent("soss-routes");
        bindEvents();
        var input = routes && routes.getInputData ? routes.getInputData() : {};
        loadReport(input && input.code ? routeCode(input.code) : "");
    };
});
