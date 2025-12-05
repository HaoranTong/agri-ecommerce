<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Label_View' ) ) :

    /**
     * AWL label view class
     */
    class AWL_Label_View {

        private $labels = null;
        private $position_type = null;
        private $global_settings = null;

        private $settings = null;
        private $label_id = null;
        private $label_type = null;
        private $custom_styles = null;
        private $label_num = 0;

        /*
         * Constructor
         */
        public function __construct( $labels, $position_type, $global_settings ) {

            $this->labels = $labels;
            $this->position_type = $position_type;
            $this->global_settings = $global_settings;

        }

        /*
         * Generate label markup
         */
        public function html() {

            $label_container_classes = $this->label_container_classes();
            $label_container_styles = $this->label_container_styles();

            $html = '';

            foreach( $this->labels as $label ) {

                $label_html = '';

                $this->label_num++;
                $this->settings = $label;
                $this->label_id = $label['id'];
                $this->label_type = isset( $label['type'] ) ? $label['type'] : '';
                $this->custom_styles = isset( $label['custom_styles'] ) && $label['custom_styles'] === 'true' ? true : false;

                $label_wrap_styles = $this->label_wrap_styles();
                $label_wrap_classes = $this->label_wrap_classes();

                $label_styles = $this->label_styles();
                $label_classes = $this->label_classes();

                $label_html .= $this->label_custom_css();

                $label_html .= '<div class="awl-label-wrap' . $label_wrap_classes . '" style="' . $label_wrap_styles . '">';

                    $label_html .= '<span class="awl-product-label' . $label_classes . '" style="' . $label_styles . '">';

                        $label_html .= $this->label_side_block( 'before' );

                        $label_html .= $this->label_text();

                        $label_html .= $this->label_side_block( 'after' );

                    $label_html .= '</span>';

                $label_html .= '</div>';

                /**
                 * Filter current label markup
                 * @since 1.08
                 * @param string $html
                 * @param string $this->position_type
                 * @param array $label
                 */
                $label_html = apply_filters( 'awl_current_label_markup', $label_html, $this->position_type, $label );

                $html .= $label_html;

            }

            $html = '<div class="advanced-woo-labels' . $label_container_classes . '" style="' . $label_container_styles . '">' . $html . '</div>';

            /**
             * Filter all available labels markup for this position type
             * @since 1.00
             * @param string $html
             * @param string $this->position_type
             * @param array $this->labels
             */
            $html = apply_filters( 'awl_label_markup', $html, $this->position_type, $this->labels );

            return $html;

        }

        /*
         * Class names for label container
         */
        private function label_container_classes() {
            $class = '';
            $class .= ' awl-position-type-' . sanitize_title( $this->position_type );
            $class .= ' awl-label-type-' . AWL_Helpers::get_label_type();
            $class .= ( $this->position_type === 'on_image' ) ? ' awl-align-' . $this->labels[0]['position'] : ' awl-align-' . sanitize_title( $this->labels[0]['position_x'] );
            return str_replace( '_', '-', $class );
        }

        /*
         * Class names for label wrapper
         */
        private function label_wrap_classes() {
            $class = '';
            $class .= ' awl-label-id-' . strval( $this->label_id );
            return str_replace( '_', '-', $class );
        }

        /*
         * Class names for label
         */
        private function label_classes() {
            $class = '';
            $class .= ( $this->label_type === 'image' ) ? ' awl-type-image' : ' awl-type-label';
            $class .= ( $this->label_type !== 'image' ) ? ' awl-type-label-' . sanitize_title( $this->settings['template'] ) : '';
            return str_replace( '_', '-', $class );
        }

        /*
         * Custom css
         */
        private function label_custom_css() {

            $css = '';

            if ( $this->custom_styles && isset( $this->settings['custom_css'] ) && $this->settings['custom_css'] ) {
                $css .= '<style type="text/css">';
                $css .= preg_replace( '/(\.awl-[\w\-\s\.]+{)/', '.awl-label-id-' . strval( $this->label_id ) . ' $1', $this->settings['custom_css'] );
                $css .= '</style>';
                $css = str_replace('.awl-label-id-'.strval( $this->label_id ).' .awl-label-wrap', '.awl-label-id-'.strval( $this->label_id ).'.awl-label-wrap', $css );
            }

            return $css;

        }

        /*
         * Styles for label container
         */
        private function label_container_styles() {

            $position = $this->position_type === 'on_image' ? $this->labels[0]['position'] : $this->labels[0]['position_x'];
            $direction = ( count( $this->labels ) > 1 && isset( $this->global_settings['labels_alignment'] ) && $this->global_settings['labels_alignment'] === 'vertical' ) ? 'column' : 'row';

            $styles = array();
            $styles['display'] = 'flex';
            $styles['flex-wrap'] = 'wrap';
            $styles['flex-direction'] = $direction;
            $styles['text-align'] = 'left';
            $styles['width'] = $direction === 'row' ? '100%' : 'auto';
            $styles['position'] = $this->position_type === 'on_image' ? 'absolute' : 'relative';
            $styles['z-index'] = $this->position_type === 'on_image' ? '9' : '1';

            switch( $position ) {
                case 'left_top':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '0px';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'flex-start';
                break;
                case 'center_top':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '0px';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'center';
                break;
                case 'right_top':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '0px';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'flex-end';
                    $styles['align-items'] = $direction === 'row' ? 'stretch' : 'flex-end';
                    $styles['width'] = '100%';
                break;
                case 'left_center':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '50%';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'translateY(-50%)';
                    $styles['justify-content'] = 'flex-start';
                break;
                case 'center_center':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '50%';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'translateY(-50%)';
                    $styles['justify-content'] = 'center';
                break;
                case 'right_center':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = '50%';
                    $styles['bottom'] = 'auto';
                    $styles['transform'] = 'translateY(-50%)';
                    $styles['justify-content'] = 'flex-end';
                    $styles['align-items'] = $direction === 'row' ? 'stretch' : 'flex-end';
                    $styles['width'] = '100%';
                break;
                case 'left_bottom':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = 'auto';
                    $styles['bottom'] = '0px';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'flex-start';
                break;
                case 'center_bottom':
                    $styles['left'] = '0';
                    $styles['right'] = 'auto';
                    $styles['top'] = 'auto';
                    $styles['bottom'] = '0px';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'center';
                break;
                case 'right_bottom':
                    $styles['left'] = '0px';
                    $styles['right'] = 'auto';
                    $styles['top'] = 'auto';
                    $styles['bottom'] = '0px';
                    $styles['transform'] = 'none';
                    $styles['justify-content'] = 'flex-end';
                    $styles['align-items'] = $direction === 'row' ? 'stretch' : 'flex-end';
                    $styles['width'] = '100%';
                break;
                case 'left':
                    $styles['justify-content'] = 'flex-start';
                break;
                case 'center':
                    $styles['justify-content'] = 'center';
                break;
                case 'right':
                    $styles['justify-content'] = 'flex-end';
                    $styles['align-items'] = $direction === 'row' ? 'stretch' : 'flex-end';
                    $styles['width'] = '100%';
                break;
            }

            /**
             * Filter label container styles
             * @since 1.00
             * @param array $styles
             * @param array $this->labels
             */
            $styles = apply_filters( 'awl_label_container_styles', $styles, $this->position_type, $this->labels );

            return $this->implode_styles( $styles );

        }

        /*
         * Styles for label wrapper
         */
        private function label_wrap_styles() {

            $styles = array();

            if ( count( $this->labels ) > 1 && $this->label_num < count( $this->labels ) ) {
                $direction = ( isset( $this->global_settings['labels_alignment'] ) && $this->global_settings['labels_alignment'] === 'vertical' ) ? 'column' : 'row';
                $margin = isset( $this->global_settings['labels_distance'] ) ? $this->global_settings['labels_distance'] : '0';
                $styles['margin'] = $direction === 'row' ? '0 ' . $margin . 'px 0 0' : '0 0 ' . $margin . 'px 0';
            }

            /**
             * Filter label wrapper styles
             * @since 1.00
             * @param array $styles
             * @param array $this->settings
             */
            $styles = apply_filters( 'awl_label_wrapper_styles', $styles, $this->position_type, $this->settings );

            return $this->implode_styles( $styles );

        }

        /*
         * Styles for label
         */
        private function label_styles() {

            $styles = array(
                'display' => 'table',
                'position' => 'relative',
                'line-height' => '1',
                'white-space' => 'nowrap',
                'vertical-align' => 'baseline',
                'font-size' => '14px',
                'font-weight' => '400',
                'font-style' => 'normal',
                'letter-spacing' => '0',
                'color' => '#fff'
            );

            if ( $this->custom_styles && $this->label_type !== 'image' ) {

                if ( isset( $this->settings['font_size'] ) ) {
                    $styles['font-size'] = $this->settings['font_size'] . 'px';
                }

                if ( isset( $this->settings['font_weight'] ) ) {
                    $styles['font-weight'] = $this->settings['font_weight'];
                }

                if ( isset( $this->settings['text_color'] ) ) {
                    $styles['color'] = $this->settings['text_color'];
                }

                if ( isset( $this->settings['letter_spacing'] ) ) {
                    $styles['letter-spacing'] = $this->settings['letter_spacing'] . 'px';
                }

                if ( isset( $this->settings['font_style'] ) ) {
                    switch( $this->settings['font_style'] ) {
                        case 'italic':
                            $styles['font-style'] = 'italic';
                            break;
                        case 'bold_italic':
                            $styles['font-style'] = 'italic';
                            $styles['font-weight'] = '700';
                            break;
                        case 'bold':
                            $styles['font-style'] = 'normal';
                            $styles['font-weight'] = '700';
                            break;
                        case 'oblique':
                            $styles['font-style'] = 'oblique';
                            break;
                        default:
                            $styles['font-style'] = 'normal';
                    }
                }

            }

            $styles['opacity'] = '1';
            $styles['margin'] = '0';

            if ( $this->custom_styles ) {

                if ( isset( $this->settings['opacity'] ) ) {
                    $styles['opacity'] = $this->settings['opacity'];
                }

                if ( isset( $this->settings['margin'] ) ) {
                    $styles['margin'] = $this->settings['margin']['top'] . 'px ' . $this->settings['margin']['right'] . 'px ' . $this->settings['margin']['bottom'] . 'px ' . $this->settings['margin']['left'] . 'px';
                }

            }

            /**
             * Filter label styles
             * @since 1.00
             * @param array $styles
             * @param array $this->settings
             */
            $styles = apply_filters( 'awl_label_styles', $styles, $this->position_type, $this->settings );

            return $this->implode_styles( $styles );

        }

        /*
         * Label text block markup
         */
        private function label_text() {

            $styles = array(
                'display' => 'table-cell',
                'padding' => '0.3em 0.6em',
                'z-index' => '1',
                'background' => '#3986c6',
                'position' => 'relative',
                'line-height' => '1'
            );

            $styles['border-radius'] = $this->settings['template'] === 'rounded' ? '0.25em' : ( $this->settings['template'] === 'round' ? '1em' : '0' );

            if ( $this->custom_styles ) {

                $styles['background'] = $this->settings['bg_color'];
                $styles['padding'] = $this->settings['padding']['top'] . 'em ' . $this->settings['padding']['right'] . 'em ' . $this->settings['padding']['bottom'] . 'em ' . $this->settings['padding']['left'] . 'em';

            }

            $html = '';

            /**
             * Filter label text styles
             * @since 1.00
             * @param array $styles
             * @param array $this->settings
             */
            $styles = apply_filters( 'awl_label_text_styles', $styles, $this->position_type, $this->settings );

            $html .= '<span class="awl-label-text"  style="' . $this->implode_styles( $styles ) . '">';
                $html .= '<span class="awl-inner-text">' . AWL_Helpers::get_label_text( $this->settings ) . '</span>';
            $html .= '</span>';

            return $html;

        }

        /*
         * Label 'after' block markup
         */
        private function label_side_block( $name ) {

            $html = '';

            if ( $this->is_show_side( $name ) ) {

                $block_styles = array(
                    'display' => 'table-cell',
                    'width'   => '0',
                    'height'  => '0',
                    'position' => 'relative',
                    'z-index' => '2',
                    'overflow' => 'hidden',
                    'padding' => '0 0.65em 0 0'
                );

                $svg_styles = array(
                    'position' => 'absolute',
                    'top' => '0',
                    'width' => '100%',
                    'height' => '100%',
                    'fill' => '#3986c6'
                );

                $svg_line_styles = array();

                if ( $this->custom_styles && isset( $this->settings['bg_color'] ) ) {
                    $svg_styles['fill'] = $this->settings['bg_color'];
                }

                $html .= '<span class="awl-label-' . $name . '" style="' . $this->implode_styles( $block_styles ) . '">';
                    $html .= '<svg viewBox="0 0 100 100" preserveAspectRatio="none" style="' . $this->implode_styles( $svg_styles ) . '">';
                        $html .= $this->get_svg( $this->settings['template'] . '-' . $name );
                    $html .= '</svg>';
                $html .= '</span>';

                if ( ! empty( $svg_line_styles ) ) {
                    $html = str_replace( 'class="d-stroke"', 'class="d-stroke" style="' . $this->implode_styles( $svg_line_styles ) . '"', $html );
                }

            }

            return $html;

        }

        /*
         * Get svg shape
         */
        private function get_svg( $name ) {

            $icon = '';

            $icon_arr = array(
                'triangled-after' => '<g class="awl-triangled-after"><polygon vector-effect="non-scaling-stroke" points="0,0 0,100 97,50" style="stroke:none;" /><line vector-effect="non-scaling-stroke" x1="0" y1="0" x2="97" y2="50" /><line vector-effect="non-scaling-stroke" x1="97" y1="50" x2="0" y2="100" /></g>',
                'angle-after' => '<g class="awl-angle-after"><polygon vector-effect="non-scaling-stroke" points="0,0 97,0 0,100" style="stroke:none;" /><line class="d-stroke" vector-effect="non-scaling-stroke" x1="0" y1="0" x2="97" y2="0" /><line vector-effect="non-scaling-stroke" x1="97" y1="0" x2="0" y2="100" /></g>',
            );

            /**
             * Filter the array of svg icons
             * @since 1.00
             * @param array $icon_arr Array of icons
             */
            $icon_arr = apply_filters( 'awl_svg_icons', $icon_arr );

            if ( $name && isset( $icon_arr[$name] ) ) {
                $icon = $icon_arr[$name];
            }

            return $icon;

        }

        /*
         * Check weather to show side block or not
         */
        private function is_show_side( $name ) {

            $temp = array(
                'before' => array( 'arrow' ),
                'after'  => array( 'triangled', 'angle' )
            );

            if ( in_array( $this->settings['template'], $temp[$name] ) ) {
                return true;
            }

            return false;

        }

        /*
         * Implode style
         */
        private function implode_styles( $styles ) {

            $style_string = '';

            if ( $styles && ! empty( $styles ) ) {
                foreach( $styles as $style_name => $style ) {
                    $style_string .= $style_name . ':' . $style . ';';
                }
            }

            return $style_string;

        }

    }

endif;