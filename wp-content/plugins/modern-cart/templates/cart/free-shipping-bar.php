<?php
/**
 * Modern Cart Woo free shipping bar content
 *
 * @package modern-cart
 * @version 0.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$allow_wp_kses = [
	'span' => [
		'class' => [],
	],
	'p'    => [
		'class' => [],
	],
	'div'  => [
		'class' => [],
	],
	'a'    => [
		'href'  => [],
		'title' => [],
		'class' => [],
	],
];
?>
<div class="<?php echo esc_attr( $classes ); ?>">
	<div class="moderncart-notification moderncart-has-shadow moderncart-is-light moderncart-is-success"><?php echo wp_kses( $content, $allow_wp_kses ); ?></div>		
	<div class="moderncart-progress-bar progress-bar shine stripes">
		<span class="moderncart-free-shipping-progress-bar" style="width: <?php echo esc_attr( $percent ); ?>%;"></span>
	</div>
</div>
