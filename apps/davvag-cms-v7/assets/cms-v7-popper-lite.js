(function(window){
    "use strict";

    if(window.Popper){
        return;
    }

    function unwrap(element){
        return element && element.jquery ? element[0] : element;
    }

    function LitePopper(reference, popper){
        this.reference = unwrap(reference);
        this.popper = unwrap(popper);
        this.scheduleUpdate = this.update.bind(this);
        this.update();
    }

    LitePopper.prototype.update = function(){
        if(!this.popper){
            return;
        }
        this.popper.style.willChange = "transform";
    };

    LitePopper.prototype.destroy = function(){};
    LitePopper.Defaults = {};

    window.Popper = LitePopper;
})(window);
