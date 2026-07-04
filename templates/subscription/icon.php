<?php
/**
 * Inline SVG icons for subscription product UI.
 *
 * @var string $name renew|payment|cancel|calendar
 */

defined('ABSPATH') || exit;

$icon = $name ?? '';

$attrs = 'class="subscription-svg-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

switch ($icon) {
    case 'renew':
        echo '<svg ' . $attrs . '><path d="M4 12a8 8 0 0 1 13.4-5.9M20 7v4h-4"/><path d="M20 12a8 8 0 0 1-13.4 5.9M4 17v-4h4"/></svg>';
        break;
    case 'payment':
        echo '<svg ' . $attrs . '><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>';
        break;
    case 'cancel':
        echo '<svg ' . $attrs . '><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg>';
        break;
    case 'calendar':
        echo '<svg ' . $attrs . '><path d="M8 3v3M16 3v3M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/></svg>';
        break;
}
