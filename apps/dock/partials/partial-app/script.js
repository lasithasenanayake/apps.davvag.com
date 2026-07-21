WEBDOCK.component().register(function (exports) {

    var pInstance = exports.getShellComponent("soss-routes");
    var routeSettings = pInstance.getSettings();
    var loadSequence = 0;
    var activeLoad;
    var vueFailures = {};

    var vueData = {
        methods: {
        },
        data: {
            appName: undefined
        },
        deferRendering: true,
        onReady: function (s, renderDiv, variables) {
            var appName = variables.routeParams.appName;
            var subRoute = variables.routeParams.appRoute;
            downloadApp(appName, subRoute);
        }
    }

    exports.vue = vueData;
    exports.onReady = function () {

    }

    installVueErrorBoundary();

    function downloadApp(appId, subRoute) {
        var load = beginLoad(appId, subRoute);
        try {


            var leftMenu = exports.getComponent("left-menu");
            WEBDOCK.freezeUiComponent("left-menu", true);
            leftMenu.getApps(function (apps) {
                if (!isActive(load))
                    return;

                try {
                var appObj = apps[appId];
                var startupComponent;

                if (!appObj || !appObj.config || !appObj.config.webdock) {
                    failLoad(load, new Error("The app descriptor was not found or has no Webdock configuration."), "app descriptor");
                    return;
                }

                if (appObj.config.webdock)
                    if (appObj.config.webdock.routes)
                        if (appObj.config.webdock.routes.partials)
                            if (appObj.config.webdock.routes.partials[subRoute])
                                startupComponent = appObj.config.webdock.routes.partials[subRoute];

                if (!startupComponent)
                    startupComponent = appObj.config.webdock.startupComponent;

                if (!startupComponent) {
                    failLoad(load, new Error("No startup component is registered for this route."), "route");
                    return;
                }


                var renderDiv = $("#" + routeSettings.routes.renderDiv);
                renderDiv.empty();
                showLoadingBar(renderDiv);

                WEBDOCK.componentManager.downloadAppDescriptor(appId, function (descriptor) {
                    if (!isActive(load))
                        return;
                    if (!descriptor) {
                        failLoad(load, new Error("The application descriptor could not be downloaded."), "app descriptor");
                        return;
                    }
                    WEBDOCK.componentManager.downloadComponents(appId, descriptor, function () {
                        WEBDOCK.componentManager.getOnDemand(appId, descriptor, startupComponent, function (results, desc, instance) {
                            if (!isActive(load))
                                return;
                            try {
                                renderApp(results, desc, instance, load);
                                finishLoad(load);
                            } catch (error) {
                                failLoad(load, error, "component render");
                            }
                        }, appObj.version);
                    }, appObj.version);
                }, appObj.version);
                } catch (error) {
                    failLoad(load, error, "route setup");
                }
            });
        } catch (e) {
            failLoad(load, e, "application load");
        }

    }

    function showLoadingBar(renderDiv) {
        renderDiv.empty();
        renderDiv.append("<div style='left:50%;top:50%;position:fixed;' role='status' aria-label='Loading application'><i class='fa fa-spinner fa-spin'></i></div>");
    }

    function renderApp(data, desc, instance, load) {
        var renderDiv = $("#" + routeSettings.routes.renderDiv);
        var view;

        if (!instance)
            throw new Error("The component script did not register a component instance.");
        if (!data || typeof data.length === "undefined")
            throw new Error("The component resources could not be loaded.");

        for (var i = 0; i < data.length; i++)
            if (data[i] && data[i].object && data[i].object.type === "mainView")
                view = data[i].object.view;

        if (typeof view !== "string")
            throw new Error("The component has no usable mainView resource.");

        var viewJQuery = $(view);
        renderDiv.empty();

        if (!instance.deferredVue) {
            renderDiv.html(view);
            renderDiv.attr("style", "animation: fadein 0.2s");
        }

        if (typeof instance.onLoad === "function")
            instance.onLoad(instance);

        var canCallOnReady = true;
        if (instance.vue) {
            ensureRenderDivId(renderDiv);
            prepareVueOptions(instance.vue, load);
            instance.vue.el = '#' + renderDiv.attr('id');
            var vueInstance = new Vue(instance.vue);
            if (vueFailures[load.id]) {
                var mountError = vueFailures[load.id];
                delete vueFailures[load.id];
                throw mountError;
            }
            var scope = instance.vue.data;
            canCallOnReady = false;

            instance.Complete = function (result) {
                instance.onStatusChange(result);
            };
            instance.renderDiv = renderDiv;
            if (typeof instance.vue.onReady === "function")
                instance.vue.onReady(scope, renderDiv, vueInstance);
        }

        if (instance.deferredVue) {
            canCallOnReady = false;
            instance.deferredVue(function (deferredOptions) {
                if (load.id !== loadSequence)
                    return;
                try {
                    renderDiv.html(viewJQuery);
                    renderDiv.attr("style", "animation: fadein 0.2s");
                    ensureRenderDivId(renderDiv);
                    prepareVueOptions(deferredOptions, load);
                    deferredOptions.el = '#' + renderDiv.attr('id');
                    var deferredVue = new Vue(deferredOptions);
                    if (typeof deferredOptions.onReady === "function")
                        deferredOptions.onReady(deferredOptions.data, renderDiv, deferredVue);
                } catch (error) {
                    handleVueError(error, null, "deferred component mount", load);
                }
            }, viewJQuery);
        }

        if (canCallOnReady && typeof instance.onReady === "function")
            instance.onReady(renderDiv);
    }

    function beginLoad(appId, subRoute) {
        if (activeLoad && activeLoad.timeoutId)
            clearTimeout(activeLoad.timeoutId);

        var load = {
            id: ++loadSequence,
            appId: appId,
            subRoute: subRoute || "",
            timeoutId: null
        };

        activeLoad = load;
        load.timeoutId = setTimeout(function () {
            failLoad(load, new Error("The application took too long to load."), "timeout");
        }, 20000);
        return load;
    }

    function isActive(load) {
        return activeLoad && activeLoad.id === load.id;
    }

    function finishLoad(load) {
        if (!isActive(load))
            return;
        clearTimeout(load.timeoutId);
        activeLoad = null;
        WEBDOCK.freezeUiComponent("left-menu", false);
    }

    function failLoad(load, error, phase) {
        if (!isActive(load))
            return;
        console.error("DAVVAG app boundary caught an error during " + phase + ":", error);
        renderFailure(load.appId, load.subRoute, error, phase);
        finishLoad(load);
    }

    function ensureRenderDivId(renderDiv) {
        if (!renderDiv.attr('id'))
            renderDiv.attr('id', "sossroutes_" + (new Date()).getTime());
    }

    function prepareVueOptions(options, load) {
        if (!options || typeof options !== "object")
            throw new Error("The component supplied invalid Vue options.");
        options.__davvagErrorContext = {
            loadId: load.id,
            appId: load.appId,
            subRoute: load.subRoute
        };
    }

    function installVueErrorBoundary() {
        if (typeof Vue === "undefined" || !Vue.config || window.__davvagVueBoundaryInstalled)
            return;

        var previousHandler = Vue.config.errorHandler;
        Vue.config.errorHandler = function (error, vm, info) {
            var context = vm && vm.$options ? vm.$options.__davvagErrorContext : null;
            var handled = false;

            if (context && typeof window.__davvagHandleVueError === "function")
                handled = window.__davvagHandleVueError(error, vm, info, context) === true;

            if (typeof previousHandler === "function") {
                try {
                    previousHandler(error, vm, info);
                } catch (handlerError) {
                    console.error("The previous Vue error handler failed:", handlerError);
                }
            }

            if (!handled)
                console.error("Unhandled Vue error during " + info + ":", error);
        };
        window.__davvagVueBoundaryInstalled = true;
    }

    window.__davvagHandleVueError = function (error, vm, info, context) {
        if (!context || context.loadId !== loadSequence)
            return false;
        handleVueError(error, vm, info, {
            id: context.loadId,
            appId: context.appId,
            subRoute: context.subRoute
        });
        return true;
    };

    function handleVueError(error, vm, info, load) {
        console.error("DAVVAG isolated a Vue component error during " + info + ":", error);
        vueFailures[load.id] = error;
        if (!activeLoad || activeLoad.id !== load.id) {
            renderFailure(load.appId, load.subRoute, error, "Vue " + info);
            delete vueFailures[load.id];
        }
        WEBDOCK.freezeUiComponent("left-menu", false);
    }

    function renderFailure(appId, subRoute, error, phase) {
        var renderDiv = $("#" + routeSettings.routes.renderDiv);
        var panel = $("<section></section>").attr("role", "alert").css({
            maxWidth: "720px",
            margin: "48px auto",
            padding: "28px",
            background: "#ffffff",
            border: "1px solid #e2e8f0",
            borderLeft: "5px solid #c0392b",
            borderRadius: "8px",
            boxShadow: "0 8px 24px rgba(15, 23, 42, 0.08)"
        });

        $("<h3></h3>").text("This application could not be displayed").css({marginTop: 0}).appendTo(panel);
        $("<p></p>").text("The rest of DAVVAG is still available. Retry this screen or open another application from the menu.").appendTo(panel);
        $("<p></p>").append($("<strong></strong>").text("App: ")).append(document.createTextNode(appId || "unknown")).appendTo(panel);
        $("<p></p>").append($("<strong></strong>").text("Stage: ")).append(document.createTextNode(phase || "unknown")).appendTo(panel);

        var details = $("<details></details>").css({margin: "16px 0"}).appendTo(panel);
        $("<summary></summary>").text("Technical details").appendTo(details);
        $("<pre></pre>").text(error && error.message ? error.message : String(error || "Unknown error")).css({
            whiteSpace: "pre-wrap",
            marginTop: "10px"
        }).appendTo(details);

        var actions = $("<div></div>").css({display: "flex", gap: "10px", flexWrap: "wrap"}).appendTo(panel);
        $("<button type='button' class='btn btn-primary'></button>").text("Retry").on("click", function () {
            downloadApp(appId, subRoute);
        }).appendTo(actions);
        $("<button type='button' class='btn btn-default'></button>").text("Reload page").on("click", function () {
            window.location.reload();
        }).appendTo(actions);

        renderDiv.empty().append(panel);
    }

});
