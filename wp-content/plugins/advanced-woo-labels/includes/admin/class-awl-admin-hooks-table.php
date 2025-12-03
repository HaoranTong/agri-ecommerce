<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Hooks_Table' ) ) :

    /**
     * Class for admin condition rules
     */
    class AWL_Admin_Hooks_Table {

        /**
         * @var array AWL_Admin_Hooks_Table The array of hooks
         */
        private $hooks;

        /**
         * @var array AWL_Admin_Hooks_Table Current hook
         */
        private $hook = array();

        /**
         * @var string AWL_Admin_Hooks_Table Field name
         */
        private $field_name = '';
        
        /*
         * Constructor
         */
        public function __construct( $hooks ) {

            $this->hooks = $hooks;

        }

        /*
         * Hooks table html
         * @return string
         */
        public function get_hooks_table() {

            $table_class = AWL()->get_settings( 'display_hooks' ) === 'true' ? '' : 'awl-disabled';
            if ( isset( $_REQUEST['display_hooks'] ) ) {
                $table_class = $_REQUEST['display_hooks'] === 'true' ? '' : 'awl-disabled';
            }

            $use_default_hooks_class = AWL()->get_settings( 'hooks_relation' ) !== 'default' ? '' : ' awl-use-default';
            if ( isset( $_REQUEST['hooks_relation'] ) ) {
                $use_default_hooks_class = $_REQUEST['hooks_relation'] !== 'default' ? '' : ' awl-use-default';
            }
            $table_class .= $use_default_hooks_class;

            if ( $this->hooks && ! empty( $this->hooks ) ) {
                $table_class .= ' awl-has-hooks';
            }

            $default_hook = array( 'hookid_1' => array( "position" => "on_image", "hook" => "", "type" => "action", "custom" => "", "priority" => "10", "js" => "", "js_pos" => "", "callback" => "", "callback_args" => "" ) );

            $html = '';

            $html .= '<script id="awlHooksTableTemplate" type="text/html">';
                $html .=  $this->get_hooks( $default_hook );
            $html .= '</script>';

            $html .= '<table class="awl-hooks-table ' . $table_class . '" cellspacing="0">';

                $html .= '<thead>';
                    $html .= '<tr>';
                        $html .= '<th>' . __( 'Label position', 'advanced-woo-labels' ) . '</th>';
                        $html .= '<th class="h-hook">' . __( 'Hook', 'advanced-woo-labels' ) . '</th>';
                        $html .= '<th>' . __( 'Priority', 'advanced-woo-labels' ) . '</th>';
                        $html .= '<th class="h-remove"></th>';
                    $html .= '</tr>';
                $html .= '</thead>';

                $html .= '<tbody>';

                    $html .= $this->get_hooks( $this->hooks );

                $html .= '</tbody>';

                $html .= '<tfoot>';
                    $html .= '<tr>';
                        $html .= '<th>';
                            $html .= '<a href="#" class="button add-hook-group" data-awl-add-hook>' . '<span class="dashicons dashicons-plus-alt2"></span> ' . '<span class="text">' . __( "Add hook", "advanced-woo-labels" ) . '</span>' . '</a>';
                            $html .= '<a href="#" class="button generate-hook-group" data-awl-generate-hook>' . '<span class="dashicons dashicons-randomize"></span> ' . '<span class="text">' . __( "Show current hooks", "advanced-woo-labels" ) . '</span>' . '</a>';
                        $html .= '</th>';
                    $html .= '</tr>';
                $html .= '</tfoot>';

            $html .= '</table>';


            return $html;

        }

        /*
         * Hook table row html
         * @return string
         */
        public function get_hooks( $hooks ) {

            $html = '';

            if ( $hooks && ! empty( $hooks ) ) {
                foreach ($hooks as $hook_id => $hook_args) {

                    $this->hook['id'] = $hook_id;
                    $this->hook['args'] = $hook_args;

                    $position_html = $this->get_field('position');
                    $hooks_html = $this->get_field('hook');
                    $hook_custom_html = $this->get_field('custom') . $this->get_field('type') . $this->get_field('js') . $this->get_field('js_pos') . $this->get_field('callback') . $this->get_field('callback_args');
                    $priority_html = $this->get_field('priority');

                    if ( in_array( $this->hook['args']['hook'], array( 'custom action', 'custom filter', 'custom' ) ) ) {
                        $custom_class = ' awl-custom';
                    } elseif ( $this->hook['args']['hook'] === 'advanced' ) {
                        $custom_class = ' awl-custom awl-advanced';
                    } else {
                        $custom_class = '';
                    }

                    $html .= '<tr class="awl-hook" data-awl-hook-container>';

                        $html .= '<td class="position">';
                            $html .= $position_html;
                        $html .= '</td>';

                        $html .= '<td class="hook' . $custom_class . '">';
                            $html .= $hooks_html . $hook_custom_html;
                        $html .= '</td>';

                        $html .= '<td class="priority">';
                            $html .= $priority_html;
                        $html .= '</td>';

                        $html .= '<td class="remove">';
                            $html .= '<a href="#" title="' . __( 'Remove hook', 'advanced-woo-labels' ) . '" class="button remove-hook" data-awl-remove-hook>&#150;</a>';
                        $html .= '</td>';

                    $html .= '</tr>';

                }
            }

            return $html;

        }

        /*
         * Get field html markup
         * @param $type string Field type
         * @return string
         */
        private function get_field( $type ) {

            $this->field_name = "hooks[" . $this->hook['id'] . "][{$type}]";

            return call_user_func( array( $this, 'get_hook_' . $type ) );

        }

        /*
         * Hook position html
         * @return string
         */
        private function get_hook_position() {

            $positions = AWL_Helpers::get_labels_positions();

            $html = '';

            $html .= '<select name="' . esc_attr( $this->field_name ) . '" class="position-val" data-awl-position>';

            foreach ( $positions as $position ) {
                $html .= '<option ' . selected( $this->hook['args']['position'], $position['slug'], false ) . ' value="'. esc_attr( $position['slug'] ) .'">'. esc_html( $position['name'] ) .'</option>';
            }

            $html .= '</select>';

            return $html;

        }

        /*
         * Hooks html
         * @return string
         */
        private function get_hook_hook() {

            $hooks = AWL_Helpers::get_woocommerce_hooks();
            $hooks[] = 'custom';
            $hooks[] = 'advanced';

            $html = '';

            $html .= '<select name="' . esc_attr( $this->field_name ) . '" class="hook-val" data-awl-hook>';

            foreach ( $hooks as $hook ) {
                $html .= '<option ' . selected( $this->hook['args']['hook'], $hook, false ) . ' value="'. esc_attr( $hook ) .'">'. esc_html( $hook ) .'</option>';
            }

            $html .= '</select>';

            return $html;

        }

        /*
         * Hooks html
         * @return string
         */
        private function get_hook_type() {

            $types = array(
                'action', 'filter',
            );

            $val = isset( $this->hook['args']['type'] ) ? $this->hook['args']['type'] : "action";

            $html = '';

            $html .= '<select name="' . esc_attr( $this->field_name ) . '" class="custom-val custom-val-type">';

            foreach ( $types as $type ) {
                $html .= '<option ' . selected( $val, $type, false ) . ' value="'. esc_attr( $type ) .'">'. esc_html( $type ) .'</option>';
            }

            $html .= '</select>';

            return $html;

        }

        /*
         * Custom hook html
         * @return string
         */
        private function get_hook_custom() {

            $html = '';
            $html .= '<input placeholder="' . __( "Hook name", "advanced-woo-labels" ) . '" type="text" name="' . esc_attr( $this->field_name ) . '" value="' . esc_attr( $this->hook['args']['custom'] ) . '" class="custom-val custom-val-name">';
            return $html;

        }

        /*
         * Hook html
         * @return string
         */
        private function get_hook_js() {

            $val = isset( $this->hook['args']['js'] ) ? $this->hook['args']['js'] : "";

            $html = '';
            $html .= '<input placeholder="' . __( "JS selector ( optional )", "advanced-woo-labels" ) . '" type="text" name="' . esc_attr( $this->field_name ) . '" value="' . esc_attr( $val ) . '" class="advanced-val advanced-val-js">';
            return $html;

        }

        /*
         * Hook html
         * @return string
         */
        private function get_hook_js_pos() {

            $positions = array(
                'append', 'prepend', 'after', 'before'
            );

            $val = isset( $this->hook['args']['js_pos'] ) ? $this->hook['args']['js_pos'] : "";
            $is_empty = $val ? '' : ' advanced-val-empty';

            $html = '';
            $html .= '<select name="' . esc_attr( $this->field_name ) . '" class="advanced-val advanced-val-js-pos' . $is_empty . '">';
                $html .= '<option value="" disabled selected>' . __( "JS placement ( optional )", "advanced-woo-labels" ) . '</option>';
                foreach ( $positions as $position ) {
                    $html .= '<option ' . selected( $val, $position, false ) . ' value="'. esc_attr( $position ) .'">'. esc_html( $position ) .'</option>';
                }
            $html .= '</select>';

            return $html;

        }

        /*
         * Hook html
         * @return string
         */
        private function get_hook_callback() {

            $val = isset( $this->hook['args']['callback'] ) ? $this->hook['args']['callback'] : "";

            $html = '';
            $html .= '<input placeholder="' . __( "Callback ( optional )", "advanced-woo-labels" ) . '" type="text" name="' . esc_attr( $this->field_name ) . '" value="' . esc_attr( $val ) . '" class="advanced-val advanced-val-callback">';
            return $html;

        }

        /*
         * Hooks html
         * @return string
         */
        private function get_hook_callback_args() {

            $val = isset( $this->hook['args']['callback_args'] ) ? $this->hook['args']['callback_args'] : "";

            $html = '';
            $html .= '<input placeholder="' . __( "Callback args ( optional )", "advanced-woo-labels" ) . '" type="number" name="' . esc_attr( $this->field_name ) . '" value="' . esc_attr( stripslashes( $val ) ) . '" class="advanced-val advanced-val-callback-args" min="0">';
            return $html;

        }

        /*
         * Hooks html
         * @return string
         */
        private function get_hook_priority() {

            $html = '';
            $html .= '<input type="number" name="' . esc_attr( $this->field_name ) . '" value="' . esc_attr( stripslashes( $this->hook['args']['priority'] ) ) . '" class="priority-val" min="0">';
            return $html;

        }

    }

endif;