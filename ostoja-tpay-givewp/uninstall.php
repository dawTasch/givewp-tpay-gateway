<?php
/**
 * Sprzątanie po odinstalowaniu: usuwa zapisane klucze Tpay z ustawień GiveWP i cache.
 * Darowizny, darczyńcy i notatki GiveWP NIE są usuwane.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('give_settings');
if (is_array($settings)) {
    foreach (array_keys($settings) as $key) {
        if (strpos((string) $key, 'ostoja_tpay_') === 0) {
            unset($settings[$key]);
        }
    }
    update_option('give_settings', $settings);
}

global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_ostoja\\_tpay\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_ostoja\\_tpay\\_%'
        OR option_name LIKE 'ostoja\\_tpay\\_lock\\_%'"
);
