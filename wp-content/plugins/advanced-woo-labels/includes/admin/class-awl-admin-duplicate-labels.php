<?php
/**
 * AWL plugin duplicate labels action
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class AWL_Admin_Duplicate_Labels {

    /**
     * @var AWL_Admin_Duplicate_Labels The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Main AWL_Admin_Duplicate_Labels Instance
     * @static
     * @return AWL_Admin_Duplicate_Labels - Main instance
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {

        add_action( 'admin_action_duplicate_label', array( $this, 'duplicate_label_action' ) );
        add_filter( 'post_row_actions', array( $this, 'duplicate_link' ), 10, 2 );
        add_action( 'post_submitbox_start', array( $this, 'duplicate_button' ) );

    }

    /**
     * Duplicate label action
     */
    public function duplicate_label_action() {

        if ( empty( $_REQUEST['post'] ) ) {
            wp_die( esc_html__( 'No label to duplicate has been supplied!', 'advanced-woo-labels' ) );
        }

        $label_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : '';

        check_admin_referer( 'awl-duplicate-label_' . $label_id );

        $label = get_posts( array(
            'post_type' => 'awl-labels',
            'numberposts' => -1,
            'include' => $label_id,
            'post_status' => 'any'
        ) );

        if ( false === $label ) {
            wp_die( sprintf( esc_html__( 'Label creation failed, could not find original label: %s', 'advanced-woo-labels' ), esc_html( $label_id ) ) );
        }

        $duplicate_id = $this->label_duplicate( $label );

        if ( isset( $_REQUEST['single_post'] ) ) {
            wp_redirect( admin_url( 'post.php?action=edit&post=' . $duplicate_id ) );
        } else {
            wp_redirect( admin_url( 'edit.php?post_type=awl-labels' ) );
        }

        exit;

    }

    /*
     * Function to create the duplicate of the label
     * @param $label
     * @return $duplicate The duplicate
     */
    private function label_duplicate( $label ) {

        $duplicate = (array) $label[0];
        $duplicate_meta = AWL()->get_label_settings( $duplicate['ID'] );
        $duplicate_priority = get_post_meta( $duplicate['ID'], '_awl_label_priority', true );

        $duplicate['ID'] = 0;
        $duplicate['post_status'] = 'draft';
        $duplicate['post_title'] = $duplicate['post_title'] . ' ' . esc_html__( '(copy)', 'advanced-woo-labels' );
        $duplicate['post_name'] = '';
        $duplicate['post_date'] = null;
        $duplicate['post_date_gmt'] = null;
        $duplicate['post_modified'] = null;
        $duplicate['post_modified_gmt'] = null;

        $duplicate_id = wp_insert_post( $duplicate, true );

        if ( is_wp_error( $duplicate_id ) ) {
            wp_die( sprintf( esc_html__( 'Label creation failed, could not create label duplicate: %s', 'advanced-woo-labels' ), esc_html( $duplicate_id ) ) );
        }

        AWL()->update_label_settings( $duplicate_id, $duplicate_meta );
        update_post_meta( $duplicate_id, '_awl_label_priority', $duplicate_priority );

        return $duplicate_id;

    }

    /**
     * Show the "Duplicate" link in admin products list
     * @param array   $actions Array of actions.
     * @param WP_Post $post Post object.
     * @return array
     */
    public function duplicate_link( $actions, $post ) {

        if ( ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }

        if ( 'awl-labels' !== $post->post_type ) {
            return $actions;
        }

        $actions['duplicate'] = '<a href="' . wp_nonce_url( admin_url( 'edit.php?post_type=awl-labels&action=duplicate_label&amp;post=' . $post->ID ), 'awl-duplicate-label_' . $post->ID ) . '" aria-label="' . esc_attr__( 'Duplicate label', 'advanced-woo-labels' )
            . '" rel="permalink">' . esc_html__( 'Duplicate', 'advanced-woo-labels' ) . '</a>';

        if ( isset( $actions['trash'] ) ) {
            $trash_action = $actions['trash'];
            unset( $actions['trash'] );
            $actions['trash'] = $trash_action;
        }

        return $actions;

    }

    /**
     * Show the duplicate product link in admin
     */
    public function duplicate_button() {
        global $post;

        if ( ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        if ( ! is_object( $post ) ) {
            return;
        }

        if ( 'awl-labels' !== $post->post_type ) {
            return;
        }

        $notify_url = wp_nonce_url( admin_url( 'edit.php?post_type=awl-labels&action=duplicate_label&single_post=true&post=' . absint( $post->ID ) ), 'awl-duplicate-label_' . $post->ID );

        echo '<div id="duplicate-action"><a class="submitduplicate duplication" href="' . esc_url( $notify_url ) . '">' . esc_html( "Duplicate label", "advanced-woo-labels" ) . '</a></div>';

    }

}