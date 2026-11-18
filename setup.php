<?php
/**
 * MEFTii ePrescription Clinic Admin Setup Dashboard
 *
 * Package:   OpenEMR MEFTii Module
 * Author:    MEFTii Technologies
 * Copyright: (C) 2026 MEFTii Technologies
 * License:   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 2 or later
 */

$sessionAllowWrite = true;
if (file_exists(__DIR__ . "/../../../globals.php")) {
    require_once(__DIR__ . "/../../../globals.php");
} elseif (file_exists(__DIR__ . "/../../../../globals.php")) {
    require_once(__DIR__ . "/../../../../globals.php");
}

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

// Resolve OpenEMR session
$session = class_exists(SessionWrapperFactory::class) ? SessionWrapperFactory::getInstance()->getActiveSession() : null;
$authUserId = $session ? ($session->get('authUserID') ?? $session->get('authUser')) : ($_SESSION['authUserID'] ?? $_SESSION['authUser'] ?? null);

// Check user login & administrator permissions
if (empty($authUserId) || (!AclMain::aclCheckCore('admin', 'super') && !AclMain::aclCheckCore('admin', 'users'))) {
    if (class_exists(AccessDeniedHelper::class)) {
        AccessDeniedHelper::denyWithTemplate("ACL check failed: Please log in as an administrator to access MEFTii Setup.", "MEFTii Setup");
        exit;
    } else {
        die("Access denied. Administrator privileges required to access MEFTii Setup.");
    }
}

// Handle Settings Form Submission
$saveSuccess = false;
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (class_exists(CsrfUtils::class)) {
        if (!CsrfUtils::checkCsrfToken($_POST["csrf_token_form"] ?? '')) {
            CsrfUtils::csrfNotAuth();
            exit;
        }
    }

    $portal_url = trim($_POST['portal_url'] ?? 'https://eprescription.meftii.com');
    $client_id = trim($_POST['client_id'] ?? 'mediscript_client');

    // Security validation: URL must be valid and use HTTPS (or localhost for dev)
    if (!filter_var($portal_url, FILTER_VALIDATE_URL) || (!str_starts_with($portal_url, 'https://') && !str_starts_with($portal_url, 'http://localhost') && !str_starts_with($portal_url, 'http://127.0.0.1'))) {
        $errorMessage = "Invalid Portal URL. Must use HTTPS (or localhost for development).";
    } else {
        // Ensure config table exists
        sqlStatement("
            CREATE TABLE IF NOT EXISTS `oe_mediscript_config` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `portal_url` VARCHAR(255) NOT NULL DEFAULT 'https://eprescription.meftii.com',
              `client_id` VARCHAR(100) NOT NULL DEFAULT 'mediscript_client',
              `auto_sync_erx` TINYINT(1) NOT NULL DEFAULT 1,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $exists = sqlQuery("SELECT id FROM `oe_mediscript_config` WHERE id = 1");
        if ($exists) {
            sqlStatement("UPDATE `oe_mediscript_config` SET portal_url = ?, client_id = ? WHERE id = 1", [$portal_url, $client_id]);
        } else {
            sqlStatement("INSERT INTO `oe_mediscript_config` (id, portal_url, client_id) VALUES (1, ?, ?)", [$portal_url, $client_id]);
        }
        $saveSuccess = true;
    }
}

// Load current configuration
$config = sqlQuery("SELECT * FROM `oe_mediscript_config` WHERE id = 1");
$portalUrl = $config['portal_url'] ?? (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ? 'http://localhost:5002' : 'https://eprescription.meftii.com');
$clientId = $config['client_id'] ?? 'mediscript_client';

// Fetch OpenEMR Primary Practice Facility
$facility = sqlQuery("SELECT * FROM facility WHERE service_location = 1 OR primary_business_entity = 1 ORDER BY primary_business_entity DESC LIMIT 1");
if (!$facility) {
    $facility = sqlQuery("SELECT * FROM facility LIMIT 1");
}

// Fetch all Active Authorized Providers (Doctors) in OpenEMR
$providers = [];
$res = sqlStatement("
    SELECT id, username, fname, lname, npi, federaldrugid, state_license_number, specialty, email, phonecell, phone 
    FROM users 
    WHERE active = 1 AND authorized = 1 
    ORDER BY lname, fname
");
while ($row = sqlFetchArray($res)) {
    $providers[] = $row;
}

$webRoot = class_exists(OEGlobalsBag::class) ? OEGlobalsBag::getInstance()->getWebRoot() : ($GLOBALS['web_root'] ?? '');
$launchUrl = $webRoot . "/interface/modules/custom_modules/oe-module-mediscript/index.php";

$formattedProviders = array_map(function($p) {
    return [
        'id' => $p['id'],
        'firstName' => $p['fname'] ?? '',
        'lastName' => $p['lname'] ?? '',
        'email' => $p['email'] ?: ($p['username'] ?? ''),
        'npi' => $p['npi'] ?? '',
        'deaNumber' => $p['federaldrugid'] ?? '',
        'stateLicenseNumber' => $p['state_license_number'] ?? '',
        'specialty' => $p['specialty'] ?? 'General Practice',
        'phone' => $p['phonecell'] ?: ($p['phone'] ?? '')
    ];
}, $providers);

$providersParam = base64_encode(json_encode($formattedProviders));
$setupLaunchUrl = $launchUrl . "?providers=" . urlencode($providersParam);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEFTii ePrescription Setup</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot); ?>/public/assets/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot); ?>/public/assets/font-awesome/css/all.min.css">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; padding: 20px; }
        .card { border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 24px; background: #fff; }
        .card-header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 16px 20px; font-weight: 700; border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .btn-primary { background-color: #2563eb; border-color: #2563eb; }
        .btn-primary:hover { background-color: #1d4ed8; }
        .badge-active { background-color: #dcfce7; color: #166534; font-weight: 600; padding: 4px 8px; border-radius: 6px; }
        .table th { background-color: #f1f5f9; color: #475569; font-size: 13px; font-weight: 600; }
        .table td { vertical-align: middle; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container-fluid max-w-6xl">
        <!-- Header Banner -->
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
            <div>
                <h3 class="mb-1 fw-bold text-primary"><i class="fas fa-prescription-bottle-alt me-2"></i> MEFTii ePrescription & EPCS</h3>
                <p class="text-muted mb-0 small">OpenEMR Practice Management & Doctor Onboarding Dashboard</p>
            </div>
            <div>
                <a href="<?php echo htmlspecialchars($launchUrl); ?>" target="_blank" class="btn btn-primary shadow-sm">
                    <i class="fas fa-external-link-alt me-1"></i> Launch MEFTii Portal
                </a>
            </div>
        </div>

        <?php if ($saveSuccess): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-1"></i> Configuration updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Step 1: Practice Setup Status -->
        <div class="row">
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-clinic-medical text-primary me-2"></i> Practice / Organization Info</span>
                        <span class="badge-active"><i class="fas fa-check me-1"></i> OpenEMR Facility Detected</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">PRACTICE NAME</label>
                                <div class="fw-bold"><?php echo htmlspecialchars($facility['name'] ?? 'Main Clinic Facility'); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">FACILITY NPI / TAX ID</label>
                                <div><?php echo htmlspecialchars($facility['facility_npi'] ?: ($facility['federal_ein'] ?: 'Not Specified')); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">ADDRESS</label>
                                <div><?php echo htmlspecialchars(($facility['street'] ?? '') . ' ' . ($facility['city'] ?? '') . ', ' . ($facility['state'] ?? '') . ' ' . ($facility['postal_code'] ?? '')); ?></div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted small fw-semibold">PHONE</label>
                                <div><?php echo htmlspecialchars($facility['phone'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <hr class="my-3">

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 fw-bold">1-Click Practice Onboarding</h6>
                                <small class="text-muted">Register or manage this clinic organization in MEFTii ePrescription.</small>
                            </div>
                            <a href="<?php echo htmlspecialchars($setupLaunchUrl); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-arrow-circle-right me-1"></i> Start Practice Setup
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Server Settings Card -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-cog text-secondary me-2"></i> Connection Settings
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if (class_exists(CsrfUtils::class)): ?>
                                <input type="hidden" name="csrf_token_form" value="<?php echo htmlspecialchars(CsrfUtils::collectCsrfToken()); ?>">
                            <?php endif; ?>
                            <input type="hidden" name="save_settings" value="1">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">MEFTii Portal URL</label>
                                <input type="url" name="portal_url" class="form-control" value="<?php echo htmlspecialchars($portalUrl); ?>" required>
                                <div class="form-text small">Local: <code>http://localhost:5002</code> | Cloud: <code>https://eprescription.meftii.com</code></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold">FHIR SMART Client ID</label>
                                <input type="text" name="client_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($clientId); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-sm btn-secondary w-100">
                                <i class="fas fa-save me-1"></i> Save Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Clinic Doctors Roster -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-user-md text-primary me-2"></i> Clinic Prescribing Doctors (<?php echo count($providers); ?> Found in OpenEMR)</span>
                <span class="text-muted small">Doctors can write prescriptions directly from OpenEMR patient charts</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Doctor Name</th>
                                <th>Username / Email</th>
                                <th>NPI Number</th>
                                <th>DEA Number (EPCS)</th>
                                <th>State License</th>
                                <th>Specialty</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($providers)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        No authorized prescribing providers found in OpenEMR. Ensure users have "Authorized" checked in OpenEMR User Management.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($providers as $doc): ?>
                                    <tr>
                                        <td class="fw-semibold text-primary">
                                            Dr. <?php echo htmlspecialchars(trim(($doc['fname'] ?? '') . ' ' . ($doc['lname'] ?? ''))); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['email'] ?: $doc['username']); ?></td>
                                        <td><code><?php echo htmlspecialchars($doc['npi'] ?: 'Missing NPI'); ?></code></td>
                                        <td><?php echo htmlspecialchars($doc['federaldrugid'] ?: 'Optional'); ?></td>
                                        <td><?php echo htmlspecialchars($doc['state_license_number'] ?: 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($doc['specialty'] ?: 'General Practice'); ?></td>
                                        <td>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Ready for eRx</span>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?php echo htmlspecialchars($launchUrl); ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Launch ePrescribe session for this doctor">
                                                <i class="fas fa-stethoscope me-1"></i> Test eRx
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light text-muted small py-2 d-flex justify-content-between">
                <span>All OpenEMR providers listed above are automatically synced when they launch MEFTii ePrescription.</span>
                <span>To add a new doctor, add an authorized user in <strong>OpenEMR Administration ➔ Users</strong>.</span>
            </div>
        </div>

        <!-- Step 3: Doctor Daily Prescribing Instructions -->
        <div class="card bg-light border-0">
            <div class="card-body">
                <h6 class="fw-bold mb-2 text-dark"><i class="fas fa-info-circle text-primary me-2"></i> How Doctors Write Prescriptions Daily:</h6>
                <ol class="small text-muted mb-0 ps-3">
                    <li>Open any patient in OpenEMR (via Patient Finder or Calendar).</li>
                    <li>In the left-hand Patient Menu or top menu, click <strong>"E-Prescribe (MEFTii)"</strong>.</li>
                    <li>The MEFTii ePrescription pad opens with the patient's demographics, allergies, and active medications pre-populated.</li>
                    <li>Search for drugs, review drug-drug interaction warnings, select the pharmacy, and transmit the prescription electronically!</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>
