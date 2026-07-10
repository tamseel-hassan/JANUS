<?php
/**
 * drivers/driver_registry.php
 * Central registry for all vendor drivers.
 *
 * Include this file instead of individual driver files.
 * nac_lib.php requires this once at startup.
 *
 * Adding a new vendor:
 *   1. Create drivers/driver_VENDOR.php with:
 *        driver_VENDOR_meta(): array
 *        driver_VENDOR_parse(string $mac, string $ifc, string $lldp): array
 *   2. Add the require_once here
 *   3. Add the platform_key mapping in DRIVER_MAP below
 */

$__dir = __DIR__;

require_once $__dir . '/driver_junos.php';
require_once $__dir . '/driver_ios.php';
require_once $__dir . '/driver_procurve.php';
require_once $__dir . '/driver_snmp.php';
/* ═══════════════════════════════════════════════════════════════
   DRIVER MAP
   Maps platform_key (from nac_platforms.platform_key) to a
   callable parser function name.
═══════════════════════════════════════════════════════════════ */
const DRIVER_MAP = [
    'junos'      => 'driver_junos_parse',
    'junos-new'  => 'driver_junos_parse',
    'ios'        => 'driver_ios_parse',
    'ios-xe'     => 'driver_ios_parse',
    'ios-legacy' => 'driver_ios_parse',     // 2950/IOS 12.1 hyphenated command
    'ios-telnet' => 'driver_ios_parse',     // 2960+/IOS 12.2 via telnet, space command
    'procurve'   => 'driver_procurve_parse',
    'aruba-os'   => 'driver_aruba_os_parse',
    'aruba'      => 'driver_procurve_parse',
];

/* ═══════════════════════════════════════════════════════════════
   DRIVER META MAP
   Maps platform_key to a callable meta function (returns config).
═══════════════════════════════════════════════════════════════ */
const DRIVER_META_MAP = [
    'junos'      => 'driver_junos_meta',
    'junos-new'  => 'driver_junos_meta',
    'ios'        => 'driver_ios_meta',
    'ios-xe'     => 'driver_ios_xe_meta',
    'ios-legacy' => 'driver_ios_legacy_meta',  // 2950/IOS 12.1 hyphenated
    'ios-telnet' => 'driver_ios_telnet_meta',  // 2960+/IOS 12.2 via telnet
    'procurve'   => 'driver_procurve_meta',
    'aruba-os'   => 'driver_aruba_os_meta',
    'aruba'      => 'driver_procurve_meta',
];

/* ═══════════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════════ */

/**
 * Get the parser callable for a platform key.
 * Falls back to JunOS parser if unknown (safe default for Juniper variants).
 */
function driver_get_parser(string $platform_key): callable {
    $map = DRIVER_MAP;
    $fn  = $map[$platform_key] ?? 'driver_junos_parse';
    if (!function_exists($fn)) {
        $fn = 'driver_junos_parse';
    }
    return $fn;
}

/**
 * Get the meta/config array for a platform key.
 */
function driver_get_meta(string $platform_key): array {
    $map = DRIVER_META_MAP;
    $fn  = $map[$platform_key] ?? null;
    if ($fn && function_exists($fn)) {
        return $fn();
    }
    return driver_junos_meta();
}

/**
 * Get the prompt regex for a platform key.
 * Used by the SSH expect script to recognise when the switch is ready.
 */
function driver_get_prompt_regex(string $platform_key): string {
    if (str_contains($platform_key, 'junos')) {
        return driver_junos_prompt_regex();
    }
    // IOS and ProCurve use simple hostname# or hostname> prompts
    return '[A-Za-z0-9_\-\.]+[#>]\s';
}

/**
 * Returns all registered platform metadata as an array.
 * Used by nac_ajax.php 'get_platforms' to seed the nac_platforms table.
 */
function driver_all_meta(): array {
    $result = [];
    foreach (array_unique(array_values(DRIVER_META_MAP)) as $fn) {
        if (function_exists($fn)) {
            $meta = $fn();
            $result[$meta['platform_key']] = $meta;
        }
    }
    return $result;
}
