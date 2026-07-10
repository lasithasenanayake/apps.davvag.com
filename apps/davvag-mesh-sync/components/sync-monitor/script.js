WEBDOCK.component().register(function(exports){
    var api, networkApi, scope;
    var state = {
        loading: true,
        saving: false,
        error: "",
        notice: "",
        networks: [],
        selectedNetworkId: 0,
        health: emptyHealth(),
        plan: null,
        knownRefs: "",
        uploadJson: "{\"events\":[]}"
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: load,
            selectNetwork: selectNetwork,
            getPlan: getPlan,
            uploadBatch: uploadBatch
        },
        onReady: function(s){
            scope=s;
            init();
        }
    };

    function init(){
        api = exports.getComponent("sync-api");
        if(!requireService(api, ["SyncHealth", "GetSyncPlan", "UploadBatch"], "Sync")){
            state.loading = false;
            return;
        }
        exports.getAppComponent("davvag-mesh-networks", "network-api", function(napi){
            networkApi = napi;
            if(!requireService(networkApi, ["ListNetworks"], "Network")){
                state.loading = false;
                return;
            }
            loadNetworks();
        });
    }

    function routeNetworkId(){
        var m = (window.location.hash || "").match(/[?&]networkId=([0-9]+)/);
        return m ? parseInt(m[1], 10) : 0;
    }

    function loadNetworks(){
        if(!requireService(networkApi, ["ListNetworks"], "Network")){
            state.loading = false;
            return;
        }
        networkApi.services.ListNetworks({}).then(function(r){
            state.networks = r.success ? (r.result || []) : [];
            var wanted = routeNetworkId();
            state.selectedNetworkId = wanted || ((state.networks[0] && state.networks[0].id) || 0);
            load();
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load networks.";
        });
    }

    function selectNetwork(){
        state.plan = null;
        load();
    }

    function load(){
        if(!state.selectedNetworkId){
            state.loading = false;
            return;
        }
        if(!requireService(api, ["SyncHealth"], "Sync")){
            state.loading = false;
            return;
        }
        state.loading = true;
        state.error = "";
        api.services.SyncHealth({network_id: state.selectedNetworkId}).then(function(r){
            state.loading = false;
            state.health = r.success ? (r.result || emptyHealth()) : emptyHealth();
            if(!r.success){
                state.error = r.message || "Unable to load sync health.";
            }
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load sync health.";
        });
    }

    function getPlan(){
        if(!requireService(api, ["GetSyncPlan"], "Sync")){
            state.saving = false;
            return;
        }
        var refs = state.knownRefs.split(/\r?\n/).map(function(v){
            return v.trim();
        }).filter(Boolean);
        state.saving = true;
        api.services.GetSyncPlan({network_id: state.selectedNetworkId, known_event_refs: refs}).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to build sync plan.";
                return;
            }
            state.plan = r.result;
            state.notice = "Sync plan prepared.";
        }).error(function(){
            state.saving = false;
            state.error = "Unable to build sync plan.";
        });
    }

    function uploadBatch(){
        if(!requireService(api, ["UploadBatch"], "Sync")){
            state.saving = false;
            return;
        }
        var payload;
        try{
            payload = JSON.parse(state.uploadJson);
        }catch(e){
            state.error = "Upload batch must be valid JSON.";
            return;
        }
        payload.network_id = payload.network_id || state.selectedNetworkId;
        state.saving = true;
        api.services.UploadBatch(payload).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to upload batch.";
                return;
            }
            state.notice = "Batch uploaded.";
            load();
        }).error(function(){
            state.saving = false;
            state.error = "Unable to upload batch.";
        });
    }

    function emptyHealth(){
        return {status: "Waiting", server_time: "", pending_uploads: 0, known_cloud_events: 0};
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
