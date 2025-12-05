<?php

if (!defined('ABSPATH'))
    die('No direct access allowed');

//30-06-2023
final class WOOF_SD_PRESETS {

    private $db = null;
    private $table = null;
    private $table_main = null;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $this->db->prefix . 'woof_sd_presets';
        $this->table_main = $this->db->prefix . 'woof_sd';

        add_action('wp_ajax_woof_sd_load_presets', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }				
            echo json_encode($this->gets(esc_html($_REQUEST['type'])));
            exit;
        });

        add_action('wp_ajax_woof_sd_create_preset', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }
			wp_send_json(strval($this->create(esc_html($_REQUEST['title']), esc_html($_REQUEST['type']), intval($_REQUEST['element_id']))));
            //die(strval($this->create(esc_html($_REQUEST['title']), esc_html($_REQUEST['type']), intval($_REQUEST['element_id']))));
        });

        add_action('wp_ajax_woof_sd_apply_preset', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }				
            $element_id = intval($_REQUEST['element_id']);
            $preset = $this->get(intval($_REQUEST['option_id']));
            $this->db->update($this->table_main, array('options' => $preset), array('id' => $element_id));
			wp_send_json(strval($preset));
            //die(strval($preset));
        });

        add_action('wp_ajax_woof_sd_get_preset', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }				
            $preset = $this->get(intval($_REQUEST['option_id']));
			wp_send_json(strval($preset));
            //wp_die(strval($preset));
        });

        add_action('wp_ajax_woof_sd_import_preset', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }				
            $this->update(intval($_REQUEST['option_id']), stripcslashes($_REQUEST['value']));
            die('1');
        });

        add_action('wp_ajax_woof_sd_delete_preset', function () {
			if (!isset($_REQUEST['sd_nonce']) || !wp_verify_nonce($_REQUEST['sd_nonce'], 'woof_sd_nonce')) {
				die('0');
			}
            if (!current_user_can('manage_woocommerce')) {
                die('0');
            }				
            $this->delete(intval($_REQUEST['option_id']));
            die('1');
        });

        add_action('woof_print_applications_tabs_content_smart_designer', function () {

            $charset_collate = '';
            if (method_exists($this->db, 'has_cap') AND $this->db->has_cap('collation')) {
                if (!empty($this->db->charset)) {
                    $charset_collate = "DEFAULT CHARACTER SET {$this->db->charset}";
                }
                if (!empty($this->db->collate)) {
                    $charset_collate .= " COLLATE {$this->db->collate}";
                }
            }

            $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `title` varchar(32) DEFAULT NULL,
                        `type` varchar(32) NOT NULL DEFAULT 'checkbox',
                        `options` text,
                        PRIMARY KEY (`id`)
                      )  {$charset_collate};";

            if ($this->db->query($sql) === false) {
                $data['error'] = esc_html__("HUSKY cannot create database table for smart designer! Make sure that your mysql user has the CREATE privilege! Do it manually using your host panel and phpmyadmin!", 'woocommerce-products-filter');
                $data['last_error'] = $this->db->last_error;
                $data['sql'] = $sql;
            } else {
                //$this->db->query($sql);//todo add presets here
            }
        }, 10, 1);
    }

    private function create($title, $type, $element_id) {
        $this->db->insert($this->table, [
            'title' => $title,
            'type' => $type,
            'options' => $this->get_element_options($element_id)
        ]);

        return $this->db->insert_id;
    }

    private function get($option_id) {
		$prepared_sql = $this->db->prepare("SELECT options FROM %i WHERE id=%d", $this->table, $option_id);
        return $this->db->get_var($prepared_sql);
    }

    private function update($option_id, $preset) {
        $this->db->update($this->table, array('options' => $preset), array('id' => $option_id));
    }

    private function get_element_options($element_id) {
		$prepared_sql = $this->db->prepare("SELECT options FROM %i WHERE id=%d", $this->table_main, $element_id);
        return $this->db->get_var($prepared_sql);
    }

    private function gets($type) {
		$prepared_sql = $this->db->prepare("SELECT * FROM %i WHERE type=%s ORDER BY id desc", $this->table, $type);
        return $this->db->get_results($prepared_sql, ARRAY_A);
    }

    private function delete($id) {
        $this->db->delete($this->table, array('id' => $id));
    }

}

new WOOF_SD_PRESETS();

