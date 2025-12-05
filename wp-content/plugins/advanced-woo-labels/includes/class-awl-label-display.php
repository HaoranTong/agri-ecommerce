<?php
/**
 * AWL plugin label display
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class AWL_Label_Display {

    /**
     * @var AWL_Label_Display The single instance of the class
     */
    protected static $_instance = null;

    /**
     * @var AWL_Label_Display Array of product labels
     */
    protected $p_labels = array();

    /**
     * @var AWL_Label_Display Array of product hooks in use
     */
    protected $p_hooks = array();

    /**
     * @var AWL_Label_Display Array of all active label hooks
     */
    protected $hooks = array();

    /**
     * Main AWL_Label_Display Instance
     *
     * Ensures only one instance of AWL_Label_Display is loaded or can be loaded.
     *
     * @static
     * @return AWL_Label_Display - Main instance
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

        if ( ! $this->is_enable() ) {
            return;
        }

        $hooks = AWL_Helpers::get_hooks();

        if ( is_array( $hooks ) && ! empty( $hooks ) ) {
            foreach( $hooks as $position => $hooks_list_type ) {
                foreach ( $hooks_list_type as $hooks_list ) {
                    foreach ( $hooks_list as $hook_name => $hook_vars ) {

                        $this->hooks[$position][] = $hook_name;

                        $callback = isset( $hook_vars['callback'] ) ? $hook_vars['callback'] : ( ( ! isset( $hook_vars['type'] ) || $hook_vars['type'] !== 'filter' ) ? array( $this, 'position_' . $position ) : ( isset( $hook_vars['before'] ) ? array( $this, 'position_' . $position . '_filter_before' ) : array( $this, 'position_' . $position . '_filter' ) ) );
                        $accepted_args = isset( $hook_vars['args'] ) ? intval( $hook_vars['args'] ) : 1;

                        if ( isset( $hook_vars['type'] ) && $hook_vars['type'] === 'filter' ) {
                            add_filter( $hook_name, $callback, $hook_vars['priority'], $accepted_args );
                        } else {
                            add_action( $hook_name, $callback, $hook_vars['priority'], $accepted_args );
                        }

                    }
                }
            }
        }

        add_action( 'loop_start', array( $this, 'new_loop_start' ) );
        add_filter( 'woocommerce_product_loop_start', array( $this, 'new_loop_start_filter' ) );

    }

    /*
     * Hook action: on image label position
     */
    public function position_on_image() {
        echo $this->show_label( 'on_image' );
    }

    /*
     * Hook action: before title label position
     */
    public function position_before_title() {
        echo $this->show_label( 'before_title' );
    }

    /*
     * Hook filter: on image label position
     */
    public function position_on_image_filter( $html ) {
        return $html . $this->show_label( 'on_image' );
    }

    /*
     * Hook filter: before title label position
     */
    public function position_before_title_filter( $html ) {
        return $html . $this->show_label( 'before_title' );
    }

    /*
     * Hook filter: on image label position
     */
    public function position_on_image_filter_before( $html ) {
        return $this->show_label( 'on_image' ) . $html;
    }

    /*
     * Hook filter: before title label position
     */
    public function position_before_title_filter_before( $html ) {
        return $this->show_label( 'before_title' ) . $html;
    }

    /*
     * New products loop started
     */
    public function new_loop_start() {
        $this->p_hooks = array();
    }

    /*
     * New products loop started
     */
    public function new_loop_start_filter( $loop_start ) {
        $this->p_hooks = array();
        return $loop_start;
    }

    /**
     * Display labels
     * @param string $label_position_type Label position type
     * @return string Label html
     */
    public function show_label( $label_position_type ) {

        if ( ! isset( $GLOBALS['product'] ) ) {
            return '';
        }

        global $product;

        if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return '';
        }

        $product_id = $product->get_id();
        $label_html = '';
        $current_filter = current_filter();
        $is_duplicate = false;

        // same product repeated on page
        if ( isset( $this->p_hooks[$product_id] ) && isset( $this->p_hooks[$product_id][$label_position_type] ) && in_array( $current_filter, $this->p_hooks[$product_id][$label_position_type] ) ) {
            unset( $this->p_hooks[$product_id] );
        }

        $this->p_hooks[$product_id][$label_position_type][] = $current_filter;

        if ( isset( $this->hooks[$label_position_type] ) ) {
            if ( count( array_intersect( $this->p_hooks[$product_id][$label_position_type], $this->hooks[$label_position_type] ) ) > 1 ) {
                $is_duplicate = true;
            }
        }

        $this->generate_labels();

        if ( isset( $this->p_labels[$product_id] ) && isset( $this->p_labels[$product_id][$label_position_type] ) && ! empty( $this->p_labels[$product_id][$label_position_type] ) && ! $is_duplicate ) {

            $settings = $this->p_labels[$product_id][$label_position_type];
            $containers = array( $label_position_type => $settings );

            if ( $label_position_type === 'on_image' && count( $settings ) > 1 ) {
                $containers = array();
                foreach ( $settings as $setting ) {
                    $position = $setting['position'];
                    $containers[$position][] = $setting;
                }
            }
            foreach ( $containers as $container_settings ) {
                $label_html .= AWL_Helpers::get_label_html( $container_settings, $label_position_type );
            }

        }

        /**
         * Filter label html before output
         * @since 1.00
         * @param array $label_html
         * @param array $label_position_type
         */
        $label_html = apply_filters( 'awl_labels_output', $label_html, $label_position_type );

        return $label_html;

    }

    /*
     * Choose and generate labels output
     */
    private function generate_labels() {

        global $product;

        $product_id = $product->get_id();

        // Check if labels are disabled for current product
        $disable_labels = (bool) ( 'yes' == get_post_meta( $product_id, '_awl_disable_labels', true ) );

        /**
         * Show or not labels for the current product
         * @since 1.07
         * @param bool $disable_labels Show or not
         * @param object $product Current product
         */
        $disable_labels = apply_filters( 'awl_show_labels_for_product', $disable_labels, $product );

        if ( $disable_labels ) {
            return;
        }

        if ( isset( $this->p_labels[$product_id] ) ) {
            return;
        }

        $labels_per_product = (int) AWL()->get_settings( 'number_per_product' );
        $labels_per_position = (int) AWL()->get_settings( 'number_per_position' );
        $products_count = 0;

        // Get all labels
        $labels = wp_cache_get( 'awl_all_labels' );
        if ( false === $labels ) {
            $labels = AWL_Helpers::get_awl_labels();
            wp_cache_set( 'awl_all_labels', $labels, '', 60 );
        }

        $this->p_labels[$product_id] = array();

        foreach ( $labels as $label_id ) {

            if ( $products_count >= $labels_per_product ) {
                break;
            }

            /**
             * Force specific label to show for current product
             * @since 1.44
             * @param bool $force_label Force label to show for current product
             * @param int $label_id Label ID
             */
            $force_label = apply_filters( 'awl_show_labels_for_product', false, $label_id );

            $label_options = AWL()->get_label_settings( $label_id );

            $label_is_active  = isset( $label_options['awl_label_status'] ) ? $label_options['awl_label_status']['status'] : true;
            $label_conditions = isset( $label_options['conditions'] ) ? $label_options['conditions'] : false;
            $label_settings   = isset( $label_options['settings'] ) ? $label_options['settings'] : false;

            if ( ( $label_is_active && $label_conditions && $label_settings ) || $force_label ) {

                $label_settings['id'] = $label_id;
                $label_position_type  = $label_settings['position_type'];

                $match_condition = AWL_Helpers::match_conditions( $label_conditions );

                /**
                 * Show or not label on current product
                 * @since 1.07
                 * @param bool $match_condition Show or not
                 * @param object $product Current product
                 * @param int $label_id Label id to display on current product
                 */
                $match_condition = apply_filters( 'awl_show_label_for_product', $match_condition, $product, $label_id );

                if ( $match_condition || $force_label ) {

                    if ( isset( $this->p_labels[$product_id][$label_position_type] ) && count( $this->p_labels[$product_id][$label_position_type] ) >= $labels_per_position ) {
                        continue;
                    }

                    $is_not_empty = ( isset( $label_settings['type'] ) && $label_settings['type'] === 'image' ) || ( isset( $label_settings['text'] ) && trim( AWL_Helpers::get_label_text( $label_settings ) ) );
                    if ( ! $is_not_empty ) {
                        continue;
                    }

                    $this->p_labels[$product_id][$label_position_type][] = $label_settings;
                    $products_count++;

                }

            }

        }

    }

    /*
     * Check that we need to trigger label hooks
     */
    private function is_enable() {

        $enable = false;

        if ( ! is_admin() ||
            ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] ) ||
            isset( $_GET['elementor-preview'] ) ||
            ( isset( $_POST['action'] ) && 'elementor_ajax' === $_POST['action'] ) ||
            ( isset( $_REQUEST['action'] ) && 'jet_smart_filters' === $_REQUEST['action'] ) ||
            ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() && isset( $_REQUEST['action'] ) )
        ) {
            $enable = true;
        }

        if ( AWL()->get_settings( 'display_hooks' ) === 'false' ) {
            $enable = false;
        }

        /**
         * Enable or not labels display
         * @since 1.17
         * @param bool $enable Enable or not labels display
         */
        $enable = apply_filters( 'awl_enable_labels', $enable );

        return $enable;

    }

}