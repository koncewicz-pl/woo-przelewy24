<?php

/**
 * @var array $config
 */
?>

<form class="p24-payment-container p24-payment-apple-pay">
    <div id="card-whitelabel"></div>

    <div class="p24-checkbox">
        <label for="regulation">
            <input id="regulation" type="checkbox" name="regulation" value="1" required />
            <span><?= $config['i18n']['label']['regulation'] ?></span>
        </label>
    </div>

    <button class="woocommerce-button wp-element-button button" type="submit" id="submit">
        <?= $config['i18n']['label']['submit'] ?>
    </button>
</form>

<div id="p24-3ds-modal" class="p24-3ds-modal hidden fixed inset-0 z-50 bg-black bg-opacity-60 flex items-center justify-center">
    <div class="modal-content">
        <div id="p24-3ds-loader" class="p24-3ds-loader-content">
            <div class="loader"></div>
            <p class="p24-3ds-loader-message"><?= $config['i18n']['waiting_3ds'] ?? __('Please wait… we are preparing the payment verification. Do not close this window - the confirmation screen will appear shortly.', 'woocommerce-p24') ?></p>
        </div>
        <div id="p24-3ds-iframe-wrapper"></div>
    </div>
</div>

<script>window._config = <?= json_encode($config) ?></script>
<script src="<?= WC_P24_PLUGIN_URL . 'assets/js/apple-pay-receipt.bundle.js' ?>"></script>
