WEBDOCK.component().register(function(exports){
    var scope, Rini, handler;

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

    function setBusy(isBusy){
        scope.isBusy = isBusy;
    }

    function defaultGroupId(){
        if (scope.groups.length)
            return scope.groups[0].groupid;

        return "";
    }

    function loadGroups(){
        handler.services.UserGroups().then(function(result){
            scope.groups = toArray(result.result);
        }).error(function(result){
            scope.submitErrors.push(getErrorMessage(result, "Unable to load user groups."));
        });
    }

    function loadUsers(){
        setBusy(true);
        scope.submitErrors = [];
        handler.services.allusers().then(function(result){
            scope.items = toArray(result.result);
            setBusy(false);
        }).error(function(result){
            scope.items = [];
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to load users."));
        });
    }

    function searchUsers(){
        var email = trimValue(scope.userSearchEmail);
        scope.submitErrors = [];

        if (email === ""){
            loadUsers();
            return;
        }

        setBusy(true);
        handler.services.SearchUsersByEmail({"email":email}).then(function(result){
            scope.items = toArray(result.result);
            setBusy(false);
        }).error(function(result){
            scope.items = [];
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to search users."));
        });
    }

    function clearSearch(){
        scope.userSearchEmail = "";
        loadUsers();
    }

    function validateUser(){
        var errors = [];
        var email = trimValue(scope.item.email);
        var name = trimValue(scope.item.name);
        var password = scope.item.password || "";
        var confirmPassword = scope.item.confirmpassword || "";

        if (name === "")
            errors.push("Full name is required.");

        if (email === "" || email.indexOf("@") === -1)
            errors.push("A valid email address is required.");

        if (!scope.item.groupid)
            errors.push("Please select a user group.");

        if (password.length < 6)
            errors.push("Password must contain at least 6 characters.");

        if (password !== confirmPassword)
            errors.push("Password mismatch.");

        return errors;
    }

    function saveUser(){
        scope.submitErrors = validateUser();
        scope.submitInfo = [];

        if (scope.submitErrors.length)
            return;

        setBusy(true);
        handler.services.RegisterUser(scope.item).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to save user."));
                return;
            }

            scope.submitInfo.push("User created successfully.");
            $('#modalImagePopup').modal('toggle');
            loadUsers();
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to save user."));
        });
    }

    function validateResetPassword(){
        var errors = [];

        if (!scope.resetItem.userid)
            errors.push("Please select a user.");

        if ((scope.resetPassword || "").length < 6)
            errors.push("Password must contain at least 6 characters.");

        if (scope.resetPassword !== scope.resetConfirmPassword)
            errors.push("Password mismatch.");

        return errors;
    }

    function saveResetPassword(){
        scope.submitErrors = validateResetPassword();
        scope.submitInfo = [];

        if (scope.submitErrors.length)
            return;

        setBusy(true);
        handler.services.AdminResetPassword({"userid":scope.resetItem.userid,"password":scope.resetPassword}).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to reset password."));
                return;
            }

            scope.submitInfo.push("Password reset successfully.");
            $('#modalResetPassword').modal('toggle');
            scope.resetPassword = "";
            scope.resetConfirmPassword = "";
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to reset password."));
        });
    }

    function changeGroup(){
        scope.submitErrors = [];
        scope.submitInfo = [];

        if (!scope.item.userid || !scope.item.groupid){
            scope.submitErrors.push("Please select a user and group.");
            return;
        }

        setBusy(true);
        handler.services.ChangeGroup({"userid":scope.item.userid,"groupid":scope.item.groupid}).then(function(result){
            setBusy(false);

            if (result.success === false){
                scope.submitErrors.push(getErrorMessage(result, "Unable to change group."));
                return;
            }

            $('#modalGroup').modal('toggle');
            scope.submitInfo.push("User group changed successfully.");
        }).error(function(result){
            setBusy(false);
            scope.submitErrors.push(getErrorMessage(result, "Unable to change group."));
        });
    }

    function openGroupManager(){
        $('#modalGroup').modal('hide');
        window.location = "#/app/davvag-useradmin/groups";
    }

    var vueData = {
        methods:{
            navigate: function(e){
                scope.submitErrors = [];
                scope.submitInfo = [];
                scope.item = e ? e : {groupid: defaultGroupId()};
                $('#modalImagePopup').modal({backdrop: 'static', keyboard: false});
            },
            navigateGroup: function(e){
                if (e){
                    scope.submitErrors = [];
                    scope.submitInfo = [];
                    scope.item = e;
                    $('#modalGroup').modal({backdrop: 'static', keyboard: false});
                }
            },
            navigateResetPassword:function(e){
                if (e){
                    scope.submitErrors = [];
                    scope.submitInfo = [];
                    scope.resetItem = e;
                    scope.resetPassword = "";
                    scope.resetConfirmPassword = "";
                    $('#modalResetPassword').modal({backdrop: 'static', keyboard: false});
                }
            },
            canceluserForm:function(){
                scope.item={};
                $('#modalImagePopup').modal('toggle');
            },
            cancelgroupForm:function(){
                scope.item={};
                $('#modalGroup').modal('toggle');
            },
            cancelResetPassword:function(){
                scope.resetItem={};
                scope.resetPassword = "";
                scope.resetConfirmPassword = "";
                $('#modalResetPassword').modal('toggle');
            },
            searchUsers:searchUsers,
            clearSearch:clearSearch,
            loadUsers:loadUsers,
            saveUser:saveUser,
            changeGroup:changeGroup,
            saveResetPassword:saveResetPassword,
            openGroupManager:openGroupManager
        },
        computed:{
            filteredItems:function(){
                var email = normalizeText(this.userSearchEmail);
                if (email === "")
                    return this.items;

                var result = [];
                for (var i=0;i<this.items.length;i++){
                    if (normalizeText(this.items[i].email).indexOf(email) > -1)
                        result.push(this.items[i]);
                }

                return result;
            }
        },
        data :{
            items : [],
            item:{},
            resetItem:{},
            resetPassword:"",
            resetConfirmPassword:"",
            submitErrors:[],
            submitInfo:[],
            groups:[],
            userSearchEmail:"",
            isBusy:false
        },
        onReady: function(s){
            handler= exports.getComponent("user-handler");
            scope = s;
            loadGroups();
            loadUsers();

            Rini = exports.getShellComponent("soss-routes");
            if (Rini && Rini.getInputData)
                Rini.getInputData();
        }
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
});
