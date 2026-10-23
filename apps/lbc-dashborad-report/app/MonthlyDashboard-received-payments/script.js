WEBDOCK.component().register(function(exports){
    var scope;
    var loading = false;

    function formatDate(date){
        return date.getFullYear() + "-" + (date.getMonth() + 1) + "-" + date.getDate();
    }

    function loadReport(){
        if (loading){
            return;
        }

        var start = new Date($('#receivedPaymentsStartDate').val());
        var end = new Date($('#receivedPaymentsEndDate').val());
        if (isNaN(start.getTime()) || isNaN(end.getTime())){
            scope.message = "Please select a valid date range.";
            return;
        }

        loading = true;
        scope.message = "Loading received payments...";
        var handler = exports.getComponent("rpt-handler");
        handler.services.ReceivedPayments({
            startdate: formatDate(start),
            enddate: formatDate(end)
        }).then(function(result){
            scope.items = result.result || {};
            scope.message = Object.keys(scope.items).length ? "" : "No received payments found for this date range.";
            loading = false;
        }).error(function(){
            scope.message = "Unable to load received payments.";
            loading = false;
        });
    }

    function sum(year, month, field){
        var rows = scope.items[year] || [];
        return rows.reduce(function(total, item){
            return total + Number(item[month][field] || 0);
        }, 0);
    }

    exports.vue = {
        methods: {
            refreshData: loadReport,
            sum: sum
        },
        data: {
            items: {},
            startDate: "",
            endDate: "",
            message: ""
        },
        onReady: function(s){
            scope = s;
            var now = new Date();
            var firstDay = new Date(now.getFullYear(), 0, 1);
            var lastDay = new Date(now.getFullYear(), 11, 31);

            scope.startDate = (firstDay.getMonth() + 1) + "-" + firstDay.getDate() + "-" + firstDay.getFullYear();
            scope.endDate = (lastDay.getMonth() + 1) + "-" + lastDay.getDate() + "-" + lastDay.getFullYear();

            $('#receivedPaymentsStartDate').val(scope.startDate).datepicker();
            $('#receivedPaymentsEndDate').val(scope.endDate).datepicker();
            loadReport();
        },
        filters: {
            currency: function(value){
                return Number(value || 0).toFixed(2);
            }
        }
    };

    exports.onReady = function(element){};
});
