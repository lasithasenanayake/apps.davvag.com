WEBDOCK.component().register(function (exports) {
    var service, renderDiv, disposed = false;
    var bindData = {
        submitErrors: [], data: {}, messages: [], message: "", sending: false, loading: true,
        profile: localStorage.profile ? JSON.parse(localStorage.profile) : {id: 0}
    };

    function decode(value) {
        try { return decodeURIComponent(String(value || "")); }
        catch (error) { return String(value || ""); }
    }

    function scrollToBottom() {
        if (typeof Vue === "undefined") { return; }
        Vue.nextTick(function () {
            if (disposed) { return; }
            var element = renderDiv && renderDiv.find ? renderDiv.find("#message_data")[0] : document.getElementById("message_data");
            if (element) { element.scrollTop = element.scrollHeight; }
        });
    }

    function loadMessages() {
        bindData.loading = true;
        bindData.submitErrors = [];
        service.services.Messages({id: bindData.data.storeid || bindData.data.pid, page: "0", size: "40", lastid: "0"}).then(function (result) {
            if (disposed) { return; }
            bindData.loading = false;
            if (result.success && Array.isArray(result.result)) {
                bindData.messages = result.result.map(function (message) {
                    return Object.assign({}, message, {messagetext: decode(message.messagetext)});
                });
                scrollToBottom();
            } else {
                bindData.submitErrors = ["Unable to load messages. Please try again."];
            }
        }).error(function () {
            if (disposed) { return; }
            bindData.loading = false;
            bindData.submitErrors = ["Unable to load messages. Please try again."];
        });
    }

    function send() {
        var text = bindData.message.trim();
        if (!text || text.length > 500 || bindData.sending || bindData.loading) { return; }
        bindData.sending = true;
        bindData.submitErrors = [];
        service.services.SendMessage({id: bindData.data.storeid || bindData.data.pid, messagetype: "text", message: encodeURIComponent(text)}).then(function (result) {
            if (disposed) { return; }
            bindData.sending = false;
            if (result.success && result.result) {
                bindData.messages.push(Object.assign({}, result.result, {messagetext: decode(result.result.messagetext)}));
                bindData.message = "";
                scrollToBottom();
            } else {
                bindData.submitErrors = ["Your message was not sent. Please try again."];
            }
        }).error(function () {
            if (disposed) { return; }
            bindData.sending = false;
            bindData.submitErrors = ["Your message was not sent. Please try again."];
        });
    }

    exports.vue = {
        data: bindData,
        onReady: function (scope, context) {
            bindData.data = context && context.data && typeof context.data === "object" ? context.data : {};
            renderDiv = context && context.renderDiv || exports.renderDiv;
            service = exports.getComponent("p_svr");
            loadMessages();
        },
        beforeDestroy: function () { disposed = true; },
        computed: {TextLength: function () { return this.message.length; }},
        methods: {
            send: send,
            retry: loadMessages,
            isOwn: function (message) { return String(message.m_from) === String(bindData.profile.id); }
        }
    };
});
