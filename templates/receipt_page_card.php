<?php

use WC_P24\Render;

/**
 * @var array $config
 */
?>

<form class="p24-payment-container p24-payment-card">
    <?php if (!empty($config['oneClick']['enabled']) && !empty($config['oneClick']['items'])): ?>
        <div class="p24-1clicks">
            <div class="p24-1clicks__label"> <?= $config['i18n']['use_saved'] ?> </div>
            <div class="p24-1clicks__items">
                <?php foreach ($config['oneClick']['items'] as $card): ?>
                    <?php Render::template('partials/card-item', ['card' => $card]); ?>
                <?php endforeach ?>
            </div>
            <div class="p24-1clicks__or"><?= $config['i18n']['or'] ?></div>
        </div>
        <button
            type="button"
            class="p24-1clicks__new-card"
            id="p24-use-new-card"
            aria-controls="p24-new-card-block"
            aria-expanded="false"
        >
            <svg class="p24-1clicks__new-card-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                <line x1="1" y1="10" x2="23" y2="10"></line>
                <circle cx="12" cy="16" r="2"></circle>
            </svg>
            <span class="p24-1clicks__new-card-text"><?= esc_html($config['i18n']['use_new_card'] ?? '') ?></span>
        </button>
    <?php endif ?>

    <div id="p24-new-card-block" class="p24-new-card-block">
        <div id="card-tokenizer"></div>
        <div id="card-whitelabel"></div>
    </div>

    <?php if (!empty($config['oneClick']['enabled']) && empty($config['hasSubscription'])): ?>
        <div class="p24-checkbox p24-save-card-row" id="p24-save-card-row">
            <label for="save-one-click">
                <input id="save-one-click" type="checkbox" name="save" value="1" />
                <span><?= $config["i18n"]["label"]["save"] ?></span>
            </label>
        </div>
    <?php endif ?>

    <?php if (!empty($config['hasSubscription'])): ?>
        <div class="p24-checkbox p24-recurring-consent-row" id="p24-recurring-consent-row">
            <label for="recurring-consent">
                <input id="recurring-consent" type="checkbox" name="recurring_consent" value="1" />
                <span><?= esc_html($config['i18n']['label']['save_recurring'] ?? '') ?></span>
            </label>
        </div>
    <?php endif ?>

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

<div id="p24-3ds-loader" class="p24-3ds-overlay hidden">
    <div class="p24-3ds-loader-content">
        <div class="loader"></div>
        <p class="p24-3ds-loader-message"><?= $config['i18n']['waiting_3ds'] ?? __('Please wait… we are preparing the payment verification. Do not close this window - the confirmation screen will appear shortly.', 'woocommerce-p24') ?></p>
    </div>
</div>

<div id="p24-3ds-modal" class="p24-3ds-modal hidden fixed inset-0 z-50 bg-black bg-opacity-60 flex items-center justify-center">
    <div class="modal-content">
        <div id="p24-3ds-iframe-wrapper"></div>
    </div>
</div>


<script>window._config = <?= json_encode($config) ?></script>
<script src="<?= WC_P24_PLUGIN_URL . 'assets/js/card-receipt.bundle.js' ?>"></script>
