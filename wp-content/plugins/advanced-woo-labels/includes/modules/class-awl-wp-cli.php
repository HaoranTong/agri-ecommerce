<?php
/**
 * WP-Cli commands
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

WP_CLI::add_command( 'awl', 'AWL_WP_CLI' );

class AWL_WP_CLI {

    public $data = array();

    /**
     * Display plugin information
     *
     * ## EXAMPLES
     * wp awl info
     *
     * @subcommand info
     */
    function info( $args, $assoc_args ) {
        WP_CLI::success( 'Thanks for using Advanced Woo Labels plugin!' );
        WP_CLI::line( '' );
        WP_CLI::line( '- Plugin Version: ' . AWL_VERSION );
        WP_CLI::line( '- Plugin Directory: ' . AWL_DIR );
        WP_CLI::line( '- Plugin Website: https://advanced-woo-labels.com/' );
        WP_CLI::line( '' );
    }

    /**
     * Delete label
     *
     * ## OPTIONS
     *
     * <id>
     * : Label id
     *
     * ## EXAMPLES
     * wp awl delete 123
     *
     * @subcommand delete
     */
    function delete( $args, $assoc_args ) {

        if ( empty( $args ) ) {
            WP_CLI::error( 'Label ID is not specified!' );
        }

        $label_id = intval( $args[0] );

        $delete = wp_delete_post( $label_id, true );

        if ( ! $delete ) {
            WP_CLI::error( 'Failed to delete label!' );
        }

        WP_CLI::success( 'Label deleted!' );

    }

    /**
     * Deactivate label
     *
     * ## OPTIONS
     *
     * <id>
     * : Label id
     *
     * ## EXAMPLES
     * wp awl deactivate 123
     *
     * @subcommand deactivate
     */
    function deactivate( $args, $assoc_args ) {

        if ( empty( $args ) ) {
            WP_CLI::error( 'Label ID is not specified!' );
        }

        $label_id = intval( $args[0] );

        $label_options = AWL()->get_label_settings( $label_id );

        if ( $label_options && isset( $label_options['awl_label_status']['status']  ) ) {
            $label_options['awl_label_status']['status'] = '0';
            AWL()->update_label_settings( $label_id, $label_options );
        } else {
            WP_CLI::error( 'Label not found!' );
        }

        WP_CLI::success( 'Label deactivated!' );

    }

    /**
     * Activate label
     *
     * ## OPTIONS
     *
     * <id>
     * : Label id
     *
     * ## EXAMPLES
     * wp awl activate 123
     *
     * @subcommand activate
     */
    function activate( $args, $assoc_args ) {

        if ( empty( $args ) ) {
            WP_CLI::error( 'Label ID is not specified!' );
        }

        $label_id = intval( $args[0] );

        $label_options = AWL()->get_label_settings( $label_id );

        if ( $label_options && isset( $label_options['awl_label_status']['status']  ) ) {
            $label_options['awl_label_status']['status'] = '1';
            AWL()->update_label_settings( $label_id, $label_options );
        } else {
            WP_CLI::error( 'Label not found!' );
        }

        WP_CLI::success( 'Label activated!' );

    }

}
