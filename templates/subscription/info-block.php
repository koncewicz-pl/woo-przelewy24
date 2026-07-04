<?php
/**
 * @var \WC_P24\Subscriptions\Product\Product $product
 */

defined('ABSPATH') || exit;

use WC_P24\Render;
use WC_P24\Subscriptions\Product\Product;

if ($product->get_type() !== Product::TYPE) {
    return;
}

$days = $product->get_days();

if ($days <= 0) {
    return;
}

// Determine the renewal period text
if ($days % 30 === 0) {
    $months = $days / 30;
    $period_days = sprintf(
        _n('%d month', '%d months', $months, 'woocommerce-p24'),
        $months
    );
} elseif ($days % 7 === 0) {
    $weeks = $days / 7;
    $period_days = sprintf(
        _n('%d week', '%d weeks', $weeks, 'woocommerce-p24'),
        $weeks
    );
} else {
    $period_days = sprintf(
        _n('%d day', '%d days', $days, 'woocommerce-p24'),
        $days
    );
}

$title_id = 'p24-subscription-info-title';

?>

<section class="subscription-extra-box" aria-labelledby="<?= esc_attr($title_id); ?>">
    <h4 id="<?= esc_attr($title_id); ?>" class="subscription-extra-box__title">
        <?= esc_html__('How does the subscription work?', 'woocommerce-p24'); ?>
    </h4>
    <ul class="subscription-extra-box__list">
        <li class="subscription-extra-box__item">
            <span class="subscription-extra-box__icon">
                <?php Render::template('subscription/icon', ['name' => 'renew'], true); ?>
            </span>
            <span class="subscription-extra-box__text">
                <?= sprintf(esc_html__('Renews automatically every %s', 'woocommerce-p24'), esc_html($period_days)); ?>
            </span>
        </li>
        <li class="subscription-extra-box__item">
            <span class="subscription-extra-box__icon">
                <?php Render::template('subscription/icon', ['name' => 'payment'], true); ?>
            </span>
            <span class="subscription-extra-box__text">
                <?= esc_html__('Payment is charged automatically', 'woocommerce-p24'); ?>
            </span>
        </li>
        <li class="subscription-extra-box__item">
            <span class="subscription-extra-box__icon">
                <?php Render::template('subscription/icon', ['name' => 'cancel'], true); ?>
            </span>
            <span class="subscription-extra-box__text">
                <?= esc_html__('Can be cancelled at any time', 'woocommerce-p24'); ?>
            </span>
        </li>
    </ul>
</section>
