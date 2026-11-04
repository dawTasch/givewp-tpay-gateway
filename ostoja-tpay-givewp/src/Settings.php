<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sekcja „Tpay” w Darowizny → Ustawienia → Bramki płatności.
 *
 * Środowisko wybiera „Tryb testowy” GiveWP; tu są tylko klucze – osobno dla produkcji i Sandboxa.
 * Pola sekretów są „tylko do zapisu”: zapisany sekret nigdy nie jest wypisywany w HTML,
 * a puste pole przy zapisie zachowuje dotychczasową wartość.
 * Zalecane: klucze w wp-config.php – wtedy odpowiednie pola znikają z panelu.
 */
final class Settings
{
    const SECTION = 'ostoja-tpay';

    public static function register(): void
    {
        add_filter('give_get_sections_gateways', [__CLASS__, 'addSection']);
        add_filter('give_get_settings_gateways', [__CLASS__, 'addFields']);
        add_action('give_admin_field_ostoja_tpay_secret', [__CLASS__, 'renderSecretField'], 10, 2);
        add_action('give_admin_field_ostoja_tpay_status', [__CLASS__, 'renderStatusField'], 10, 2);
        add_action('give_admin_field_ostoja_tpay_heading', [__CLASS__, 'renderHeading'], 10, 2);
        foreach ([true, false] as $sandbox) {
            foreach (['client_secret', 'security_code'] as $key) {
                add_filter('give_admin_settings_sanitize_option_' . Config::optionName($key, $sandbox), [__CLASS__, 'keepSecretIfEmpty'], 10, 3);
            }
        }
        foreach (['ostoja_tpay_status', 'ostoja_tpay_heading_live', 'ostoja_tpay_heading_sandbox'] as $displayOnly) {
            add_filter('give_admin_settings_sanitize_option_' . $displayOnly, '__return_null');
        }
        add_action('admin_post_ostoja_tpay_test', [__CLASS__, 'handleTestConnection']);
        add_action('admin_notices', [__CLASS__, 'adminNotices']);
    }

    public static function addSection(array $sections): array
    {
        $sections[self::SECTION] = __('Tpay', 'ostoja-tpay-givewp');

        return $sections;
    }

    public static function addFields(array $settings): array
    {
        if (!function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== self::SECTION) {
            return $settings;
        }

        $fields = [
            ['id' => 'ostoja_tpay_title', 'type' => 'title'],
            ['id' => 'ostoja_tpay_status', 'name' => __('Status', 'ostoja-tpay-givewp'), 'type' => 'ostoja_tpay_status'],
        ];

        foreach ([false, true] as $sandbox) {
            $fields[] = [
                'id' => $sandbox ? 'ostoja_tpay_heading_sandbox' : 'ostoja_tpay_heading_live',
                'type' => 'ostoja_tpay_heading',
                'name' => $sandbox
                    ? __('Klucze Sandbox (Tryb testowy GiveWP włączony)', 'ostoja-tpay-givewp')
                    : __('Klucze produkcyjne (Tryb testowy GiveWP wyłączony)', 'ostoja-tpay-givewp'),
                'desc' => $sandbox
                    ? __('panel.sandbox.tpay.com → Integracja → API → klucze Open API.', 'ostoja-tpay-givewp')
                    : __('Panel Akceptanta Tpay → Integracja → API → klucze Open API.', 'ostoja-tpay-givewp'),
            ];
            if (!Config::isFromConstant('client_id', $sandbox)) {
                $fields[] = [
                    'id' => Config::optionName('client_id', $sandbox),
                    'name' => __('Client ID', 'ostoja-tpay-givewp'),
                    'type' => 'text',
                ];
            }
            if (!Config::isFromConstant('client_secret', $sandbox)) {
                $fields[] = [
                    'id' => Config::optionName('client_secret', $sandbox),
                    'name' => __('Client Secret', 'ostoja-tpay-givewp'),
                    'type' => 'ostoja_tpay_secret',
                ];
            }
            if (!Config::isFromConstant('security_code', $sandbox)) {
                $fields[] = [
                    'id' => Config::optionName('security_code', $sandbox),
                    'name' => __('Kod bezpieczeństwa (opcjonalnie)', 'ostoja-tpay-givewp'),
                    'type' => 'ostoja_tpay_secret',
                    'desc' => __('Panel Tpay → Powiadomienia → Bezpieczeństwo. Dodatkowa weryfikacja sumy md5sum.', 'ostoja-tpay-givewp'),
                ];
            }
        }

        $fields[] = ['id' => 'ostoja_tpay_title', 'type' => 'sectionend'];

        return $fields;
    }

    public static function renderHeading(array $field, $optionValue): void
    {
        ?>
        <tr valign="top">
            <th scope="row" colspan="2" style="padding-bottom:0">
                <h3 style="margin:1.5em 0 0"><?php echo esc_html($field['name'] ?? ''); ?></h3>
                <?php if (!empty($field['desc'])) : ?>
                    <p class="give-field-description" style="font-weight:normal"><?php echo esc_html($field['desc']); ?></p>
                <?php endif; ?>
            </th>
        </tr>
        <?php
    }

    /**
     * Pole sekretu bez wartości w HTML.
     */
    public static function renderSecretField(array $field, $optionValue): void
    {
        $id = (string) $field['id'];
        $isSet = trim((string) $optionValue) !== '';
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($field['name'] ?? ''); ?></label>
            </th>
            <td class="give-forminp">
                <input type="password" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>"
                       value="" autocomplete="new-password" class="give-input-field regular-text"
                       placeholder="<?php echo $isSet ? esc_attr__('•••••• zapisany – wpisz nowy, aby zmienić', 'ostoja-tpay-givewp') : ''; ?>">
                <p class="give-field-description">
                    <?php echo $isSet
                        ? esc_html__('Sekret jest zapisany. Zostaw puste, aby go zachować.', 'ostoja-tpay-givewp')
                        : esc_html__('Brak zapisanego sekretu.', 'ostoja-tpay-givewp'); ?>
                    <?php if (!empty($field['desc'])) : ?><br><?php echo esc_html($field['desc']); ?><?php endif; ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Puste pole sekretu = zachowaj obecną wartość (null => GiveWP pomija zapis).
     */
    public static function keepSecretIfEmpty($value, $option, $rawValue)
    {
        // Surowa wartość (GiveWP podaje ją po wp_unslash) – sanitize_text_field mógłby zmienić znaki
        // w sekrecie (np. %xx, <...>) i zepsuć logowanie albo sumę md5sum. Sekret nigdy nie jest wyświetlany.
        $secret = is_string($rawValue) ? trim($rawValue) : '';
        if ($secret === '' || preg_match('/[\x00-\x1F\x7F]/', $secret) || strlen($secret) > 255) {
            return null;
        }

        return $secret;
    }

    public static function renderStatusField(array $field, $optionValue): void
    {
        $notificationUrl = add_query_arg(
            [
                'give-listener' => 'give-gateway',
                'give-gateway-id' => TpayGatewayIds::ID,
                'give-gateway-method' => 'handleNotification',
            ],
            defined('GIVEWP_GATEWAY_ROUTE_BASE_URL') ? GIVEWP_GATEWAY_ROUTE_BASE_URL : home_url()
        );
        $testModeUrl = admin_url('edit.php?post_type=give_forms&page=give-settings&tab=gateways&section=gateways-settings');
        $current = Config::isSandbox();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo esc_html($field['name'] ?? ''); ?></th>
            <td class="give-forminp">
                <p>
                    <strong><?php esc_html_e('Nowe płatności idą do:', 'ostoja-tpay-givewp'); ?></strong>
                    <?php if ($current) : ?>
                        <span style="color:#996800"><?php esc_html_e('Sandbox (Tryb testowy GiveWP włączony)', 'ostoja-tpay-givewp'); ?></span>
                    <?php else : ?>
                        <span style="color:#00a32a"><?php esc_html_e('Produkcja (Tryb testowy GiveWP wyłączony)', 'ostoja-tpay-givewp'); ?></span>
                    <?php endif; ?>
                    – <a href="<?php echo esc_url($testModeUrl); ?>"><?php esc_html_e('zmień Tryb testowy', 'ostoja-tpay-givewp'); ?></a>
                </p>
                <table class="widefat striped" style="max-width:640px;margin:.5em 0">
                    <thead><tr>
                        <th><?php esc_html_e('Środowisko', 'ostoja-tpay-givewp'); ?></th>
                        <th><?php esc_html_e('Klucze', 'ostoja-tpay-givewp'); ?></th>
                        <th><?php esc_html_e('Kod bezp.', 'ostoja-tpay-givewp'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ([false, true] as $sandbox) : ?>
                        <tr>
                            <td><?php echo esc_html($sandbox ? 'Sandbox' : __('Produkcja', 'ostoja-tpay-givewp')); ?><?php echo $sandbox === $current ? ' <strong>•</strong>' : ''; ?></td>
                            <td><?php echo Config::isConfigured($sandbox)
                                    ? '✓ ' . esc_html(self::sourceLabel('client_secret', $sandbox))
                                    : '<span style="color:#d63638">✗ ' . esc_html__('brak', 'ostoja-tpay-givewp') . '</span>'; ?></td>
                            <td><?php echo Config::securityCode($sandbox) !== '' ? '✓' : '–'; ?></td>
                            <td><?php if (Config::isConfigured($sandbox)) : ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ostoja_tpay_test&env=' . ($sandbox ? 'sandbox' : 'live')), 'ostoja_tpay_test')); ?>"><?php esc_html_e('Testuj połączenie', 'ostoja-tpay-givewp'); ?></a>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <strong><?php esc_html_e('Adres powiadomień:', 'ostoja-tpay-givewp'); ?></strong><br>
                    <code><?php echo esc_html($notificationUrl); ?></code><br>
                    <span class="give-field-description"><?php esc_html_e('Przekazywany automatycznie przy każdej transakcji (ten sam dla obu środowisk). Musi być publicznym adresem HTTPS bez przekierowań i nie może być blokowany przez wtyczki bezpieczeństwa/Cloudflare.', 'ostoja-tpay-givewp'); ?></span>
                </p>
                <p class="give-field-description">
                    <?php esc_html_e('Każda darowizna jest weryfikowana w środowisku, w którym powstała – przełączenie Trybu testowego nie wpływa na płatności w toku.', 'ostoja-tpay-givewp'); ?><br>
                    <?php esc_html_e('Zalecane: klucze w wp-config.php – nie trafią wtedy do bazy danych ani jej kopii zapasowych:', 'ostoja-tpay-givewp'); ?>
                    <code>OSTOJA_TPAY_CLIENT_ID</code>, <code>OSTOJA_TPAY_CLIENT_SECRET</code>, <code>OSTOJA_TPAY_SECURITY_CODE</code>,
                    <code>OSTOJA_TPAY_SANDBOX_CLIENT_ID</code>, <code>OSTOJA_TPAY_SANDBOX_CLIENT_SECRET</code>, <code>OSTOJA_TPAY_SANDBOX_SECURITY_CODE</code>.
                </p>
            </td>
        </tr>
        <?php
    }

    private static function sourceLabel(string $key, bool $sandbox): string
    {
        return Config::isFromConstant($key, $sandbox) ? 'wp-config.php' : __('ustawienia', 'ostoja-tpay-givewp');
    }

    public static function handleTestConnection(): void
    {
        if (!current_user_can('manage_give_settings') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'ostoja-tpay-givewp'), 403);
        }
        check_admin_referer('ostoja_tpay_test');

        $env = isset($_GET['env']) && $_GET['env'] === 'sandbox' ? 'sandbox' : 'live';
        $result = 'ok';
        try {
            TpayApi::forEnvironment($env === 'sandbox')->testConnection();
        } catch (\Exception $e) {
            $result = 'fail';
        }

        wp_safe_redirect(add_query_arg(
            [
                'post_type' => 'give_forms',
                'page' => 'give-settings',
                'tab' => 'gateways',
                'section' => self::SECTION,
                'ostoja_tpay_test' => $result,
                'ostoja_tpay_env' => $env,
            ],
            admin_url('edit.php')
        ));
        exit;
    }

    public static function adminNotices(): void
    {
        // Te same uprawnienia co do uruchomienia testu połączenia.
        if (!current_user_can('manage_give_settings') && !current_user_can('manage_options')) {
            return;
        }

        if (defined(Config::LEGACY_SANDBOX_CONSTANT)) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('Tpay dla GiveWP: stała OSTOJA_TPAY_SANDBOX w wp-config.php jest ignorowana. Środowisko wybiera teraz „Tryb testowy” GiveWP; klucze Sandbox podaj jako OSTOJA_TPAY_SANDBOX_CLIENT_ID / OSTOJA_TPAY_SANDBOX_CLIENT_SECRET.', 'ostoja-tpay-givewp')
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wyświetlenie komunikatu.
        $result = isset($_GET['ostoja_tpay_test']) ? sanitize_key(wp_unslash($_GET['ostoja_tpay_test'])) : '';
        if ($result === '') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sandbox = isset($_GET['ostoja_tpay_env']) && $_GET['ostoja_tpay_env'] === 'sandbox';
        if ($result === 'ok') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Połączenie z Tpay (%s) działa – klucze są poprawne.', 'ostoja-tpay-givewp'), Config::label($sandbox)))
            );
        } else {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Nie udało się połączyć z Tpay (%s). Sprawdź, czy klucze są z właściwego panelu (Sandbox / produkcja) i czy serwer może wykonywać połączenia HTTPS.', 'ostoja-tpay-givewp'), Config::label($sandbox)))
            );
        }
    }
}

/**
 * Identyfikator bramki dostępny bez ładowania klas GiveWP (Settings ładuje się wcześniej niż bramka).
 */
final class TpayGatewayIds
{
    const ID = 'ostoja-tpay';
}
