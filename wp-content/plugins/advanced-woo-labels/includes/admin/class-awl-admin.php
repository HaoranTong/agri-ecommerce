<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! class_exists( 'AWL_Admin' ) ) :

    /**
     * Class for plugin admin panel
     */
    class AWL_Admin {

        /**
         * @var AWL_Admin The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Admin Instance
         *
         * Ensures only one instance of AWL_Admin is loaded or can be loaded.
         *
         * @static
         * @return AWL_Admin - Main instance
         */
        public static function instance() {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /*
         * Constructor
         */
        public function __construct() {

            if ( ! AWL_Admin_Options::check_settings() ) {
                $default_settings = AWL_Admin_Options::get_default_settings();
                update_option( 'awl_settings', $default_settings );
            }

            add_action( 'admin_menu', array( $this, 'add_admin_page' ) );

            add_action( 'admin_init', array( $this, 'register_settings' ) );

            add_action( 'add_meta_boxes', array( $this, 'post_type_meta_box' ) );

            add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 2 );

            add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

            add_filter( 'submenu_file', array( $this, 'submenu_file' ), 10, 2 );

        }

        /**
         * Add options page
         */
        public function add_admin_page() {

            $slug = 'edit.php?post_type=awl-labels';

            add_menu_page( esc_html__( 'Adv. Woo Labels', 'advanced-woo-labels' ), esc_html__( 'Adv. Woo Labels', 'advanced-woo-labels' ), 'edit_others_posts', $slug, false, 'dashicons-tag', 58);

            add_submenu_page( $slug, __( 'All Labels', 'advanced-woo-labels' ), __('All Labels', 'advanced-woo-labels'), 'edit_others_posts', $slug );
            add_submenu_page( $slug, __( 'Add New', 'advanced-woo-labels' ), __('Add New', 'advanced-woo-labels'), 'edit_others_posts', 'post-new.php?post_type=awl-labels' );
            add_submenu_page( $slug, __( 'Settings', 'advanced-woo-labels' ), __( 'Settings', 'advanced-woo-labels'), 'manage_options', 'awl-options', array( $this, 'display_admin_page' ) );
            add_submenu_page( $slug, __( 'Premium', 'advanced-woo-labels' ),  '<span style="color:rgba(255, 255, 91, 0.8);">' . __( 'Premium', 'advanced-woo-labels' ) . '</span>', 'manage_options', admin_url( 'admin.php?page=awl-options&tab=premium' ) );

        }

        /*
         * Add meta boxes
         */
        public function post_type_meta_box() {

            add_meta_box( 'awl_label_conditions',  __( 'Label conditions', 'advanced-woo-labels' ), array( $this, 'metabox_show_rules' ), 'awl-labels', 'normal' );

            add_meta_box( 'awl_label_settings',  __( 'Label settings', 'advanced-woo-labels' ), array( $this, 'metabox_show_settings' ), 'awl-labels', 'normal' );

            add_meta_box( 'awl_label_pro',  __( 'PRO plugin version', 'advanced-woo-labels' ), array( $this, 'metabox_show_premium' ), 'awl-labels', 'side' );

            add_meta_box( 'awl_label_status',  __( 'Status', 'advanced-woo-labels' ), array( $this, 'metabox_show_status' ), 'awl-labels', 'side' );

            add_meta_box( 'awl_label_priority',  __( 'Priority', 'advanced-woo-labels' ), array( $this, 'metabox_show_priority' ), 'awl-labels', 'side' );

        }

        /*
         * Save meta box settings
         */
        public function save_meta_boxes( $post_id, $post ) {

            if ( ! isset( $_POST['awl_label_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['awl_label_meta_box_nonce'], 'awl_label_meta_box' ) ) {
                return $post_id;
            }

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

            if ( ! isset( $_POST['awl_label_params'] ) ) {
                return $post_id;
            }

            $label = $_POST['awl_label_params'];
            //$label['conditions'] = wpc_sanitize_conditions( $_POST['conditions'] );

            if ( isset( $label['awl_label_priority'] ) ) {
                update_post_meta( $post_id, '_awl_label_priority', sanitize_text_field( $label['awl_label_priority']['priority'] ) );
            }

            if ( ! isset( $label['awl_label_status'] ) ) {
                $label['awl_label_status']['status'] = '0';
            }

            AWL()->update_label_settings( $post_id, $label );

        }

        /*
         * Meta box settings for labels rules
         */
        public function metabox_show_rules() {

            global $post;

            wp_nonce_field( 'awl_label_meta_box', 'awl_label_meta_box_nonce' );

            $label = AWL()->get_label_settings( $post->ID );

            $html = AWL_Admin_Meta_Boxes::get_rules_meta_box( $label );

            echo $html;

        }

        /*
         * Meta box settings for labels
         */
        public function metabox_show_settings() {

            global $post;

            $label = AWL()->get_label_settings( $post->ID );

            $html = AWL_Admin_Meta_Boxes::get_settings_meta_box( $label );

            echo $html;

        }

        /*
         * Meta box status for premium features promotion
         */
        public function metabox_show_premium() {

            $html = AWL_Admin_Meta_Boxes::get_premium_meta_box();

            echo $html;

        }

        /*
         * Meta box status for labels
         */
        public function metabox_show_status() {

            global $post;

            $label = AWL()->get_label_settings( $post->ID );

            $html = AWL_Admin_Meta_Boxes::get_status_meta_box( $label );

            echo $html;

        }

        /*
         * Meta box priority for labels
         */
        public function metabox_show_priority() {

            global $post;

            $label = AWL()->get_label_settings( $post->ID );

            $html = AWL_Admin_Meta_Boxes::get_priority_meta_box( $post->ID );

            echo $html;

        }

        /**
         * Generate and display options page
         */
        public function display_admin_page() {

            $options = AWL_Admin_Options::options_array();
            $nonce = wp_create_nonce( 'plugin-settings' );

            $admin_page = new AWL_Admin_Page( $options, $nonce );

            $admin_page->generate_page();

        }

        /*
         * Register plugin settings
         */
        public function register_settings() {
            register_setting( 'awl_settings', 'awl_settings' );
        }

        /*
         * Enqueue admin scripts and styles
         */
        public function admin_enqueue_scripts() {

            $screen = get_current_screen();

            if ( ( isset( $_GET['page'] ) && $_GET['page'] == 'awl-options' ) || ( $screen && $screen->post_type && $screen->post_type === 'awl-labels' ) ) {

                wp_register_script( 'aws-tiptip', AWL_URL . '/assets/js/jquery.tipTip.js', array( 'jquery' ), AWL_VERSION, true );
                wp_register_script( 'awl-purify', AWL_URL . '/assets/js/purify.min.js', array( 'jquery' ), AWL_VERSION, true );

                wp_enqueue_style( 'wp-color-picker' );
                wp_enqueue_style( 'awl-select2', AWL_URL . '/assets/css/select2.min.css' );
                wp_enqueue_style( 'awl-admin-style', AWL_URL . '/assets/css/admin.css', array(), AWL_VERSION );

                wp_enqueue_script( 'jquery' );
                wp_enqueue_script( 'aws-tiptip' );
                wp_enqueue_script( 'select2' );
                wp_enqueue_script( 'wp-color-picker' );
                wp_enqueue_script( 'awl-purify' );
                //wp_enqueue_script( 'wp-color-picker-alpha', AWL_URL . '/assets/js/wp-color-picker-alpha.js', array('jquery', 'wp-color-picker' ), AWL_VERSION );
                wp_enqueue_script( 'awl-admin-scripts', AWL_URL . '/assets/js/admin.js', array( 'jquery', 'wp-color-picker', 'select2', 'aws-tiptip' ), AWL_VERSION );
                wp_enqueue_script( 'awl-admin-preview', AWL_URL . '/assets/js/admin-preview.js', array('jquery', 'wp-color-picker', 'awl-purify' ), AWL_VERSION );
                wp_localize_script( 'awl-admin-scripts', 'awl_vars', array(
                    'ajaxurl' => admin_url( 'admin-ajax.php', 'relative' ),
                    'ajax_nonce' => wp_create_nonce( 'awl_admin_ajax_nonce' ),
                    'img_url' => AWL_IMG,
                    'copy_text' => __( 'Copied', 'advanced-woo-labels' ),
                    'copy_pro_text' => __( 'Pro version only!', 'advanced-woo-labels' ),
                    'confirm_hooks' => __( 'This action will generate the currently used display hooks. Please only confirm the action if you are sure of what you are doing. In most cases, this action is not required as these hooks are already in use.', 'advanced-woo-labels' ),
                ) );

            }

        }

        /*
         * Change current class for premium tab
         */
        public function submenu_file( $submenu_file, $parent_file ) {
            if ( $parent_file === 'edit.php?post_type=awl-labels' && isset( $_GET['tab'] ) && $_GET['tab'] === 'premium' ) {
                $submenu_file = admin_url( 'admin.php?page=awl-options&tab=premium' );
            }
            return $submenu_file;
        }

    }

endif;


add_action( 'init', 'AWL_Admin::instance' );