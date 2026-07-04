<?php
/**
 * @var WP_List_Table $table
 * @var string $url
 * @var string $sync_url
 * @var array{type: string, message: string}|null $sync_notice
 */
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?= __('Subscriptions P24', 'woocommerce-p24') ?></h1>
    <a href="<?= esc_url($url) ?>" target="_blank" class="page-title-action"><?= esc_html__('Export', 'woocommerce-p24') ?></a>
    <a
        href="<?= esc_url($sync_url) ?>"
        class="page-title-action"
        onclick="return confirm('<?= esc_js(__('Suspend subscriptions whose retry window has ended? Card charges will not be attempted.', 'woocommerce-p24')) ?>');"
    ><?= esc_html__('Sync statuses', 'woocommerce-p24') ?></a>
    <h2 class="screen-reader-text"><?= __('Subscriptions P24', 'woocommerce-p24') ?></h2>
    <hr class="wp-header-end">

    <?php if (!empty($sync_notice)) : ?>
        <div class="notice notice-<?= esc_attr($sync_notice['type']) ?> is-dismissible">
            <p><?= esc_html($sync_notice['message']) ?></p>
        </div>
    <?php endif; ?>

    <?php $table->views() ?>

    <form method="get">
        <input type="hidden" name="page" value="p24-subscriptions" />
        <?php $table->display() ?>
    </form>
</div>
