<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Label_Settings' ) ) :

    /**
     * Class for admin label settings fields
     */
    class AWL_Admin_Label_Settings {

        /**
         * @var array AWL_Admin_Label_Settings The array of label options
         */
        private $options;

        /**
         * @var array AWL_Admin_Label_Settings The array of label settings values
         */
        private $values;

        /**
         * @var string AWL_Admin_Label_Settings Field name
         */
        private $field_name = '';

        private $field_value = '';

        /*
         * Constructor
         */
        public function __construct( $options, $values ) {

            $this->options = $options;
            $this->values = $values;

        }

        /*
         * Generate label settings tabs
         * @return string
         */
        public function generate_nav_tabs() {

            if ( empty( $this->options ) ) {
                return;
            }

            $tabs = AWL_Admin_Options::include_label_settings_tabs();

            $html = '';

            $html .= '<div class="awl-label-settings-tabs">';
                $html .= '<div class="inner">';

                    $html .= '<ul>';

                    if ( ! empty( $tabs ) ) {
                        foreach ( $tabs as $tab_slug => $tab_name ) {

                            $class = $tab_slug === 'general' ? 'current' : '';

                            $html .= '<li>';
                                $html .= '<a href="#" data-section="' . $tab_slug . '" class="tab-' . $tab_slug . ' ' . $class . '">' . $tab_name . '</a>';
                            $html .= '</li>';

                        }
                    }

                    $html .= '</ul>';

                $html .= '</div>';
            $html .= '</div>';

            return $html;

        }

        /*
         * Generate options fields
         * @return string
         */
        public function generate_fields() {

            if ( empty( $this->options ) ) {
                return;
            }

            $html = '';

            $html .= '<table data-currect-section="general" class="awl-label-settings-table' . $this->extra_classes() . '">';
                $html .= '<tbody>';

                foreach ( $this->options as $section => $section_options ) {

                    foreach( $section_options as $field) {

                        /**
                         * Filter label settings field before render
                         * @since 1.56
                         * @param array $field Array of options for current field
                         * @param array $this->values Array of current label options
                         */
                        $field = apply_filters( 'awl_settings_field_' . $field['id'], $field, $this->values );

                        $this->field_name = $this->get_field_name( $field );

                        $html .= '<tr valign="top" data-section="' . esc_attr( $section ) . '" data-option-id="' . esc_attr( $field['id'] ) . '" ' . $this->get_field_classes( $field ) . '>';

                            $html .= '<th scope="row"><label for="' . esc_attr( AWL_Admin_Helpers::sanitize_tag( $this->field_name ) ) . '">' . $field['name'] . '</label></th>';

                            $html .= '<td>';

                                $html .= $this->get_field( $field );

                                if ( isset( $field['tip'] ) && $field['tip'] ) {
                                    $html .= '<span class="awl-help-tip" data-tip="'. esc_attr( $field['tip'] ) .'"></span>';
                                }

                                if ( isset( $field['spoiler'] ) && $field['spoiler'] ) {

                                    $html .= '<br>';

                                    $html .= '<span class="additional-info">';
                                        $html .= '<a href="#">' . $field['spoiler']['title'] . '</a>';
                                        $html .= '<span class="info-spoiler">' . stripslashes( $field['spoiler']['text'] ) . '</span>';
                                    $html .= '<span>';

                                }

                            $html .= '</td>';

                        $html .= '</tr>';

                    }

                }

                $html .= '</tbody>';
            $html .= '</table>';

            return $html;

        }

        /*
         * Get field name
         * @return string
         */
        private function get_field_name( $field ) {
            return "awl_label_params[settings][" . $field['id'] . "]";
        }

        /*
         * Get field value
         * @return string
         */
        private function get_field_value( $field ) {

            $value = isset( $field['value'] ) ? $field['value'] : '';
            $field_value = false;

            if ( $this->values && ! empty( $this->values ) && isset( $this->values['settings'] ) ) {
                $field_id = $field['id'];
                if ( isset( $this->values['settings'][$field_id] ) ) {
                    $field_value = $this->values['settings'][$field_id];
                    $suboption = isset( $field['suboption'] ) ? $field['suboption'] : '';
                    if ( $suboption && isset( $field_value[$suboption] ) ) {
                        $field_value = $field_value[$suboption];
                    }
                }
            }

            if ( is_string( $field_value ) ) {
                $value =  $field_value;
            }

            return $value;

        }

        /*
         * Get field class names
         * @return string
         */
        private function get_field_classes( $field ) {
            $classes = isset( $field['class'] ) && is_string( $field['class'] ) ? 'class="' . $field['class'] . '"' : '';
            return $classes;
        }

        /*
         * Get extra table class names
         * @return string
         */
        private function extra_classes() {
            $classes = '';
            $classes .= ( $this->get_field_value( array( 'id' => 'position_type' ) ) === 'on_image' ) ? ' awl-position-on-image' : ' awl-position-on-line';
            $classes .= ( $this->get_field_value( array( 'id' => 'custom_styles' ) ) === 'true' ) ? '' : ' awl-disabled-styles';
            return $classes;
        }

        /*
         * Get field html markup
         * @param field array Field params
         * @return string
         */
        private function get_field( $field ) {

            $field_html = '';

            if ( isset( $field['params'] ) && is_array( $field['params'] ) ) {
                 $field_html .= '<div class="fields-columns fields-columns-' . count( $field['params'] ) . '">';
                 foreach( $field['params'] as $name => $val ) {
                     $this->field_name = $this->get_field_name( $field ) . '[' . $name . ']';
                     $field['suboption'] = $name;
                     $field['value'] = $val;
                     $field_html .= '<div class="fields-column-item">' . $this->call_field( $field ) . '</div>';
                 }
                $field_html .= '</div>';
            } else {
                $field_html .= $this->call_field( $field );
            }

            return $field_html;

        }

        /*
         * Call field type method
         * @param field array Field params
         * @return string
         */
        private function call_field( $field ) {
            $this->field_value = $this->get_field_value( $field );
            return call_user_func_array( array( $this, 'get_field_' . $field['type'] ), array( $field ) );
        }

        /*
         * Select field html markup
         * @return string
         */
        private function get_field_select( $field ) {

            $disabled = isset( $field['disabled'] ) && $field['disabled'] ? ' disabled' : '';

            $html = '';

            $html .= '<select id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" name="' . esc_attr( $this->field_name ) . '"'. $disabled .'>';

            foreach ( $field['choices'] as $val => $label ) {
                $disabled = strpos( $val, '__pro' ) !== false ? ' disabled' : '';
                $html .= '<option ' . selected( $this->field_value, $val, false ) . ' value="' . esc_attr( $val ) . '"'. $disabled .'>' . esc_html( $label ) . '</option>';
            }

            $html .= '</select>';

            return $html;

        }

        /*
         * Text field html markup
         * @return string
         */
        private function get_field_text( $field ) {

            $disabled = isset( $field['disabled'] ) && $field['disabled'] ? ' disabled' : '';

            $html = '<input id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" name="' . esc_attr( $this->field_name ) . '" type="text" value="' . esc_attr( stripslashes( $this->field_value ) ) . '"'. $disabled .'/>';

            return $html;

        }

        /*
        * Number field html markup
        * @return string
        */
        private function get_field_number( $field ) {

            $disabled = isset( $field['disabled'] ) && $field['disabled'] ? ' disabled' : '';

            $params = '';
            $params .= isset( $field['step'] ) ? ' step="' . $field['step'] . '"' : '';
            $params .= isset( $field['min'] ) ? ' min="' . $field['min'] . '"' : '';
            $params .= isset( $field['max'] ) ? ' max="' . $field['max'] . '"' : '';

            $html = '<input id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" name="' . esc_attr( $this->field_name ) . '" type="number" '.$params.' value="' . floatval( esc_attr( stripslashes( $this->field_value ) ) ) . '"'. $disabled .'/>';

            return $html;

        }

        /*
         * Textarea field html markup
         * @return string
         */
        private function get_field_textarea( $field ) {

            $disabled = isset( $field['disabled'] ) && $field['disabled'] ? ' disabled' : '';

            $html = '<textarea id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" name="' . esc_attr( $this->field_name ) . '" cols="45" rows="3"'. $disabled .'>' . stripslashes( $this->field_value ) . '</textarea>';

            return $html;

        }

        /*
         * Color field html markup
         * @return string
         */
        private function get_field_color( $field ) {

            $params = isset( $field['alpha'] ) && $field['alpha'] ? 'data-alpha="true"' : '';
            $html = '<input '.$params.' name="' . esc_attr( $this->field_name ) . '" id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" type="text" value="' . esc_attr( $this->field_value ) . '" class="awl-color-picker" />';

            return $html;

        }

        /*
         * Checkbox field html markup
         * @return string
         */
        private function get_field_checkbox( $field ) {

            $disabled = isset( $field['disabled'] ) && $field['disabled'] ? ' disabled' : '';

            $html = '';

            $html .= '<input id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" type="checkbox" name="' . esc_attr( $this->field_name ) . '" value="1" ' . checked( $this->field_value, '1', false ) . $disabled . '>';

            return $html;

        }

        /*
         * Checkbox field html markup
         * @return string
         */
        private function get_field_checkbox2( $field ) {

            $html = '';

            $html .= '<label class="awl-toggle-label" data-toggle="">';
                $html .= '<input id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" type="checkbox" name="' . esc_attr( $this->field_name ) . '" value="true" ' . checked( $this->field_value, 'true', false ) . '>';
                $html .= '<span class="awl-toggle"></span>';
            $html .= '</label>';

            return $html;

        }

        /*
         * Upload button field html markup
         * @return string
         */
        private function get_field_upload( $field ) {

            $html =  '<input id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" data-awl-upload type="button" name="upload-btn" class="upload-btn button-secondary" value="' . esc_attr( stripslashes( $field['value'] ) ) . '">';

            return $html;

        }

        /*
         * Template field html markup
         * @return string
         */
        private function get_field_template( $field ) {

            $template_type = $this->get_field_value( array( 'id' => 'type', 'value' => 'text' ) );
            $image_url = isset( $field['choices'][$template_type] ) && isset( $field['choices'][$template_type][$this->field_value] ) ? $field['choices'][$template_type][$this->field_value] : $field['choices']['text']['standard'];

            // custom uploaded image
            if ( ! $image_url ) {
                $image_url = $this->field_value;
                $field['choices']['image'] = array( $this->field_value => $this->field_value ) + $field['choices']['image'];
            }

            $html = '';

            $html .= '<div data-awl-template class="awl-template">';

                $html .= '<input data-template-val id="' . AWL_Admin_Helpers::sanitize_tag( $this->field_name ) . '" name="' . esc_attr( $this->field_name ) . '" type="hidden" value="' . esc_attr( $this->field_value ) . '"/>';

                $html .= '<span data-current-template class="ico" style="background: url(' .  esc_url( $image_url ) . ') no-repeat 50% 50%;"></span>';

                $html .= '<div data-template-select class="img-select">';

                    $html .= '<div class="img-select-inner">';

                        foreach ( $field['choices'] as $template_group => $template_group_items ) {

                            $html .= '<ul data-templates="' . $template_group . '">';

                                foreach( $template_group_items as $template_val => $template_img ) {

                                    $is_active = ( $this->field_value === $template_val ) ? ' awl-active' : '';
                                    $pro_only = strpos( $template_val, '-pro' ) !== false ? ' awl-pro-template' : '';

                                    $html .= '<li class="option' . $is_active . $pro_only . '" data-val="' . $template_val . '">';
                                        $html .= '<span class="ico" style="background: url(' .  esc_url( $template_img ) . ') no-repeat 50% 50%;"></span>';
                                    $html .= '</li>';

                                }

                            $html .= '</ul>';

                        }

                    $html .= '</div>';

                $html .= '</div>';

            $html .= '</div>';

            return $html;

        }

    }

endif;