(function(window, document, $){
    "use strict";

    if(!$){
        return;
    }

    function getSelector(trigger){
        return trigger.getAttribute("data-target") ||
            trigger.getAttribute("data-bs-target") ||
            trigger.getAttribute("href") ||
            "";
    }

    function ensureBackdrop(){
        var backdrop = document.querySelector(".modal-backdrop.cms-v7-modal-backdrop");
        if(backdrop){
            return backdrop;
        }
        backdrop = document.createElement("div");
        backdrop.className = "modal-backdrop fade show cms-v7-modal-backdrop";
        document.body.appendChild(backdrop);
        return backdrop;
    }

    function removeBackdrop(){
        var backdrops = document.querySelectorAll(".modal-backdrop.cms-v7-modal-backdrop");
        for(var i = 0; i < backdrops.length; i++){
            backdrops[i].parentNode.removeChild(backdrops[i]);
        }
    }

    function showFallbackModal(element){
        var modal = $(element);
        var showEvent = $.Event("show.bs.modal");
        modal.trigger(showEvent);
        if(showEvent.isDefaultPrevented()){
            return;
        }
        ensureBackdrop();
        document.body.classList.add("modal-open");
        element.removeAttribute("aria-hidden");
        element.setAttribute("aria-modal", "true");
        element.style.display = "block";
        element.scrollTop = 0;
        modal.addClass("show in");
        modal.trigger("shown.bs.modal");
        element.focus();
    }

    function hideFallbackModal(element){
        var modal = $(element);
        var hideEvent = $.Event("hide.bs.modal");
        modal.trigger(hideEvent);
        if(hideEvent.isDefaultPrevented()){
            return;
        }
        modal.removeClass("show in");
        element.style.display = "none";
        element.setAttribute("aria-hidden", "true");
        element.removeAttribute("aria-modal");
        document.body.classList.remove("modal-open");
        removeBackdrop();
        modal.trigger("hidden.bs.modal");
    }

    if(!$.fn.modal){
        $.fn.modal = function(action){
            return this.each(function(){
                var method = action || "toggle";
                if(method === "show"){
                    showFallbackModal(this);
                }else if(method === "hide"){
                    hideFallbackModal(this);
                }else if(method === "toggle"){
                    if($(this).hasClass("show") || $(this).hasClass("in")){
                        hideFallbackModal(this);
                    }else{
                        showFallbackModal(this);
                    }
                }
            });
        };
    }

    if(!$.fn.collapse){
        $.fn.collapse = function(action){
            return this.each(function(){
                var panel = $(this);
                var method = action || "toggle";
                var shouldShow = method === "show" || (method === "toggle" && !panel.hasClass("show") && !panel.hasClass("in"));
                panel.toggleClass("show in", shouldShow);
                panel.toggleClass("collapse", true);
                panel.trigger(shouldShow ? "shown.bs.collapse" : "hidden.bs.collapse");
            });
        };
    }

    $(document).on("click.cmsV7BootstrapFallback", "[data-toggle='modal'], [data-bs-toggle='modal']", function(event){
        if($.fn.modal && $.fn.modal.Constructor){
            return;
        }
        var selector = getSelector(this);
        if(selector && selector.charAt(0) === "#"){
            event.preventDefault();
            $(selector).modal("toggle");
        }
    });

    $(document).on("click.cmsV7BootstrapDismiss", "[data-dismiss='modal'], [data-bs-dismiss='modal']", function(event){
        var modal = $(this).closest(".modal");
        if(modal.length && (!$.fn.modal || !$.fn.modal.Constructor)){
            event.preventDefault();
            modal.modal("hide");
        }
    });

    $(document).on("keyup.cmsV7BootstrapFallback", function(event){
        if(event.key === "Escape"){
            $(".modal.show, .modal.in").each(function(){
                if(!$.fn.modal || !$.fn.modal.Constructor){
                    hideFallbackModal(this);
                }
            });
        }
    });

    window.addEventListener("cms-v7-theme-changed", function(event){
        if(event.detail && event.detail.name){
            document.documentElement.setAttribute("data-cms-v7-theme", event.detail.name);
        }
    });
})(window, document, window.jQuery);
