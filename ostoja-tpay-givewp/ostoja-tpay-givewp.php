<?php
/**
 * Plugin Name:       Ostoja – Tpay dla GiveWP
 * Description:       Bramka płatności Tpay (Open API) dla GiveWP. Darczyńca płaci na stronie Tpay (BLIK, przelew, karta); darowizna jest oznaczana jako opłacona wyłącznie po podpisanym powiadomieniu (JWS) i weryfikacji transakcji w API Tpay.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  give
 * Author:            Ostoja
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ostoja-tpay-givewp
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OSTOJA_TPAY_GIVEWP_VERSION', '0.2.0');
define('OSTOJA_TPAY_GIVEWP_FILE', __FILE__);
define('OSTOJA_TPAY_GIVEWP_DIR', plugin_dir_path(__FILE__));
define('OSTOJA_TPAY_GIVEWP_URL', plugin_dir_url(__FILE__));

require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/Config.php';
require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/TpayApiException.php';
require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/TpayApi.php';
require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/JwsVerifier.php';
require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/Settings.php';

/**
 * Rejestracja bramki w GiveWP (hook wywoływany tylko, gdy GiveWP jest aktywny).
 */
add_action('givewp_register_payment_gateway', static function ($paymentGatewayRegister) {
    require_once OSTOJA_TPAY_GIVEWP_DIR . 'src/TpayGateway.php';
    $paymentGatewayRegister->registerGateway(\OstojaTpayGiveWP\TpayGateway::class);
});

add_action('plugins_loaded', static function () {
    if (!class_exists('Give')) {
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Ostoja – Tpay dla GiveWP wymaga aktywnej wtyczki GiveWP.', 'ostoja-tpay-givewp')
                . '</p></div>';
        });
        return;
    }

    \OstojaTpayGiveWP\Settings::register();
});
