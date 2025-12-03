<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Ajax' ) ) :

    /**
     * Class for plugin admin ajax hooks
     */
    class AWL_Admin_Ajax {

        /*
         * Constructor
         */
        public function __construct() {

            add_action( 'wp_ajax_awl-getRuleGroup', array( $this, 'get_rule_group' ) );

            add_action( 'wp_ajax_awl-getSuboptionValues', array( $this, 'get_suboption_values' ) );

            add_action( 'wp_ajax_awl-changeLabelStatus', array( $this, 'change_label_status' ) );

            add_action( 'wp_ajax_awl-getSelectOptionValues', array( $this, 'get_select_option_values' ) );

            add_action( 'wp_ajax_awl-showCurrentHooks', array( $this, 'show_current_hooks' ) );

        }

        /*
         * Ajax hook for rule groups
         */
        public function get_rule_group() {

            check_ajax_referer( 'awl_admin_ajax_nonce' );

            $name = sanitize_text_field( $_POST['name'] );
            $group_id = sanitize_text_field( $_POST['groupID'] );
            $rule_id = sanitize_text_field( $_POST['ruleID'] );

            $rules = AWL_Admin_Options::include_rules();
            $html = array();

            foreach ( $rules as $rule_section => $section_rules ) {
                foreach ( $section_rules as $rule ) {
                    if ( $rule['id'] === $name ) {

                        $rule_obj = new AWL_Admin_Label_Rules( $rule, $group_id, $rule_id );

                        $html['aoperators'] = $rule_obj->get_field( 'operator' );

                        if ( isset( $rule['suboption'] ) ) {
                            $html['asuboptions'] = $rule_obj->get_field( 'suboption' );
                        }

                        $html['avalues'] = $rule_obj->get_field( 'value' );

                        break;

                    }
                }
            }

            wp_send_json_success( $html );

        }

        /*
         * Ajax hook for suboption values
         */
        public function get_suboption_values() {

            check_ajax_referer( 'awl_admin_ajax_nonce' );

            $param = sanitize_text_field( $_POST['param'] );
            $suboption = sanitize_text_field( $_POST['suboption'] );
            $group_id = sanitize_text_field( $_POST['groupID'] );
            $rule_id = sanitize_text_field( $_POST['ruleID'] );

            $rules = AWL_Admin_Options::include_rules();
            $html = array();

            foreach ( $rules as $rule_section => $section_rules ) {
                foreach ( $section_rules as $rule ) {
                    if ( $rule['id'] === $param ) {

                        $rule['choices']['params'] = array( $suboption );

                        $rule_obj = new AWL_Admin_Label_Rules( $rule, $group_id, $rule_id );

                        $html = $rule_obj->get_field( 'value' );

                        break;

                    }
                }
            }

            wp_send_json_success( $html );

        }

        /*
         * Ajax hook for label status change
         */
        public function change_label_status() {

            check_ajax_referer( 'awl_admin_ajax_nonce' );

            $id = sanitize_text_field( $_POST['id'] );

            $label_options = AWL()->get_label_settings( $id );

            $label_is_active  = isset( $label_options['awl_label_status'] ) ? $label_options['awl_label_status']['status'] : true;

            if ( $label_is_active  ) {
                $label_options['awl_label_status']['status'] = '0';
            } else {
                $label_options['awl_label_status']['status'] = '1';
            }

            AWL()->update_label_settings( $id, $label_options );

            wp_send_json_success( '1' );

        }

        /*
         * Ajax hook to search for products
         */
        public function get_select_option_values() {

            check_ajax_referer( 'awl_admin_ajax_nonce' );

            $callback = sanitize_text_field( $_POST['callback'] );
            $callback_params = isset( $_POST['param'] ) ? (array) $_POST['param'] : array();

            $term = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
            $term = (string) wc_clean( wp_unslash( $term ) );

            if ( $term ) {
                $callback_params = array( $term );
            }

            $values_arr = call_user_func_array( $callback, $callback_params );

            wp_send_json( array( 'results' => $values_arr ) );

        }

        /*
         * Show currently active display hooks
         */
        public function show_current_hooks() {
            
            check_ajax_referer( 'awl_admin_ajax_nonce' );

            $hooks = AWL_Helpers::get_hooks();
            $html = '';

            $hook_position = '';

            if ( $hooks ) {

                $hooks_tabls = new AWL_Admin_Hooks_Table( array() );

                foreach ( $hooks as $pos => $hooks_types ) {
                    $hook_position = $pos;
                    if ( $hooks_types ) {
                        foreach ( $hooks_types as $type => $hooks ) {

                            if ( $type === 'archive_custom' ) {
                                continue;
                            }

                            if ( $hooks ) {
                                foreach ( $hooks as $name => $args ) {
                                    if ( $name && $args ) {

                                        $custom = '';
                                        $hook = '';

                                        $hook_type = isset( $args['type'] ) ? $args['type'] : 'action';
                                        $hook_priority = isset( $args['priority'] ) ? $args['priority'] : '10';

                                        $js = '';
                                        $js_pos = '';

                                        if ( isset( $args['js'] ) && ! empty( $args['js'] ) ) {
                                            $js = $args['js'][0];
                                            $js_pos = isset( $args['js'][1] ) ? $args['js'][1] : 'append';
                                        }

                                        $callback = '';
                                        $callback_args = '';

                                        if ( isset( $args['callback'] )  ) {
                                            $callback = is_array( $args['callback'] ) && isset( $args['callback'][0] ) && is_object( $args['callback'][0] ) ? get_class( $args['callback'][0] ) . '|' . $args['callback'][1] : (string) $args['callback'];
                                            $callback_args = isset( $args['args'] ) ? $args['args'] : 0;
                                        }

                                        $default_hooks = AWL_Helpers::get_woocommerce_hooks();
                                        if ( array_search( $name, $default_hooks ) === false || $js || $callback ) {
                                            $custom = $name;
                                        }

                                        // simple or advanced hooks
                                        if ( $js || $callback ) {
                                            $hook = 'advanced';
                                        } else if ( $custom ) {
                                            $hook = 'custom';
                                        } else {
                                            $hook = $name;
                                        }

                                        $hook_id = 'hookid_' . uniqid();

                                        $hook_arr = array(
                                            $hook_id => array(
                                                "position" => $hook_position,
                                                "hook" => $hook,
                                                "custom" => $custom,
                                                "type" => $hook_type,
                                                "priority" => $hook_priority,
                                                "js" => $js,
                                                "js_pos" => $js_pos,
                                                "callback" => $callback,
                                                "callback_args" => $callback_args
                                            )
                                        );

                                        $html .= $hooks_tabls->get_hooks( $hook_arr );

                                    }
                                }
                            }
                        }
                    }
                }
            }

            wp_send_json_success( $html );

        }

    }

endif;


new AWL_Admin_Ajax();