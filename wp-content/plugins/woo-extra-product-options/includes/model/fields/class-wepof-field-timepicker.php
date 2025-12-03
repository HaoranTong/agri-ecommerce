<?php
/**
 * Product Field - Time Picker
 *
 * @author    Themehigh
 * @category  Admin
 */

if(!defined('ABSPATH')){ exit; }

if(!class_exists('WEPOF_Product_Field_TimePicker')):
class WEPOF_Product_Field_TimePicker extends WEPOF_Product_Field{
	public function __construct() {
		$this->type = 'Timepicker';
	}
}
endif;