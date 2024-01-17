WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,player,captureButton,handleSuccess;

    

    var handleSuccess = function(stream) {
        // Attach the video stream to the video element and autoplay.
        player.srcObject = stream;
    };

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},items:[]
    };

    var vueData =  {
        methods:{
            capture:function(){
                var context = snapshot.getContext('2d');
                // Draw the video frame to the canvas.
                context.drawImage(player, 0, 0, snapshotCanvas.width,
                    snapshotCanvas.height);
                    const stream = player.srcObject;
                    const tracks = stream.getTracks();
                    
                    tracks.forEach(function(track) {
                      track.stop();
                    });
                    player.srcObject=null;
                     exports.Complete(context.canvas.toDataURL());
                //console.log();
            }
            
           
        },
        data :bindData,
        onReady: function(s,data){
            scope=s;
            initialize();
        }
    }

    function initialize(){
         player = document.getElementById('player');
         snapshotCanvas = document.getElementById('snapshot');
         captureButton = document.getElementById('capture');
         navigator.mediaDevices.getUserMedia({video: true})
      .then(handleSuccess);
        //exports.Complete({});
        loadValidator();
    }


    

    function lockForm(){
        $("#form-details :input").prop("disabled", true);
        $("#form-details :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details :input").prop("disabled", false);
        $("#form-details :button").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent ("soss-validator");

        validator_profile = validatorInstance.newValidator (scope);
        validator_profile.map ("data.email",true, "Please enter your full name");
        validator_profile.map ("data.password",true, "Please enter your contact number");
        validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
