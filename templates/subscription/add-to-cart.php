<?php
/**
 * @var \WC_Product $product
 */

defined('ABSPATH') || exit;

use WC_P24\Subscriptions\User_Subscription_Helper;

$button_config = User_Subscription_Helper::get_button_config($product);

?>

<?php do_action('woocommerce_before_add_to_cart_form'); ?>

<div class="subscription-button-wrapper">
    <?php do_action('woocommerce_before_add_to_cart_button'); ?>

    <a href="<?= esc_url($button_config['url']); ?>"
       class="single_add_to_cart_button button alt <?= esc_attr($button_config['class']); ?><?= esc_attr(wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>">
        <?= esc_html($button_config['text']); ?>
    </a>

    <?php do_action('woocommerce_after_add_to_cart_button'); ?>
</div>

<?php do_action('woocommerce_after_add_to_cart_form'); ?>

