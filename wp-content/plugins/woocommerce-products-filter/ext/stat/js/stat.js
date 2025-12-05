"use strict";

jQuery(function ($) {
    //stat collection
    if (woof_current_values.hasOwnProperty(swoof_search_slug)) {
	var nonce = woof_front_nonce;
        var data = {
            action: "woof_write_stat",
            woof_current_values: woof_current_values,
	    nonce_filter: nonce
        };
        jQuery.post(woof_ajaxurl, data, function () {
            //***
        });
    }
});


