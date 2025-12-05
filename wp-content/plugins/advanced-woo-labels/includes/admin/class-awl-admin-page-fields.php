<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'AWL_Admin_Page_Fields' ) ) :

    /**
     * Class for plugin admin ajax hooks
     */
    class AWL_Admin_Page_Fields {

        /**
         * @var AWL_Admin_Page_Fields The array of options that is need to be generated
         */
        private $options_array;

        /**
         * @var AWL_Admin_Page_Fields Current plugin instance options
         */
        private $plugin_options;

        /*
         * Constructor
         */
        public function __construct( $options ) {

            $this->options_array = $options;
            $this->plugin_options = get_option( 'awl_settings' );

            $this->generate_fields();

        }

        /*
         * Generate options fields
         */
        private function generate_fields() {

            if ( empty( $this->options_array ) ) {
                return;
            }

            $plugin_options = $this->plugin_options;

            echo '<table class="form-table">';
            echo '<tbody>';

            foreach ( $this->options_array as $k => $value ) {

                $disabled_class = isset( $value['disabled'] ) && $value['disabled'] ? ' awl-disabled' : '';

                switch ( $value['type'] ) {

                    case 'text': ?>
                        <?php $placeholder = $value['placeholder'] ? $value['placeholder'] : ''; ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( $value['id'] ); ?>" placeholder="<?php echo $placeholder; ?>" class="regular-text" value="<?php echo isset( $plugin_options[ $value['id'] ] ) ? esc_attr( stripslashes( $plugin_options[ $value['id'] ] ) ) : ''; ?>">

                                <?php if ( isset( $value['tip'] ) && $value['tip'] ): ?>
                                    <span class="awl-help-tip" data-tip="<?php echo esc_attr( $value['tip'] ); ?>"></span>
                                <?php endif; ?>

                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'image': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( $value['id'] ); ?>" class="regular-text" value="<?php echo esc_attr( stripslashes( $plugin_options[ $value['id'] ] ) ); ?>">
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                                <img style="display: block;max-width: 100px;margin-top: 20px;" src="<?php echo esc_url( $plugin_options[ $value['id'] ] ); ?>">
                            </td>
                        </tr>
                        <?php break;

                    case 'number': ?>

                        <?php
                        $params = '';
                        $params .= isset( $value['step'] ) ? ' step="' . $value['step'] . '"' : '';
                        $params .= isset( $value['min'] ) ? ' min="' . $value['min'] . '"' : '';
                        $params .= isset( $value['max'] ) ? ' max="' . $value['max'] . '"' : '';
                        ?>

                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <input type="number" <?php echo $params; ?> name="<?php echo esc_attr( $value['id'] ); ?>" class="regular-text" value="<?php echo esc_attr( stripslashes( $plugin_options[ $value['id'] ] ) ); ?>">

                                <?php if ( isset( $value['tip'] ) && $value['tip'] ): ?>
                                    <span class="awl-help-tip" data-tip="<?php echo esc_attr( $value['tip'] ); ?>"></span>
                                <?php endif; ?>

                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'textarea': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <?php $textarea_cols = isset( $value['cols'] ) ? $value['cols'] : "55"; ?>
                                <?php $textarea_rows = isset( $value['rows'] ) ? $value['rows'] : "4"; ?>
                                <textarea id="<?php echo esc_attr( $value['id'] ); ?>" name="<?php echo esc_attr( $value['id'] ); ?>" cols="<?php echo $textarea_cols; ?>" rows="<?php echo $textarea_rows; ?>"><?php print stripslashes( $plugin_options[ $value['id'] ] ); ?></textarea>
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'checkbox': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <?php $checkbox_options = $plugin_options[ $value['id'] ]; ?>
                                <?php foreach ( $value['choices'] as $val => $label ) { ?>
                                    <input type="checkbox" name="<?php echo esc_attr( $value['id'] . '[' . $val . ']' ); ?>" id="<?php echo esc_attr( $value['id'] . '_' . $val ); ?>" value="1" <?php checked( $checkbox_options[$val], '1' ); ?>> <label for="<?php echo esc_attr( $value['id'] . '_' . $val ); ?>"><?php echo esc_html( $label ); ?></label><br>
                                <?php } ?>
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'radio': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <?php foreach ( $value['choices'] as $val => $label ) { ?>
                                    <input class="radio" type="radio" name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'].$val ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( $plugin_options[ $value['id'] ], $val ); ?>> <label for="<?php echo esc_attr( $value['id'].$val ); ?>"><?php echo esc_html( $label ); ?></label><br>
                                <?php } ?>
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'select': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <select class="<?php echo $disabled_class; ?>" name="<?php echo esc_attr( $value['id'] ); ?>">
                                    <?php foreach ( $value['choices'] as $val => $label ) { ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $plugin_options[ $value['id'] ], $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php } ?>
                                </select>

                                <?php if ( isset( $value['tip'] ) && $value['tip'] ): ?>
                                    <span class="awl-help-tip" data-tip="<?php echo esc_attr( $value['tip'] ); ?>"></span>
                                <?php endif; ?>

                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'select_advanced': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <select name="<?php echo esc_attr( $value['id'].'[]' ); ?>" multiple class="chosen-select">
                                    <?php $values = $plugin_options[ $value['id'] ]; ?>
                                    <?php foreach ( $value['choices'] as $val => $label ) {  ?>
                                        <?php $selected = ( is_array( $values ) && in_array( $val, $values ) ) ? ' selected="selected" ' : ''; ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"<?php echo $selected; ?>><?php echo esc_html( $label ); ?></option>
                                    <?php } ?>
                                </select>
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>

                                <?php if ( $value['sub_option'] ): ?>
                                    <?php $sub_options = $value['sub_option']; ?>
                                    <br><br>
                                    <p>
                                        <label for="<?php echo esc_attr( $sub_options['id'] ); ?>">
                                            <input type="checkbox" value="1" id="<?php echo esc_attr( $sub_options['id'] ); ?>" name="<?php echo esc_attr( $sub_options['id'] ); ?>" <?php checked( $plugin_options[ $sub_options['id'] ], '1' ); ?>>
                                            <?php echo esc_html( $sub_options['desc'] ); ?>
                                        </label>
                                    </p>
                                <?php endif; ?>

                            </td>
                        </tr>
                        <?php break;

                    case 'radio-image': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>
                                <ul class="img-select">
                                    <?php foreach ( $value['choices'] as $val => $img ) { ?>
                                        <li class="option">
                                            <input class="radio" type="radio" name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'].$val ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( $plugin_options[ $value['id'] ], $val ); ?>>
                                            <span class="ico" style="background: url('<?php echo esc_url( AWL_URL . '/assets/img/' . $img ); ?>') no-repeat 50% 50%;"></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>
                            </td>
                        </tr>
                        <?php break;

                    case 'sortable': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>

                                <script>
                                    jQuery(document).ready(function() {

                                        jQuery( "#sti-sortable1, #sti-sortable2" ).sortable({
                                            connectWith: ".connectedSortable",
                                            placeholder: "highlight",
                                            update: function(event, ui){
                                                var serviceList = '';
                                                jQuery("#sti-sortable2 li").each(function(){

                                                    serviceList = serviceList + ',' + jQuery(this).attr('id');

                                                });
                                                var serviceListOut = serviceList.substring(1);
                                                jQuery('#<?php echo esc_attr( $value['id'] ); ?>').attr('value', serviceListOut);
                                            }
                                        }).disableSelection();

                                    });
                                </script>

                                <span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span><br><br>

                                <?php
                                $all_buttons = $value['choices'];
                                $active_buttons = explode( ',', $plugin_options[ $value['id'] ] );
                                $inactive_buttons = array_diff($all_buttons, $active_buttons);
                                ?>

                                <div class="sortable-container">

                                    <div class="sortable-title">
                                        <?php esc_html_e( 'Active sources', 'advanced-woo-labels' ) ?><br>
                                        <?php esc_html_e( 'Change order by drag&drop', 'advanced-woo-labels' ) ?>
                                    </div>

                                    <ul id="sti-sortable2" class="sti-sortable enabled connectedSortable">
                                        <?php
                                        if ( count( $active_buttons ) > 0 ) {
                                            foreach ($active_buttons as $button) {
                                                if ( ! $button ) continue;
                                                echo '<li id="' . esc_attr( $button ) . '" class="sti-btn sti-' . esc_attr( $button ) . '-btn">' . $button . '</li>';
                                            }
                                        }
                                        ?>
                                    </ul>

                                </div>

                                <div class="sortable-container">

                                    <div class="sortable-title">
                                        <?php esc_html_e( 'Deactivated sources', 'advanced-woo-labels' ) ?><br>
                                        <?php esc_html_e( 'Excluded from search results', 'advanced-woo-labels' ) ?>
                                    </div>

                                    <ul id="sti-sortable1" class="sti-sortable disabled connectedSortable">
                                        <?php
                                        if ( count( $inactive_buttons ) > 0 ) {
                                            foreach ($inactive_buttons as $button) {
                                                echo '<li id="' . esc_attr( $button ) . '" class="sti-btn sti-' . esc_attr( $button ) . '-btn">' . $button . '</li>';
                                            }
                                        }
                                        ?>
                                    </ul>

                                </div>

                                <input type="hidden" id="<?php echo esc_attr( $value['id'] ); ?>" name="<?php echo esc_attr( $value['id'] ); ?>" value="<?php echo esc_attr( $plugin_options[ $value['id'] ] ); ?>" />

                            </td>
                        </tr>
                        <?php break;

                    case 'hooks_table': ?>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html( $value['name'] ); ?></th>
                            <td>

                                <?php
                                $hooks = isset( $plugin_options[ $value['id'] ] ) && $plugin_options[ $value['id'] ] ? $plugin_options[ $value['id'] ] : array();
                                $table = new AWL_Admin_Hooks_Table( $hooks );
                                echo $table->get_hooks_table();
                                ?>

                                <br><span class="description"><?php echo wp_kses_post( $value['desc'] ); ?></span>

                            <td>
                        </tr>
                        <?php break;

                    case 'heading': ?>
                        <tr valign="top">
                            <th scope="row"><h3 class="awl-heading"><?php echo esc_html( $value['name'] ); ?></h3></th>
                        </tr>
                        <?php break;

                }

            }

            echo '</tbody>';
            echo '</table>';

            echo '<p class="submit"><input name="Submit" type="submit" class="button-primary" value="' . esc_attr__( 'Save Changes', 'advanced-woo-labels' ) . '" /></p>';

        }



    }

endif;
