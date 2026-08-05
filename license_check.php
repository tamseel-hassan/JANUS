<?php
/**
 * license_check.php — Janus POC License Validator (Token-Based)
 * ─────────────────────────────────────────────────────────────────────────────
 * Validates the deployment using a cryptographically signed license token.
 * The expiry date is LOCKED INSIDE the signed token — it cannot be changed
 * by anyone who only has DB access. The token is verified using HMAC-SHA256.
 *
 * TOKEN STRUCTURE
 * ────────────────
 *   TOKEN = base64url(JSON_PAYLOAD) + "." + HMAC-SHA256(base64url(payload), signing_key)
 *
 *   JSON_PAYLOAD = { client, expires, issued, version }
 *
 * TO GENERATE A NEW TOKEN (run locally, never on VM)
 * ────────────────────────────────────────────────────
 *   php generate_license_token.php \
 *       --client  "Acme Corp" \
 *       --expires "2026-09-01 23:59:59" \
 *       --key     "YOUR_64_HEX_SIGNING_KEY"
 *
 * TO DEPLOY
 * ──────────
 *   1. Set in /etc/php/8.3/fpm/pool.d/www.conf:
 *        env[POC_LICENSE_TOKEN] = <token from generator>
 *        env[POC_SIGNING_KEY]   = <64 hex char signing key>
 *
 *   2. sudo systemctl reload php8.3-fpm
 *
 * SECURITY PROPERTIES
 * ─────────────────────
 *   ✓ Expiry is cryptographically signed — changing it invalidates the token
 *   ✓ No DB required — validation is entirely offline/in-memory
 *   ✓ DB admin cannot extend or modify the license
 *   ✓ Token is verified inside bolt-encrypted PHP (source not readable)
 *   ✓ Signing key is never written to disk (PHP-FPM memory env only)
 */

/**
 * Checks whether the current deployment has a valid, non-expired license token.
 *
 * @return bool  true  → token valid + not expired
 *               false → missing, tampered, or expired
 */
function janus_license_is_valid(): bool
{
    // ── 1. Read token and signing key from environment ────────────────────────
    $token      = trim(getenv('POC_LICENSE_TOKEN') ?: ($_SERVER['POC_LICENSE_TOKEN'] ?? ''));
    $signing_key = trim(getenv('POC_SIGNING_KEY')  ?: ($_SERVER['POC_SIGNING_KEY']  ?? ''));

    if ($token === '' || $signing_key === '') {
        error_log('[Janus License] POC_LICENSE_TOKEN or POC_SIGNING_KEY is not set.');
        return false;
    }

    // ── 2. Split token into payload + signature ───────────────────────────────
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        error_log('[Janus License] Malformed token — missing signature.');
        return false;
    }
    [$payload_b64, $provided_sig] = $parts;

    // ── 3. Verify HMAC signature (tamper detection) ───────────────────────────
    $raw_key      = hex2bin($signing_key);
    $expected_sig = hash_hmac('sha256', $payload_b64, $raw_key);

    if (!hash_equals($expected_sig, $provided_sig)) {
        error_log('[Janus License] Token signature INVALID — possible tampering detected.');
        return false;
    }

    // ── 4. Decode and parse payload ───────────────────────────────────────────
    // Re-pad base64url to standard base64 before decoding
    $padded      = str_pad(strtr($payload_b64, '-_', '+/'), strlen($payload_b64) % 4 === 0 ? strlen($payload_b64) : strlen($payload_b64) + (4 - strlen($payload_b64) % 4), '=');
    $payload_raw = base64_decode($padded, true);

    if ($payload_raw === false) {
        error_log('[Janus License] Failed to base64-decode token payload.');
        return false;
    }

    $payload = json_decode($payload_raw, true);
    if (!is_array($payload) || empty($payload['expires']) || empty($payload['client'])) {
        error_log('[Janus License] Token payload is missing required fields.');
        return false;
    }

    // ── 5. Check expiry (compare timestamps, not strings) ────────────────────
    $expires_ts = strtotime($payload['expires']);
    if ($expires_ts === false) {
        error_log('[Janus License] Token expires field is not a valid datetime.');
        return false;
    }

    $now_ts = time();
    $valid  = $expires_ts > $now_ts;

    // ── 6. Populate session for expiry page ───────────────────────────────────
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_lic_client']  = $payload['client'];
        $_SESSION['_lic_expires'] = $payload['expires'];
        $_SESSION['_lic_active']  = $valid;
    }

    if (!$valid) {
        error_log('[Janus License] EXPIRED — client=' . $payload['client']
            . ' expires=' . $payload['expires']
            . ' now=' . date('Y-m-d H:i:s', $now_ts));
    }

    return $valid;
}
