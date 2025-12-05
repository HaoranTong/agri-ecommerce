"use strict";

var woof_add_seo_rule_lock = false;

jQuery(document).ready(function () {

    jQuery(".woof_add_seo_rule").on('click', function () {

        if (woof_add_seo_rule_lock) {
            return;
        }

        woof_add_seo_rule_lock = true;

        var url = jQuery('.woof_seo_rule_url_add').val();
        var lang = jQuery('.woof_seo_current_lang').val();
	const nonce = document.querySelector('#woof_url_request_nonce').value;

        var data = {
            action: "woof_get_seo_rule_html",
            url: url,
            lang: lang,
	    url_request_nonce: nonce
        };

        if (jQuery('#woof_seo_rules_list li').length >= 2 && woof_show_notes) {
            alert('In FREE version it is possible to operate with 2 rules only!');
            return;
        }

        jQuery.post(ajaxurl, data, function (section) {
            jQuery('#woof_seo_rules_list').append(section);
            jQuery('.woof_seo_rule_url_add').val("");
            woof_init_seo_rules_scripts();
            woof_add_seo_rule_lock = false;
        });

    });

    jQuery(".woof_seo_current_lang").on('change', function () {
        woof_seo_rules_check_lang();
    });

    woof_init_seo_rules_scripts();
    woof_seo_rules_check_lang();
});

function woof_seo_rules_check_lang() {
    var lang = jQuery('.woof_seo_current_lang').val();
    jQuery('.woof_seo_rules_item').hide();
    jQuery('.woof_seo_rules_item_' + lang).show();
}

function woof_init_seo_rules_scripts() {
    jQuery('.woof_seo_rules_delete').off('click');
    jQuery('.woof_seo_rules_delete').on('click', function () {
        var key = jQuery(this).data('key');
        jQuery("li[data-key='" + key + "']").remove();

        return false;
    });
    jQuery('.woof_seo_rules_move_down').off('click');
    jQuery('.woof_seo_rules_move_down').on('click', function () {
        var key = jQuery(this).data('key');
        var item = jQuery("li[data-key='" + key + "']");
	var after = item.next();
	item.insertAfter(after);

        return false;
    });
    jQuery('.woof_seo_rules_move_up').off('click');
    jQuery('.woof_seo_rules_move_up').on('click', function () {
        var key = jQuery(this).data('key');
        var item = jQuery("li[data-key='" + key + "']");
	var after = item.prev();
	item.insertBefore(after);

        return false;
    });       
}
