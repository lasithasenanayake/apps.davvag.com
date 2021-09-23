WEBDOCK.component().register(function(exports){
    var appLoader;
    var callback,popupName,popupDialog;

    

    function openPopupForm(appName,appComponent,data,cb,dialogName){
        popupName=appName+"-"+appComponent;
        popupDialog=appName+"-"+appComponent+"dialog";
        let popup=document.getElementById(popupDialog);
        callback=cb;
        if(popup==null){
            let bodyEt=$("body");
                bodyEt.append("<div id='"+popupDialog+"' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='exampleModalLabel' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'>"+
                "<div class='modal-header'> <h5 class='modal-title'>"+dialogName+"</h5></div>"+
                "<div id='"+popupName+"' class='modal-body'>Loading...</div>"+
                "<div class='modal-footer'></div></div></div></div>");
        }
        appLoader.downloadAPP(appName,appComponent,popupName,function(d){
                    
        },function(e){
            console.log(e);
            $("#"+popupName).html("<h1>Error</h1><p></p>");
            //bindData.loadingAppError=truae;
        },function(_data){
            //$("#"+popupDialog).modal('toggle');
            callback(_data);    
        },data);
        
        $("#"+popupDialog).modal('show');
    }

    function close() {
        $("#"+popupDialog).modal('toggle');
    }

    function initiate() {
        exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_app){
            appLoader=_app;
            appLoader.initialize();
        });
    }
    
    initiate();
    //exports.retriveDataFromServer=attribute.retriveDataFromServer;
    exports.open = openPopupForm;
    exports.close=close;
    //exports.get_data=attribute.get_data;

});
