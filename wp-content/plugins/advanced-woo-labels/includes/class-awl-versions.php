<?php
/**
 * Versions capability
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'AWL_Versions' ) ) :

    /**
     * Class for plugin search
     */
    class AWL_Versions {

        /**
         * Return a singleton instance of the current class
         *
         * @return object
         */
        public static function factory() {
            static $instance = false;

            if ( ! $instance ) {
                $instance = new self();
                $instance->setup();
            }

            return $instance;
        }

        /**
         * Placeholder
         */
        public function __construct() {}

        /**
         * Setup actions and filters for all things settings
         */
        public function setup() {

            add_filter( 'awl_settings_field_font_style', array( $this, 'change_old_style_field' ), 10, 2 );
            add_filter( 'awl_settings_field_font_weight', array( $this, 'change_old_style_field' ), 10, 2 );

            $current_version = get_option( 'awl_plugin_ver' );

            if ( $current_version ) {

                if ( version_compare( $current_version, '1.45', '<' ) ) {
                    $settings = get_option( 'awl_settings' );
                    if ( $settings ) {
                        if ( ! isset( $settings['display_hooks'] ) ) {
                            $settings['display_hooks'] = 'true';
                        }
                        update_option( 'awl_settings', $settings );
                    }
                }

                if ( version_compare( $current_version, '1.75', '<' ) ) {
                    $settings = get_option( 'awl_settings' );
                    if ( $settings ) {
                        if ( ! isset( $settings['hooks_relation'] ) ) {
                            $settings['hooks_relation'] = 'additional';
                        }
                        if ( isset( $settings['hooks'] ) && is_array( $settings['hooks'] ) ) {
                            $hooks = $settings['hooks'];
                            foreach ( $hooks as $hooks_id => $hooks_args ) {
                                if ( isset( $hooks_args['custom'] ) && $hooks_args['custom'] && in_array( $hooks_args['hook'], array( 'custom action', 'custom filter' ) ) ) {
                                    $type = $hooks_args['hook'] === 'custom filter' ? 'filter' : 'action';
                                    $settings['hooks'][$hooks_id]['hook'] = 'custom';
                                    $settings['hooks'][$hooks_id]['type'] = $type;
                                }
                            }
                        }
                        update_option( 'awl_settings', $settings );
                    }
                }

                if ( version_compare( $current_version, '1.88', '<' ) ) {
                    $settings = get_option( 'awl_settings' );
                    if ( $settings ) {
                        if ( ! isset( $settings['show_loops'] ) ) {
                            $settings['show_loops'] = 'true';
                        }
                        update_option( 'awl_settings', $settings );
                    }
                }
                
            }
            
            update_option( 'awl_plugin_ver', AWL_VERSION );

        }

        /*
         * Since version 1.56 - update font_style and font_weight options
         */
        public function change_old_style_field( $field, $label_values ) {

            if ( isset( $label_values['settings'] ) && isset( $label_values['settings']['font_style'] ) ) {

                switch ($label_values['settings']['font_style']) {

                    case 'bold_italic':

                        if ($field['id'] === 'font_style') {

                            $field['value'] = 'italic';
                            $choices = array();

                            if ( isset( $field['choices'] ) ) {
                                $choices['italic'] = $field['choices']['italic'];
                                foreach ($field['choices'] as $field_choice_id => $field_choice_val) {
                                    if (!isset($choices[$field_choice_id])) {
                                        $choices[$field_choice_id] = $field_choice_val;
                                    }
                                }
                            }

                            $field['choices'] = $choices;

                        }
                        if ($field['id'] === 'font_weight') {
                            $field['value'] = '700';
                        }

                        break;

                    case 'bold':

                        if ($field['id'] === 'font_style') {
                            $field['value'] = 'normal';
                        }
                        if ($field['id'] === 'font_weight') {
                            $field['value'] = '700';
                        }

                        break;

                }

            }

            return $field;

        }

    }

endif;

add_action( 'admin_init', 'AWL_Versions::factory' );