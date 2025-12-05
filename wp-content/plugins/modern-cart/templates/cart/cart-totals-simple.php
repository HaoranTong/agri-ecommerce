<?php
/**
 * Modern Cart Woo Cart Totals
 *
 * @package modern-cart
 * @version 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="<?php echo esc_attr( implode( ' ', array_filter( $classes ) ) ); ?>">

	<div class="moderncart-order-summary-items">
		<div class="moderncart-order-summary-item">
			<span>Shipping Fee</span>
			<span>Free</span>
		</div>

		<div class="moderncart-order-summary-item">
			<span>Est. Tax & Fees</span>
			<span>-</span>
		</div>

		<div class="moderncart-order-summary-item">
			<span>Subtotal</span>
			<span>Rs 55.00</span>
		</div>

		<div class="moderncart-order-summary-item">
			<span>Savings</span>
			<span>Rs 2.00</span>
		</div>

		<div class="moderncart-apply-coupon-container">
			<a href="javascript:void(0)">Apply Coupon Code</a>
		</div>
	</div>

	<div class="moderncart-cart-line-items">
		<?php if ( ! empty( $total ) ) : ?>
			<?php echo wp_kses_post( $total ); ?>
		<?php endif; ?>
	</div>

	<?php do_action( 'moderncart_slide_out_before_checkout_button' ); ?>

	<?php if ( ! is_checkout() ) : ?>
		<div class="wc-proceed-to-checkout">
			<a class="checkout-button wc-forward" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $button_text ); ?>
				<?php echo is_rtl() ? '&#8592;' : '&#8594;'; ?>
			</a>
		</div>
	<?php endif; ?>

	<?php do_action( 'moderncart_slide_out_after_checkout_button' ); ?>

</div>
