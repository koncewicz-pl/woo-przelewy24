<?php

namespace WC_P24\Utilities;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
    /** @var int Default / informational messages — always logged */
    const INFO = 0;

    const EXCEPTION = 1;

    /** @var int Verbose diagnostics — logged only when P24_DEBUG is enabled */
    const DEBUG = 2;

    /** @var int Non-fatal issues (e.g. skipped renew) — always logged */
    const WARNING = 3;

    public static function log($message, int $type = self::INFO, bool $log_request_data = false): void
    {
        $debugging = defined('P24_DEBUG') ? P24_DEBUG : false;
        $should_log = $type !== self::DEBUG || $debugging;

        if (is_array($message)) {
            $message = json_encode($message);
        }

        if ($type === self::WARNING && is_string($message) && strpos($message, '[P24') !== 0) {
            $message = '[P24 WARNING] ' . $message;
        }

        if ($should_log) {
            if ($log_request_data) {
                error_log(json_encode(['POST' => $_POST, 'GET' => $_GET]));
            }

            error_log((string) $message);
        }
    }
}
