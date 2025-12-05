<?php
/*
 * Functions for external use
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get all labels markup for current product
 * @since 1.45
 * @param string $position_type Labels position type
 * @param integer $product_force Product ID @since 1.49
 * @return string
 */
if ( ! function_exists( 'awl_get_labels_html_by_position' ) ) {

    function awl_get_labels_html_by_position( $position_type = 'all', $product_force = false ) {

        $label_html = '';

        if ( $product_force ) {
            setup_postdata( $product_force );
        }

        if ( ! isset( $GLOBALS['product'] ) || ! $GLOBALS['product'] || ! is_object( $GLOBALS['product'] ) ) {
            return $label_html;
        }

        global $product;

        $labels = AWL_Helpers::get_awl_labels();
        $labels_to_show = array();

        foreach ( $labels as $label_id ) {

            $label_options    = AWL()->get_label_settings( $label_id );
            $label_is_active  = isset( $label_options['awl_label_status'] ) ? $label_options['awl_label_status']['status'] : true;
            $label_conditions = isset( $label_options['conditions'] ) ? $label_options['conditions'] : false;
            $label_settings   = isset( $label_options['settings'] ) ? $label_options['settings'] : false;

            if ( $label_is_active && $label_conditions && $label_settings ) {

                $label_settings['id'] = $label_id;
                $label_position_type  = $label_settings['position_type'];

                if ( $position_type === 'all' || $label_position_type === $position_type ) {

                    $match_condition = AWL_Helpers::match_conditions( $label_conditions );
                    $match_condition = apply_filters( 'awl_show_label_for_product', $match_condition, $product, $label_id );

                    if ( $match_condition ) {
                        $label_settings['position_type'] = 'before_title';
                        $label_settings['position'] = 'left_top';
                        $label_settings['position_x'] = 'left';
                        $label_settings['margin']['top'] = '0';
                        $label_settings['margin']['right'] = '0';
                        $label_settings['margin']['bottom'] = '0';
                        $label_settings['margin']['left'] = '0';
                        $labels_to_show[] = $label_settings;
                    }

                }

            }

        }

        if ( ! empty( $labels_to_show ) ) {
            $label_html .= AWL_Helpers::get_label_html( $labels_to_show, 'before_title' );
        }

        if ( $product_force ) {
            wp_reset_postdata();
        }

        return $label_html;

    }

}

/**
 * Get label markup by label ID
 * @since 1.45
 * @param integer $label_id Labels ID
 * @param string $position_type Labels position type
 * @return string
 */
if ( ! function_exists( 'awl_get_label_html_by_id' ) ) {

    function awl_get_label_html_by_id( $label_id, $label_position_type = 'before_title' ) {

        $label_options = AWL()->get_label_settings( $label_id );
        $label_settings = isset( $label_options['settings'] ) ? $label_options['settings'] : false;
        $label_html = '';

        if ( $label_settings ) {

            $label_settings['position_type'] = $label_position_type;
            $label_settings['position'] = 'left_top';
            $label_settings['position_x'] = 'left';
            $label_settings['margin']['top'] = '0';
            $label_settings['margin']['right'] = '0';
            $label_settings['margin']['bottom'] = '0';
            $label_settings['margin']['left'] = '0';

            $labels = array( $label_settings );

            $global_settings = AWL()->get_settings();

            $label_html_obj = new AWL_Label_View( $labels, $label_position_type, $global_settings );

            $label_html = $label_html_obj->html();

        }

        return $label_html;

    }

}

/**
 * Helper function to compare values of condition rules
 * @since 1.55
 * @param string $operator Compare operator
 * @param mixed $value Value to compare
 * @param mixed $user_value Value to compare
 * @return boolean
 */
if ( ! function_exists( 'awl_compare_condition_values' ) ) {

    function awl_compare_condition_values( $operator, $value, $user_value ) {

        $match = false;

        if ( is_bool( $value )  ) {
            $value = $value ? 'true' : 'false';
        }

        if ( 'equal' == $operator ) {
            $match = ($value == $user_value);
        } elseif ( 'not_equal' == $operator ) {
            $match = ($value != $user_value);
        } elseif ( 'greater' == $operator ) {
            $match = ($value >= $user_value);
        } elseif ( 'less' == $operator ) {
            $match = ($value <= $user_value);
        }

        return $match;

    }

}