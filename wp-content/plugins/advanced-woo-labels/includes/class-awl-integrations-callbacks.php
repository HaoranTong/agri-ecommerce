<?php
/**
 * AWL plugin callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'AWL_Integrations_Callbacks' ) ) :

    /**
     * Class for plugin callbacks
     */
    class AWL_Integrations_Callbacks {
        
        public static function post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size ) {
            if ( $size === 'shop_catalog' ) {
                return $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $html;
        }

        public static function betheme_woocommerce_placeholder_img( $image_html, $size, $dimensions ) {
            if ( $size === 'shop_catalog' ) {
                return $image_html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }
            return $image_html;
        }

        public static function woocommerce_product_get_image( $image, $obj, $size ) {
            global $wp_current_filter;
            if ( in_array( 'woocommerce_before_shop_loop_item_title', $wp_current_filter ) ) {
                if ( strpos( $image, '<div' ) === false && strpos( $image, '<span' ) === false ) {
                    $label = AWL_Label_Display::instance()->show_label( 'on_image' );
                    if ( $label ) {
                        return '<div style="position:relative;">' . $image . $label . '</div>';
                    }
                }
            }
            return $image;
        }

        public static function before_loop_title( $title ) {
            global $wp_current_filter;
            if ( in_array( 'woocommerce_before_shop_loop_item_title', $wp_current_filter ) || in_array( 'woocommerce_shop_loop_item_title', $wp_current_filter ) || in_array( 'woocommerce_before_shop_loop_item', $wp_current_filter ) ) {
                return AWL_Label_Display::instance()->show_label( 'before_title' ) . $title;
            }
            return $title;
        }

        public static function wrap_thumb_container_action() {
            echo '<div style="position:relative;width:100%;height:100%;">' . AWL_Label_Display::instance()->show_label( 'on_image' ) . '</div>';
        }

        public static function wrap_thumb_container_filter( $image ) {
            return '<div style="position:relative;width:100%;height:100%;">' . $image . AWL_Label_Display::instance()->show_label( 'on_image' ) . '</div>';
        }

        public static function return_empty_string( $text ) {
            return '';
        }

        public static function echo_title_centered() {
            $label = AWL_Label_Display::instance()->show_label( 'before_title' );
            $label = str_replace( 'justify-content:flex-start;', 'justify-content:center;', $label );
            echo '<div style="margin-top: 10px;">' . $label . '</div>';
        }

        public static function woocommerce_blocks_product_grid_item_html_on_image( $html, $data, $product ) {

            setup_postdata( $product->get_id() );
            $label_on_image = AWL_Label_Display::instance()->show_label( 'on_image' );
            wp_reset_postdata();

            if ( $label_on_image && strpos( $html, 'awl-position-type-on-image' ) === false ) {
                if ( strpos( $html, $data->image ) !== false ) {
                    $html = str_replace( $data->image, '<div style="position:relative;">' . $data->image . $label_on_image . '</div>', $html );
                } else {
                    $html = str_replace( '<img', $label_on_image . '<img', $html );
                }
            }

            return $html;

        }

        public static function woocommerce_blocks_product_grid_item_html_before_title( $html, $data, $product ) {

            setup_postdata( $product->get_id() );
            $label_before_title = AWL_Label_Display::instance()->show_label( 'before_title' );
            wp_reset_postdata();

            if ( $label_before_title && strpos( $html, 'awl-position-type-before-title' ) === false ) {
                if ( strpos( $html, $data->title ) !== false ) {
                    $html = str_replace( $data->title, $label_before_title . $data->title, $html );
                } elseif ( strpos( $html, '<div class="wc-block-grid__product-title">') !== false ) {
                    $html = str_replace( '<div class="wc-block-grid__product-title">', $label_before_title . '<div class="wc-block-grid__product-title">', $html );
                }
            }

            return $html;

        }

        public static function woocommerce_blocks_product_grid_item_html_hide_bagge( $html, $data, $product ) {
            if ( $data->badge ) {
                $html = str_replace( $data->badge, '', $html );
            }
            return $html;
        }

    }

endif;