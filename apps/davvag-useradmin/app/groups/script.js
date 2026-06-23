WEBDOCK.component().register(function(exports){
    var scope, handler;

    function toArray(value){
        if (!value)
            return [];

        return value.constructor === Array ? value : [value];
    }

    function trimValue(value){
        if (value === undefined || value === null)
            return "";

        return value.toString().replace(/^\s+|\s+$/g, "");
    }

    function normalizeText(value){
        return trimValue(value).toLowerCase();
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

    function isProtectedGroup(groupid){
        return ["anonymous","web_user","facebook_user","sysadmin","sysuser"].indexOf(groupid) > -1;
    }

    function setBusy(isBusy){
        scope.isBusy = isBusy;
    }

    function loadGroups(){
        setBusy(true);
        scope.submitErrors = [];

        handler.services.UserGroups().then(function(result){
            scope.groups = toArray(result.result);
            setBusy(false);
        }).error(function(result){
            scope.groups = [];
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to load user groups."));
        });
    }

    function validateGroup(groupid, originalGroupId){
        var errors = [];

        if (groupid === "")
            errors.push("Group id is required.");

        if (!/^[A-Za-z0-9_-]+$/.test(groupid))
            errors.push("Group id can contain only letters, numbers, underscore and dash.");

        if (originalGroupId && isProtectedGroup(originalGroupId) && groupid !== originalGroupId)
            errors.push("System user groups cannot be renamed.");

        return errors;
    }

    function openNewGroup(){
        scope.submitErrors = [];
        scope.submitInfo = [];
        scope.groupForm = {groupid:"", oldGroupId:""};
        $('#modalGroupEdit').modal({backdrop: 'static', keyboard: false});
    }

    function editGroup(group){
        scope.submitErrors = [];
        scope.submitInfo = [];
        scope.groupForm = {groupid:group.groupid, oldGroupId:group.groupid};
        $('#modalGroupEdit').modal({backdrop: 'static', keyboard: false});
    }

    function cancelGroupForm(){
        scope.groupForm = {groupid:"", oldGroupId:""};
        $('#modalGroupEdit').modal('toggle');
    }

    function saveGroup(){
        var groupid = trimValue(scope.groupForm.groupid);
        var oldGroupId = trimValue(scope.groupForm.oldGroupId);
        scope.submitErrors = validateGroup(groupid, oldGroupId);
        scope.submitInfo = [];

        if (scope.submitErrors.length)
            return;

        setBusy(true);
        handler.services.SaveUserGroup({"groupid":groupid,"oldGroupId":oldGroupId}).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to save user group."));
                return;
            }

            $('#modalGroupEdit').modal('toggle');
            scope.submitInfo.push(oldGroupId ? "User group updated successfully." : "User group created successfully.");
            loadGroups();
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to save user group."));
        });
    }

    function confirmDeleteGroup(group){
        scope.submitErrors = [];
        scope.submitInfo = [];
        scope.groupToDelete = group;
        $('#modalGroupDelete').modal({backdrop: 'static', keyboard: false});
    }

    function cancelDeleteGroup(){
        scope.groupToDelete = {};
        $('#modalGroupDelete').modal('toggle');
    }

    function performDeleteGroup(){
        if (!scope.groupToDelete.groupid){
            scope.submitErrors.push("Please select a group to delete.");
            return;
        }

        setBusy(true);
        handler.services.DeleteUserGroup({"groupid":scope.groupToDelete.groupid}).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to delete user group."));
                return;
            }

            $('#modalGroupDelete').modal('toggle');
            scope.submitInfo.push("User group deleted successfully.");
            scope.groupToDelete = {};
            loadGroups();
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to delete user group."));
        });
    }

    var vueData = {
        methods:{
            loadGroups:loadGroups,
            openNewGroup:openNewGroup,
            editGroup:editGroup,
            cancelGroupForm:cancelGroupForm,
            saveGroup:saveGroup,
            confirmDeleteGroup:confirmDeleteGroup,
            cancelDeleteGroup:cancelDeleteGroup,
            performDeleteGroup:performDeleteGroup,
            isProtectedGroup:isProtectedGroup
        },
        computed:{
            filteredGroups:function(){
                var query = normalizeText(this.groupSearch);
                if (query === "")
                    return this.groups;

                var result = [];
                for (var i=0;i<this.groups.length;i++){
                    if (normalizeText(this.groups[i].groupid).indexOf(query) > -1)
                        result.push(this.groups[i]);
                }

                return result;
            }
        },
        data:{
            groups:[],
            groupSearch:"",
            groupForm:{groupid:"", oldGroupId:""},
            groupToDelete:{},
            submitErrors:[],
            submitInfo:[],
            isBusy:false
        },
        onReady:function(s){
            scope = s;
            handler = exports.getComponent("user-handler");
            loadGroups();
        }
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
});
