<?php
/**
 * MEFTii ePrescription Module Configuration Entry
 * Included by OpenEMR Module Manager when clicking the gear icon (⚙️)
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

$module_config = 1;
require_once __DIR__ . '/setup.php';
exit;
