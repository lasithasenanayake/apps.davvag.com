<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Davvag CMS v7 landing page dock">
    <title>Davvag CMS v7</title>
    <link rel="icon" href="assets/davvag-cms-v7/favicon.svg?v=0.6" type="image/svg+xml">
    <link href="assets/davvag-cms-v7/vendor/bootstrap/bootstrap.min.css?v=0.6" rel="stylesheet">
    <link href="assets/davvag-cms-v7/vendor/font-awesome/font-awesome.min.css?v=0.6" rel="stylesheet">
    <link href="assets/davvag-cms-v7/cms-v7.css?v=0.6" rel="stylesheet">
    <link href="assets/davvag-cms-v7/cms-v7-bootstrap-theme.css?v=0.6" rel="stylesheet">
    <style>
        .cms-v7-boot-loader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            color: #18202f;
            font-family: Arial, Helvetica, sans-serif;
            transition: opacity 180ms ease, visibility 180ms ease;
        }

        .cms-v7-ready .cms-v7-boot-loader {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .cms-v7-boot-card {
            width: min(360px, calc(100% - 40px));
            text-align: center;
        }

        .cms-v7-boot-spinner {
            width: 42px;
            height: 42px;
            margin: 0 auto 18px;
            border: 4px solid #e6eaef;
            border-top-color: #0f766e;
            border-radius: 50%;
            animation: cms-v7-spin 850ms linear infinite;
        }

        .cms-v7-boot-title {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
        }

        .cms-v7-boot-text {
            margin: 0 0 18px;
            color: #647083;
            font-size: 14px;
        }

        .cms-v7-boot-bar {
            height: 4px;
            border-radius: 999px;
            overflow: hidden;
            background: #e6eaef;
        }

        .cms-v7-boot-bar span {
            display: block;
            width: 42%;
            height: 100%;
            border-radius: inherit;
            background: #0f766e;
            animation: cms-v7-loadbar 1150ms ease-in-out infinite;
        }

        @keyframes cms-v7-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes cms-v7-loadbar {
            0% {
                transform: translateX(-110%);
            }
            100% {
                transform: translateX(250%);
            }
        }
    </style>
</head>
<body>
    <div id="cms-v7-boot-loader" class="cms-v7-boot-loader" role="status" aria-live="polite">
        <div class="cms-v7-boot-card">
            <div class="cms-v7-boot-spinner" aria-hidden="true"></div>
            <p class="cms-v7-boot-title">Loading Davvag CMS v7</p>
            <p id="cms-v7-boot-text" class="cms-v7-boot-text">Preparing site components...</p>
            <div class="cms-v7-boot-bar" aria-hidden="true"><span></span></div>
        </div>
    </div>
    <div id="cms-v7-root" webdock-component="dock-shell"></div>
    <script src="lib/jquery.js"></script>
    <script type="text/javascript">
        jQuery.ajaxSetup({cache: false});
    </script>
    <script src="assets/davvag-cms-v7/cms-v7-popper-lite.js?v=0.6"></script>
    <script src="assets/davvag-cms-v7/vendor/bootstrap/bootstrap.min.js?v=0.6"></script>
    <script src="assets/davvag-cms-v7/cms-v7-bootstrap-compat.js?v=0.6"></script>
    <script src="components/davvag-cms-v7/soss-routes-vue/file/vue.min.js"></script>
    <script src="lib/webdock.js" webdockapp="davvag-cms-v7"></script>
    <script type="text/javascript">
        WEBDOCK.onStatusChange(function(status){
            var label = document.getElementById("cms-v7-boot-text");
            if(label && status && status.state){
                label.textContent = status.state === "busy" ? "Loading site components..." : "Starting the site...";
            }
        });
        WEBDOCK.onReady(function(){
            document.body.classList.add("cms-v7-ready");
            window.setTimeout(function(){
                var loader = document.getElementById("cms-v7-boot-loader");
                if(loader && loader.parentNode){
                    loader.parentNode.removeChild(loader);
                }
            }, 240);
        });
        window.setTimeout(function(){
            var label = document.getElementById("cms-v7-boot-text");
            if(label && !document.body.classList.contains("cms-v7-ready")){
                label.textContent = "Still loading components...";
            }
        }, 3500);
        window.addEventListener("error", function(){
            var label = document.getElementById("cms-v7-boot-text");
            if(label && !document.body.classList.contains("cms-v7-ready")){
                label.textContent = "Loading is taking longer than expected.";
            }
        });
    </script>
</body>
</html>
