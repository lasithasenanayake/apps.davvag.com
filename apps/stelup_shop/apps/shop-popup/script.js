WEBDOCK.component().register(function (exports) {
    var owned = [];

    function open(app, component, data, title, onAction) {
        var popup = exports.getShellComponent("app_popup");
        if (!popup || typeof popup.open !== "function") {
            throw new Error("The framework app_popup component is not loaded.");
        }
        var id = app + "-" + component + "dialog_popup";
        var finished = false;
        // The framework shell renders the title as HTML. Pass escaped text.
        var safeTitle = $("<div>").text(title || "Stelup").html();
        popup.open(app, component, data || {}, function (action, instance) {
            if (!action || !action.method || finished) {
                return;
            }
            finished = true;
            var modal = $(document.getElementById(id));
            var next = function () {};
            if (action.method === "app_open") {
                next = function () {
                    open(action.appname, action.form, action.data, action.apptitle, onAction);
                };
            } else if (action.method === "url_redirect" || action.method === "buy_item") {
                next = function () {
                    window.location = action.url || "#/app/stelup_shop/checkout";
                };
            }
            if (typeof onAction === "function") {
                onAction(action);
            }
            // Register before close: CMS's modal fallback can hide synchronously.
            modal.one("hidden.bs.modal.stelupAction", function () {
                window.setTimeout(next, 0);
            });
            if (instance && typeof instance.close === "function") {
                instance.close();
            } else {
                modal.modal("hide");
            }
        }, safeTitle, true, false);
        var modal = $(document.getElementById(id));
        owned.push(modal);
        modal.one("hidden.bs.modal.stelupCleanup", function () {
            finished = true;
            modal.find("*").addBack().each(function () {
                if (this.__vue__ && !this.__vue__._isDestroyed) {
                    this.__vue__.$destroy();
                }
            });
            owned = owned.filter(function (entry) { return entry !== modal; });
            // Keep the element through all hidden handlers, then dispose it.
            window.setTimeout(function () { modal.remove(); }, 0);
        });
    }

    window.addEventListener("hashchange", function () {
        owned.slice().forEach(function (modal) { modal.modal("hide"); });
    });
    exports.open = open;
});
