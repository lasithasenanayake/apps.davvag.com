var failures = 0;

var WEBDOCK = {
    component: function () {
        return {
            register: function () {}
        };
    }
};

for (var index = 0; index < WScript.Arguments.length; index++) {
    var path = WScript.Arguments.Item(index);
    try {
        var stream = new ActiveXObject("ADODB.Stream");
        stream.Type = 2;
        stream.Charset = "utf-8";
        stream.Open();
        stream.LoadFromFile(path);
        var source = stream.ReadText();
        stream.Close();
        eval(source);
        WScript.Echo("JavaScript syntax OK: " + path);
    } catch (error) {
        failures++;
        WScript.Echo("JavaScript syntax failed: " + path + ": " + error.message);
    }
}

WScript.Quit(failures ? 1 : 0);
