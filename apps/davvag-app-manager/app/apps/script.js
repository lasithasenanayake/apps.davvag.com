WEBDOCK.component().register(function(exports){
    var scope, Rini, handler;

    function toArray(value){
        if (!value)
            return [];

        return value.constructor === Array ? value : [value];
    }

    function normalizeText(value){
        if (value === undefined || value === null)
            return "";

        return value.toString().toLowerCase();
    }

    function trimText(value){
        return normalizeText(value).replace(/^\s+|\s+$/g, "");
    }

    function containsText(value, query){
        return normalizeText(value).indexOf(query) > -1;
    }

    function isSelected(value){
        return value === true || value === 1 || value === "1" || value === "Y" || value === "true";
    }

    function normalizeSelectable(item){
        item = item || {};
        item.selected = isSelected(item.selected);
        return item;
    }

    function normalizeApplications(apps){
        apps = toArray(apps);

        for (var i=0;i<apps.length;i++){
            var app = apps[i] || {};
            app.Services = toArray(app.Services);
            app.Apps = toArray(app.Apps);
            app.Schemas = toArray(app.Schemas);

            for (var s=0;s<app.Services.length;s++){
                var service = app.Services[s] || {};
                service.methods = toArray(service.methods);

                for (var m=0;m<service.methods.length;m++)
                    service.methods[m] = normalizeSelectable(service.methods[m]);

                app.Services[s] = service;
            }

            for (var a=0;a<app.Apps.length;a++)
                app.Apps[a] = normalizeSelectable(app.Apps[a]);

            for (var sc=0;sc<app.Schemas.length;sc++)
                app.Schemas[sc] = normalizeSelectable(app.Schemas[sc]);

            apps[i] = app;
        }

        return apps;
    }

    function setBusy(isBusy){
        if (!scope)
            return;

        scope.isBusy = isBusy;
    }

    function getErrorMessage(result, fallback){
        if (!result)
            return fallback;

        if (typeof(result) === "string")
            return result;

        if (result.message)
            return result.message;

        if (result.result){
            if (typeof(result.result) === "string")
                return result.result;

            if (result.result.message)
                return result.result.message;
        }

        return fallback;
    }

    function hasGroup(groupid){
        if (!scope || !scope.groups.length)
            return true;

        for (var i=0;i<scope.groups.length;i++){
            if (scope.groups[i].groupid === groupid)
                return true;
        }

        return false;
    }

    function validateGroup(){
        if (!scope.Group){
            scope.submitErrors.push("Please select a user group.");
            return false;
        }

        if (!hasGroup(scope.Group)){
            scope.submitErrors.push("Selected user group is not available.");
            return false;
        }

        return true;
    }

    function loadGroups(){
        setBusy(true);
        scope.submitErrors = [];
        scope.submitInfo = [];

        handler.services.UserGroups().then(function(result){
            scope.groups = toArray(result.result);

            if (!hasGroup(scope.Group) && scope.groups.length)
                scope.Group = scope.groups[0].groupid;

            loadAccess();
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to load user groups."));
        });
    }

    function loadAccess(){
        scope.submitErrors = [];
        scope.submitInfo = [];

        if (!validateGroup())
            return;

        setBusy(true);
        handler.services.allApplications({"Group":scope.Group}).then(function(result){
            if (result.success === false){
                scope.items = [];
                scope.loaded = false;
                scope.submitErrors.push(getErrorMessage(result, "Unable to load application permissions."));
            }else{
                scope.items = normalizeApplications(result.result);
                scope.loaded = true;
            }

            setBusy(false);
        }).error(function(result){
            scope.items = [];
            scope.loaded = false;
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to load application permissions."));
        });
    }

    function validateBeforeSave(){
        scope.submitErrors = [];
        scope.submitInfo = [];

        if (scope.isBusy){
            scope.submitErrors.push("Please wait until loading is complete.");
            return false;
        }

        if (!validateGroup())
            return false;

        if (!scope.loaded || !scope.items.length){
            scope.submitErrors.push("No applications were loaded for the selected group.");
            return false;
        }

        return true;
    }

    function saveAccess(){
        if (!validateBeforeSave())
            return;

        setBusy(true);
        handler.services.SetAccess({"groupid":scope.Group,"data":scope.items}).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to save permissions."));
                return;
            }

            if (result.result && result.result.error){
                scope.submitErrors.push(result.result.message || "Unable to save permissions.");
                return;
            }

            scope.submitInfo.push("Permissions saved successfully.");
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to save permissions."));
        });
    }

    function serviceHasSelected(service){
        var methods = toArray(service.methods);
        for (var i=0;i<methods.length;i++){
            if (isSelected(methods[i].selected))
                return true;
        }

        return false;
    }

    function itemHasSelected(item){
        for (var i=0;i<toArray(item.Services).length;i++){
            if (serviceHasSelected(item.Services[i]))
                return true;
        }

        for (var a=0;a<toArray(item.Apps).length;a++){
            if (isSelected(item.Apps[a].selected))
                return true;
        }

        for (var s=0;s<toArray(item.Schemas).length;s++){
            if (isSelected(item.Schemas[s].selected))
                return true;
        }

        return false;
    }

    function appMatches(item, query){
        return !query ||
            containsText(item.Name, query) ||
            containsText(item.appCode, query) ||
            containsText(item.Error, query);
    }

    function serviceMatches(service, query){
        if (!query)
            return true;

        if (containsText(service.Code, query) || containsText(service.Name, query) || containsText(service.Description, query))
            return true;

        var methods = toArray(service.methods);
        for (var i=0;i<methods.length;i++){
            if (methodMatches(methods[i], query))
                return true;
        }

        return false;
    }

    function methodMatches(method, query){
        return !query ||
            containsText(method.name, query) ||
            containsText(method.Code, query);
    }

    function subAppMatches(app, query){
        return !query ||
            containsText(app.Code, query) ||
            containsText(app.Name, query) ||
            containsText(app.Description, query);
    }

    function schemaMatches(schema, query){
        return !query ||
            containsText(schema.Code, query) ||
            containsText(schema.Name, query) ||
            containsText(schema.FileName, query);
    }

    function itemMatches(item){
        var query = trimText(scope.appSearch);

        if (scope.selectedOnly && !itemHasSelected(item))
            return false;

        if (appMatches(item, query))
            return true;

        for (var i=0;i<toArray(item.Services).length;i++){
            if (serviceMatches(item.Services[i], query))
                return true;
        }

        for (var a=0;a<toArray(item.Apps).length;a++){
            if (subAppMatches(item.Apps[a], query))
                return true;
        }

        for (var s=0;s<toArray(item.Schemas).length;s++){
            if (schemaMatches(item.Schemas[s], query))
                return true;
        }

        return false;
    }

    function filterCollection(list, item, matcher, selectedGetter){
        var query = trimText(scope.appSearch);
        var parentMatches = appMatches(item, query);
        var filtered = [];

        list = toArray(list);
        for (var i=0;i<list.length;i++){
            var current = list[i];

            if (scope.selectedOnly && selectedGetter && !selectedGetter(current))
                continue;

            if (parentMatches || matcher(current, query))
                filtered.push(current);
        }

        return filtered;
    }

    function clearFilters(){
        scope.appSearch = "";
        scope.selectedOnly = false;
    }

    var vueData = {
        methods:{
            navigate: function(e){
                scope.item = e ? e : {};
                $('#modalImagePopup').modal('show');
            },
            canceluserForm:function(){
                scope.item={};
                $('#modalImagePopup').modal('toggle');
            },
            saveAccess:saveAccess,
            check:function(){
                scope.submitInfo = [];
            },
            loadAccess:loadAccess,
            clearFilters:clearFilters,
            filteredServices:function(item){
                return filterCollection(item.Services, item, serviceMatches, serviceHasSelected);
            },
            filteredMethods:function(service, item){
                var query = trimText(scope.appSearch);
                var parentMatches = appMatches(item, query) || serviceMatches(service, query);
                var filtered = [];
                var methods = toArray(service.methods);

                for (var i=0;i<methods.length;i++){
                    if (scope.selectedOnly && !isSelected(methods[i].selected))
                        continue;

                    if (parentMatches || methodMatches(methods[i], query))
                        filtered.push(methods[i]);
                }

                return filtered;
            },
            filteredApps:function(item){
                return filterCollection(item.Apps, item, subAppMatches, function(app){ return isSelected(app.selected); });
            },
            filteredSchemas:function(item){
                return filterCollection(item.Schemas, item, schemaMatches, function(schema){ return isSelected(schema.selected); });
            },
            countSelected:function(item){
                var count = 0;

                for (var i=0;i<toArray(item.Services).length;i++){
                    var methods = toArray(item.Services[i].methods);
                    for (var m=0;m<methods.length;m++){
                        if (isSelected(methods[m].selected))
                            count++;
                    }
                }

                for (var a=0;a<toArray(item.Apps).length;a++){
                    if (isSelected(item.Apps[a].selected))
                        count++;
                }

                for (var s=0;s<toArray(item.Schemas).length;s++){
                    if (isSelected(item.Schemas[s].selected))
                        count++;
                }

                return count;
            }
        },
        computed:{
            filteredItems:function(){
                var filtered = [];

                for (var i=0;i<this.items.length;i++){
                    if (itemMatches(this.items[i]))
                        filtered.push(this.items[i]);
                }

                return filtered;
            },
            selectedCount:function(){
                var count = 0;

                for (var i=0;i<this.items.length;i++)
                    count += this.countSelected(this.items[i]);

                return count;
            }
        },
        data :{
            items : [],
            item:{},
            submitErrors:[],
            submitInfo:[],
            Group:"anonymous",
            groups:[],
            appSearch:"",
            selectedOnly:false,
            isBusy:false,
            loaded:false
        },
        onReady: function(s){
            handler= exports.getComponent("app-handler");
            scope = s;
            loadGroups();

            Rini = exports.getShellComponent("soss-routes");
            if (Rini && Rini.getInputData)
                Rini.getInputData();
        }
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
});
