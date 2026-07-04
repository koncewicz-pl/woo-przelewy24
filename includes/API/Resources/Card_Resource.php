<?php

namespace WC_P24\API\Resources;

use WC_P24\API\Api_Client;

if (!defined('ABSPATH')) {
    exit;
}

class Card_Resource extends Resource
{
    public const CARD_INFO_BY_ORDER_ID_ENDPOINT = 'api/v1/card/info/{orderId}';

    public function get_info(string $orderId): array
    {
        $endpoint = str_replace('{orderId}', $orderId, self::CARD_INFO_BY_ORDER_ID_ENDPOINT);

        return $this->get_api_client()->request($endpoint, Api_Client::METHOD_GET);
    }
}
