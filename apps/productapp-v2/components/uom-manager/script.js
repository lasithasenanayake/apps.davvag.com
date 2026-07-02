WEBDOCK.component().register(function(exports){
    var uomHandler;

    var bindData = {
        errors: [],
        info: [],
        units: [],
        conversions: [],
        unitForm: emptyUnit(),
        conversionForm: emptyConversion()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadUoms,
            saveUnit: saveUnit,
            editUnit: editUnit,
            saveConversion: saveConversion,
            editConversion: editConversion,
            complete: complete
        },
        onReady: function(){
            initialize();
        }
    };

    exports.onReady = function(){};

    function initialize(){
        ensureProductStyles();
        uomHandler = exports.getComponent("uom-handler");
        loadUoms();
    }

    function emptyUnit(){
        return {
            name: "",
            symbol: "",
            status: "Active",
            recordtype: "unit"
        };
    }

    function emptyConversion(){
        return {
            name: "",
            symbol: "",
            status: "Active",
            recordtype: "conversion",
            fromSymbol: "",
            toSymbol: "",
            fromQty: 1,
            toQty: 1,
            multiplier: 1,
            notes: ""
        };
    }

    function loadUoms(){
        clearMessages();
        if(!uomHandler || !uomHandler.transformers || !uomHandler.transformers.allUom){
            setError("UOM service is not loaded.");
            return;
        }
        uomHandler.transformers.allUom()
            .then(function(response){
                if(response.success){
                    splitRecords(response.result || []);
                }else{
                    setError(response.error || "Unable to load units.");
                }
            })
            .error(function(error){
                setError(error && error.responseJSON ? error.responseJSON.result : "Unable to load units.");
            });
    }

    function splitRecords(records){
        bindData.units = [];
        bindData.conversions = [];
        records.forEach(function(record){
            if(isConversion(record)){
                bindData.conversions.push(record);
            }else{
                record.recordtype = record.recordtype || "unit";
                bindData.units.push(record);
            }
        });
        if(!bindData.conversionForm.fromSymbol && bindData.units.length){
            bindData.conversionForm.fromSymbol = bindData.units[0].symbol;
            bindData.conversionForm.toSymbol = bindData.units[0].symbol;
        }
    }

    function isConversion(record){
        return (record.recordtype || "").toString().toLowerCase() === "conversion"
            || (!!record.fromSymbol && !!record.toSymbol);
    }

    function saveUnit(){
        clearMessages();
        if(!bindData.unitForm.name || !bindData.unitForm.symbol){
            setError("Unit name and symbol are required.");
            return;
        }
        bindData.unitForm.recordtype = "unit";
        bindData.unitForm.status = bindData.unitForm.status || "Active";
        saveRecord(bindData.unitForm, function(){
            setInfo("Unit saved.");
            bindData.unitForm = emptyUnit();
            loadUoms();
        });
    }

    function editUnit(unit){
        bindData.unitForm = clone(unit);
        bindData.unitForm.recordtype = "unit";
    }

    function saveConversion(){
        clearMessages();
        var conversion = bindData.conversionForm;
        conversion.fromQty = parseFloat(conversion.fromQty || 0);
        conversion.toQty = parseFloat(conversion.toQty || 0);
        if(!conversion.fromSymbol || !conversion.toSymbol || conversion.fromQty <= 0 || conversion.toQty <= 0){
            setError("Select both units and enter valid quantities.");
            return;
        }
        conversion.recordtype = "conversion";
        conversion.status = conversion.status || "Active";
        conversion.multiplier = conversion.toQty / conversion.fromQty;
        conversion.name = conversion.fromSymbol + " to " + conversion.toSymbol;
        conversion.symbol = conversion.fromSymbol + "->" + conversion.toSymbol;
        saveRecord(conversion, function(){
            setInfo("Conversion saved.");
            bindData.conversionForm = emptyConversion();
            loadUoms();
        });
    }

    function editConversion(conversion){
        bindData.conversionForm = clone(conversion);
        bindData.conversionForm.recordtype = "conversion";
    }

    function saveRecord(record, cb){
        var promise = record.id
            ? uomHandler.transformers.updateUom(clone(record))
            : uomHandler.transformers.insertUom(clone(record));
        promise
            .then(function(response){
                if(response.success){
                    cb();
                }else{
                    setError(response.error || "Save failed.");
                }
            })
            .error(function(error){
                setError(error && error.responseJSON ? error.responseJSON.result : "Save failed.");
            });
    }

    function complete(){
        if(exports.Complete){
            exports.Complete({refreshUom: true});
            return;
        }
        var routeHandler = exports.getShellComponent("soss-routes");
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("..");
        }
    }

    function ensureProductStyles(){
        if(document.getElementById("productapp-v2-common-css")){
            return;
        }
        var link = document.createElement("link");
        link.id = "productapp-v2-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.5";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function clone(obj){
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function setError(message){
        bindData.errors.push(message);
    }

    function setInfo(message){
        bindData.info.push(message);
    }

    function clearMessages(){
        bindData.errors = [];
        bindData.info = [];
    }
});
