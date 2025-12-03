jQuery(document).ready(function ($) {
    'use strict';

    // Label preview

    var settingsTable = $('.awl-label-settings');
    var previewBox = $('#awl-preview');
    var previewLabelContainer = $('#awl-preview .advanced-woo-labels');
    var previewLabel = previewLabelContainer.find('.awl-product-label');
    var previewLabelBefore = previewLabel.find('.awl-label-before');
    var previewLabelAfter = previewLabel.find('.awl-label-after');
    var labelSvg = previewLabel.find('svg');
    var labelSvgLine = labelSvg.find('.d-stroke');
    var previewLabelTextContainer = previewLabelContainer.find('.awl-label-text');
    var previewLabelText = previewLabelContainer.find('.awl-inner-text');
    var previewLabelImage = previewLabelContainer.find('.awl-label-image img');

    var customStylesCheckbox = $('#awl-label-params-settings-custom-styles');

    var inputLabelType = $('#awl-label-params-settings-type');
    var inputLabelText = $('#awl-label-params-settings-text');
    var inputLabelPositionType = $('#awl-label-params-settings-position-type');
    var inputLabelPosition = $('#awl-label-params-settings-position');
    var inputLabelPositionX = $('#awl-label-params-settings-position-x');
    var inputLabelTemplate = $('#awl-label-params-settings-template');
    var inputLabelBgColor = $('#awl-label-params-settings-bg-color');
    var inputLabelTextColor = $('#awl-label-params-settings-text-color');
    var inputLabelFontSize = $('#awl-label-params-settings-font-size');
    var inputLabelFontStyle = $('#awl-label-params-settings-font-style');
    var inputLabelFontWeight = $('#awl-label-params-settings-font-weight');
    var inputLabelLetterSpacing = $('#awl-label-params-settings-letter-spacing');
    var inputLabelOpacity = $('#awl-label-params-settings-opacity');
    var inputLabelPaddingTop = $('#awl-label-params-settings-padding-top');
    var inputLabelPaddingRight = $('#awl-label-params-settings-padding-right');
    var inputLabelPaddingBottom = $('#awl-label-params-settings-padding-bottom');
    var inputLabelPaddingLeft = $('#awl-label-params-settings-padding-left');
    var inputLabelMarginTop = $('#awl-label-params-settings-margin-top');
    var inputLabelMarginRight = $('#awl-label-params-settings-margin-right');
    var inputLabelMarginBottom = $('#awl-label-params-settings-margin-bottom');
    var inputLabelMarginLeft = $('#awl-label-params-settings-margin-left');
    var inputLabelSize = $('#awl-label-params-settings-label-size');
    var inputLabelCustomCss = $('#awl-label-params-settings-custom-css');

    var firstInit = true;


    var variables = {
        '{REGULAR_PRICE}' : '$100',
        '{PRICE}' : '$90',
        '{SALE_PRICE}': '$90',
        '{SAVE_PERCENT\\s*\\|*\\s*([\\d]*)\\s*}': '10',
        '{SAVE_AMOUNT\\s*\\|*\\s*([\\d]*)\\s*}': '10',
        '{SALE_ENDS}': '7',
        '{SYMBOL}': '$',
        '{SKU}': 'AA564',
        '{QTY}': 99,
        '{SALES_NUM\\s*\\|*\\s*([\\d]*)\\s*}' : 26,
        '{REVIEWS_NUM\\s*\\|*\\s*([\\d]*)\\s*}' : 17,
        '{RATING\\s*\\|*\\s*([\\d]*)\\s*}' : 4.73,
        '{ATTR\\:([\\w]+)\\s*}' : 'white, blue',
        '{TAX\\:([\\w]+)\\s*}' : 'Category',
        '{META\\:([\\w]+)\\s*}' : 'some meta',
        '{BR}' : '<br>'
    };


    // on load
    // on label type change
    // on custom styles checkbox change
    function rebuildPreview() {

        previewBox.addClass('awl-rebuild');

        previewLabelContainer.removeAttr( 'style' );
        previewLabel.removeAttr( 'style' );
        previewLabelTextContainer.removeAttr( 'style' );
        previewLabelText.removeAttr( 'style' );
        previewLabelImage.removeAttr( 'style' );
        labelSvg.removeAttr( 'style' );
        labelSvgLine.removeAttr( 'style' );
        previewBox.find('#awl-css').remove();

        var isCustomStylesEnabled = customStylesCheckbox.is(':checked');

        window.setTimeout(function(){
            if ( ! firstInit ) {
                // styles, position
                $('#awl_label_settings .awl-position-on-image [data-option-id="position"], #awl_label_settings .awl-position-on-line [data-option-id="position_x"]').find('input, select, textarea').trigger('change').trigger('keyup');
            } else {
                // all from general except label type
                $('#awl_label_settings').find('td:visible input, td:visible select:not(#awl-label-params-settings-type), td:visible textarea').trigger('change').trigger('keyup');
            }
            if ( isCustomStylesEnabled ) {
                $('#awl_label_settings [data-section="styles"]').find('input:not(#awl-label-params-settings-custom-styles), select, textarea').trigger('change').trigger('keyup');
            }
            previewBox.removeClass('awl-rebuild');
            firstInit = false;
        }, 600);

        window.setTimeout(function(){
            settingsTable.removeClass('awl-first-init');
        }, 2000);

    }

    rebuildPreview();

    customStylesCheckbox.on( 'change', function(e) {
        if ( ! firstInit ) {
            rebuildPreview();
        }
    });

    inputLabelType.on( 'change', function(e) {
        if ( ! firstInit ) {
            rebuildPreview();
        }
    });

    function replaceAll( str, find ) {
        return str.replace(new RegExp(find, 'g'), function(match, p1, p2){
            if ( p1 && match.indexOf('{RATING') !== -1 ) {
                return variables[find].toFixed(p1);
            }
            return variables[find];
        });
    }

    inputLabelText.on( 'keyup input', function(e) {

        var text = $(this).val();

        $.each(variables, function (name, value) {
            text = replaceAll( text, name );
        });

        var html_entities = {
            '\<script\>' : '',
            '\<\/script\>' : '',
            '\<noscript\>' : '',
            '\<\/noscript\>' : '',
            '\<iframe.*?\>' : '',
            '\<noframes\>' : '',
            '\<\/noframes\>' : '',
        };

        $.each(html_entities, function (name, value) {
            text = text.replace(new RegExp(name, "g"), value);
        });

        if ( typeof DOMPurify === 'function' ) {
            text = DOMPurify.sanitize( text );
        }

        previewLabelText.html( text );

    });


    inputLabelPositionType.on( 'change', function(e) {

        var label = $('.advanced-woo-labels');
        var typeClass = '';

        switch ( $(this).val() ) {
            case 'on_image':
                label.prependTo("#awl-preview .image-wrapper");
                typeClass = 'awl-position-type-image';
                break;
            case 'before_title':
                $( "#awl-preview .product-name" ).before( label );
                typeClass = 'awl-position-type-before-title';
                break;
        }

        previewLabelContainer[0].className = previewLabelContainer[0].className.replace( /(awl-position-type-[a-z-]+)/gi , '' );
        previewLabelContainer.addClass(typeClass);

        if ( typeClass === 'awl-position-type-image' ) {
            inputLabelPosition.trigger('change');
        } else {
            inputLabelPositionX.trigger('change');
        }

    });


    inputLabelTemplate.on( 'change', function(e) {
        var label = '';

        switch ( $(this).val() ) {
            case 'standard':
                label = 'awl-type-label';
                break;
            case 'rounded':
                label = 'awl-type-label awl-type-label-rounded';
                break;
            case 'round':
                label = 'awl-type-label awl-type-label-round';
                break;
            case 'triangled':
                label = 'awl-type-label awl-type-label-triangle';
                break;
            case 'angle':
                label = 'awl-type-label awl-type-label-right-angle';
                break;
            default:
                label = 'awl-type-image';
        }

        previewLabel[0].className = previewLabel[0].className.replace( /(awl-type-[a-z-]+)/gi , '' );
        previewLabel.addClass(label);

        if ( label === 'awl-type-image' ) {

            var imageUrl = $(this).val();
            // not uploaded image
            if ( imageUrl.indexOf("image-") === 0 ) {
                imageUrl = awl_vars.img_url + imageUrl + '.png';
            }

            previewLabel.find('.awl-label-image img').attr( 'src', imageUrl );

        } else {

            inputLabelText.trigger('keyup');

        }

    });

    inputLabelPosition.on( 'change', function(e) {
        var style;

        switch ( this.value ) {
            case 'left_top':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "flex-start" };
                break;
            case 'center_top':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "center" };
                break;
            case 'right_top':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "flex-end" };
                break;
            case 'left_center':
                style = { "left" : "0", "top" : "50%", "right" : "auto", "bottom" : "auto", "transform" : "translateY(-50%)", "justify-content" : "flex-start" };
                break;
            case 'center_center':
                style = { "left" : "0", "top" : "50%", "right" : "auto", "bottom" : "auto", "transform" : "translateY(-50%)", "justify-content" : "center"  };
                break;
            case 'right_center':
                style = { "left" : "0", "top" : "50%", "right" : "auto", "bottom" : "auto", "transform" : "translateY(-50%)", "justify-content" : "flex-end" };
                break;
            case 'left_bottom':
                style = { "left" : "0", "right" : "auto", "top" : "auto", "bottom" : "0", "transform" : "none", "justify-content" : "flex-start" };
                break;
            case 'center_bottom':
                style = { "left" : "0", "right" : "auto", "top" : "auto", "bottom" : "0", "transform" : "none", "justify-content" : "center" };
                break;
            case 'right_bottom':
                style = { "left" : "0", "right" : "auto", "top" : "auto", "bottom" : "0", "transform" : "none", "justify-content" : "flex-end" };
                break;
        }

        previewLabelContainer.css( style );

    });

    inputLabelPositionX.on( 'change', function(e) {
        var style;

        switch ( this.value ) {
            case 'left':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "flex-start"  };
                break;
            case 'center':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "center"  };
                break;
            case 'right':
                style = { "left" : "0", "top" : "0", "right" : "auto", "bottom" : "auto", "transform" : "none", "justify-content" : "flex-end"  };
                break;
        }

        previewLabelContainer.css( style );

    });

    inputLabelBgColor.on( 'change', function(e) {
        previewLabelTextContainer.css( 'background-color', $(this).val() );
        labelSvg.css( 'fill', $(this).val() );
    });

    inputLabelTextColor.on( 'change', function(e) {
        previewLabel.css( 'color', $(this).val() );
    });

    inputLabelFontSize.on( 'keyup input', function(e) {
        previewLabel.css( 'font-size', $(this).val() + 'px' );
    });

    inputLabelLetterSpacing.on( 'keyup input', function(e) {
        previewLabel.css( 'letter-spacing', $(this).val() + 'px' );
    });

    inputLabelFontWeight.on( 'change', function(e) {
        previewLabel.css( 'font-weight', $(this).val() );
    });

    inputLabelFontStyle.on( 'change', function(e) {
        previewLabel.css( 'font-style', $(this).val() );
    });

    inputLabelPaddingTop.on( 'keyup input', function(e) {
        previewLabelTextContainer.css( 'padding-top', $(this).val()+'em' );
    });

    inputLabelPaddingRight.on( 'keyup input', function(e) {
        previewLabelTextContainer.css( 'padding-right', $(this).val()+'em' );
    });

    inputLabelPaddingBottom.on( 'keyup input', function(e) {
        previewLabelTextContainer.css( 'padding-bottom', $(this).val()+'em' );
    });

    inputLabelPaddingLeft.on( 'keyup input', function(e) {
        previewLabelTextContainer.css( 'padding-left', $(this).val()+'em' );
    });

    inputLabelMarginTop.on( 'keyup input', function(e) {
        previewLabel.css( 'margin-top', $(this).val()+'px' );
    });

    inputLabelMarginRight.on( 'keyup input', function(e) {
        previewLabel.css( 'margin-right', $(this).val()+'px' );
    });

    inputLabelMarginBottom.on( 'keyup input', function(e) {
        previewLabel.css( 'margin-bottom', $(this).val()+'px' );
    });

    inputLabelMarginLeft.on( 'keyup input', function(e) {
        previewLabel.css( 'margin-left', $(this).val()+'px' );
    });

    inputLabelOpacity.on( 'keyup input', function(e) {
        previewLabel.css( 'opacity', $(this).val() );
    });

    inputLabelSize.on( 'keyup input', function(e) {
        previewLabelImage.css( 'width', $(this).val()+'px' );
    });

    inputLabelCustomCss.on( 'keyup input', function(e) {

        var css = $(this).val();
        var cssBox = $('#awl-css');

        if ( cssBox.length > 0 ) {
            cssBox.text( css );
        } else {
            previewLabelContainer.before( $('<style id="awl-css">'+css+'</style>') );
        }

    });

});