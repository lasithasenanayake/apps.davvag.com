WEBDOCK.component().register(function(exports){
    var api, networkApi, deviceApi, scope;
    var state = {
        loading: true,
        saving: false,
        error: "",
        notice: "",
        networks: [],
        endpoints: [],
        events: [],
        summary: emptySummary(),
        selectedNetworkId: 0,
        form: blankEvent()
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: load,
            selectNetwork: selectNetwork,
            ingestEvent: ingestEvent
        },
        onReady: function(s){
            scope=s;
            init();
        }
    };

    function init(){
        api = exports.getComponent("event-api");
        if(!requireService(api, ["GetEventState", "ListEvents", "IngestEvents"], "Event")){
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
        exports.getAppComponent("davvag-mesh-devices", "device-api", function(dapi){
            deviceApi = dapi;
            if(requireService(deviceApi, ["ListEndpoints"], "Device")){
                loadEndpoints();
            }
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
            state.form.network_id = state.selectedNetworkId;
            load();
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load networks.";
        });
    }

    function selectNetwork(){
        state.form = blankEvent();
        state.form.network_id = state.selectedNetworkId;
        load();
        loadEndpoints();
    }

    function load(){
        if(!state.selectedNetworkId){
            state.loading = false;
            return;
        }
        if(!requireService(api, ["GetEventState", "ListEvents"], "Event")){
            state.loading = false;
            return;
        }
        state.loading = true;
        state.error = "";
        api.services.GetEventState({network_id: state.selectedNetworkId}).then(function(r){
            state.summary = r.success ? (r.result || emptySummary()) : emptySummary();
            loadEvents();
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load event state.";
        });
    }

    function loadEvents(){
        if(!requireService(api, ["ListEvents"], "Event")){
            state.loading = false;
            return;
        }
        api.services.ListEvents({network_id: state.selectedNetworkId, pageSize: 100}).then(function(r){
            state.loading = false;
            state.events = r.success ? (r.result || []) : [];
            if(!r.success){
                state.error = r.message || "Unable to load events.";
            }
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load events.";
        });
    }

    function loadEndpoints(){
        if(!state.selectedNetworkId || !requireService(deviceApi, ["ListEndpoints"], "Device")){
            return;
        }
        deviceApi.services.ListEndpoints({network_id: state.selectedNetworkId}).then(function(r){
            state.endpoints = r.success ? (r.result || []) : [];
        }).error(function(){
            state.endpoints = [];
        });
    }

    function ingestEvent(){
        if(!requireService(api, ["IngestEvents"], "Event")){
            state.saving = false;
            return;
        }
        state.error = "";
        state.notice = "";
        state.saving = true;
        state.form.network_id = state.selectedNetworkId;
        api.services.IngestEvents({
            events: [state.form],
            gateway_endpoint_id: state.form.gateway_endpoint_id,
            upload_session: state.form.upload_session
        }).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to ingest event.";
                return;
            }
            var result = r.result || {};
            state.notice = "Accepted " + ((result.accepted || []).length) + " event(s), duplicate " + ((result.duplicates || []).length) + ".";
            state.form = blankEvent();
            state.form.network_id = state.selectedNetworkId;
            load();
        }).error(function(){
            state.saving = false;
            state.error = "Unable to ingest event.";
        });
    }

    function blankEvent(){
        return {
            network_id: state ? state.selectedNetworkId : 0,
            origin_endpoint_id: 0,
            gateway_endpoint_id: 0,
            session_id: "manual",
            sequence: 1,
            schema_version: "1",
            event_type: "STATUS_REPORTED",
            priority: "Normal",
            created_at_device: "",
            time_quality: "SERVER",
            payload_json: "{}",
            upload_session: "manual"
        };
    }

    function emptySummary(){
        return {
            recent_count: 0,
            last_event_type: "None",
            last_event_at: "",
            priority_counts: {critical: 0, high: 0, normal: 0, low: 0}
        };
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
