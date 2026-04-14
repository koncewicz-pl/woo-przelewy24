<?php
/**
 * @var array $items
 * @var string $nonce
 */

use WC_P24\Models\Database\Reference;

?>

<?php if (!count($items)): ?>
    <div class="wc-block-components-notice-banner is-info" role="alert">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"
             focusable="false">
            <path
                d="M12 3.2c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.2-7.2 7.2zM11 17h2v-6h-2v6zm0-8h2V7h-2v2z"></path>
        </svg>
        <div
            class="wc-block-components-notice-banner__content"><?= __('No payment/one click has been saved yet.', 'woocommerce-p24') ?></div>
    </div>
<?php else: ?>

    <table class="shop_table shop_table_responsive account-p24-one-clicks-table">
        <thead>
        <tr>
            <th>
                <span class="nobr"><?= __('Type', 'woocommerce-p24') ?></span>
            </th>
            <th>
                <span class="nobr"><?= __('Name/Card number', 'woocommerce-p24') ?></span>
            </th>
            <th>
                <span class="nobr"><?= __('Valid to', 'woocommerce-p24') ?></span>
            </th>
            <th>
                <span class="nobr"><?= __('Actions', 'woocommerce-p24') ?></span>
            </th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($items as $item): ?>
            <?php
            $icon = $item->get_icon();
            $is_blik = in_array($item->get_type(), [Reference::TYPE_BLIK, Reference::TYPE_BLIK_RECURRING]);
            ?>
            <tr>
                <td data-title="<?= __('Type', 'woocommerce-p24') ?>">
                    <?php if (!empty($icon)): ?>
                        <img src="<?= $icon['url'] ?>" alt="<?= $icon['alt'] ?>" />
                    <?php else: ?>
                        <?= ucfirst($item->get_type()) ?>
                    <?php endif; ?>
                </td>

                <td data-title="<?= __('Name/Card number', 'woocommerce-p24') ?>">
                    <?php if ($is_blik) : ?>
                        <?= $item->get_info() ?>
                    <?php else: ?>
                        <small>✱✱✱✱ ✱✱✱✱ ✱✱✱✱</small> <?= $item->get_info() ?>
                        <?php if ($item->has_subscriptions()): ?>
                            <p class="shop_table__cell-description"><small><?= __('This payment has a subscription attached to it', 'woocommerce-p24') ?></small></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>

                <td data-title="<?= __('Valid to', 'woocommerce-p24') ?>">
                    <?= $item->get_valid_to()->format($is_blik ? 'd/m/Y' : 'm/Y') ?>
                </td>

                <td data-title="<?= __('Actions', 'woocommerce-p24') ?>">
                    <?php if (!$is_blik) : ?>
                        <button type="button" data-delete data-id="<?= $item->get_id() ?>" data-nonce="<?= $nonce ?>"
                                class="woocommerce-button wp-element-button button"><?= _x('Remove', 'one click', 'woocommerce-p24') ?></button>
                    <?php endif; ?>
                </td>
            </tr>

        <?php endforeach; ?>
        </tbody>
    </table>

    <script>
      (function(){
        const url = "<?= esc_js(add_query_arg(['action' => 'remove_one_click'], admin_url('admin-ajax.php'))) ?>";
        document.addEventListener('click', function(e){
          const btn = e.target.closest && e.target.closest('button[data-delete]');
          if (!btn) return;
          e.preventDefault();
          try { e.stopPropagation(); } catch (err) {}
          const id = btn.dataset.id;
          const nonce = btn.dataset.nonce;
          if (!url) { alert('Missing endpoint URL'); return; }
          const body = new FormData();
          body.append('nonce', nonce);
          body.append('id', parseInt(id));
          (async function(){
            try{
              const response = await fetch(url, { method: 'POST', body: body, credentials: 'same-origin' });
              const text = await response.text();
              if (!text) { if (response.ok) { window.location.reload(); return; } alert('Empty response'); return; }
              try { const data = JSON.parse(text); if (data && (data.success === true || data.success === 'true')) { window.location.reload(); return; } alert(data && data.data && data.data.message ? data.data.message : 'Error'); } catch (err) { alert('Invalid JSON response'); }
            }catch(err){ console.warn('p24 remove error', err); alert(err && err.message ? err.message : 'Error'); }
          })();
        }, true);
      })();
    </script>

<?php endif; ?>
