WEBDOCK.component().register(function(exports){
    var scope, service_handler, routeData;

    var bindData = {
        submitErrors: [],
        submitInfo: [],
        order: null,
        isBusy: true,
        isRendered: false
    };

    var vueData = {
        data: bindData,
        onReady: function(s){
            scope = s;
            initialize();
        }
    };

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if (!service_handler){
            bindData.submitErrors.push("Service has not loaded.");
            bindData.isBusy = false;
            return;
        }

        var router = exports.getShellComponent("soss-routes");
        routeData = router.getInputData();

        if (!routeData.orderid){
            bindData.submitErrors.push("Order is not found to pay.");
            bindData.isBusy = false;
            return;
        }

        service_handler.services.Order({ id: routeData.orderid }).then(function(result){
            if (!result.success || !result.result){
                bindData.submitErrors.push("Order is not found to pay.");
                bindData.isBusy = false;
                return;
            }

            bindData.order = result.result;
            if (!bindData.order.clientId){
                bindData.submitErrors.push("There is no PayPal account mapped for this seller.");
                bindData.isBusy = false;
                return;
            }

            if (Number(bindData.order.balance) <= 0){
                bindData.submitErrors.push("Order is already paid.");
                bindData.isBusy = false;
                if (routeData.url){
                    window.location = decodeURI(routeData.url);
                }
                return;
            }

            loadPayPalSdk();
        }).error(function(){
            bindData.submitErrors.push("Critical error please refresh.");
            bindData.isBusy = false;
        });
    }

    function loadPayPalSdk(){
        var scriptId = "paypal-sdk-davvag";
        var existing = document.getElementById(scriptId);
        if (existing){
            waitForPayPal();
            return;
        }

        var currency = bindData.order.currencycode;
        if(!currency){
            bindData.submitErrors.push("No active checkout currency is configured.");
            bindData.isBusy = false;
            return;
        }
        var src = "https://www.paypal.com/sdk/js?client-id=" + encodeURIComponent(bindData.order.clientId) +
            "&currency=" + encodeURIComponent(currency) +
            "&intent=capture&components=buttons&enable-funding=card";

        var script = document.createElement("script");
        script.id = scriptId;
        script.src = src;
        script.onload = waitForPayPal;
        script.onerror = function(){
            bindData.submitErrors.push("Unable to load the PayPal checkout form.");
            bindData.isBusy = false;
        };
        document.head.appendChild(script);
    }

    function waitForPayPal(){
        if (window.paypal && window.paypal.Buttons){
            renderButtons();
            return;
        }

        setTimeout(waitForPayPal, 300);
    }

    function renderButtons(){
        if (bindData.isRendered){
            return;
        }

        var container = document.getElementById("paypal-button-container");
        if (!container){
            setTimeout(renderButtons, 100);
            return;
        }

        bindData.isRendered = true;
        bindData.isBusy = false;

        window.paypal.Buttons({
            style: {
                layout: "vertical",
                color: "gold",
                shape: "rect",
                label: "paypal"
            },
            createOrder: function(){
                bindData.submitErrors = [];
                bindData.submitInfo = [];
                bindData.isBusy = true;
                return new Promise(function(resolve, reject){
                    service_handler.services.CreateOrder({ id: bindData.order.invoiceNo }).then(function(result){
                        bindData.isBusy = false;
                        if (!result.success || !result.result || !result.result.id){
                            reject(new Error("Unable to create the PayPal order."));
                            return;
                        }
                        resolve(result.result.id);
                    }).error(function(){
                        bindData.isBusy = false;
                        reject(new Error("Unable to create the PayPal order."));
                    });
                });
            },
            onApprove: function(data){
                bindData.submitErrors = [];
                bindData.submitInfo = [];
                bindData.isBusy = true;
                return new Promise(function(resolve, reject){
                    service_handler.services.CaptureOrder({
                        id: bindData.order.invoiceNo,
                        paypalOrderId: data.orderID
                    }).then(function(result){
                        bindData.isBusy = false;
                        if (!result.success){
                            reject(new Error("Unable to confirm the PayPal payment."));
                            return;
                        }

                        bindData.submitInfo.push("Payment completed successfully.");
                        if (routeData.url){
                            sessionStorage.tmpRept = JSON.stringify(result.result);
                            window.location = decodeURI(routeData.url);
                        } else {
                            window.location = "#/app/davvag-shop/order-complete";
                        }
                        resolve(result.result);
                    }).error(function(){
                        bindData.isBusy = false;
                        reject(new Error("Unable to confirm the PayPal payment."));
                    });
                });
            },
            onCancel: function(){
                bindData.submitInfo = ["PayPal checkout was cancelled."];
            },
            onError: function(err){
                bindData.isBusy = false;
                bindData.submitErrors = [err && err.message ? err.message : "PayPal payment failed."];
            }
        }).render("#paypal-button-container");
    }

    exports.vue = vueData;
    exports.onReady = function(){
        
    };
});
