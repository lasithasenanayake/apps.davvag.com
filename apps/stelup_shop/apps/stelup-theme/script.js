WEBDOCK.component().register(function () {
    // Reuse CMS assets and their live --cms-* variables. Do not duplicate the theme.
    ["cms-v7.css", "cms-v7-bootstrap-theme.css"].forEach(function (file) {
        var path = "assets/davvag-cms-v7/" + file;
        var exists = Array.prototype.some.call(document.querySelectorAll('link[rel="stylesheet"]'), function (link) {
            return link.href.split("?")[0] === new URL(path, document.baseURI).href;
        });
        if (!exists) {
            var link = document.createElement("link");
            link.rel = "stylesheet";
            link.href = path;
            document.head.appendChild(link);
        }
    });
});
