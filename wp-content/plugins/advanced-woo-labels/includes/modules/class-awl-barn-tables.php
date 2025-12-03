<?php

/**
 * WooCommerce Product Table by Barn2 integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Barn_Tables')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Barn_Tables {

        /**
         * @var AWL_Barn_Tables Custom data
         */
        public $data = array();

        /**
         * @var AWL_Barn_Tables The single instance of the class
         */
        protected static $_instance = null;

        /**
         * Main AWL_Barn_Tables Instance
         *
         * Ensures only one instance of AWL_Barn_Tables is loaded or can be loaded.
         *
         * @static
         * @return AWL_Barn_Tables - Main instance
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

            $this->data['disable_labels'] = false;

            /**
             * Enable/disable product labels inside table
             * @since 1.44
             * @param bool Show or not labels for products tables
             */
            $this->data['disable_labels'] = apply_filters( 'awl_hide_for_barn2_tables', $this->data['disable_labels'] );

            add_filter('wc_product_table_cell_data_image', array( $this, 'wc_product_table_cell_data_image' ) );
            add_filter('wc_product_table_data_image', array( $this, 'wc_product_table_cell_data_image' ) );

            add_filter('wc_product_table_cell_data_name', array( $this, 'wc_product_table_cell_data_name' ) );
            add_filter('wc_product_table_data_name', array( $this, 'wc_product_table_cell_data_name' ) );

        }

        /*
         * Show labels for table image column
         */
        public function wc_product_table_cell_data_image( $data ) {
            if ( ! ( isset( $this->data['disable_labels'] ) && $this->data['disable_labels'] ) ) {
                $data = '<div style="position: relative;">' . AWL_Label_Display::instance()->show_label( 'on_image' ) . $data . '</div>';
            }
            return $data;
        }

        /*
         * Show labels for table name column
         */
        public function wc_product_table_cell_data_name( $data ) {
            if ( ! ( isset( $this->data['disable_labels'] ) && $this->data['disable_labels'] ) ) {
                $data = AWL_Label_Display::instance()->show_label( 'before_title' ) . $data;
            }
            return $data;
        }

    }

endif;

AWL_Barn_Tables::instance();