WEBDOCK.component().register(function(exports){
    var api, networkApi, scope;
    var state = {
        loading: true,
        saving: false,
        error: "",
        notice: "",
        networks: [],
        selectedNetworkId: 0,
        devices: [],
        endpoints: [],
        deviceEditor: false,
        endpointEditor: false,
        deviceForm: blankDevice(),
        endpointForm: blankEndpoint()
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: load,
            selectNetwork: selectNetwork,
            newDevice: newDevice,
            editDevice: editDevice,
            saveDevice: saveDevice,
            newEndpoint: newEndpoint,
            editEndpoint: editEndpoint,
            saveEndpoint: saveEndpoint,
            closeEditors: closeEditors,
            endpointsForDevice: endpointsForDevice
        },
        onReady: function(s){
            scope=s;
            init();
        }
    };

    function init(){
        api = exports.getComponent("device-api");
        if(!requireService(api, ["ListDevices", "ListEndpoints", "SaveDevice", "SaveEndpoint"], "Device")){
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

    function load(){
        if(!state.selectedNetworkId){
            state.loading = false;
            state.devices = [];
            state.endpoints = [];
            return;
        }
        if(!requireService(api, ["ListDevices", "ListEndpoints"], "Device")){
            state.loading = false;
            return;
        }
        state.loading = true;
        state.error = "";
        api.services.ListDevices({network_id: state.selectedNetworkId}).then(function(r){
            state.devices = r.success ? (r.result || []) : [];
            if(!r.success){
                state.error = r.message || "Unable to load devices.";
            }
            loadEndpoints();
        }).error(function(){
            state.loading = false;
            state.error = "Unable to load devices.";
        });
    }

    function loadEndpoints(){
        if(!requireService(api, ["ListEndpoints"], "Device")){
            state.loading = false;
            return;
        }
        api.services.ListEndpoints({network_id: state.selectedNetworkId}).then(function(r){
            state.loading = false;
            state.endpoints = r.success ? (r.result || []) : [];
        }).error(function(){
            state.loading = false;
        });
    }

    function selectNetwork(){
        closeEditors();
        load();
    }

    function blankDevice(){
        return {
            id: 0,
            network_id: state ? state.selectedNetworkId : 0,
            hardware_profile_id: 0,
            name: "",
            serial_number: "",
            manufacturer: "",
            model: "",
            device_role: "Sensor Node",
            firmware_version: "",
            firmware_channel: "stable",
            provisioning_status: "Unclaimed",
            last_seen_at: "",
            status: "Active",
            configuration_json: "{}"
        };
    }

    function blankEndpoint(){
        return {
            id: 0,
            network_id: state ? state.selectedNetworkId : 0,
            device_id: 0,
            profile_id: 0,
            endpoint_number: 1,
            endpoint_type: "FIRMWARE",
            status: "Active",
            auth_key_version: "v1",
            label: ""
        };
    }

    function newDevice(){
        state.deviceForm = blankDevice();
        state.deviceEditor = true;
        state.endpointEditor = false;
        state.error = "";
        state.notice = "";
    }

    function editDevice(device){
        state.deviceForm = Object.assign(blankDevice(), JSON.parse(JSON.stringify(device)));
        state.deviceEditor = true;
        state.endpointEditor = false;
        state.error = "";
        state.notice = "";
    }

    function saveDevice(){
        if(!state.deviceForm.name || !state.deviceForm.name.trim()){
            state.error = "Device name is required.";
            return;
        }
        if(!requireService(api, ["SaveDevice"], "Device")){
            state.saving = false;
            return;
        }
        state.deviceForm.network_id = state.selectedNetworkId;
        state.saving = true;
        api.services.SaveDevice(state.deviceForm).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to save device.";
                return;
            }
            state.notice = "Device saved.";
            closeEditors();
            load();
        }).error(function(){
            state.saving = false;
            state.error = "Unable to save device.";
        });
    }

    function newEndpoint(device){
        state.endpointForm = blankEndpoint();
        state.endpointForm.device_id = device.id;
        state.endpointForm.endpoint_number = endpointsForDevice(device).length + 1;
        state.endpointEditor = true;
        state.deviceEditor = false;
        state.error = "";
        state.notice = "";
    }

    function editEndpoint(endpoint){
        state.endpointForm = Object.assign(blankEndpoint(), JSON.parse(JSON.stringify(endpoint)));
        state.endpointEditor = true;
        state.deviceEditor = false;
        state.error = "";
        state.notice = "";
    }

    function saveEndpoint(){
        if(!requireService(api, ["SaveEndpoint"], "Device")){
            state.saving = false;
            return;
        }
        state.endpointForm.network_id = state.selectedNetworkId;
        state.saving = true;
        api.services.SaveEndpoint(state.endpointForm).then(function(r){
            state.saving = false;
            if(!r.success){
                state.error = r.message || "Unable to save endpoint.";
                return;
            }
            state.notice = "Endpoint saved.";
            closeEditors();
            load();
        }).error(function(){
            state.saving = false;
            state.error = "Unable to save endpoint.";
        });
    }

    function closeEditors(){
        state.deviceEditor = false;
        state.endpointEditor = false;
    }

    function endpointsForDevice(device){
        return state.endpoints.filter(function(endpoint){
            return endpoint.device_id == device.id;
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
