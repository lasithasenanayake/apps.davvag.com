WEBDOCK.component().register(function(exports){
    if(typeof window !== "undefined"){
        window.DAVVAG_PROFILEAPP_INSTALL_REQUEST_LOCK = installRequestButtonLock;
    }

    function installRequestButtonLock(){
        if(typeof window === "undefined" || typeof document === "undefined" || typeof window.jQuery === "undefined"){
            return;
        }
        if(window.DAVVAG_PROFILEAPP_REQUEST_LOCK){
            return;
        }

        var $ = window.jQuery;
        var state = {
            pendingButton: null,
            activeButton: null,
            lockedButtons: []
        };
        var lockCountKey = "davvagProfileRequestLockCount";
        var xhrButtonKey = "davvagProfileRequestButton";

        window.DAVVAG_PROFILEAPP_REQUEST_LOCK = state;

        $("<style type=\"text/css\">" +
            ".davvag-request-locked{" +
                "pointer-events:none!important;" +
                "cursor:wait!important;" +
                "opacity:.65!important;" +
            "}" +
        "</style>").attr("data-davvag-request-lock", "profileapp.v1").appendTo("head");

        document.addEventListener("click", function(event){
            var button = closestActionButton(event.target);
            if(!button){
                return;
            }
            if($(button).hasClass("davvag-request-locked")){
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

            state.pendingButton = button;
            window.setTimeout(function(){
                if(state.pendingButton === button){
                    state.pendingButton = null;
                }
            }, 0);
        }, true);

        $(document)
            .off("ajaxSend.profileappRequestLock ajaxComplete.profileappRequestLock")
            .on("ajaxSend.profileappRequestLock", function(event, xhr, settings){
                if(!isServiceRequest(settings && settings.url)){
                    return;
                }

                var button = state.pendingButton || state.activeButton;
                if(!button || !document.documentElement.contains(button)){
                    return;
                }

                state.pendingButton = null;
                state.activeButton = button;
                xhr[xhrButtonKey] = button;
                lockButton(button);
            })
            .on("ajaxComplete.profileappRequestLock", function(event, xhr){
                var button = xhr && xhr[xhrButtonKey];
                if(button){
                    unlockButton(button);
                    xhr[xhrButtonKey] = null;
                }
            });

        function closestActionButton(target){
            if(!target){
                return null;
            }
            if(target.nodeType !== 1){
                target = target.parentElement;
            }
            if(!target){
                return null;
            }
            if(typeof target.closest === "function"){
                return target.closest("button, input[type='button'], input[type='submit'], a.btn");
            }
            return $(target).closest("button, input[type='button'], input[type='submit'], a.btn")[0] || null;
        }

        function isServiceRequest(url){
            return typeof url === "string" && /(^|\/)components\/[^/]+\/[^/]+\/service\//i.test(url);
        }

        function lockButton(button){
            var $button = $(button);
            var count = parseInt($button.data(lockCountKey), 10) || 0;
            $button.data(lockCountKey, count + 1);
            if(count > 0){
                return;
            }

            state.lockedButtons.push(button);
            $button
                .addClass("davvag-request-locked")
                .attr("aria-disabled", "true")
                .attr("aria-busy", "true");
        }

        function unlockButton(button){
            var $button = $(button);
            var count = parseInt($button.data(lockCountKey), 10) || 0;
            count = Math.max(0, count - 1);
            $button.data(lockCountKey, count);
            if(count > 0){
                return;
            }

            window.setTimeout(function(){
                var currentCount = parseInt($button.data(lockCountKey), 10) || 0;
                if(currentCount > 0){
                    return;
                }

                $button
                    .removeClass("davvag-request-locked")
                    .removeAttr("aria-disabled")
                    .removeAttr("aria-busy")
                    .removeData(lockCountKey);
                state.lockedButtons = state.lockedButtons.filter(function(item){
                    return item !== button;
                });
                if(state.activeButton === button){
                    state.activeButton = state.lockedButtons.length ? state.lockedButtons[state.lockedButtons.length - 1] : null;
                }
            }, 0);
        }
    }
});
