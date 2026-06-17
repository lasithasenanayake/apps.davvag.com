WEBDOCK.component().register(function(exports){
    var bindData = {
        i_profile:{},
        InvItems:[],
        products:[],
        subtotal:0,
        tax:0,
        taxamount:0,
        total:0,
        paidamount:0,
        paymenttype:"Cash",
        date:new Date(),
        duedate:new Date(),
        invoiceSave:false,
        InvoiceToSave:{receiptNo:0,InvoiceItems:[]},
        canCancel:false,
        canceling:false,
        cancelNotice:"",
        receiptCancelled:false
    };

   
    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            navigateBack: function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            },
            print:function(){
                var prtContent=document.getElementById("printcontent");
                var WinPrint = window.open('', '', 'left=0,top=0,width=800,height=900,toolbar=0,scrollbars=0,status=0');
                WinPrint.document.open('text/html');
                WinPrint.document.write('<link href="//netdna.bootstrapcdn.com/bootstrap/3.1.0/css/bootstrap.min.css" rel="stylesheet" id="bootstrap-css"><div style="margin: 30px;"> '+prtContent.innerHTML+'</div>');
                WinPrint.document.close();
                WinPrint.focus();
                setTimeout(function(){ WinPrint.print();WinPrint.close(); }, 3000);
            },
            cancelReceipt:cancelReceipt
        },
        filters: {
            currency: function (value) {
              if (!value) return ''
              value = value.toString()
              return parseFloat(value).toFixed(2);
            }
          
        }
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
    
    var profileHandler;
    var pInstance;

    function numberValue(value){
        var amount = parseFloat(value || 0);
        return isNaN(amount) ? 0 : amount;
    }

    function isCancelledReceipt(payment){
        var status = payment && payment.status ? payment.status.toString().toLowerCase() : "";
        return status === "cancelled" || status === "canceled";
    }

    function notify(message,type){
        if(window.$ && $.notify){
            $.notify(message,type);
        }else{
            alert(message);
        }
    }

    function responseError(response,fallback){
        if(response && response.result){
            if(response.result.error){
                return response.result.error;
            }
            if(typeof response.result === "string"){
                return response.result;
            }
        }
        return fallback;
    }

    function paidDetails(details){
        var items = [];
        (details || []).forEach(function(element){
            if(numberValue(element.PaidAmout) > 0){
                items.push(element);
            }
        });
        return items;
    }

    function setLoadedReceipt(payment,details){
        bindData.InvoiceToSave=payment || {receiptNo:0,InvoiceItems:[]};
        bindData.InvoiceToSave.InvoiceItems=paidDetails(details);
        bindData.invoiceSave=!!payment;
        bindData.receiptCancelled=isCancelledReceipt(bindData.InvoiceToSave);
        bindData.canCancel=false;
        bindData.cancelNotice=bindData.receiptCancelled ? "Receipt has been cancelled." : "";
        if(payment && payment.receiptNo && payment.profileId && !bindData.receiptCancelled){
            loadCancelState();
        }
    }

    function loadCancelState(){
        var currentReceiptNo = numberValue(bindData.InvoiceToSave.receiptNo);
        var profileId = bindData.InvoiceToSave.profileId;
        bindData.canCancel=false;
        bindData.cancelNotice="";
        profileHandler.services.q([{storename:"paymentheader",search:"profileId:"+profileId,nocache:true}])
        .then(function(r){
            if(r.success && r.result.paymentheader){
                var latestReceiptNo = 0;
                r.result.paymentheader.forEach(function(payment){
                    if(!isCancelledReceipt(payment)){
                        latestReceiptNo = Math.max(latestReceiptNo,numberValue(payment.receiptNo));
                    }
                });
                bindData.canCancel=latestReceiptNo === currentReceiptNo;
                bindData.cancelNotice=bindData.canCancel ? "" : "Only the latest receipt for this profile can be cancelled.";
            }
        })
        .error(function(error){
            bindData.cancelNotice="Unable to verify latest receipt.";
            console.log(error.responseJSON);
        });
    }

    function cancelReceipt(){
        if(bindData.canceling){
            return;
        }
        if(!bindData.canCancel){
            notify(bindData.cancelNotice || "Only the latest receipt for this profile can be cancelled.","warn");
            return;
        }
        if(!confirm("Cancel this receipt and reverse ledger and invoice balances?")){
            return;
        }
        bindData.canceling=true;
        profileHandler.services.ReceiptCancelation({id:bindData.InvoiceToSave.receiptNo})
        .then(function(response){
            bindData.canceling=false;
            if(response.success){
                setLoadedReceipt(response.result,response.result && response.result.InvoiceItems ? response.result.InvoiceItems : []);
                notify("Receipt has been cancelled.","success");
            }else{
                notify(responseError(response,"Receipt cancellation failed."),"error");
            }
        })
        .error(function(error){
            bindData.canceling=false;
            notify(responseError(error.responseJSON,"Receipt cancellation failed."),"error");
            console.log(error.responseJSON);
        });
    }

    function initializeComponent(){
        profileHandler = exports.getComponent("profile");
        pInstance = exports.getShellComponent("soss-routes");
        var routeData = pInstance.getInputData();
        if(routeData.tid!=null){
            var query=[{storename:"paymentheader",search:"receiptNo:"+routeData.tid},{storename:"paymentdetails",search:"receiptNo:"+routeData.tid}];
                    profileHandler.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            if(r.result.paymentheader.length!=0){
                                setLoadedReceipt(r.result.paymentheader[0],r.result.paymentdetails);
                            }
                            return;
                            //calcTotals();
                            
                        }
                    })
                    .error(function(error){
                        console.log(error.responseJSON);
            });
            //getProfilebyID(routeData.id)
        }
        
    }

    

    

    



    

    
});
