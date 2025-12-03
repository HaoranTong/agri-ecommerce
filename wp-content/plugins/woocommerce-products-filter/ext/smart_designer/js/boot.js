'use strict';

import Helper from './helper.js';
import SD from './sd.js';
//02-11-2022
addEventListener('DOMContentLoaded', function (e) {
    var nonce = document.getElementById('woof_sd_nonce').value;
    Helper.ajax('woof_sd_boot', {sd_nonce: nonce}, data => new SD(data));
});

