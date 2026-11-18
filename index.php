<?php
/**
 * MEFTii ePrescription OpenEMR Launch Bridge
 *
 * Package:   OpenEMR MEFTii Module
 * Author:    MEFTii Technologies
 * Copyright: (C) 2026 MEFTii Technologies
 * License:   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 2 or later
 */

// Load OpenEMR core session and authentication
$ignoreAuth = false;
if (file_exists("../../../globals.php")) {
    require_once("../../../globals.php");
} elseif (file_exists("../../globals.php")) {
    require_once("../../globals.php");
}

use OpenEMR\Common\Auth\AuthUtils;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\FHIR\SMART\SMARTLaunchToken;

// Check user login via session wrapper or superglobal
$session = class_exists(SessionWrapperFactory::class) ? SessionWrapperFactory::getInstance()->getActiveSession() : null;
$authUserId = $session ? ($session->get('authUserID') ?? $session->get('authUser')) : ($_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? null);

if (empty($authUserId)) {
    die("Access denied. Please log in to OpenEMR.");
}

// 1. Determine active OpenEMR Patient Context
$pid = null;
$patientUuid = null;
if (!empty($_GET['pid'])) {
    $pid = intval($_GET['pid']);
} elseif (!empty($_GET['set_pid'])) {
    $pid = intval($_GET['set_pid']);
} elseif (!empty($_GET['patient'])) {
    $pid = intval($_GET['patient']);
} elseif (!empty($_REQUEST['pid'])) {
    $pid = intval($_REQUEST['pid']);
} elseif ($session && !empty($session->get('pid'))) {
    $pid = intval($session->get('pid'));
} elseif (!empty($_SESSION['pid'])) {
    $pid = intval($_SESSION['pid']);
} elseif (!empty($GLOBALS['pid'])) {
    $pid = intval($GLOBALS['pid']);
}

// Check if patient UUID is passed directly
if (!empty($_GET['patientUuid'])) {
    $patientUuid = $_GET['patientUuid'];
} elseif (!empty($_GET['patient_uuid'])) {
    $patientUuid = $_GET['patient_uuid'];
}

if (!empty($pid) && empty($patientUuid)) {
    $patientRow = sqlQuery("SELECT uuid FROM patient_data WHERE pid = ?", [$pid]);
    if (!empty($patientRow['uuid'])) {
        $patientUuid = class_exists(UuidRegistry::class) ? UuidRegistry::uuidToString($patientRow['uuid']) : $patientRow['uuid'];
    }
}

// 2. Determine OpenEMR FHIR Server Endpoint (iss)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$web_root = isset($GLOBALS['web_root']) ? $GLOBALS['web_root'] : '';
$fhir_endpoint = rtrim($protocol . $host . $web_root, '/') . '/apis/default/fhir';

// 3. MediScript / MEFTii Portal URL
$mediscript_base_url = 'https://eprescription.meftii.com';
if (getenv('MEDISCRIPT_PORTAL_URL')) {
    $mediscript_base_url = rtrim(getenv('MEDISCRIPT_PORTAL_URL'), '/');
} elseif (function_exists('sqlQuery')) {
    try {
        $cfg = sqlQuery("SELECT portal_url FROM oe_mediscript_config WHERE id = 1");
        if (!empty($cfg['portal_url'])) {
            $mediscript_base_url = rtrim($cfg['portal_url'], '/');
        }
    } catch (\Throwable $e) {
        // Table might not be installed yet
    }
}

// Allow query parameter override ONLY for authorized hosts (localhost / meftii.com)
if (isset($_GET['mediscript_url'])) {
    $requested_url = rtrim($_GET['mediscript_url'], '/');
    $parsed = parse_url($requested_url);
    $reqHost = $parsed['host'] ?? '';
    if (
        in_array($reqHost, ['localhost', '127.0.0.1'], true) ||
        preg_match('/(^|\.)meftii\.com$/i', $reqHost)
    ) {
        $mediscript_base_url = $requested_url;
    }
} elseif (!$mediscript_base_url && isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
    // Fallback for local development environments
    $mediscript_base_url = 'http://localhost:5002';
}

// 4. Create SMART Launch Context Token using OpenEMR's native SMARTLaunchToken serializer
if (class_exists(SMARTLaunchToken::class)) {
    $tokenObj = new SMARTLaunchToken($patientUuid);
    $tokenObj->setIntent(!empty($patientUuid) ? SMARTLaunchToken::INTENT_PATIENT_TAB : SMARTLaunchToken::INTENT_MAIN_TAB);
    $launch_token = $tokenObj->serialize();
} else {
    $launch_payload = [
        'patient' => $patientUuid ?: ($pid ? strval($pid) : null),
        'user' => strval($_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? '1'),
        'timestamp' => time(),
        'nonce' => bin2hex(random_bytes(8))
    ];
    $launch_token = base64_encode(json_encode($launch_payload));
}

// 4b. Resolve current OpenEMR logged-in prescriber credentials
$currUserId = $_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? '1';
$currentUserRow = null;
if (function_exists('sqlQuery')) {
    try {
        $currentUserRow = sqlQuery("SELECT id, username, fname, lname, email, npi, federaldrugid FROM users WHERE id = ? OR username = ?", [$currUserId, $currUserId]);
    } catch (\Throwable $e) {}
}

// 5. Construct SMART Launch URL
$launch_params = [
    'iss' => $fhir_endpoint,
    'launch' => $launch_token,
];
if (!empty($currentUserRow['email'])) {
    $launch_params['email'] = $currentUserRow['email'];
}
if (!empty($currentUserRow['npi'])) {
    $launch_params['npi'] = $currentUserRow['npi'];
}
if (!empty($patientUuid)) {
    $launch_params['patient'] = $patientUuid;
} elseif (!empty($pid)) {
    $launch_params['patient'] = strval($pid);
}
if (!empty($_GET['providers'])) {
    $launch_params['providers'] = $_GET['providers'];
}
$launch_url = $mediscript_base_url . '/smart/launch?' . http_build_query($launch_params);

// 6. Direct physician into MEFTii ePrescription Portal
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MEFTii ePrescription</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 1rem;
        }
        .container {
            text-align: center;
            background: #1e293b;
            border: 1px solid #334155;
            padding: 2.5rem 2rem;
            border-radius: 1rem;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(59, 130, 246, 0.2);
            border-top-color: #3b82f6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 1.25rem;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; color: #ffffff; }
        p { font-size: 0.875rem; color: #94a3b8; margin: 0 0 1.5rem; line-height: 1.4; }
        .actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-primary { background: #2563eb; color: #ffffff; border: 1px solid #2563eb; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #334155; color: #f1f5f9; border: 1px solid #475569; }
        .btn-secondary:hover { background: #475569; }
    </style>
    <script>
        // Seamlessly auto-navigate into SMART Launch with parent-frame patient discovery fallback
        (function() {
            var pid = <?php echo json_encode($pid); ?>;
            var patientUuid = <?php echo json_encode($patientUuid); ?>;
            var launchUrl = <?php echo json_encode($launch_url); ?>;

            try {
                var pWin = window.parent || window.top;
                if (!pid && pWin) {
                    // 1. Check JS functions and objects on parent/top
                    if (typeof pWin.get_patient === 'function') {
                        var pData = pWin.get_patient();
                        if (pData) {
                            pid = pData.pid || pData.id;
                            if (pData.uuid) patientUuid = pData.uuid;
                        }
                    }
                    if (!pid && pWin.current_pid) pid = pWin.current_pid;
                    if (!pid && pWin.pid) pid = pWin.pid;
                    if (!pid && pWin.patient_data && pWin.patient_data.pid) {
                        pid = pWin.patient_data.pid;
                        if (pWin.patient_data.uuid) patientUuid = pWin.patient_data.uuid;
                    }

                    // 2. Iterate through all frames on window.top
                    if (!pid && window.top && window.top.frames) {
                        for (var i = 0; i < window.top.frames.length; i++) {
                            try {
                                var f = window.top.frames[i];
                                if (f.current_pid) { pid = f.current_pid; break; }
                                if (f.pid) { pid = f.pid; break; }
                                if (f.patient_data && f.patient_data.pid) { pid = f.patient_data.pid; break; }
                                if (typeof f.get_patient === 'function') {
                                    var fp = f.get_patient();
                                    if (fp && (fp.pid || fp.id)) { pid = fp.pid || fp.id; break; }
                                }
                            } catch (fe) {}
                        }
                    }

                    // 3. Scan top-level DOM for OpenEMR patient banner (e.g. "Adam Mughal (2)")
                    if (!pid && window.top && window.top.document) {
                        var topDoc = window.top.document;
                        var patEl = topDoc.querySelector('[data-patient-id], [data-pid], .patient-title, #patient_name, .pt-header');
                        if (patEl) {
                            pid = patEl.getAttribute('data-patient-id') || patEl.getAttribute('data-pid') || patEl.innerText.replace(/\D/g, '');
                        }
                        if (!pid && topDoc.body) {
                            var bText = topDoc.body.innerText || '';
                            var match = bText.match(/\b[A-Z][a-zA-Z\s]+\s*\((\d+)\)/);
                            if (match && match[1]) {
                                pid = match[1];
                            }
                        }
                    }
                }
            } catch (e) {
                console.warn('[MEFTii Launch] Parent frame check notice:', e);
            }

            if (pid && !launchUrl.includes('patient=')) {
                var sep = launchUrl.indexOf('?') !== -1 ? '&' : '?';
                launchUrl += sep + 'patient=' + encodeURIComponent(patientUuid || pid) + '&pid=' + encodeURIComponent(pid);
            }

            window.location.replace(launchUrl);
        })();
    </script>
</head>
<body>
    <div class="container">
        <div class="spinner"></div>
        <h1>Connecting to MEFTii ePrescription</h1>
        <p>Launching SMART on FHIR prescription session for patient chart...</p>
        <div class="actions">
            <a href="<?php echo htmlspecialchars($launch_url); ?>" class="btn btn-primary">
                Continue Inside Tab
            </a>
            <a href="<?php echo htmlspecialchars($launch_url); ?>" target="_blank" class="btn btn-secondary">
                Open in New Tab ↗
            </a>
        </div>
    </div>
</body>
</html>
