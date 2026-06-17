WEBDOCK.component().register(function(exports){
    var bindData = {
        i_profile:{},
        InvItems:[{itemid:0,name:"",uom:"",qty:0,price:parseFloat("0").toFixed(2),total:parseFloat("0").toFixed(2),selected:null}],
        products:[],
        subtotal:0,
        tax:0,
        taxamount:0,
        total:0,
        date:new Date(),
        duedate:new Date(),
        invoiceSave:false,
        InvoiceToSave:{invoiceNo:0,InvoiceItems:[]},
        canCancel:false,
        canceling:false,
        cancelNotice:"",
        invoiceCancelled:false
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
            cancelInvoice:cancelInvoice
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

    function isCancelledInvoice(invoice){
        var status = invoice && invoice.status ? invoice.status.toString().toLowerCase() : "";
        return status === "cancelled" || status === "canceled" || status === "deleted" || status === "void";
    }

    function invoicePaidAmount(invoice){
        if(!invoice){
            return 0;
        }
        var paid = numberValue(invoice.paidamount);
        var total = numberValue(invoice.total);
        var balance = invoice.balance === undefined || invoice.balance === null || invoice.balance === "" ? total : numberValue(invoice.balance);
        if(total > 0 && balance < total){
            paid = Math.max(paid,total - balance);
        }
        return paid;
    }

    function canCancelInvoice(invoice){
        if(!invoice || !invoice.invoiceNo || isCancelledInvoice(invoice)){
            return false;
        }
        var paymentComplete = invoice.PaymentComplete ? invoice.PaymentComplete.toString().toUpperCase() : "N";
        if(paymentComplete === "Y" || paymentComplete === "C"){
            return false;
        }
        return invoicePaidAmount(invoice) <= 0.009;
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

    function setLoadedInvoice(invoice,details,allowCancel){
        bindData.InvoiceToSave=invoice || {invoiceNo:0,InvoiceItems:[]};
        bindData.InvoiceToSave.InvoiceItems=details || [];
        bindData.invoiceSave=!!invoice;
        bindData.invoiceCancelled=isCancelledInvoice(bindData.InvoiceToSave);
        bindData.canCancel=false;
        bindData.cancelNotice="";

        if(!invoice || !invoice.invoiceNo){
            return;
        }
        if(allowCancel === false){
            bindData.cancelNotice="Pending invoices cannot be cancelled from this view.";
            return;
        }
        if(bindData.invoiceCancelled){
            bindData.cancelNotice="Invoice has been cancelled.";
            return;
        }
        if(canCancelInvoice(invoice)){
            bindData.canCancel=true;
        }else{
            bindData.cancelNotice="Paid invoices cannot be cancelled.";
        }
    }

    function cancelInvoice(){
        if(bindData.canceling){
            return;
        }
        if(!bindData.canCancel){
            notify(bindData.cancelNotice || "Only unpaid invoices can be cancelled.","warn");
            return;
        }
        if(!confirm("Cancel this unpaid invoice and reverse ledger and inventory balances?")){
            return;
        }
        bindData.canceling=true;
        profileHandler.services.InvoiceCancelation({id:bindData.InvoiceToSave.invoiceNo})
        .then(function(response){
            bindData.canceling=false;
            if(response.success){
                setLoadedInvoice(response.result,response.result && response.result.InvoiceItems ? response.result.InvoiceItems : bindData.InvoiceToSave.InvoiceItems);
                notify("Invoice has been cancelled.","success");
            }else{
                notify(responseError(response,"Invoice cancellation failed."),"error");
            }
        })
        .error(function(error){
            bindData.canceling=false;
            notify(responseError(error && error.responseJSON ? error.responseJSON : null,"Invoice cancellation failed."),"error");
            console.log(error && error.responseJSON ? error.responseJSON : error);
        });
    }

    function initializeComponent(){
        pInstance = exports.getShellComponent("soss-routes");
        var routeData = pInstance.getInputData();
        profileHandler = exports.getComponent("profile");
        if(routeData.tid!=null){
            var query=[{storename:"orderheader",search:"invoiceNo:"+routeData.tid},{storename:"orderdetails",search:"invoiceNo:"+routeData.tid}];
                    profileHandler.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            if(r.result.orderheader.length!=0){
                                setLoadedInvoice(r.result.orderheader[0],r.result.orderdetails);
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

        if(routeData.xid!=null){
            var menuhandler  = exports.getShellComponent("soss-data");
            var query={query:[{storename:"orderheader_pending",search:"invoiceNo:"+routeData.xid},{storename:"orderdetails_pending",search:"invoiceNo:"+routeData.xid}]};
                 menuhandler.services.qcrossdomain(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            if(r.result.orderheader_pending.length!=0){
                                setLoadedInvoice(r.result.orderheader_pending[0],r.result.orderdetails_pending,false);
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
