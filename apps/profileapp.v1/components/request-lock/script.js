WEBDOCK.component().register(function(exports){
    if(typeof window !== "undefined" && typeof window.DAVVAG_PROFILEAPP_INSTALL_REQUEST_LOCK === "function"){
        window.DAVVAG_PROFILEAPP_INSTALL_REQUEST_LOCK();
    }
});
