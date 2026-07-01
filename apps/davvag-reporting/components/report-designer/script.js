WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var routes;
    var report;

    function emptyReport() {
        return {
            code: "",
            title: "",
            description: "",
            namespace: "",
            sql: "",
            parameters: [
                { name: "page", label: "Page", type: "number", defaultValue: "0", required: false },
                { name: "size", label: "Size", type: "number", defaultValue: "50", required: false }
            ],
            fields: [],
            grid: { pageSize: 50, columns: [] },
            chart: { enabled: false, type: "bar", xField: "", yFields: [] }
        };
    }

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
        var status = find("[data-designer-status]");
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

    function cleanCode(value, separator) {
        value = (value || "").toLowerCase().trim().replace(/[^a-z0-9_ -]+/g, separator).replace(/[ _-]+/g, separator);
        return value.replace(new RegExp("^" + separator + "+|" + separator + "+$", "g"), "");
    }

    function labelFromName(name) {
        return (name || "").replace(/[_-]+/g, " ").replace(/\b\w/g, function(match) {
            return match.toUpperCase();
        });
    }

    function routeCode(value) {
        return decodeURIComponent(value || "").split("/")[0];
    }

    function loadReport(code) {
        if (!code) {
            report = emptyReport();
            populateForm();
            setStatus("Ready.", "muted");
            return;
        }
        setStatus("Loading report.", "muted");
        api.services.GetReport({ code: code }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to load report.", "error");
                return;
            }
            report = normalizeLoadedReport(result || emptyReport());
            populateForm();
            setStatus("Report loaded.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to load report.", "error");
        });
    }

    function normalizeLoadedReport(value) {
        value.parameters = Array.isArray(value.parameters) ? value.parameters : [];
        value.fields = Array.isArray(value.fields) ? value.fields : [];
        value.grid = value.grid || { pageSize: 50 };
        value.chart = value.chart || { enabled: false, type: "bar", xField: "", yFields: [] };
        return value;
    }

    function populateForm() {
        find("[data-report-code]").val(report.code || "");
        find("[data-report-title]").val(report.title || "");
        find("[data-report-namespace]").val(report.namespace || "");
        find("[data-report-description]").val(report.description || "");
        find("[data-report-sql]").val(report.sql || "");
        find("[data-grid-page-size]").val(report.grid && report.grid.pageSize ? report.grid.pageSize : 50);
        find("[data-chart-enabled]").prop("checked", !!(report.chart && report.chart.enabled));
        find("[data-chart-type]").val(report.chart && report.chart.type ? report.chart.type : "bar");
        find("[data-chart-y]").val(report.chart && Array.isArray(report.chart.yFields) ? report.chart.yFields.join(",") : "");
        renderParamRows();
        renderFieldRows();
        renderChartOptions();
        find("[data-chart-x]").val(report.chart && report.chart.xField ? report.chart.xField : "");
        find("[data-designer-current]").text(report.code ? report.code + " / " + (report.namespace || "") : "New report");
    }

    function collectReport() {
        syncTables();
        var title = find("[data-report-title]").val();
        var code = cleanCode(find("[data-report-code]").val() || title, "-");
        var namespace = cleanCode(find("[data-report-namespace]").val() || ("rpt_" + code), "_");
        var yFields = (find("[data-chart-y]").val() || "").split(",").map(function(item) {
            return item.trim();
        }).filter(Boolean);
        report.code = code;
        report.title = title || code;
        report.description = find("[data-report-description]").val();
        report.namespace = namespace;
        report.sql = find("[data-report-sql]").val();
        report.grid = { pageSize: parseInt(find("[data-grid-page-size]").val() || "50", 10) || 50 };
        report.chart = {
            enabled: find("[data-chart-enabled]").is(":checked"),
            type: find("[data-chart-type]").val() || "bar",
            xField: find("[data-chart-x]").val() || "",
            yFields: yFields
        };
        find("[data-report-code]").val(report.code);
        find("[data-report-namespace]").val(report.namespace);
        return report;
    }

    function syncTables() {
        var params = [];
        find("[data-param-rows] tr").each(function() {
            var row = $(this);
            var name = cleanField(row.find("[data-param-name]").val());
            if (!name) {
                return;
            }
            params.push({
                name: name,
                label: row.find("[data-param-label]").val() || labelFromName(name),
                type: row.find("[data-param-type]").val() || "text",
                defaultValue: row.find("[data-param-default]").val() || "",
                required: row.find("[data-param-required]").is(":checked")
            });
        });
        report.parameters = params;

        var fields = [];
        find("[data-field-rows] tr").each(function() {
            var row = $(this);
            var fieldName = cleanField(row.find("[data-field-name]").val());
            if (!fieldName) {
                return;
            }
            fields.push({
                fieldName: fieldName,
                label: row.find("[data-field-label]").val() || labelFromName(fieldName),
                dataType: row.find("[data-field-type]").val() || "java.lang.String",
                visible: row.find("[data-field-visible]").is(":checked"),
                format: row.find("[data-field-format]").val() || "",
                maxLen: row.find("[data-field-maxlen]").val() || ""
            });
        });
        report.fields = fields;
    }

    function cleanField(value) {
        value = (value || "").trim().replace(/[^A-Za-z0-9_]+/g, "_").replace(/^_+|_+$/g, "");
        if (value && !/^[A-Za-z_]/.test(value)) {
            value = "field_" + value;
        }
        return value;
    }

    function renderParamRows() {
        var body = find("[data-param-rows]");
        body.empty();
        if (!report.parameters.length) {
            body.append($("<tr>").append($("<td>").attr("colspan", 6).text("No parameters.")));
            return;
        }
        report.parameters.forEach(function(param, index) {
            body.append($("<tr>").attr("data-index", index)
                .append($("<td>").append(input("text", "data-param-name", param.name || "")))
                .append($("<td>").append(input("text", "data-param-label", param.label || "")))
                .append($("<td>").append(select("data-param-type", ["text", "number", "date", "email"], param.type || "text")))
                .append($("<td>").append(input("text", "data-param-default", param.defaultValue || "")))
                .append($("<td>").append($("<input>").attr({ type: "checkbox", "data-param-required": "" }).prop("checked", !!param.required)))
                .append($("<td>").append($("<button>").attr({ type: "button", "data-remove-param": index }).addClass("btn btn-danger btn-xs").html('<i class="fa fa-trash"></i>'))));
        });
    }

    function renderFieldRows() {
        var body = find("[data-field-rows]");
        body.empty();
        if (!report.fields.length) {
            body.append($("<tr>").append($("<td>").attr("colspan", 7).text("No fields.")));
            renderChartOptions();
            return;
        }
        report.fields.forEach(function(field, index) {
            body.append($("<tr>").attr("data-index", index)
                .append($("<td>").append(input("text", "data-field-name", field.fieldName || "")))
                .append($("<td>").append(input("text", "data-field-label", field.label || "")))
                .append($("<td>").append(select("data-field-type", ["java.lang.String", "int", "float", "java.util.Date"], field.dataType || "java.lang.String")))
                .append($("<td>").append($("<input>").attr({ type: "checkbox", "data-field-visible": "" }).prop("checked", field.visible !== false)))
                .append($("<td>").append(input("text", "data-field-format", field.format || "")))
                .append($("<td>").append(input("number", "data-field-maxlen", field.maxLen || "")))
                .append($("<td>").append($("<button>").attr({ type: "button", "data-remove-field": index }).addClass("btn btn-danger btn-xs").html('<i class="fa fa-trash"></i>'))));
        });
        renderChartOptions();
    }

    function renderChartOptions() {
        var selected = find("[data-chart-x]").val();
        var selectBox = find("[data-chart-x]");
        selectBox.empty();
        selectBox.append($("<option>").attr("value", "").text("Select field"));
        (report.fields || []).forEach(function(field) {
            selectBox.append($("<option>").attr("value", field.fieldName).text(field.label || field.fieldName));
        });
        if (selected) {
            selectBox.val(selected);
        } else if (report.chart && report.chart.xField) {
            selectBox.val(report.chart.xField);
        }
    }

    function input(type, attr, value) {
        return $("<input>").attr({ type: type }).attr(attr, "").addClass("form-control input-sm").val(value);
    }

    function select(attr, options, value) {
        var element = $("<select>").attr(attr, "").addClass("form-control input-sm");
        options.forEach(function(option) {
            element.append($("<option>").attr("value", option).text(option));
        });
        element.val(value);
        return element;
    }

    function addParameter() {
        syncTables();
        report.parameters.push({ name: "", label: "", type: "text", defaultValue: "", required: false });
        renderParamRows();
    }

    function addField() {
        syncTables();
        report.fields.push({ fieldName: "", label: "", dataType: "java.lang.String", visible: true, format: "", maxLen: "" });
        renderFieldRows();
    }

    function inferFields() {
        collectReport();
        if (!report.sql) {
            setStatus("SQL query is required before inference.", "error");
            return;
        }
        setStatus("Inferring parameters and fields.", "muted");
        api.services.InferFields({ sql: report.sql }).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to infer fields.", "error");
                return;
            }
            mergeParameters(result.parameters || []);
            if (!report.fields.length || window.confirm("Replace current fields with inferred fields?")) {
                report.fields = result.fields || [];
            }
            populateForm();
            setStatus("Inference complete.", "success");
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to infer fields.", "error");
        });
    }

    function mergeParameters(params) {
        var seen = {};
        report.parameters.forEach(function(param) {
            if (param.name) {
                seen[param.name] = true;
            }
        });
        params.forEach(function(param) {
            if (param.name && !seen[param.name]) {
                report.parameters.push(param);
                seen[param.name] = true;
            }
        });
    }

    function saveReport(callback) {
        collectReport();
        if (!report.code || !report.title || !report.sql) {
            setStatus("Report code, title, and SQL are required.", "error");
            return;
        }
        setStatus("Saving report and generated schema.", "muted");
        api.services.SaveReport(report).then(function(response) {
            var result = serviceResult(response);
            if (result && result.success === false) {
                setStatus(result.message || "Unable to save report.", "error");
                return;
            }
            report = normalizeLoadedReport(result);
            populateForm();
            setStatus("Report saved.", "success");
            if (callback) {
                callback(report);
            }
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Unable to save report.", "error");
        });
    }

    function previewReport() {
        saveReport(function(saved) {
            var params = {};
            (saved.parameters || []).forEach(function(param) {
                params[param.name] = param.defaultValue || "";
            });
            setStatus("Running preview.", "muted");
            api.services.RunReport({ code: saved.code, parameters: params }).then(function(response) {
                var result = serviceResult(response);
                if (result && result.success === false) {
                    setStatus(result.message || "Preview failed.", "error");
                    return;
                }
                renderPreview(result.columns || [], result.rows || []);
                setStatus("Preview loaded.", "success");
            }).error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Preview failed.", "error");
            });
        });
    }

    function renderPreview(columns, rows) {
        var output = find("[data-preview-output]");
        output.empty();
        if (!rows.length) {
            output.append($("<div>").addClass("davvag-reporting__empty").text("No rows returned."));
            return;
        }
        if (!columns.length) {
            Object.keys(rows[0] || {}).forEach(function(key) {
                columns.push({ field: key, label: labelFromName(key) });
            });
        }
        var table = $("<table>").addClass("davvag-reporting__table");
        var head = $("<tr>");
        columns.forEach(function(column) {
            head.append($("<th>").text(column.label || column.field));
        });
        table.append($("<thead>").append(head));
        var body = $("<tbody>");
        rows.slice(0, 50).forEach(function(row) {
            var tr = $("<tr>");
            columns.forEach(function(column) {
                tr.append($("<td>").text(valueFor(row, column.field)));
            });
            body.append(tr);
        });
        table.append(body);
        output.append($("<div>").addClass("davvag-reporting__table-wrap").append(table));
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

    function bindEvents() {
        find("[data-designer-back]").on("click", function() {
            navigate("/reports");
        });
        find("[data-designer-new]").on("click", function() {
            report = emptyReport();
            populateForm();
            renderPreview([], []);
            setStatus("Ready.", "muted");
        });
        find("[data-designer-save]").on("click", function() {
            saveReport();
        });
        find("[data-designer-preview]").on("click", previewReport);
        find("[data-designer-infer]").on("click", inferFields);
        find("[data-add-param]").on("click", addParameter);
        find("[data-add-field]").on("click", addField);
        find("[data-param-rows]").on("click", "[data-remove-param]", function() {
            syncTables();
            report.parameters.splice(parseInt($(this).attr("data-remove-param"), 10), 1);
            renderParamRows();
        });
        find("[data-field-rows]").on("click", "[data-remove-field]", function() {
            syncTables();
            report.fields.splice(parseInt($(this).attr("data-remove-field"), 10), 1);
            renderFieldRows();
        });
        find("[data-report-title]").on("blur", function() {
            if (!find("[data-report-code]").val()) {
                find("[data-report-code]").val(cleanCode($(this).val(), "-"));
            }
            if (!find("[data-report-namespace]").val()) {
                find("[data-report-namespace]").val("rpt_" + cleanCode($(this).val(), "_"));
            }
        });
        find("[data-field-rows]").on("change input", "input,select", function() {
            syncTables();
            renderChartOptions();
        });
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("report-api");
        routes = exports.getShellComponent("soss-routes");
        report = emptyReport();
        bindEvents();
        var input = routes && routes.getInputData ? routes.getInputData() : {};
        loadReport(input && input.code ? routeCode(input.code) : "");
    };
});
