<?php

namespace WC_P24\Utilities;

class Sanitizer
{
    private array $input = [];
    private $filters;

    public function __construct(array $input, array $filters)
    {
        $this->input = $input;
        $this->filters = $filters;
    }

    public function run(): array
    {
        return filter_var_array($this->input, $this->filters);
    }

    public static function sanitize_key(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', trim(strtolower(strip_tags($value))));
    }

    public static function sanitize_token(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9\-]/', '', trim(strip_tags($value)));
    }

    public static function sanitize_string($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }

        return trim(htmlentities(strip_tags((string) $value), ENT_QUOTES, 'UTF-8'));
    }

    /** Replaces deprecated FILTER_SANITIZE_STRING for filter_var_array (PHP 8.1+). */
    public static function sanitize_string_as_filter(): array
    {
        return [
            'filter' => FILTER_CALLBACK,
            'options' => [self::class, 'sanitize_string'],
        ];
    }

    /** Strip non-base64 characters from Google Pay / Apple Pay payload tokens. */
    public static function sanitize_base64_payload($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return preg_replace('/[^A-Za-z0-9+\/=]/', '', $value);
    }

    public static function sanitize_base64_payload_as_filter(): array
    {
        return [
            'filter' => FILTER_CALLBACK,
            'options' => [self::class, 'sanitize_base64_payload'],
        ];
    }

    public static function sanitize_key_as_filter(): array
    {
        return [
            'filter' => FILTER_CALLBACK,
            'options' => function ($value) {
                return self::sanitize_key($value);
            }
        ];
    }
}
