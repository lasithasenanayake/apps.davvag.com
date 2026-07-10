WEBDOCK.component().register(function(exports){
    var networkApi, deviceApi, eventApi, syncApi, routes, scope;
    var state = {
        loading: true,
        error: "",
        networks: [],
        selected: null,
        deviceSummary: emptyDevice(),
        eventState: emptyEvent(),
        syncHealth: emptySync(),
        capabilities: {devices: false, events: false, sync: false}
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: loadNetworks,
            openNetworks: openNetworks,
            openDevices: openDevices,
            openEvents: openEvents,
            openSync: openSync,
            selectNetwork: selectNetwork,
            activeCount: activeCount
        },
        onReady: function(s){
            scope=s;
            init();
        }
    };

    function init(){
        routes = exports.getShellComponent("soss-routes");
        exports.getAppComponent("davvag-mesh-networks", "network-api", function(api){
            networkApi = api;
            if(!requireService(networkApi, ["ListNetworks"], "Network")){
                state.loading = false;
                return;
            }
            loadNetworks();
        });
        exports.getAppComponent("davvag-mesh-devices", "device-api", function(api){
            deviceApi = api;
            state.capabilities.devices = requireService(deviceApi, ["DeviceSummary"], "Device", true);
            loadDeviceSummary();
        });
        exports.getAppComponent("davvag-mesh-events", "event-api", function(api){
            eventApi = api;
            state.capabilities.events = requireService(eventApi, ["GetEventState"], "Event", true);
            loadEventState();
        });
        exports.getAppComponent("davvag-mesh-sync", "sync-api", function(api){
            syncApi = api;
            state.capabilities.sync = requireService(syncApi, ["SyncHealth"], "Sync", true);
            loadSyncHealth();
        });
        setTimeout(function(){
            if(state.loading && !networkApi){
                state.loading = false;
                state.error = "Network capability is unavailable.";
            }
        }, 5000);
    }

    function loadNetworks(){
        if(!requireService(networkApi, ["ListNetworks"], "Network")){
            state.loading = false;
            return;
        }
        state.loading = true;
        state.error = "";
        networkApi.services.ListNetworks({}).then(function(r){
            state.loading = false;
            if(!r.success){
                state.error = r.message || "Unable to load the Mesh overview.";
                return;
            }
            state.networks = r.result || [];
            if(state.selected){
                var current = findNetwork(state.selected.id);
                state.selected = current || state.networks[0] || null;
            }else{
                state.selected = state.networks[0] || null;
            }
            loadRelated();
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load the Mesh overview.";
        });
    }

    function loadRelated(){
        loadDeviceSummary();
        loadEventState();
        loadSyncHealth();
    }

    function loadDeviceSummary(){
        if(!state.selected || !requireService(deviceApi, ["DeviceSummary"], "Device", true)){
            state.deviceSummary = emptyDevice();
            return;
        }
        deviceApi.services.DeviceSummary({network_id: state.selected.id}).then(function(r){
            state.deviceSummary = r.success ? (r.result || emptyDevice()) : emptyDevice();
        }).error(function(){
            state.deviceSummary = emptyDevice();
        });
    }

    function loadEventState(){
        if(!state.selected || !requireService(eventApi, ["GetEventState"], "Event", true)){
            state.eventState = emptyEvent();
            return;
        }
        eventApi.services.GetEventState({network_id: state.selected.id}).then(function(r){
            state.eventState = r.success ? (r.result || emptyEvent()) : emptyEvent();
        }).error(function(){
            state.eventState = emptyEvent();
        });
    }

    function loadSyncHealth(){
        if(!state.selected || !requireService(syncApi, ["SyncHealth"], "Sync", true)){
            state.syncHealth = emptySync();
            return;
        }
        syncApi.services.SyncHealth({network_id: state.selected.id}).then(function(r){
            state.syncHealth = r.success ? (r.result || emptySync()) : emptySync();
        }).error(function(){
            state.syncHealth = emptySync();
        });
    }

    function selectNetwork(network){
        state.selected = network;
        loadRelated();
    }

    function findNetwork(id){
        for(var i = 0; i < state.networks.length; i++){
            if(state.networks[i].id == id){
                return state.networks[i];
            }
        }
        return null;
    }

    function activeCount(){
        var count = 0;
        state.networks.forEach(function(n){
            if(n.status === "Active"){
                count++;
            }
        });
        return count;
    }

    function openNetworks(){
        navigate("#/app/davvag-mesh-networks");
    }

    function openDevices(){
        navigate("#/app/davvag-mesh-devices" + (state.selected ? "?networkId=" + encodeURIComponent(state.selected.id) : ""));
    }

    function openEvents(){
        navigate("#/app/davvag-mesh-events" + (state.selected ? "?networkId=" + encodeURIComponent(state.selected.id) : ""));
    }

    function openSync(){
        navigate("#/app/davvag-mesh-sync" + (state.selected ? "?networkId=" + encodeURIComponent(state.selected.id) : ""));
    }

    function navigate(hash){
        window.location.hash = hash;
    }

    function emptyDevice(){
        return {total_devices: 0, total_endpoints: 0, online_devices: 0, attention_devices: 0};
    }

    function emptyEvent(){
        return {
            recent_count: 0,
            last_event_type: "None",
            last_event_at: "",
            priority_counts: {critical: 0, high: 0, normal: 0, low: 0}
        };
    }

    function emptySync(){
        return {status: "Waiting", server_time: "", pending_uploads: 0, known_cloud_events: 0};
    }

    function requireService(handler, methods, label, silent){
        if(!handler || !handler.services){
            if(!silent){
                state.error = label + " service is unavailable. Refresh the page to reload service descriptors.";
            }
            return false;
        }
        for(var i = 0; i < methods.length; i++){
            if(typeof handler.services[methods[i]] !== "function"){
                if(!silent){
                    state.error = label + " service method is unavailable: " + methods[i] + ". Refresh the page to reload service descriptors.";
                }
                return false;
            }
        }
        return true;
    }
});
