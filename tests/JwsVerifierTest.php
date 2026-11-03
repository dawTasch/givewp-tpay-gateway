<?php
/**
 * Standalone test of JwsVerifier (no WordPress needed).
 * Generates a throw-away CA + signing certificate, stubs the few WP functions used,
 * and checks that only correctly signed notifications are accepted.
 *
 * Run: php tests/JwsVerifierTest.php   (or: bin/test.sh to run on PHP 7.4 and 8.3 in Docker)
 */

define('ABSPATH', '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['transients'] = [];
$GLOBALS['fetches'] = 0;
$GLOBALS['pem'] = [];

function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $e) { $GLOBALS['transients'][$k] = $v; return true; }
function is_wp_error($x) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_safe_remote_get($url, $args)
{
    $GLOBALS['fetches']++;
    return isset($GLOBALS['pem'][$url]) ? ['code' => 200, 'body' => $GLOBALS['pem'][$url]] : ['code' => 404, 'body' => ''];
}

require __DIR__ . '/../ostoja-tpay-givewp/src/JwsVerifier.php';

use OstojaTpayGiveWP\JwsVerifier as J;

// --- throw-away PKI ---------------------------------------------------------
function newKey() { return openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]); }
function pem($cert) { openssl_x509_export($cert, $out); return $out; }

$rootKey = newKey();
$rootCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Root'], $rootKey), null, $rootKey, 30);
$signKey = newKey();
$signCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Sign'], $signKey), $rootCert, $rootKey, 30);
$evilKey = newKey();

$base = 'https://secure.sandbox.tpay.com';
$x5u = $base . '/x509/notifications-jws.pem';
$GLOBALS['pem'][$x5u] = pem($signCert);
$GLOBALS['pem'][$base . '/x509/tpay-jws-root.pem'] = pem($rootCert);

function sign(string $body, $key, string $x5u, string $alg = 'RS256'): string
{
    $header = J::base64UrlEncode(json_encode(['alg' => $alg, 'x5u' => $x5u]));
    openssl_sign($header . '.' . J::base64UrlEncode($body), $sig, $key, OPENSSL_ALGO_SHA256);
    return $header . '..' . J::base64UrlEncode($sig);
}

// --- cases ------------------------------------------------------------------
$body = 'id=1010&tr_id=TR-ABC&tr_amount=50.00&tr_crc=givewp-12&tr_status=TRUE&md5sum=x';
$cases = [
    'valid signature'                  => [sign($body, $signKey, $x5u), $body, true],
    'tampered body (amount changed)'   => [sign($body, $signKey, $x5u), str_replace('50.00', '5000.00', $body), false],
    'signed with a foreign key'        => [sign($body, $evilKey, $x5u), $body, false],
    'foreign x5u (SSRF attempt)'       => [sign($body, $signKey, 'https://evil.example/c.pem'), $body, false],
    'production x5u while in sandbox'  => [sign($body, $signKey, 'https://secure.tpay.com/x509/notifications-jws.pem'), $body, false],
    'alg none'                         => [J::base64UrlEncode(json_encode(['alg' => 'none', 'x5u' => $x5u])) . '..', $body, false],
    'empty signature'                  => ['', $body, false],
    'garbage'                          => ['a.b', $body, false],
];

$failed = 0;
foreach ($cases as $name => [$jws, $payload, $expected]) {
    $ok = J::verify($jws, $payload, $base) === $expected;
    $failed += $ok ? 0 : 1;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
echo sprintf('%d passed, %d failed (PHP %s, certificate fetches: %d)', count($cases) - $failed, $failed, PHP_VERSION, $GLOBALS['fetches']) . PHP_EOL;
exit($failed ? 1 : 0);
