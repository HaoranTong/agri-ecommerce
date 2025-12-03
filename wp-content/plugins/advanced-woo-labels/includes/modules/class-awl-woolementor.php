<?php

/**
 * AWL Woolementor plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Woolementor')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Woolementor {

        /**
         * @var AWL_Woolementor The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Woolementor Instance
         *
         * Ensures only one instance of AWL_Woolementor is loaded or can be loaded.
         *
         * @static
         * @return AWL_Woolementor - Main instance
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

            add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

            add_action( 'the_post', array( $this, 'the_post' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive_woolementor']['elementor/widget/render_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_content' ), 'args' => 2 );
            $hooks['before_title']['archive_woolementor']['elementor/widget/render_content'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'render_content' ), 'args' => 2 );

            return $hooks;

        }

        /*
         * Add necessary data to the post loop
         */
        public function the_post( $post ) {

            if ( $post && is_object( $post ) ) {

                $is_woolementor_block = false;
                foreach ( debug_backtrace() as $trace_part ) {
                    if ( isset( $trace_part['class'] ) && $trace_part['class'] === 'Elementor\Widget_Base' && isset( $trace_part['object'] ) && property_exists( $trace_part['object'], 'widget' ) ) {
                        if ( isset( $trace_part['object']->widget['categories'] ) && array_search( 'woolementor', $trace_part['object']->widget['categories'] ) !== false ) {
                            $is_woolementor_block = true;
                        }
                    }
                }

                if ( $is_woolementor_block ) {
                    $post_id = $post->ID;
                    $this->data[$post_id]['on_image'] = AWL_Label_Display::instance()->show_label( 'on_image' );
                    $this->data[$post_id]['before_title'] = AWL_Label_Display::instance()->show_label( 'before_title' );
                    echo '<div data-awl-block-end style="display: none;"></div>';
                    echo '<div data-awl-block-start data-awl-id="' . $post_id . '" style="display: none;"></div>';
                }

            }

        }

        /*
         * Render blocks content
         */
        function render_content( $widget_content, $block ) {

            $block_categories = $block->get_categories();

            if ( $block_categories && array_search( 'woolementor', $block_categories ) !== false ) {

                $this->data['current_block'] = $block->get_name();

                if ( $block->get_name() === 'product-thumbnail' && is_singular( 'product' ) ) {
                    $widget_content = preg_replace( '/<img[\S\s]*?>/i', '${0}' . AWL_Label_Display::instance()->show_label( 'on_image' ), $widget_content );
                }

                if ( $block->get_name() === 'product-title' && is_singular( 'product' ) ) {
                    $widget_content = AWL_Label_Display::instance()->show_label( 'before_title' ) . $widget_content;
                }

                $widget_content = preg_replace_callback( '/<div data-awl-block-start data-awl-id="([\S\s]*?)"[\S\s]*?<div data-awl-block-end[\S\s]*?<\/div>/i', array( $this, 'render_content_callback'), $widget_content );
                $widget_content = preg_replace( '/<div data-awl-block-start[\S\s]*?<\/div>/i', '', $widget_content );
                $widget_content = preg_replace( '/<div data-awl-block-end[\S\s]*?<\/div>/i', '', $widget_content );

            }

            return $widget_content;

        }

        /*
         * Replace callback for blocks
         */
        private function render_content_callback( $matches ) {

            $product_id = $matches[1];
            $widget_content = $matches[0];

            $on_image_label =  $this->data[$product_id]['on_image'];
            $on_title_label =  $this->data[$product_id]['before_title'];

            if ( $this->data['current_block'] === 'shop-classic' ) {
                $widget_content = preg_replace( '/<div class="wl-sc-product-img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-sc-product-name[\S\s]*?>/i', '${0}' . $on_title_label, $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-standard' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;">${0}' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-ss-product-name[\S\s]*?>/i', '${0}' . $on_title_label, $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-flip' || $this->data['current_block'] === 'related-products-flip' ) {
                $widget_content = preg_replace( '/class="front"[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/class="front"[\S\s]*?>[\S\s]*?<div class="inner">/i', '${0}<div style="display:inline-block;margin: 0 auto;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-trendy' || $this->data['current_block'] === 'related-products-trendy' ) {
                $widget_content = preg_replace( '/<figure[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<figcaption[\S\s]*?>/i', '${0}' . $on_title_label, $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-curvy' ) {
                $widget_content = preg_replace( '/<div class="wl-scr-product-img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-scr-product-info[\S\s]*?>/i', '${0}<div style="display:inline-block;margin: 0 auto;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-curvy-horizontal' ) {
                $widget_content = preg_replace( '/<div class="wl-sch-product-img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-sch-product-info[\S\s]*?>/i', '${0}<div style="display:inline-block;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-slider' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;">${0}' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-ssl-product-info[\S\s]*?>/i', '${0}<div style="display:inline-block;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-accordion' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;">${0}' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-sa-accordion-content-inner[\S\s]*?>/i', '${0}<div style="display:inline-block;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-table' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;width:100%;height:100%;">${0}' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<td class="[\S\s]*?wl-st-name[\S\s]*?>/i', '${0}<div style="float:right;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'shop-beauty' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '<div style="position:relative;width:100%;height:100%;">${0}' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-sb-product-name[\S\s]*?>/i', '${0}<div style="display:inline-block; margin-right:10px;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'related-products-classic' ) {
                $widget_content = preg_replace( '/<div class="wl-rpc-product-img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-rpc-product-name[\S\s]*?>/i', '${0}' . $on_title_label, $widget_content );
            }

            if ( $this->data['current_block'] === 'related-products-standard' ) {
                $widget_content = preg_replace( '/<div class="wl-rps-product-img[\S\s]*?>/i', '${0}<div style="position:relative;width:100%;height:100%;">' . $on_image_label . '</div>', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-rps-product-name[\S\s]*?>/i', '${0}' . $on_title_label, $widget_content );
            }

            if ( $this->data['current_block'] === 'related-products-curvy' ) {
                $widget_content = preg_replace( '/<div class="wl-rpcr-product-img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-rpcr-product-info[\S\s]*?>/i', '${0}<div style="display:inline-block;margin: 0 auto;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $this->data['current_block'] === 'related-products-accordion' ) {
                $widget_content = preg_replace( '/<img[\S\s]*?>/i', '${0}' . $on_image_label, $widget_content );
                $widget_content = preg_replace( '/<div class="wl-rpa-accordion-content-inner[\S\s]*?>/i', '${0}<div style="display:inline-block;">' . $on_title_label . '</div>', $widget_content );
            }

            if ( $on_image_label ) {
                $widget_content = preg_replace( '/<div class="wl-sc-product-img/i', '<div style="position:relative !important;" class="wl-sc-product-img', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-ss-product-img/i', '<div style="position:relative !important;" class="wl-ss-product-img', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-scr-product-img/i', '<div style="position:relative !important;" class="wl-scr-product-img', $widget_content );
                $widget_content = preg_replace( '/<div class="wl-sch-product-img/i', '<div style="position:relative !important;overflow:visible !important;" class="wl-sch-product-img', $widget_content );
            }

            return $widget_content;

        }

    }

endif;

AWL_Woolementor::instance();