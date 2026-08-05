<?php
/**
 * license_expired.php — Janus POC License Expiry Page
 * ─────────────────────────────────────────────────────
 * Displayed automatically when the POC license has expired or been revoked.
 * Destroys the active session so the user cannot navigate back.
 * No auth required — this page is publicly accessible.
 */

// Capture license metadata before destroying the session
if (session_status() === PHP_SESSION_NONE) session_start();
$client_name  = $_SESSION['_lic_client']  ?? 'Valued Client';
$expires_at   = $_SESSION['_lic_expires'] ?? null;
$is_active    = $_SESSION['_lic_active']  ?? true;
session_destroy();

// Format the expiry datetime — show both date AND time so it's unambiguous
if ($expires_at && strtotime($expires_at) !== false) {
    $ts           = strtotime($expires_at);
    $expiry_label = date('d M Y', $ts);           // e.g. 22 Aug 2026
    $expiry_time  = date('H:i:s', $ts);           // e.g. 15:00:00
    $expiry_full  = date('d M Y, H:i:s', $ts);   // for subtitle
} else {
    $expiry_label = 'Unknown';
    $expiry_time  = '--:--:--';
    $expiry_full  = 'an unspecified date';
}

// Reason: revoked (is_active=0) vs naturally expired (past expires_at)
$is_revoked     = !$is_active;
$badge_text     = $is_revoked ? 'License Revoked' : 'License Expired';
$heading_text   = $is_revoked ? 'Access Has Been Revoked' : 'POC Access Has Ended';
$subtitle_extra = $is_revoked
    ? 'This deployment has been manually deactivated.'
    : "This deployment expired on <strong>{$expiry_full}</strong>.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POC License Expired — Janus</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-deep:     #090d15;
            --bg-card:     #0e1623;
            --border:      rgba(99, 179, 237, 0.15);
            --accent:      #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.25);
            --danger:      #ef4444;
            --danger-glow: rgba(239, 68, 68, 0.2);
            --text-primary:   #f0f6ff;
            --text-secondary: #8ca0be;
            --text-muted:     #4b6080;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-deep);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        /* Ambient background glow */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 60% 50% at 50% -10%, rgba(59,130,246,0.12) 0%, transparent 70%),
                radial-gradient(ellipse 40% 30% at 80% 80%, rgba(239,68,68,0.07) 0%, transparent 60%);
            pointer-events: none;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 3rem 3.5rem;
            max-width: 520px;
            width: 100%;
            text-align: center;
            position: relative;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.04) inset,
                0 40px 80px rgba(0,0,0,0.5),
                0 0 60px var(--danger-glow);
            animation: fadeIn 0.6s ease both;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Lock icon */
        .icon-wrap {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(239,68,68,0.18) 0%, transparent 70%);
            border: 1px solid rgba(239, 68, 68, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.75rem;
            animation: pulse 2.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.3); }
            50%       { box-shadow: 0 0 0 12px rgba(239,68,68,0); }
        }

        .icon-wrap svg {
            width: 36px;
            height: 36px;
            color: var(--danger);
        }

        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #fca5a5;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: 1.25rem;
        }

        .badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--danger);
            animation: blink 1.2s step-start infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0; }
        }

        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.02em;
            margin-bottom: 0.75rem;
            line-height: 1.2;
        }

        .subtitle {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .subtitle strong {
            color: var(--text-primary);
            font-weight: 500;
        }

        /* Info grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1px;
            background: var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .info-cell {
            background: rgba(14, 22, 35, 0.9);
            padding: 1rem;
        }

        .info-cell:first-child { border-radius: 12px 0 0 12px; }
        .info-cell:last-child  { border-radius: 0 12px 12px 0; }

        .info-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
        }

        .info-value.expired { color: var(--danger); }

        /* Divider */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 0 0 1.75rem;
        }

        /* CTA */
        p.cta {
            font-size: 0.875rem;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        p.cta a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        p.cta a:hover { color: #93c5fd; text-decoration: underline; }

        /* Janus wordmark */
        .wordmark {
            margin-top: 2.25rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 500;
            letter-spacing: 0.05em;
        }

        .wordmark svg {
            width: 18px;
            height: 18px;
            color: var(--accent);
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="card" role="main" aria-labelledby="expiry-heading">

        <div class="icon-wrap" aria-hidden="true">
            <!-- Lock icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <div class="badge"><?= htmlspecialchars($badge_text) ?></div>

        <h1 id="expiry-heading"><?= htmlspecialchars($heading_text) ?></h1>

        <p class="subtitle">
            The Janus proof-of-concept deployment for
            <strong><?= htmlspecialchars($client_name) ?></strong>
            is no longer accessible. <?= $subtitle_extra ?>
        </p>

        <div class="info-grid">
            <div class="info-cell">
                <div class="info-label">Organisation</div>
                <div class="info-value"><?= htmlspecialchars($client_name) ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label"><?= $is_revoked ? 'Status' : 'Expired On' ?></div>
                <div class="info-value expired">
                    <?php if ($is_revoked): ?>
                        Manually Revoked
                    <?php else: ?>
                        <?= htmlspecialchars($expiry_label) ?><br>
                        <span style="font-size:0.78rem;opacity:0.7"><?= htmlspecialchars($expiry_time) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <hr class="divider">

        <p class="cta">
            To continue using Janus or to discuss a full license,
            please contact the Janus team to arrange access renewal.
        </p>

        <div class="wordmark" aria-label="Janus Security Platform">
            <!-- Shield icon -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            Janus Security Platform
        </div>
    </div>
</body>
</html>
