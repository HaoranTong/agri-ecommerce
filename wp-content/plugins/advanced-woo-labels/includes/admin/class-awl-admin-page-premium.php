<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Page_Premium' ) ) :

    /**
     * Class for plugin admin ajax hooks
     */
    class AWL_Admin_Page_Premium {

        /*
         * Constructor
         */
        public function __construct() {
            
            $this->generate_content();

        }

        /*
         * Generate options fields
         */
        private function generate_content() {

            add_thickbox();

            echo '<div class="links">';
                echo  '<span class="links-title">' . __( 'Website Links:', 'advanced-woo-labels' ) . '</span>';
                echo '<ul>';
                    echo '<li><a target="_blank" href="https://advanced-woo-labels.com/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin">' . __( 'Plugin home page', 'advanced-woo-labels' ) . '</a></li>';
                    echo '<li><a target="_blank" href="https://advanced-woo-labels.com/features/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin">' . __( 'Features', 'advanced-woo-labels' ) . '</a></li>';
                    echo '<li><a target="_blank" href="https://advanced-woo-labels.com/guide/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin">' . __( 'Documentation', 'advanced-woo-labels' ) . '</a></li>';
                    echo '<li><a target="_blank" href="https://advanced-woo-labels.com/faq/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin">' . __( 'FAQ', 'advanced-woo-labels' ) . '</a></li>';
                    echo '<li><a target="_blank" href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin">' . __( 'Pricing', 'advanced-woo-labels' ) . '</a></li>';
                echo '</ul>';
            echo '</div>';


            echo '<div class="buy-premium">';
                echo '<a href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">';
                    echo '<span class="desc">' . __( 'Upgrade to the', 'advanced-woo-labels' ) . '<b> ' . __( 'Premium plugin version', 'advanced-woo-labels' ) . '</b><br>' . __( 'to have all available features!', 'advanced-woo-labels' ) . '</span>';
                    echo '<ul>';
                        echo '<li>' . __( '30-day money back guarantee', 'advanced-woo-labels' ) . '</li>';
                        echo '<li>' . __( 'Priority support', 'advanced-woo-labels' ) . '</li>';
                        echo '<li>' . __( '1 year of support and updates', 'advanced-woo-labels' ) . '</li>';
                    echo '</ul>';
                    echo '</a>';
            echo '</div>';

            echo '<div class="features">';

                echo '<h3>' . __( 'Premium Features', 'advanced-woo-labels' ) . '</h3>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Image Labels', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo  __(  'Use one of predefined images as a product label or upload your custom one. Use png/jpg/gif or SVG images.' , 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/image-labels/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Upload a custom image or use one of predefined', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Use png/jpg/gif/svg images', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Set any image size', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Set image opacity', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-image-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'More Label Shapes', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Choose from a variety of different templates for your text label to attract users attention.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/styling-settings/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Choose from several new label shapes', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-shapes-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                 echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Label Animation', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Set unique animation for each label with different customizable parameters.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/label-animations/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Choose one of predefined animation types', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Customize animation parameters', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Create your own custom animations', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-animation.gif' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Emojis Support', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Use any emoji inside the text label. Mix theme with text variables, plain text to other emojis.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/emojis-support/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Use any emoji inside the label', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Use emojis together with plain text/text vars', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-emoji-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'More Label Conditions', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Number of label conditions has been significantly increased. Now you can create more complex label display rules by using additional conditions.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/label-conditions/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'More product related conditions', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'User based conditions', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Page based conditions', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Date/time conditions', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-cond-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'More Text Variables', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Use some additional text variables to make labels text even more attractive and product-specific.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/text-variables/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Additional text variables', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Attributes, taxonomies, custom fields display', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Math calculations with CALC function', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-text-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Label Custom Links', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Add any custom link inside your label. So now your product labels not just tell users some important information but can contain some useful links.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/label-custom-links/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Link label to any page', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Works with all label types', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-link-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Label Styles', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Use even more label styling options to fully adapt it to your needs.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/styling-settings/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Set label shadows', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Set label borders color', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Set label borders size', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Set label rotation', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Additional label templates', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-styles-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'ACF Plugin Support', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Advanced integration with Advanced Custom Fields plugin. Show value of any ACF field inside label. Also set label display conditions based on ACF fields values.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/advanced-custom-fields-acf-support/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . '</a>';
                            echo '<ul>';
                                echo '<li>' . __( 'Display ACF fields values inside labels', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Conditions based on ACF fields', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-acf-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'WCFM / Dokan / WC Vendors Plugins Support', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'Build-in integration with WCFM Multivendor Marketplace, WC Vendors and Dokan Multivendor Marketplace plugins. Show vendor related data inside product labels and create special labels display conditions based on these data.', 'advanced-woo-labels' );
                            echo '<br><a href="https://advanced-woo-labels.com/guide/wcfm-multivendor-marketplace/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . ' (WCFM)</a>';
                            echo '<br><a href="https://advanced-woo-labels.com/guide/dokan-woocommerce-multivendor-marketplace/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . ' (Dokan)</a>';
                            echo '<br><a href="https://advanced-woo-labels.com/guide/wc-vendors/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">' . __( 'Learn more', 'advanced-woo-labels' ) . ' (WC Vendors)</a>';

                            echo '<ul>';
                                echo '<li>' . __( 'Show vendor data via text variables', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Label display conditions based on vendor data', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-wcfm-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

                echo '<div class="features-item">';
                    echo '<div class="column">';
                        echo '<h4 class="title">';
                            echo __( 'Priority Support', 'advanced-woo-labels' );
                        echo '</h4>';
                        echo '<p class="desc">';
                            echo __( 'You will benefit from our full support for any issues you have with this plugin.', 'advanced-woo-labels' );
                            echo '<ul>';
                                echo '<li>' . __( 'Always ready to help', 'advanced-woo-labels' ) . '</li>';
                                echo '<li>' . __( 'Fast and professional support', 'advanced-woo-labels' ) . '</li>';
                            echo '</ul>';
                        echo '</p>';
                    echo '</div>';
                    echo '<div class="column">';
                        echo '<div class="img">';
                            echo '<img alt="" src="' . AWL_URL . '/assets/img/pro/feature-sup-pro.png' . '" />';
                        echo '</div>';
                    echo '</div>';
                echo '</div>';

            echo '</div>';





            echo '<div class="screenshots">';

                echo '<h3>' . __( 'Screenshots', 'advanced-woo-labels' ) . '</h3>';

                echo '<div class="screenshots-section">';

                    echo '<div class="screenshots-list">';

                        echo '<div class="screen">';
                            echo '<a href="#TB_inline?&width=1200&height=720&inlineId=my-content-id-1" class="thickbox">';
                                echo '<span id="my-content-id-1" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/product-cond-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=540&inlineId=my-content-id-2" class="thickbox">';
                                echo '<span id="my-content-id-2" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/user-cond-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=560&inlineId=my-content-id-3" class="thickbox">';
                                echo '<span id="my-content-id-3" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/page-cond-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1020&height=800&inlineId=my-content-id-8" class="thickbox">';
                                echo '<span id="my-content-id-8" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/image-label-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=450&inlineId=my-content-id-4" class="thickbox">';
                                echo '<span id="my-content-id-4" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/date-cond-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=900&inlineId=my-content-id-5" class="thickbox">';
                                echo '<span id="my-content-id-5" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/text-vars-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=820&inlineId=my-content-id-6" class="thickbox">';
                                echo '<span id="my-content-id-6" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/styling-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                        echo '<div class="screen">';
                             echo '<a href="#TB_inline?&width=1200&height=550&inlineId=my-content-id-7" class="thickbox">';
                                echo '<span id="my-content-id-7" >';
                                    echo '<img class="awl-screen" src="' . AWL_URL . '/assets/img/pro/templates-pro.png' . '">';
                                echo '<span>';
                            echo '</a>';
                        echo '</div>';

                    echo '</div>';

                echo '</div>';

            echo '</div>';

            echo '<div class="faq">';

                echo '<h3>' . __( 'Frequently Asked Questions', 'advanced-woo-labels' ) . '</h3>';

                echo '<div class="faq-item">';
                    echo '<h4 class="question">';
                        echo __( 'Do you offer refunds?', 'advanced-woo-labels' );
                    echo '</h4>';
                    echo '<div class="answer">';
                        echo __( 'If you\'re not completely happy with your purchase and we\'re unable to resolve the issue, let us know and we\'ll refund the full purchase price. Refunds can be processed within 30 days of the original purchase.', 'advanced-woo-labels' );
                    echo '</div>';
                echo '</div>';

                echo '<div class="faq-item">';
                    echo '<h4 class="question">';
                        echo __( 'What payment methods do you accept?', 'advanced-woo-labels' );
                    echo '</h4>';
                    echo '<div class="answer">';
                        echo __( 'Checkout is powered FastSpring company. They supports major credit and debit cards, PayPal, and a variety of other mainstream payment methods, so there’s plenty to pick from.', 'advanced-woo-labels' );
                    echo '</div>';
                echo '</div>';

                echo '<div class="faq-item">';
                    echo '<h4 class="question">';
                        echo __( 'Do you offer support if I need help?', 'advanced-woo-labels' );
                    echo '</h4>';
                    echo '<div class="answer">';
                        echo __( 'Yes! You will benefit of our full support for any issues you have with this plugin.', 'advanced-woo-labels' );
                    echo '</div>';
                echo '</div>';

                echo '<div class="faq-item">';
                    echo '<h4 class="question">';
                        echo __( 'I have other pre-sale questions, can you help?', 'advanced-woo-labels' );
                    echo '</h4>';
                    echo '<div class="answer">';
                        echo __( 'Yes! You can ask us any question through our', 'advanced-woo-labels' ) . ' <a href="https://advanced-woo-labels.com/contact/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=sti-pro-plugin" target="_blank">' . __( 'contact form.', 'advanced-woo-labels' ) . '</a>';
                    echo '</div>';
                echo '</div>';

            echo '</div>';

            echo '<div class="buy-premium">';
                echo '<a href="https://advanced-woo-labels.com/pricing/?utm_source=plugin&utm_medium=premium-tab&utm_campaign=awl-pro-plugin" target="_blank">';
                    echo '<span class="desc">' . __( 'Upgrade to the', 'advanced-woo-labels' ) . '<b> ' . __( 'Premium plugin version', 'advanced-woo-labels' ) . '</b><br>' . __( 'to have all available features!', 'advanced-woo-labels' ) . '</span>';
                echo '</a>';
            echo '</div>';

        }
        
    }

endif;
