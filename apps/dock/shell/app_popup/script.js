WEBDOCK.component().register(function(exports){
    var appLoader;
    var callback,popupName,popupDialog;
    
    function popup_large(id,title){
        wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade bd-example-modal-lg' tabindex='-1' role='dialog' aria-labelledby='"+id+"_popup' aria-hidden='true'><div class='modal-dialog modal-lg' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title' id='modalLabel'>"+title+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div>");
        
       
    }

    function popup_small(id,title){
        wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='"+id+"_popup' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title' id='modalLabel'>"+title+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div>");
        
        
    }

    function Popup(id,appId,startupComponent,cb,er,cbcompleted,data){
        popup_real(id,appId,startupComponent,cb,er,cbcompleted,data);
        $("#"+id+'_popup').modal('show');
        //modal
    }

    function Popup_lock(id,appId,startupComponent,cb,er,cbcompleted,data){
        popup_real(id,appId,startupComponent,cb,er,cbcompleted,data);
        $("#"+id+'_popup').modal({backdrop: 'static', keyboard: false});
        
    }

    function popup_real(id,appId,startupComponent,cb,er,cbcompleted,data){
        
        RenderApplication(appId,startupComponent,id+"_window",cb,er,function(d){
            $modal=$("#"+id+'_popup');
            $modal.on('hidden.bs.modal', function () {
                if(cbcompleted)
                    cbcompleted(d);
            });
            $modal.modal('toggle');

            
        },data);
    }

    function openPopupForm(appName,appComponent,data,cb,dialogName,popupLarg,popupLock){
        popupLarg=popupLarg==null?false:popupLarg;
        popupLock=popupLock==null?true:popupLock;
        let _popupName=appName+"-"+appComponent;
        let _popupDialog=appName+"-"+appComponent+"dialog";
        callback=cb;

        if(popupLarg){
            popup_large(_popupDialog,dialogName);
        }else{
            popup_small(_popupDialog,dialogName);
        }

        appLoader.downloadAPP(appName,appComponent,_popupDialog+"_window",function(d){
                    
        },function(e){
            console.log(e);
            $("#"+_popupName).html("<h1>Error</h1><p></p>");
        },function(_data){
            app={id:"#"+popupDialog+"_popup",close:function(cb){
                
                $("#"+_popupDialog+"_popup").on('hidden.bs.modal', function () {
                   if(cb){
                    cb();
                   }
                });

                $("#"+_popupDialog+"_popup").modal('toggle');
            }}
            callback(_data,app);    
        },data);
        if(popupLock){
            $("#"+_popupDialog+"_popup").modal({backdrop: 'static', keyboard: false});
        }else{
            $("#"+_popupDialog+"_popup").modal('show'); 
        }

    }

    function close(cb) {
        $("#"+popupDialog+"_popup").modal('toggle');

        
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
    window.openAppPopup=openPopupForm;
    window.openViewObject=function(data,cb){
            openPopupForm("davvag-tools","viewObject",data,cb,"Change Record Permision",false,true);


    }
    //exports.get_data=attribute.get_data;

});
