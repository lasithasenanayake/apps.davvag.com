WEBDOCK.component().register(function(exports){
    var scope;
    var loading = false;

    function formatRequestDate(date){
        return date.getFullYear() + "-" + (date.getMonth() + 1) + "-" + date.getDate();
    }

    function numberValue(value){
        var number = Number(value);
        return isNaN(number) ? 0 : number;
    }

    function prepareRows(rows){
        var cumulativeBillings = 0;
        var cumulativeCollections = 0;
        return (rows || []).map(function(row){
            row.grossBillings = numberValue(row.grossBillings);
            row.cashCollections = numberValue(row.cashCollections);
            row.invoiceCount = numberValue(row.invoiceCount);
            row.receiptCount = numberValue(row.receiptCount);
            row.collectionVariance = row.grossBillings - row.cashCollections;
            row.collectionRate = row.grossBillings > 0 ? (row.cashCollections / row.grossBillings) * 100 : null;
            cumulativeBillings += row.grossBillings;
            cumulativeCollections += row.cashCollections;
            row.cumulativeVariance = cumulativeBillings - cumulativeCollections;
            return row;
        });
    }

    function recalculateTotals(){
        var totals = {
            grossBillings: 0,
            cashCollections: 0,
            collectionVariance: 0,
            collectionRate: null,
            invoiceCount: 0,
            receiptCount: 0
        };
        scope.rows.forEach(function(row){
            totals.grossBillings += row.grossBillings;
            totals.cashCollections += row.cashCollections;
            totals.invoiceCount += row.invoiceCount;
            totals.receiptCount += row.receiptCount;
        });
        totals.collectionVariance = totals.grossBillings - totals.cashCollections;
        totals.collectionRate = totals.grossBillings > 0
            ? (totals.cashCollections / totals.grossBillings) * 100
            : null;
        scope.totals = totals;
    }

    function loadReport(){
        if (loading){
            return;
        }

        var start = new Date($('#billingCollectionsStartDate').val());
        var end = new Date($('#billingCollectionsEndDate').val());
        if (isNaN(start.getTime()) || isNaN(end.getTime()) || start > end){
            scope.message = "Please select a valid date range.";
            return;
        }

        loading = true;
        scope.message = "Loading reconciliation...";
        var handler = exports.getComponent("rpt-handler");
        handler.services.BillingCollectionsReconciliation({
            startdate: formatRequestDate(start),
            enddate: formatRequestDate(end)
        }).then(function(result){
            scope.rows = prepareRows(result.result || []);
            recalculateTotals();
            scope.message = scope.rows.length ? "" : "No accounting activity found for this date range.";
            loading = false;
        }).error(function(){
            scope.message = "Unable to load the billing and collections reconciliation.";
            loading = false;
        });
    }

    exports.vue = {
        data: {
            rows: [],
            totals: {
                grossBillings: 0,
                cashCollections: 0,
                collectionVariance: 0,
                collectionRate: null,
                invoiceCount: 0,
                receiptCount: 0
            },
            startDate: "",
            endDate: "",
            message: ""
        },
        methods: {
            refreshData: loadReport,
            printReport: function(){ window.print(); },
            formatMoney: function(value){
                return numberValue(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            },
            formatPercent: function(value){
                return value === null ? "N/A" : numberValue(value).toFixed(1) + "%";
            },
            varianceClass: function(value){
                return numberValue(value) > 0 ? "reconciliation-gap" : "reconciliation-covered";
            }
        },
        onReady: function(s){
            scope = s;
            var now = new Date();
            var firstDay = new Date(now.getFullYear(), 0, 1);
            var lastDay = new Date(now.getFullYear(), 11, 31);
            scope.startDate = (firstDay.getMonth() + 1) + "-" + firstDay.getDate() + "-" + firstDay.getFullYear();
            scope.endDate = (lastDay.getMonth() + 1) + "-" + lastDay.getDate() + "-" + lastDay.getFullYear();
            $('#billingCollectionsStartDate').val(scope.startDate).datepicker();
            $('#billingCollectionsEndDate').val(scope.endDate).datepicker();
            loadReport();
        }
    };

    exports.onReady = function(element){};
});
