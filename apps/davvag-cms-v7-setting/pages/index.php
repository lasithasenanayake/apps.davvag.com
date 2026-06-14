<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Davvag CMS v7 settings">
    <title>Davvag CMS v7 Settings</title>
    <style>
        .cms-v7-settings-loader {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f7f8fa;
            color: #172033;
            font-family: Arial, Helvetica, sans-serif;
            transition: opacity 180ms ease, visibility 180ms ease;
        }

        .cms-v7-settings-ready .cms-v7-settings-loader {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        .cms-v7-settings-loader-card {
            width: min(340px, calc(100% - 40px));
            text-align: center;
        }

        .cms-v7-settings-spinner {
            width: 40px;
            height: 40px;
            margin: 0 auto 16px;
            border: 4px solid #d8dee8;
            border-top-color: #0f766e;
            border-radius: 50%;
            animation: cms-v7-settings-spin 850ms linear infinite;
        }

        .cms-v7-settings-loader-card strong {
            display: block;
            margin-bottom: 8px;
            font-size: 19px;
        }

        .cms-v7-settings-loader-card span {
            color: #677386;
            font-size: 14px;
        }

        @keyframes cms-v7-settings-spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <div id="cms-v7-settings-loader" class="cms-v7-settings-loader" role="status" aria-live="polite">
        <div class="cms-v7-settings-loader-card">
            <div class="cms-v7-settings-spinner" aria-hidden="true"></div>
            <strong>Loading CMS settings</strong>
            <span id="cms-v7-settings-loader-text">Preparing editor components...</span>
        </div>
    </div>
    <div id="cms-v7-setting-root" webdock-component="settings-console"></div>
    <script src="lib/jquery.js"></script>
    <script type="text/javascript">
        jQuery.ajaxSetup({cache: false});
    </script>
    <script src="components/davvag-cms-v7/soss-routes-vue/file/vue.min.js"></script>
    <script src="lib/webdock.js" webdockapp="davvag-cms-v7-setting"></script>
    <script type="text/javascript">
        WEBDOCK.onStatusChange(function(status){
            var label = document.getElementById("cms-v7-settings-loader-text");
            if(label && status && status.state){
                label.textContent = status.state === "busy" ? "Loading editor components..." : "Starting settings...";
            }
        });
        WEBDOCK.onReady(function(){
            document.body.classList.add("cms-v7-settings-ready");
            window.setTimeout(function(){
                var loader = document.getElementById("cms-v7-settings-loader");
                if(loader && loader.parentNode){
                    loader.parentNode.removeChild(loader);
                }
            }, 240);
        });
    </script>
</body>
</html>
