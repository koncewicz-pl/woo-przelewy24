<?php

namespace WC_P24;

use Exception;
use WC_P24\API\Updater_Client;
use WC_P24\Integrity\Integrity;

class Updater
{

    const VALUE_KEY = '_p24_update_data';

    /** Cached successful response (seconds). */
    private const CACHE_TTL_OK = 3600;

    /** Cached failure / empty response (seconds) - avoids hammering the update server. */
    private const CACHE_TTL_ERROR = 900;

    /** Transient value meaning “remote fetch failed”; not a valid JSON payload. */
    public const REMOTE_ERROR_PLACEHOLDER = '__p24_remote_error__';

    private $cached_data = null;
    private $data_loaded = false;

    public function __construct()
    {
        if (!empty(WC_P24_UPDATE_URL)) {
            add_filter('plugins_api', [$this, 'info'], 20, 3);
            add_filter('site_transient_update_plugins', [$this, 'update'], 50, 2);
            add_filter('transient_update_plugins', [$this, 'update'], 50, 2);
            add_action('in_plugin_update_message-' . WC_P24_PLUGIN_BASENAME, [$this, 'update_message'], 20, 2);
        }
    }

    public function update_message($data, $response)
    {
        if (!$response->integrity) {
            echo '<br />';
            echo __('Changes have been detected in the currently installed plug-in, the update will overwrite the changes made.', 'woocommerce-p24');
        }
    }

    public function get_update_data()
    {
        if ($this->data_loaded) {
            return $this->cached_data;
        }

        if ($this->should_bypass_update_cache()) {
            delete_transient(self::VALUE_KEY);
        }

        $value = get_transient(self::VALUE_KEY);

        if ($value === self::REMOTE_ERROR_PLACEHOLDER) {
            $this->data_loaded = true;
            $this->cached_data = null;
            return null;
        }

        if ($value !== false) {
            $this->data_loaded = true;
            $this->cached_data = $value;
            return $value;
        }

        try {
            $client = new Updater_Client();
            $value = $client->request();

            if (empty($value)) {
                set_transient(self::VALUE_KEY, self::REMOTE_ERROR_PLACEHOLDER, self::CACHE_TTL_ERROR);
                $this->data_loaded = true;
                $this->cached_data = null;
                return null;
            }

            set_transient(self::VALUE_KEY, $value, self::CACHE_TTL_OK);
        } catch (Exception $e) {
            set_transient(self::VALUE_KEY, self::REMOTE_ERROR_PLACEHOLDER, self::CACHE_TTL_ERROR);
            $this->data_loaded = true;
            $this->cached_data = null;
            return null;
        }

        $this->data_loaded = true;
        $this->cached_data = $value;
        return $value;
    }

    /**
     * WordPress “Check again” (update-core.php?force-check=1) should fetch fresh metadata.
     */
    private function should_bypass_update_cache(): bool
    {
        if (!is_admin() || !current_user_can('update_plugins')) {
            return false;
        }

        return isset($_GET['force-check']) && (string) $_GET['force-check'] === '1';
    }

    public function info($data, $action, $args)
    {
        if ('plugin_information' !== $action || WC_P24_PLUGIN_BASENAME !== $args->slug) {
            return $data;
        }

        $new_data = $this->get_update_data();

        if (empty($new_data)) {
            return $data;
        }

        if (isset($new_data->sections)) {
            $new_data->sections = (array)$new_data->sections;
        }

        if (isset($new_data->banners)) {
            $new_data->banners = (array)$new_data->banners;
        }

        $new_data->download_url = $new_data->package;
        $new_data->download_link = $new_data->package;
        $new_data->trunk = $new_data->package;

        return $new_data;
    }


    public function update($data, $action)
    {
        if ('update_plugins' !== $action || !$data) {
            return $data;
        }

        $new_data = $this->get_update_data();

        if (empty($new_data)) {
            return $data;
        }

        if (version_compare($new_data->version, Core::$version, '<=')) {
            return $data;
        }

        $data->response[WC_P24_PLUGIN_BASENAME] = (object)[
            'id' => 'woo-przelewy24',
            'integrity' => Integrity::check(),
            'slug' => WC_P24_PLUGIN_BASENAME,
            'plugin' => WC_P24_PLUGIN_BASEFILE,
            'url' => $new_data->package,
            'package' => $new_data->package,
            'new_version' => $new_data->version,
        ];

        return $data;
    }
}
