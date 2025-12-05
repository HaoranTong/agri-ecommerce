<?php

/**
 * AWL Spectra plugin support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!class_exists('AWL_Spectra')) :

    /**
     * Class for main plugin functions
     */
    class AWL_Spectra {

        /**
         * @var AWL_Spectra The single instance of the class
         */
        protected static $_instance = null;

        protected $data = array();

        /**
         * Main AWL_Spectra Instance
         *
         * Ensures only one instance of AWL_Spectra is loaded or can be loaded.
         *
         * @static
         * @return AWL_Spectra - Main instance
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

            add_action( 'uagb_post_before_article_grid', array( $this, 'before_article' ) );
            add_action( 'uagb_post_after_article_grid', array( $this, 'after_article' ) );

            add_action( 'uagb_post_before_article_carousel', array( $this, 'before_article' ) );
            add_action( 'uagb_post_after_article_carousel', array( $this, 'after_article' ) );

            add_action( 'wp_head', array( $this, 'add_styles' ) );

        }

        /*
         * Change display hooks
         */
        public function awl_labels_hooks( $hooks ) {

            $hooks['on_image']['archive']['wp_get_attachment_image'] = array( 'priority' => 10, 'type' => 'filter', 'callback' => array( $this, 'wp_get_attachment_image' ) );

            $hooks['before_title']['archive']['uagb_single_post_before_title_grid'] = array( 'priority' => 10 );
            $hooks['before_title']['archive']['uagb_single_post_before_title_carousel'] = array( 'priority' => 10 );

            return $hooks;

        }

        public function before_article() {
            $this->data['is_spectra'] = true;
        }

        public function after_article() {
            $this->data['is_spectra'] = false;
        }

        /*
         * Add label on image
         */
        public function wp_get_attachment_image( $html ) {

            if ( isset( $this->data['is_spectra'] ) && $this->data['is_spectra'] ) {
                $html = $html . AWL_Label_Display::instance()->show_label( 'on_image' );
            }

            return $html;

        }

        /*
         * Add styles for blocks
         */
        public function add_styles() { ?>

            <style>
                .wp-block-uagb-post-carousel .uagb-post__image {
                    position: relative;
                }
                .wp-block-uagb-post-carousel .awl-position-type-before-title {
                    margin: 0 20px;
                }
            </style>

        <?php }

    }

endif;

AWL_Spectra::instance();