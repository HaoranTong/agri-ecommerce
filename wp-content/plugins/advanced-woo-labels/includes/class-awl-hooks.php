<?php
/**
 * AWL plugin hooks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class AWL_Hooks {

    /**
     * @var AWL_Hooks The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main AWL_Hooks Instance
     *
     * Ensures only one instance of AWL_Hooks is loaded or can be loaded.
     *
     * @static
     * @return AWL_Hooks - Main instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {

        if ( AWL()->get_settings( 'show_single' ) === 'false' ) {
            add_filter( 'awl_labels_hooks', array( $this, 'remove_single_hooks' ), 3 );
        }

        if ( AWL()->get_settings( 'show_loops' ) === 'false' ) {
            add_filter( 'awl_labels_hooks', array( $this, 'remove_archive_hooks' ), 3 );
        }

        if ( AWL()->get_settings( 'show_default_sale' ) === 'false' ) {

            add_action( 'wp_head', array( $this, 'wp_head_sale_flash' ) );

            remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );

            add_filter( 'render_block_data', array( $this, 'remove_block_sale_badge' ) );

            if ( AWL()->get_settings( 'show_single' ) === 'true' ) {
                add_filter( 'woocommerce_sale_flash', array( $this, 'remove_woocommerce_sale_flash' ) );
                remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
            }

            do_action( 'awl_hide_default_sale_flash' );

        }

        if ( AWL()->get_settings( 'show_default_stock' ) === 'false' ) {

            add_action( 'wp_head', array( $this, 'wp_head_stock_flash' ) );

            do_action( 'awl_hide_default_stock_flash' );

        }

        // Js seamless integration
        add_action( 'wp_footer', array( $this, 'js_integration' ) );

        // Hooks display option
        add_filter( 'awl_labels_hooks', array( $this, 'awl_labels_hooks' ), 2 );

        // On products save clear meta fields
        add_action( 'woocommerce_before_product_object_save', array( $this, 'clear_meta' ) );

        // Fix issue with third-party script modifying labels query
        add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 99999, 2 );

    }

    /*
     * Remove label display hooks for single product page
     */
    public function remove_single_hooks( $hooks ) {

        if ( is_array( $hooks ) && ! empty( $hooks ) ) {
            foreach( $hooks as $position => $hooks_list_type ) {
                if ( isset( $hooks[$position]['single'] ) ) {
                    unset( $hooks[$position]['single'] );
                }
            }
        }

        return $hooks;

    }

    /*
     * Remove label display hooks for loops blocks
     */
    public function remove_archive_hooks( $hooks ) {

        if ( is_array( $hooks ) && ! empty( $hooks ) ) {
            foreach( $hooks as $position => $hooks_list_type ) {
                if ( isset( $hooks[$position]['archive'] ) ) {
                    unset( $hooks[$position]['archive'] );
                }
            }
        }

        return $hooks;

    }

    /*
     * Change custom display hooks with the hooks from the settings page
     */
    public function awl_labels_hooks( $hooks ) {

        $hooks_options = AWL()->get_settings( 'hooks' );
        $hooks_relation = AWL()->get_settings( 'hooks_relation' );
        $new_hooks = array();

        if ( $hooks_relation === 'default' ) {
            return $hooks;
        }

        if ( $hooks_options && is_array( $hooks_options ) && ! empty( $hooks_options ) ) {
            foreach( $hooks_options as $hook ) {

                $position = $hook['position'];
                $priority = $hook['priority'] ? intval( $hook['priority'] ) : 10;
                $hook_name = in_array( $hook['hook'], array( 'custom', 'advanced' ) ) ? $hook['custom'] : $hook['hook'];
                $hook_type = in_array( $hook['hook'], array( 'custom', 'advanced' ) ) && isset( $hook['type'] ) ? $hook['type'] : 'action';

                if ( $hook_name ) {

                    $hook_args = array(
                        'priority' => $priority,
                        'type' => $hook_type
                    );

                    if ( $hook['hook'] === 'advanced' ) {

                        if ( isset( $hook['js'] ) && $hook['js'] ) {
                            $placement = isset( $hook['js_pos'] ) && $hook['js_pos'] ? $hook['js_pos'] : 'append';
                            $hook_args['js'] = array( $hook['js'], $hook['js_pos'] );
                        }

                        $callback_f = '';

                        if ( isset( $hook['callback'] ) && $hook['callback'] ) {
                            $callback_args = isset( $hook['callback_args'] ) && $hook['callback_args'] ? $hook['callback_args'] : 0;
                            if ( strpos( $hook['callback'], '|' ) !== false ) {
                                $hook_callback = explode( '|', $hook['callback'] );
                                if ( class_exists( $hook_callback[0] ) ) {
                                    $obj = method_exists( $hook_callback[0], 'instance' ) ? call_user_func($hook_callback[0] . "::instance" ) : new $hook_callback[0];
                                    if ( $obj && method_exists( $obj, $hook_callback[1] ) ) {
                                        $callback_f = array( $obj, $hook_callback[1] );
                                    }
                                }
                            } else {
                                $callback_f = $hook['callback'];
                            }
                            if ( $callback_args ) {
                                $hook_args['args'] = intval( $callback_args );
                            }
                            $hook_args['callback'] = $callback_f;
                        }

                    }

                    $new_hooks[$position]['archive_custom'][$hook_name] = $hook_args;

                }

            }

        }

        if ( ! empty( $new_hooks ) ) {
            if ( $hooks_relation === 'rewrite' && ! ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'awl-showCurrentHooks' ) ) {
                $hooks = $new_hooks;
            } elseif (  $hooks_relation === 'rewrite_specified' && ! ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] === 'awl-showCurrentHooks' ) ) {
                foreach ( $new_hooks as $new_hooks_position => $new_hooks_args ) {
                    $hooks[$new_hooks_position] = $new_hooks_args;
                }
            } else {
                foreach ( $new_hooks as $new_hooks_position => $new_hooks_args ) {
                    foreach( $new_hooks_args as $new_hooks_arg_name => $new_hooks_arg_val ) {
                        $hooks[$new_hooks_position][$new_hooks_arg_name] = $new_hooks_arg_val;
                    }
                }
            }
        }

        return $hooks;

    }

    /*
     * Remove default sale flash
     */
    public function remove_woocommerce_sale_flash( $html ) {
        return '';
    }

    /*
     * Remove sale flash styles
     */
    public function wp_head_sale_flash() {

        $output = '';

        if ( defined( 'MFN_THEME_VERSION' ) ) {
            $output .= '<style>.product-loop-thumb .onsale { display: none; }</style>';
        }

        echo $output;

    }

    /*
     * Remove sale badge for WooCommerce blocks
     */
    public function remove_block_sale_badge( $block ) {
        if ( $block['blockName'] === 'woocommerce/product-image' ) {
            $block['attrs']['showSaleBadge'] = false;
        }
        return $block;
    }

    /*
     * Remove out of stock flash styles
     */
    public function wp_head_stock_flash() {

        $output = '';

        if ( class_exists( 'Avada' ) ) {
            $output .= '<style>.fusion-out-of-stock { display: none; }</style>';
        }

        if ( defined( 'MFN_THEME_VERSION' ) ) {
            $output .= '<style>.product-loop-thumb .soldout { display: none; }</style>';
        }

        if ( defined( 'NEVE_VERSION' ) ) {
            $output .= '<style>.out-of-stock-badge { display: none; }</style>';
        }

        echo $output;

    }

    /*
     * Js seamless integration method
     */
    public function js_integration() {

        $selectors = AWL_Helpers::get_js_selectors();

        if ( ! is_array( $selectors ) || empty( $selectors ) ) {
            return;
        }

        $json = json_encode( $selectors );

        $product_container = array( '.product' );

        /**
         * Filter js selectors for product container
         * @since 1.19
         * @param array $product_container Array of css selectors for product container
         */
        $product_container = apply_filters( 'awl_js_container_selectors', $product_container );

        $product_container_selector = implode( ',', $product_container );

        ?>

        <script>

            document.addEventListener('AWLTriggerJsReplace', function() {
                awlJsLabelsReplace();
            }, false);

            document.addEventListener('DOMContentLoaded', function() {
                awlJsLabelsReplace();
            }, false);

            function awlJsLabelsReplace() {

                if (!Element.prototype.matches) {
                    Element.prototype.matches = Element.prototype.msMatchesSelector || Element.prototype.webkitMatchesSelector;
                }
                if (!Element.prototype.closest) {
                    Element.prototype.closest = function(s) {
                        var el = this;

                        do {
                            if (el.matches(s)) return el;
                            el = el.parentElement || el.parentNode;
                        } while (el !== null && el.nodeType === 1);
                        return null;
                    };
                }

                setTimeout( function() {

                    var selectors = <?php echo $json; ?>;

                    for ( var property in selectors ) {

                        if ( selectors.hasOwnProperty( property ) ) {

                            var from = document.querySelectorAll(property);

                            if (from.length) {
                                for (var i = 0; i < from.length; i++) {

                                    var productContainer = from[i].closest("<?php echo $product_container_selector; ?>");

                                    if ( productContainer ) {

                                        var to = productContainer.querySelectorAll(selectors[property][0]);

                                        if ( to.length && from[i] && to[0] && from[i].style.display === 'none' ) {

                                            var addTo = to[0];

                                            if (selectors[property][1] && selectors[property][1] === 'prepend') {
                                                addTo.prepend(from[i]);
                                            } else if (selectors[property][1] && selectors[property][1] === 'after') {
                                                addTo.after(from[i]);
                                            } else if (selectors[property][1] && selectors[property][1] === 'before') {
                                                addTo.before(from[i]);
                                            } else {
                                                addTo.append(from[i]);
                                            }

                                        }

                                        if ( from[i] ) {
                                            from[i].style.display = "flex";
                                        }

                                    }

                                }
                            }

                        }

                    }

                }, 200 );

            }

        </script>

        <?php

    }

    /*
     * Clear percentage meta values
     */
    public function clear_meta( $object ) {
        $post_id = 'variation' === $object->get_type() ? $object->get_parent_id() : $object->get_id();
        delete_post_meta( $post_id, '_awl_save_percent_value' );
        delete_post_meta( $post_id, '_awl_save_amount_value' );
    }

    /*
     * Fix script for ordering products by stock status
     */
    public function posts_clauses( $posts_clauses, $query ) {
        if ( $query->query_vars && isset( $query->query_vars['_is_awl_query'] ) && $query->query_vars['_is_awl_query'] ) {
            if ( function_exists('is_woocommerce') && is_woocommerce() && strpos( $posts_clauses['where'], "AND istockstatus.meta_key = '_stock_status'" ) !== false ) {
                $posts_clauses['where'] = str_replace( "AND istockstatus.meta_key = '_stock_status'", "", $posts_clauses['where'] );
            }
        }
        return $posts_clauses;
    }

}