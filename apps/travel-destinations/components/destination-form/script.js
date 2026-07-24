WEBDOCK.component().register(function (exports) {
    var api, maps, router, selectedFiles = [], rootElement, mapHandle;
    var state = {
        step: 1, categories: [], amenities: [], capabilities: {}, files: [],
        saving: false, submitting: false, uploading: false, locating: false, searchingLocation:false, resolvingMapUrl:false, error: "", notice: "", mapError:"",
        mapConfig:{enabled:false}, addressQuery:"", locationUrl:"",
        staySubtypes: ["Hotel","Cabana","Guesthouse","Homestay","Campsite","Eco Lodge","Bungalow","Hostel"],
        form: emptyForm()
    };
    var viewState = state;
    exports.vue = {
        data: state,
        methods: {next: next, previous: previous, saveDraft: saveDraft, submitForReview: submitForReview, useCurrentLocation: useCurrentLocation, searchAddress:searchAddress, extractMapUrl:extractMapUrl, mapUrlPasted:mapUrlPasted, refreshMap:scheduleMap, chooseFiles: chooseFiles, uploadPhotos: uploadPhotos, navigate: navigate},
        onReady: function (scope, element) {
            viewState = scope || state; rootElement=element;
            api = exports.getComponent("api"); maps=resolveMaps(exports); router = exports.getShellComponent("soss-routes");
            viewState.error = ""; viewState.notice = "";
            if (!api || !api.services) { viewState.error = "Destination services are unavailable."; return; }
            Promise.all([api.services.Capabilities({}), api.services.GetCategories({}), api.services.GetAmenities({}), api.services.GetMapConfiguration({})]).then(function (responses) {
                viewState.capabilities = responses[0].success ? responses[0].result : {};
                if (!viewState.capabilities.authenticated) { viewState.error = "Sign in with an active profile to submit a destination."; }
                replaceItems(viewState.categories,responses[1].success ? responses[1].result : []);
                replaceItems(viewState.amenities,responses[2].success ? responses[2].result : []);
                viewState.mapConfig=responses[3].success ? (responses[3].result||{enabled:false}) : {enabled:false};
                var id = queryValue("id"); if (id) { loadExisting(Number(id)); }
            }).catch(function () { viewState.error = "The submission form could not be prepared."; });
        }
    };
    function emptyForm() {
        return {id:null,name:"",short_summary:"",description_markdown:"",primary_language:"en",tags:"",category_ids:[],amenity_ids:[],stay_subtype:"",latitude:null,longitude:null,coordinate_accuracy:0,location_privacy:"exact_public",province:"",district:"",nearest_town:"",village:"",location_description:"",access_road_description:"",public_transport_instructions:"",distance_from_town_km:0,walking_distance_km:0,requires_4wd:false,safety_warnings:"",responsible_travel_markdown:"",camping_info:{},hiking_info:{},stay_info:{},village_info:{}};
    }
    function loadExisting(id) {
        api.services.GetDestination({id:id}).then(function (response) {
            if (!response.success) { viewState.error = message(response,"Submission could not be loaded."); return; }
            var destination = response.result || {};
            destination.category_ids = (destination.categories || []).map(function (item) { return item.id; });
            destination.amenity_ids = (destination.amenities || []).map(function (item) { return item.id; });
            viewState.form = Object.assign(emptyForm(),destination);
            if(viewState.step===3){scheduleMap();}
        }).error(function () { viewState.error = "Submission could not be loaded."; });
    }
    function next() { viewState.error = ""; if (viewState.step === 1 && !viewState.form.name.trim()) { viewState.error = "Add a destination name before continuing."; return; } if (viewState.step === 2 && !viewState.form.category_ids.length) { viewState.error = "Select at least one category."; return; } viewState.step = Math.min(6,viewState.step+1); if(viewState.step===3){scheduleMap();} }
    function previous() { viewState.step = Math.max(1,viewState.step-1); }
    function saveDraft() {
        if (viewState.saving) { return; } viewState.saving = true; viewState.error = ""; viewState.notice = "";
        var service = viewState.capabilities.administrator ? api.services.SaveDestination : api.services.SaveDestinationDraft;
        service(copyForm()).then(function (response) {
            viewState.saving = false;
            if (response.success) { viewState.form.id = response.result.id; viewState.notice = "Draft saved. You can return to it from My submissions."; }
            else { viewState.error = message(response,"Draft could not be saved."); }
        }).error(function () { viewState.saving = false; viewState.error = "Draft could not be saved."; });
    }
    function submitForReview() {
        if (viewState.submitting) { return; }
        if (!viewState.form.id) { viewState.error = "Save the draft before submitting it."; return; }
        viewState.submitting = true; viewState.error = ""; viewState.notice = "";
        var payload = copyForm();
        if (viewState.capabilities.administrator) { payload.status = "Published"; }
        var service = viewState.capabilities.administrator ? api.services.SaveDestination : api.services.SubmitDestination;
        service(payload).then(function (response) {
            viewState.submitting = false;
            if (response.success) { viewState.notice = "Submitted for review. An administrator will review the place before publication."; }
            else { viewState.error = message(response,"Submission could not be sent."); }
        }).error(function () { viewState.submitting = false; viewState.error = "Submission could not be sent."; });
    }
    function useCurrentLocation() {
        if (viewState.locating || !navigator.geolocation) { viewState.error = "Location is not available in this browser."; return; }
        viewState.locating = true; viewState.error = "";
        navigator.geolocation.getCurrentPosition(function (position) {
            viewState.locating = false; viewState.form.latitude = position.coords.latitude; viewState.form.longitude = position.coords.longitude; viewState.form.coordinate_accuracy = Math.round(position.coords.accuracy || 0);
            scheduleMap();
        }, function () { viewState.locating = false; viewState.error = "Location permission was not granted."; }, {enableHighAccuracy:true,timeout:12000,maximumAge:30000});
    }
    function chooseFiles(event) {
        selectedFiles = Array.prototype.slice.call(event.target.files || []).filter(function (file) {
            return ["image/jpeg","image/png","image/webp"].indexOf(file.type) >= 0 && file.size <= 10485760;
        });
        replaceItems(viewState.files,selectedFiles.map(function (file) { return {name:file.name,size:file.size,type:file.type}; }));
        if (selectedFiles.length !== (event.target.files || []).length) { viewState.error = "Some files were skipped because they were not JPG, PNG or WebP under 10 MB."; }
    }
    function uploadPhotos() {
        if (viewState.uploading || !viewState.form.id || !selectedFiles.length) { return; }
        viewState.uploading = true; viewState.error = "";
        exports.getAppComponent("davvag-tools","davvag-file-uploader",function (uploader) {
            uploader.initialize();
            uploader.upload(selectedFiles,"travel_destination_media",viewState.form.id,function () {
                var pending = selectedFiles.map(function (file,index) {
                    return api.services.AssociateDestinationMedia({destination_id:viewState.form.id,media_reference:"components/dock/soss-uploader/service/get/travel_destination_media/"+viewState.form.id+"-"+file.name,file_size:file.size,alternative_text:viewState.form.name+" photo",display_order:index});
                });
                Promise.all(pending).then(function () { viewState.uploading = false; selectedFiles = []; replaceItems(viewState.files,[]); viewState.notice = "Photos uploaded and sent for moderation."; },function () { viewState.uploading = false; viewState.error = "Photos uploaded, but one or more relationships could not be saved."; });
            });
        });
    }
    function replaceItems(target,items) { target.splice.apply(target,[0,target.length].concat(Array.isArray(items) ? items : [])); }
    function copyForm() { return JSON.parse(JSON.stringify(viewState.form)); }
    function navigate(path) { if (router && router.appNavigate) { router.appNavigate(path); } else { window.location.hash = "#/app/travel-destinations"+path; } }
    function queryValue(name) { var match = new RegExp("[?&]"+name+"=([^&]+)").exec(window.location.hash); return match ? decodeURIComponent(match[1]) : ""; }
    function message(response,fallback) { return response && response.result && response.result.message ? response.result.message : fallback; }
    function searchAddress(){
        var query=String(viewState.addressQuery||"").trim();
        if(viewState.searchingLocation||!query){return;}
        maps=maps||resolveMaps(exports);
        if(!maps||typeof maps.geocode!=="function"){viewState.mapError="The Google Maps runtime is unavailable. Refresh the page and try again.";return;}
        viewState.searchingLocation=true;viewState.mapError="";
        maps.geocode(viewState.mapConfig,query).then(function(result){
            viewState.searchingLocation=false;setCoordinates(result);
            if(result.formattedAddress&&!viewState.form.location_description){viewState.form.location_description=result.formattedAddress;}
            scheduleMap();
        }).catch(function(error){viewState.searchingLocation=false;viewState.mapError=error.message||"Location search failed.";});
    }
    function mapUrlPasted(){setTimeout(extractMapUrl,0);}
    function extractMapUrl(){
        var value=String(viewState.locationUrl||"").trim();
        if(viewState.resolvingMapUrl||!value){return;}
        viewState.error="";viewState.mapError="";viewState.notice="";
        var coordinates=coordinatesFromMapUrl(value);
        if(coordinates){applyExtractedCoordinates(coordinates);return;}
        viewState.resolvingMapUrl=true;
        api.services.ResolveMapLocationUrl({url:value}).then(function(response){
            viewState.resolvingMapUrl=false;
            if(response&&response.success&&response.result){applyExtractedCoordinates({lat:response.result.latitude,lng:response.result.longitude});}
            else{viewState.error=message(response,"Coordinates could not be extracted from that Google Maps URL.");}
        }).error(function(response){viewState.resolvingMapUrl=false;viewState.error=message(response,"Coordinates could not be extracted from that Google Maps URL.");});
    }
    function applyExtractedCoordinates(coordinates){
        setCoordinates(coordinates);viewState.notice="Full-precision coordinates were extracted from the map URL and the marker was moved.";scheduleMap();
    }
    function coordinatesFromMapUrl(value){
        var candidates=[String(value||"")];
        try{candidates.push(decodeURIComponent(candidates[0]));}catch(ignore){}
        var patterns=[
            /!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i,
            /@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)(?:[,/]|$)/i,
            /[?&](?:q|query|ll|center|destination|daddr)=(-?\d{1,2}(?:\.\d+)?)(?:%2C|,|\s+)(-?\d{1,3}(?:\.\d+)?)/i,
            /(?:^|[^0-9.-])(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)(?:[^0-9.]|$)/
        ];
        for(var candidateIndex=0;candidateIndex<candidates.length;candidateIndex++){
            for(var patternIndex=0;patternIndex<patterns.length;patternIndex++){
                var match=patterns[patternIndex].exec(candidates[candidateIndex]);
                if(match){
                    var lat=Number(match[1]),lng=Number(match[2]);
                    if(isFinite(lat)&&isFinite(lng)&&lat>=-90&&lat<=90&&lng>=-180&&lng<=180){return{lat:lat,lng:lng};}
                }
            }
        }
        return null;
    }
    function setCoordinates(point){
        viewState.form.latitude=roundCoordinate(point.lat);viewState.form.longitude=roundCoordinate(point.lng);
        if(mapHandle&&mapHandle.setPosition){mapHandle.setPosition(0,point);}
        if(mapHandle&&mapHandle.map){mapHandle.map.panTo({lat:Number(point.lat),lng:Number(point.lng)});}
    }
    function scheduleMap(){if(viewState.step===3&&viewState.mapConfig&&viewState.mapConfig.enabled){setTimeout(renderMap,0);}}
    function renderMap(){
        var container=rootElement&&rootElement.find?rootElement.find("[data-google-picker-map]")[0]:document.querySelector("[data-google-picker-map]");
        if(!container){return;}
        maps=maps||resolveMaps(exports);
        if(!maps||typeof maps.createMap!=="function"){viewState.mapError="The Google Maps runtime is unavailable. Refresh the page and try again.";return;}
        var hasCoordinates=isFinite(Number(viewState.form.latitude))&&isFinite(Number(viewState.form.longitude))&&viewState.form.latitude!==null&&viewState.form.longitude!==null;
        var point=hasCoordinates?{latitude:Number(viewState.form.latitude),longitude:Number(viewState.form.longitude),name:viewState.form.name||"Selected location"}:{latitude:viewState.mapConfig.defaultCenter.lat,longitude:viewState.mapConfig.defaultCenter.lng,name:"Choose a location"};
        viewState.mapError="";
        maps.createMap(container,viewState.mapConfig,{
            center:point,zoom:hasCoordinates?14:viewState.mapConfig.defaultZoom,points:[point],draggable:true,
            onMapClick:function(position){setCoordinates(position);},
            onPositionChanged:function(position){setCoordinates(position);}
        }).then(function(result){mapHandle=result;}).catch(function(error){viewState.mapError=error.message||"Google Maps could not be loaded.";});
    }
    function resolveMaps(componentExports){
        var registered=componentExports.getComponent("google-map-runtime");
        return window.TravelDestinationGoogleMaps||(registered&&registered.runtime)||registered;
    }
    function roundCoordinate(value){return Number(Number(value).toFixed(7));}
});
