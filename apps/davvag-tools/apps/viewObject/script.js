WEBDOCK.component().register(function (exports) {
    var serviceHandler;

    var bindData = {
        loading: false,
        saving: false,
        submitErrors: [],
        submitInfo: [],
        custom: "public",
        items: [],
        valuesLoading: false,
        editingIndex: -1,
        item_type: "user",
        item_permision: "View",
        item_value: "",
        item_values: [],
        viewObjectID: 0
    };

    exports.vue = {
        data: bindData,
        methods: {
            addItem: addItem,
            editItem: editItem,
            removeItem: removeItem,
            clearEditor: clearEditor,
            loadValues: loadValues,
            submit: submit,
            cancel: cancel,
            permissionClass: permissionClass,
            selectedValueText: selectedValueText
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function initialize() {
        serviceHandler = exports.getComponent("viewObjectAPI");
        if (!serviceHandler) {
            setError("Permission service has not loaded.");
            return;
        }

        loadValues();
        if (exports.dataObject !== undefined && exports.dataObject !== null && String(exports.dataObject) !== "0") {
            bindData.custom = "custom";
            bindData.viewObjectID = exports.dataObject;
            loadObject(exports.dataObject);
        } else {
            bindData.custom = "public";
            bindData.viewObjectID = 0;
        }
    }

    function loadObject(objectId) {
        bindData.loading = true;
        serviceHandler.services.FindObject({objectID: objectId}).then(function (result) {
            bindData.loading = false;
            if (result.success) {
                bindData.items = normalizeItems(result.result || []);
                bindData.custom = bindData.items.length > 0 ? "custom" : "public";
                bindData.viewObjectID = bindData.items.length > 0 && bindData.items[0].viewObjectID ? bindData.items[0].viewObjectID : objectId;
            } else {
                setError("Could not load current permissions.");
            }
        }).error(function () {
            bindData.loading = false;
            setError("Could not load current permissions.");
        });
    }

    function loadValues() {
        bindData.valuesLoading = true;
        bindData.item_value = "";
        serviceHandler.services.PermisionValues({item_type: bindData.item_type}).then(function (result) {
            bindData.valuesLoading = false;
            if (result.success) {
                bindData.item_values = result.result || [];
                if (bindData.item_values.length > 0) {
                    bindData.item_value = bindData.item_values[0].val;
                }
            } else {
                setError("Could not load users or groups.");
            }
        }).error(function () {
            bindData.valuesLoading = false;
            setError("Could not load users or groups.");
        });
    }

    function addItem() {
        clearMessages();
        if (!bindData.item_type || !bindData.item_permision || !bindData.item_value) {
            setError("Select a type, name, and permission.");
            return;
        }

        var row = {
            item_type: bindData.item_type,
            item_permision: bindData.item_permision,
            item_value: bindData.item_value,
            item_text: selectedValueText()
        };

        var duplicateIndex = findItemIndex(row.item_type, row.item_value);
        if (bindData.editingIndex >= 0) {
            if (duplicateIndex >= 0 && duplicateIndex !== bindData.editingIndex) {
                setError("That user or group is already in this permission list.");
                return;
            }
            bindData.items.splice(bindData.editingIndex, 1, row);
            bindData.editingIndex = -1;
            setInfo("Permission row updated.");
        } else if (duplicateIndex >= 0) {
            bindData.items.splice(duplicateIndex, 1, row);
            setInfo("Existing permission row updated.");
        } else {
            bindData.items.push(row);
            setInfo("Permission row added.");
        }
    }

    function editItem(index) {
        clearMessages();
        var row = bindData.items[index];
        if (!row) {
            return;
        }
        bindData.editingIndex = index;
        bindData.item_type = row.item_type || "user";
        bindData.item_permision = row.item_permision || "View";
        bindData.item_value = row.item_value || "";
        loadValuesForEdit(row.item_value);
    }

    function loadValuesForEdit(value) {
        bindData.valuesLoading = true;
        serviceHandler.services.PermisionValues({item_type: bindData.item_type}).then(function (result) {
            bindData.valuesLoading = false;
            bindData.item_values = result.success ? (result.result || []) : [];
            bindData.item_value = value;
        }).error(function () {
            bindData.valuesLoading = false;
            setError("Could not load users or groups.");
        });
    }

    function removeItem(index) {
        clearMessages();
        bindData.items.splice(index, 1);
        if (bindData.editingIndex === index) {
            clearEditor();
        }
        setInfo("Permission row removed.");
    }

    function clearEditor() {
        bindData.editingIndex = -1;
        bindData.item_type = "user";
        bindData.item_permision = "View";
        loadValues();
    }

    function submit() {
        clearMessages();
        if (bindData.custom === "public") {
            exports.Complete(0);
            return;
        }
        if (bindData.items.length === 0) {
            setError("Add at least one user or group, or choose Public.");
            return;
        }

        bindData.saving = true;
        serviceHandler.services.Save(bindData.items).then(function (result) {
            bindData.saving = false;
            if (result.success) {
                bindData.items = normalizeItems(result.result || []);
                if (bindData.items.length > 0 && bindData.items[0].viewObjectID !== undefined) {
                    bindData.viewObjectID = bindData.items[0].viewObjectID;
                    exports.Complete(bindData.items[0].viewObjectID);
                } else if (exports.dataObject !== undefined && exports.dataObject !== null) {
                    exports.Complete(exports.dataObject);
                } else {
                    exports.Complete(0);
                }
            } else {
                setError("Could not save permissions.");
            }
        }).error(function () {
            bindData.saving = false;
            setError("Could not save permissions.");
        });
    }

    function cancel() {
        if (exports.dataObject !== undefined && exports.dataObject !== null) {
            exports.Complete(exports.dataObject);
        } else {
            exports.Complete(0);
        }
    }

    function normalizeItems(items) {
        return (items || []).map(function (item) {
            return {
                viewObjectID: item.viewObjectID,
                item_type: item.item_type || "user",
                item_permision: item.item_permision || "View",
                item_value: item.item_value || "",
                item_text: item.item_text || item.item_value || ""
            };
        });
    }

    function selectedValueText() {
        for (var i = 0; i < bindData.item_values.length; i++) {
            if (String(bindData.item_values[i].val) === String(bindData.item_value)) {
                return bindData.item_values[i].text;
            }
        }
        return bindData.item_value;
    }

    function findItemIndex(type, value) {
        for (var i = 0; i < bindData.items.length; i++) {
            if (bindData.items[i].item_type === type && String(bindData.items[i].item_value) === String(value)) {
                return i;
            }
        }
        return -1;
    }

    function permissionClass(item) {
        var permission = String((item || {}).item_permision || "View").toLowerCase();
        if (permission === "full") {
            return "vo-permission-full";
        }
        if (permission === "edit") {
            return "vo-permission-edit";
        }
        return "vo-permission-view";
    }

    function setError(message) {
        bindData.submitErrors.push(message);
    }

    function setInfo(message) {
        bindData.submitInfo.push(message);
    }

    function clearMessages() {
        bindData.submitErrors = [];
        bindData.submitInfo = [];
    }
});
