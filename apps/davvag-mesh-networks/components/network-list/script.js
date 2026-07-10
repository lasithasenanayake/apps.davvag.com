WEBDOCK.component().register(function(exports){
    var api, scope;
    var state = {
        loading: true,
        saving: false,
        networks: [],
        error: "",
        notice: "",
        editor: false,
        form: blank()
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: load,
            createNetwork: create,
            editNetwork: edit,
            closeEditor: close,
            saveNetwork: save
        },
        onReady: function(s){
            scope=s;
            init();
        }
    };

    function blank(){
        return {
            id: 0,
            name: "",
            code: "",
            description: "",
            network_type: "Private Operations",
            template_code: "private-operations",
            country_code: "",
            region: "",
            region_code: "",
            status: "Active",
            radio_profile: "LoRa + BLE",
            color: "#18a875",
            configuration_json: "{}"
        };
    }

    function init(){
        api = exports.getComponent("network-api");
        if(!requireService(api, ["ListNetworks", "CreateNetwork", "UpdateNetwork"], "Network")){
            state.loading = false;
            return;
        }
        load();
    }

    function load(){
        if(!requireService(api, ["ListNetworks"], "Network")){
            state.loading = false;
            return;
        }
        state.loading = true;
        state.error = "";
        api.services.ListNetworks({}).then(function(r){
            state.loading = false;
            state.networks = r.success ? (r.result || []) : [];
            if(!r.success){
                state.error = r.message || "Unable to load networks.";
            }
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load networks.";
        });
    }

    function create(){
        state.form = blank();
        state.editor = true;
        state.error = "";
        state.notice = "";
    }

    function edit(n){
        state.form = Object.assign(blank(), JSON.parse(JSON.stringify(n)));
        state.editor = true;
        state.error = "";
        state.notice = "";
    }

    function close(){
        state.editor = false;
    }

    function save(){
        if(!state.form.name || !state.form.name.trim()){
            state.error = "Network name is required.";
            return;
        }
        var method = state.form.id ? "UpdateNetwork" : "CreateNetwork";
        if(!requireService(api, [method], "Network")){
            state.saving = false;
            return;
        }
        state.saving = true;
        api.services[method](state.form).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to save network.";
                return;
            }
            state.notice = state.form.id ? "Network updated." : "Network created.";
            state.editor = false;
            load();
        }).error(function(){
            state.saving = false;
            state.error = "Unable to save network.";
        });
    }

    function requireService(handler, methods, label){
        if(!handler || !handler.services){
            state.error = label + " service is unavailable. Refresh the page to reload service descriptors.";
            return false;
        }
        for(var i = 0; i < methods.length; i++){
            if(typeof handler.services[methods[i]] !== "function"){
                state.error = label + " service method is unavailable: " + methods[i] + ". Refresh the page to reload service descriptors.";
                return false;
            }
        }
        return true;
    }
});
