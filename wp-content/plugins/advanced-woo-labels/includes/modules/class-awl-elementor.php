<?php

/**
 * AWL Elementor plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Elementor')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Elementor {

        /**
         * @var AWL_Elementor Custom data
         */
        public $data = array();

        /**
         * @var AWL_Elementor The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Elementor Instance
         *
         * Ensures only one instance of AWL_Elementor is loaded or can be loaded.
         *
         * @static
         * @return AWL_Elementor - Main instance
         */
        public static function instance()
        {
            if (is_null(self::$_instance)) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Constructor
         */
        public function __construct() {

            // Display hooks
            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            // Add new options for Elementor editor
            add_action( "elementor/element/woocommerce-products/section_content/before_section_end", array( $this, 'editor' ), 10, 2 );
            add_action( "elementor/element/woocommerce-product-related/section_related_products_content/before_section_end", array( $this, 'editor' ), 10, 2 );
            add_action( "elementor/element/woocommerce-product-title/section_title/before_section_end", array( $this, 'editor' ), 10, 2 );
            add_action( "elementor/element/woocommerce-product-images/section_product_gallery_style/before_section_end", array( $this, 'editor' ), 10, 2 );

            // Check labels visibility options
            add_action( 'elementor/frontend/widget/before_render', array( $this, 'before_render' ) );
            add_action( 'elementor/frontend/widget/after_render', array( $this, 'after_render' ) );
            add_action( 'elementor/widget/before_render_content', array( $this, 'before_render' ) );
            add_filter( 'elementor/widget/render_content', array( $this, 'after_render_content' ) );

            // Disable labels if needed
            add_filter( 'awl_show_labels_for_product', array( $this, 'disable_labels' ), 1, 2 );

            // New label condition options
            add_filter( 'awl_label_rules', array( $this, 'label_rules' ), 1 );
            add_filter( 'awl_labels_condition_rules', array( $this, 'condition_rules' ), 1 );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

//            $hooks['on_image']['archive']['elementor/image_size/get_attachment_image_html'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'get_attachment_image_html' ), 'args' => 1,  'js' => array( '.elementor-post__thumbnail__link' ) );
//            $hooks['before_title']['archive']['elementor/image_size/get_attachment_image_html'] = array( 'priority' => 11, 'type' => 'filter', 'js' => array( '.elementor-post__title', 'before' ) );

            $hooks['before_title']['archive']['elementor/widget/render_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_content_archive_title' ), 'args' => 2 );
            $hooks['on_image']['archive']['elementor/widget/render_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_content_archive_image' ), 'args' => 2 );
            $hooks['on_image']['single']['elementor/widget/render_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_content_single_image' ), 'args' => 2 );

            return $hooks;

        }

        public function get_attachment_image_html( $html ) {
            if ( $html ) {
                return $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $html;
        }

        public function render_content_archive_title( $widget_content, $block ) {

            if ( isset( $GLOBALS['product'] ) ) {

                global $product;

                if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {

                    if ( $block->get_name() === 'heading' ) {
                        $block_display_settings = $block->get_settings_for_display();
                        if ( is_array( $block_display_settings ) && isset( $block_display_settings['__dynamic__'] ) && isset( $block_display_settings['__dynamic__']['title'] ) && ( strpos( $block_display_settings['__dynamic__']['title'], 'woocommerce-product-title-tag' ) !== false || strpos( $block_display_settings['__dynamic__']['title'], 'post-title' ) !== false ) ) {
                            $widget_content = AWL_Label_Display::instance()->show_label( 'before_title' ) . $widget_content;
                        }
                    }

                    if ( $block->get_name() === 'woocommerce-product-title' ) {
                        $settings = $block->get_settings();
                        if ( isset( $settings['show_awl'] ) &&  $settings['show_awl'] === 'yes' ) {
                            $widget_content = AWL_Label_Display::instance()->show_label( 'before_title' ) . $widget_content;
                        }
                    }

                }

            }

            return $widget_content;

        }

        public function render_content_archive_image( $widget_content, $block ) {

            if ( isset( $GLOBALS['product'] ) ) {

                global $product;

                if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {

                    if ( $block->get_name() === 'theme-post-featured-image' ) {
                        $widget_content = AWL_Label_Display::instance()->show_label( 'on_image' ) . $widget_content;
                    }

                    if ( $block->get_name() === 'image' ) {
                        $dynamic = $block->get_parsed_dynamic_settings();
                        if ( $dynamic && isset( $dynamic['__dynamic__'] ) && ! empty( $dynamic['__dynamic__'] ) && isset( $dynamic['__dynamic__']['image'] ) ) {
                            if ( strpos( $dynamic['__dynamic__']['image'], 'woocommerce-product-image-tag') !== false || strpos( $dynamic['__dynamic__']['image'], 'post-featured-image') !== false  ) {
                                $widget_content = AWL_Label_Display::instance()->show_label( 'on_image' ) . $widget_content;
                            }
                        }
                    }

                }

            }

            return $widget_content;

        }

        public function render_content_single_image( $widget_content, $block ) {

            if (  is_singular( 'product' ) ) {

                if ( $block->get_name() === 'render_content_single_image' || $block->get_name() === 'theme-post-featured-image' ) {
                    $widget_content = AWL_Label_Display::instance()->show_label( 'on_image' ) . $widget_content;
                }

                // Jet Woo Gallery
                if ( $block->get_name() === 'template' && strpos( $widget_content, 'jet-woo-product-gallery') !== false ) {
                    $widget_content = str_replace( '<div class="swiper-gallery-top">', '<div class="swiper-gallery-top">' . AWL_Label_Display::instance()->show_label( 'on_image' ), $widget_content );
                }

            }

            return $widget_content;

        }

        /*
         * Editor options
         */
        function editor( $element, $args ) {

            $element->add_control(
                'show_awl',
                [
                    'label' => __( 'Show AWL labels', 'advanced-woo-labels' ),
                    'type' => \Elementor\Controls_Manager::SWITCHER,
                    'default' => 'yes',
                ]
            );

        }

        /*
         * Check visibility options
         */
        function before_render( $block ) {

            $name = $block->get_name();

            $this->data['show_labels'] = true;
            $this->data['is_elementor'] = true;

            $add_to = array( 'woocommerce-products', 'woocommerce-product-related', 'woocommerce-product-title', 'woocommerce-product-images' );

            if ( array_search( $name, $add_to ) !== false ) {
                $settings = $block->get_settings();
                if ( isset( $settings['show_awl'] ) && ! $settings['show_awl'] ) {
                    $this->data['show_labels'] = false;
                }
            }

        }

        function after_render( $block ) {
            $this->data['show_labels'] = true;
            $this->data['is_elementor'] = false;
        }

        function after_render_content( $widget_content ) {
            $this->data['show_labels'] = true;
            return $widget_content;
        }

        /*
         * Enable or not products labels
         */
        function disable_labels( $disable, $product ) {
            if ( isset( $this->data['show_labels'] ) && ! $this->data['show_labels'] ) {
                $disable = true;
            }
            return $disable;
        }

        /*
         * Add new label conditions for admin
         */
        public function label_rules( $options ) {

            $options['Special'][] = array(
                "name" => __( "Elementor: Is elementor block", "advanced-woo-labels" ),
                "id"   => "aws_is_elementor",
                "type" => "bool",
                "operators" => "equals",
            );

            return $options;

        }

        /*
         * Add custom condition rule method
         */
        public function condition_rules( $rules ) {
            $rules['aws_is_elementor'] = array( $this, 'awl_is_elementor' );
            return $rules;
        }

        /*
         * Condition: Is Elementor block
         */
        public function awl_is_elementor( $condition_rule ) {
            global $product;

            $match = false;
            $operator = $condition_rule['operator'];
            $value = $condition_rule['value'];
            $compare_value = 'false';

            if ( isset( $this->data['is_elementor'] ) && $this->data['is_elementor'] ) {
                $compare_value = 'true';
            }

            if ( 'equal' == $operator ) {
                $match = ($compare_value == $value);
            } elseif ( 'not_equal' == $operator ) {
                $match = ($compare_value != $value);
            }

            return $match;

        }
        
    }

endif;

AWL_Elementor::instance();