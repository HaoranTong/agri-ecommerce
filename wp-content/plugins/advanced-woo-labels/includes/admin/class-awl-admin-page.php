<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! class_exists( 'AWL_Admin_Page' ) ) :

    /**
     * Class for plugin admin page
     */
    class AWL_Admin_Page {

        private $options = null;
        private $nonce = null;

        /*
         * Constructor
         */
        public function __construct( $options, $nonce ) {

            $this->options = $options;
            $this->nonce = $nonce;

        }

        /*
         * Generate admin page tabs
         */
        private function page_tabs( $current_tab ) {

            $tabs = array(
                'general' => esc_html__( 'General', 'advanced-woo-labels' ),
                'premium' => esc_html__( 'Premium Features', 'advanced-woo-labels' ),
            );

            $tabs_html = '';

            foreach ( $tabs as $name => $label ) {
                $tabs_html .= '<a href="' . admin_url( 'edit.php?post_type=awl-labels&page=awl-options&tab=' . $name ) . '" class="nav-tab '.$name.'-tab ' . ( $current_tab == $name ? 'nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';

            }

            $tabs_html = '<h2 class="nav-tab-wrapper woo-nav-tab-wrapper">' . $tabs_html . '</h2>';

            return $tabs_html;

        }
        
        /*
         * Generate admin settings page
         */
        public function generate_page() {

            $current_tab = empty( $_GET['tab'] ) ? 'general' : sanitize_text_field( $_GET['tab'] );

            if ( isset( $_POST["Submit"] ) && current_user_can( 'manage_options' ) && isset( $_POST["_wpnonce"] ) && wp_verify_nonce( $_POST["_wpnonce"], 'plugin-settings' ) ) {
                AWL_Admin_Options::update_settings();
            }

            echo '<div class="wrap">';

            echo '<h1></h1>';

            echo '<h1 class="awl-instance-name">'. __( 'Settings', 'advanced-woo-labels' ) .'</h1>';

            echo $this->page_tabs( $current_tab );

            echo '<form action="" name="awl_form" id="awl_form" method="post">';

            switch ($current_tab) {
                case('advanced'):
                    new AWL_Admin_Page_Fields(  $this->options['advanced'] );
                    break;
                case('premium'):
                    new AWL_Admin_Page_Premium();
                    break;
                default:
                    new AWL_Admin_Page_Fields(  $this->options['general'] );
            }

            echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $this->nonce ) . '">';

            echo '</form>';

            echo '</div>';
            
        }
        
    }

endif;