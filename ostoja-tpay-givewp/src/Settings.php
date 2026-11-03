<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sekcja „Tpay” w Darowizny → Ustawienia → Bramki płatności.
 *
 * Pola sekretów są „tylko do zapisu”: zapisany sekret nigdy nie jest wypisywany w HTML
 * (nawet jako password), a puste pole przy zapisie zachowuje dotychczasową wartość.
 * Zalecane: trzymać klucze w wp-config.php – wtedy pola znikają z panelu.
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
        add_filter('give_admin_settings_sanitize_option_' . Config::OPT_CLIENT_SECRET, [__CLASS__, 'keepSecretIfEmpty'], 10, 3);
        add_filter('give_admin_settings_sanitize_option_' . Config::OPT_SECURITY_CODE, [__CLASS__, 'keepSecretIfEmpty'], 10, 3);
        add_filter('give_admin_settings_sanitize_option_ostoja_tpay_status', '__return_null');
        add_action('admin_post_ostoja_tpay_test', [__CLASS__, 'handleTestConnection']);
        add_action('admin_notices', [__CLASS__, 'testResultNotice']);
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

        if (!defined(Config::CONST_SANDBOX)) {
            $fields[] = [
                'id' => Config::OPT_SANDBOX,
                'name' => __('Środowisko', 'ostoja-tpay-givewp'),
                'type' => 'radio_inline',
                'default' => 'enabled',
                'options' => [
                    'enabled' => __('Sandbox (testy)', 'ostoja-tpay-givewp'),
                    'disabled' => __('Produkcja', 'ostoja-tpay-givewp'),
                ],
                'desc' => __('Klucze Sandbox i produkcyjne są różne – po zmianie środowiska wpisz odpowiednie klucze.', 'ostoja-tpay-givewp'),
            ];
        }

        if (!Config::isFromConstant(Config::CONST_CLIENT_ID)) {
            $fields[] = [
                'id' => Config::OPT_CLIENT_ID,
                'name' => __('Client ID', 'ostoja-tpay-givewp'),
                'type' => 'text',
                'desc' => __('Panel Akceptanta Tpay → Integracja → API → klucze Open API.', 'ostoja-tpay-givewp'),
            ];
        }
        if (!Config::isFromConstant(Config::CONST_CLIENT_SECRET)) {
            $fields[] = [
                'id' => Config::OPT_CLIENT_SECRET,
                'name' => __('Client Secret', 'ostoja-tpay-givewp'),
                'type' => 'ostoja_tpay_secret',
            ];
        }
        if (!Config::isFromConstant(Config::CONST_SECURITY_CODE)) {
            $fields[] = [
                'id' => Config::OPT_SECURITY_CODE,
                'name' => __('Kod bezpieczeństwa (opcjonalnie)', 'ostoja-tpay-givewp'),
                'type' => 'ostoja_tpay_secret',
                'desc' => __('Panel Tpay → Powiadomienia → Bezpieczeństwo. Dodatkowa weryfikacja sumy md5sum.', 'ostoja-tpay-givewp'),
            ];
        }

        $fields[] = ['id' => 'ostoja_tpay_title', 'type' => 'sectionend'];

        return $fields;
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
        return (is_string($value) && trim($value) !== '') ? trim($value) : null;
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
        $source = static function (string $constant): string {
            return Config::isFromConstant($constant) ? 'wp-config.php' : __('ustawienia', 'ostoja-tpay-givewp');
        };
        $testUrl = wp_nonce_url(admin_url('admin-post.php?action=ostoja_tpay_test'), 'ostoja_tpay_test');
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><?php echo esc_html($field['name'] ?? ''); ?></th>
            <td class="give-forminp">
                <p>
                    <strong><?php esc_html_e('Środowisko:', 'ostoja-tpay-givewp'); ?></strong>
                    <?php echo Config::isSandbox() ? esc_html__('Sandbox (testy)', 'ostoja-tpay-givewp') : '<span style="color:#00a32a">' . esc_html__('Produkcja', 'ostoja-tpay-givewp') . '</span>'; ?>
                    <?php if (defined(Config::CONST_SANDBOX)) : ?>(wp-config.php)<?php endif; ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Klucze:', 'ostoja-tpay-givewp'); ?></strong>
                    <?php if (Config::isConfigured()) : ?>
                        ✓ <?php printf(esc_html__('Client ID: %1$s, Client Secret: %2$s', 'ostoja-tpay-givewp'), esc_html($source(Config::CONST_CLIENT_ID)), esc_html($source(Config::CONST_CLIENT_SECRET))); ?>
                    <?php else : ?>
                        <span style="color:#d63638">✗ <?php esc_html_e('brak – płatności Tpay nie będą działać', 'ostoja-tpay-givewp'); ?></span>
                    <?php endif; ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Kod bezpieczeństwa (md5sum):', 'ostoja-tpay-givewp'); ?></strong>
                    <?php echo Config::securityCode() !== '' ? '✓ ' . esc_html($source(Config::CONST_SECURITY_CODE)) : esc_html__('nie ustawiono (wystarczy podpis JWS)', 'ostoja-tpay-givewp'); ?>
                </p>
                <p>
                    <strong><?php esc_html_e('Adres powiadomień:', 'ostoja-tpay-givewp'); ?></strong><br>
                    <code><?php echo esc_html($notificationUrl); ?></code><br>
                    <span class="give-field-description"><?php esc_html_e('Przekazywany automatycznie przy każdej transakcji. Musi być publicznym adresem HTTPS bez przekierowań i nie może być blokowany przez wtyczki bezpieczeństwa/Cloudflare.', 'ostoja-tpay-givewp'); ?></span>
                </p>
                <p><a class="button" href="<?php echo esc_url($testUrl); ?>"><?php esc_html_e('Testuj połączenie z Tpay', 'ostoja-tpay-givewp'); ?></a></p>
                <p class="give-field-description">
                    <?php esc_html_e('Zalecane: zdefiniuj klucze w wp-config.php (OSTOJA_TPAY_CLIENT_ID, OSTOJA_TPAY_CLIENT_SECRET, OSTOJA_TPAY_SECURITY_CODE, OSTOJA_TPAY_SANDBOX) – nie trafią wtedy do bazy danych ani kopii zapasowych bazy.', 'ostoja-tpay-givewp'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    public static function handleTestConnection(): void
    {
        if (!current_user_can('manage_give_settings') && !current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'ostoja-tpay-givewp'), 403);
        }
        check_admin_referer('ostoja_tpay_test');

        $result = 'ok';
        try {
            TpayApi::fromConfig()->testConnection();
        } catch (\Exception $e) {
            $result = 'fail';
        }

        wp_safe_redirect(add_query_arg(
            ['post_type' => 'give_forms', 'page' => 'give-settings', 'tab' => 'gateways', 'section' => self::SECTION, 'ostoja_tpay_test' => $result],
            admin_url('edit.php')
        ));
        exit;
    }

    public static function testResultNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wyświetlenie komunikatu.
        $result = isset($_GET['ostoja_tpay_test']) ? sanitize_key(wp_unslash($_GET['ostoja_tpay_test'])) : '';
        if ($result === '' || !current_user_can('manage_options')) {
            return;
        }
        if ($result === 'ok') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Połączenie z Tpay (%s) działa – klucze są poprawne.', 'ostoja-tpay-givewp'), Config::isSandbox() ? 'Sandbox' : 'produkcja'))
            );
        } else {
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html__('Nie udało się połączyć z Tpay. Sprawdź klucze, środowisko (Sandbox/produkcja) i czy serwer może wykonywać połączenia HTTPS.', 'ostoja-tpay-givewp')
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
