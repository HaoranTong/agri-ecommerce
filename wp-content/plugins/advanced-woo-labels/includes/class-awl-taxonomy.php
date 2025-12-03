<?php
/**
 * AWL plugin taxonomies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'AWL_Taxonomy' ) ) :

    /**
     * Class for main plugin functions
     */
    class AWL_Taxonomy {
        
        /**
         * @var AWL_Taxonomy The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Taxonomy Instance
         *
         * Ensures only one instance of AWL_Taxonomy is loaded or can be loaded.
         *
         * @static
         * @return AWL_Taxonomy - Main instance
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

            // Register custom post type
            add_action( 'init', array( $this, 'custom_posts' ) );

            // Custom columns
            add_filter( 'manage_edit-awl-labels_columns', array( $this, 'manage_columns' ) );

            // Columns content
            add_action( 'manage_awl-labels_posts_custom_column', array( $this, 'display_columns' ), 10, 2 );

            // Add sortable column
            add_filter( 'manage_edit-awl-labels_sortable_columns', array( $this, 'order_column_sortable' ) );

            // Sortable column order by
            add_action( 'pre_get_posts', array( $this, 'orderby_columns' ) );

            //  Update quick edit columns
            add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_custom_box' ), 10, 2 );

            // Save quick edit columns values
            add_action( 'save_post', array( $this, 'quick_edit_save_post' ), 10, 2 );

        }

        /*
         * Register custom post types
         */
        public function custom_posts() {

            register_post_type(
                'awl-labels',
                apply_filters(
                    'awl_register_post_type_labels',
                    array(
                        'labels'			=> array(
                            'name'					=> __( 'Labels', 'advanced-woo-labels' ),
                            'singular_name'			=> __( 'Label', 'advanced-woo-labels' ),
                            'add_new'				=> __( 'Add Label' , 'advanced-woo-labels' ),
                            'add_new_item'			=> __( 'Add New Label' , 'advanced-woo-labels' ),
                            'edit_item'				=> __( 'Edit Label' , 'advanced-woo-labels' ),
                            'new_item'				=> __( 'New Label' , 'advanced-woo-labels' ),
                            'view_item'				=> __( 'View Label', 'advanced-woo-labels' ),
                            'search_items'			=> __( 'Search Label', 'advanced-woo-labels' ),
                            'not_found'				=> __( 'No Label found', 'advanced-woo-labels' ),
                            'not_found_in_trash'	=> __( 'No Labels found in Trash', 'advanced-woo-labels' ),
                        ),
                        'public'			=> false,
                        'show_ui'			=> true,
                        'hierarchical'		=> false,
                        'rewrite'			=> false,
                        'capability_type'   => 'post',
                        'query_var'			=> false,
                        'supports' 			=> array( 'title' ),
                        'show_in_menu'		=> false,
                    )
                )
            );

        }

        /*
         * Manage columns for admin dashboard
         */
        public function manage_columns( $columns ) {
//            unset( $columns['date'] );
            $columns['label_status'] = __( 'Status' , 'advanced-woo-labels' );
            $columns['label_priority'] = __( 'Priority' , 'advanced-woo-labels' );
            $columns['label_position'] = __( 'Position' , 'advanced-woo-labels' );
            $columns['label_preview'] = __( 'Preview' , 'advanced-woo-labels' );
            return $columns;
        }

        /*
         * Display content for custom columns in the admin dashboard
         */
        public function display_columns( $column, $post_id ) {

            global $post;

            $label = AWL()->get_label_settings( $post_id );
            $label_settings   = isset( $label['settings'] ) ? $label['settings'] : false;

            if ( $label_settings ) {
                $label_settings['id'] = $post_id;
            }


            switch ( $column ) {

                case "label_status":

                    $status_html = '<span class="awl-list-label-status">' . $post->post_status . '</span>';

                    if ( $post->post_status === 'publish' ) {
                        $status = 'inactive';
                        if ( $label && ! empty( $label ) && isset( $label['awl_label_status'] ) && $label['awl_label_status']['status'] === '1' ) {
                            $status = 'active';
                        }
                        $status_html = '<span data-label-id="' . $post_id . '" class="awl-list-label-status awl-ajax-toggle awl-toggle ' . $status . '">';
                            $status_html .= '<span class="awl-toggle--active">' . __( "Active", "advanced-woo-labels" ) . '</span>';
                            $status_html .= '<span class="awl-toggle--inactive">' . __( "Inactive", "advanced-woo-labels" ) . '</span>';
                        $status_html .= '</span>';
                    }

                    echo $status_html;
                    break;

                case "label_position":

                    $position = '';
                    if ( $label_settings ) {
                        $position_val = $label_settings['position_type'] === 'on_image' ? $label_settings['position'] : $label_settings['position_x'];
                        $position .= isset( $label_settings['position_type'] ) ? str_replace( '_', ' ', $label_settings['position_type'] ) : '';
                        $position .= $position_val ? '<br>' . str_replace( '_', ' ', $position_val ) : '';
                    }

                    echo '<span style="text-transform:capitalize;">' . $position . '</span>';
                    break;

                case "label_preview":

                    if ( $label_settings ) {
                        $label_settings['position_type'] = 'before_title';
                        $label_settings['position'] = 'left_top';
                        $label_settings['position_x'] = 'left';
                        $label_settings['margin']['top'] = '0';
                        $label_settings['margin']['right'] = '0';
                        $label_settings['margin']['bottom'] = '0';
                        $label_settings['margin']['left'] = '0';
                        $label_html = AWL_Helpers::get_label_html( array( $label_settings ), 'before_title');
                        echo $label_html;
                    }

                    break;

                case "label_priority":

                    $label_value = '0';
                    $label_priority = get_post_meta( $post_id, '_awl_label_priority', true );
                    if ( $label_priority ) {
                        $label_value = $label_priority;
                    }

                    echo $label_value;

                    break;

            }

        }

        /*
         * Make column sortable
         */
        public function order_column_sortable($columns){
            $columns['label_priority'] = 'label_priority';
            return $columns;
        }

        /*
         * Custom column order by
         */
        function orderby_columns( $query ) {
            if ( ! is_admin() ) {
                return;
            }

            $orderby = $query->get( 'orderby');

            if ( 'label_priority' == $orderby ) {
                $query->set('meta_key', '_awl_label_priority');
                $query->set('orderby','meta_value_num');
            }

        }

        /*
         * Updat quick edit columns
         */
        public function quick_edit_custom_box( $column_name, $post_type ) {

            if ( $column_name != 'label_priority' || $post_type !== 'awl-labels' ) {
                return;
            }

            echo '<fieldset class="inline-edit-col-right" style="margin:0;">';
                echo '<div class="inline-edit-col">';
                    echo '<div class="inline-edit-group wp-clearfix">';
                        echo '<label class="inline-edit-priority alignleft">';
                            echo '<span class="title">' . __( 'Priority', 'advanced-woo-labels' ) . '</span>';
                            echo '<input type="number" name="awl_label_priority_val" value="">';
                        echo '</label>';
                    echo '</div>';
                echo '</div>';
            echo '</fieldset>';

        }

        /*
         * Save quick edit columns values
         */
        public function quick_edit_save_post( $post_id, $post ) {

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return $post_id;
            }

            if ( ! current_user_can( 'edit_posts' ) ) {
                return $post_id;
            }

            if ( wp_is_post_revision( $post_id ) ) {
                return $post_id;
            }

            if ( $post->post_type != 'awl-labels' ) {
                return $post_id;
            }

            if ( isset( $_POST['awl_label_priority_val'] ) ) {
                update_post_meta( $post_id, '_awl_label_priority', sanitize_text_field( $_POST['awl_label_priority_val'] ) );
            }

        }

    }

endif;