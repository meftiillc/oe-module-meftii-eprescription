<?php

/**
 * MEFTii ePrescription OpenEMR Module Bootstrap
 *
 * Package:   OpenEMR MEFTii Module
 * Author:    MEFTii Technologies
 * Copyright: (C) 2026 MEFTii Technologies
 * License:   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 2 or later
 */

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Menu\PatientMenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

if (!function_exists('oe_module_mediscript_add_menu_item')) {
    function oe_module_mediscript_add_menu_item(MenuEvent $event)
    {
        $menu = $event->getMenu();

        $menuItem = new stdClass();
        $menuItem->requirement = 0;
        $menuItem->target = '_blank';
        $menuItem->menu_id = 'mod_mediscript';
        $menuItem->label = xlt("MEFTii ePrescription Portal");
        $webRoot = class_exists(OEGlobalsBag::class) ? OEGlobalsBag::getInstance()->getWebRoot() : ($GLOBALS['web_root'] ?? '');
        $menuItem->url = $webRoot . "/interface/modules/custom_modules/oe-module-mediscript/index.php";
        $menuItem->children = [];
        $menuItem->acl_req = ["patients", "med"];
        $menuItem->global_req = [];

        $setupItem = new stdClass();
        $setupItem->requirement = 0;
        $setupItem->target = '_blank';
        $setupItem->menu_id = 'mod_mediscript_setup';
        $setupItem->label = xlt("MEFTii Clinic Setup & Doctors");
        $setupItem->url = $webRoot . "/interface/modules/custom_modules/oe-module-mediscript/setup.php";
        $setupItem->children = [];
        $setupItem->acl_req = ["admin", "super"];
        $setupItem->global_req = [];

        foreach ($menu as $item) {
            if ($item->menu_id == 'mod' || $item->menu_id == 'serv') {
                $item->children[] = $menuItem;
                $item->children[] = $setupItem;
                break;
            }
        }

        $event->setMenu($menu);
        return $event;
    }
}

if (!function_exists('oe_module_mediscript_patient_menu_item')) {
    function oe_module_mediscript_patient_menu_item(PatientMenuEvent $menuEvent)
    {
        $existingMenu = $menuEvent->getMenu();

        $pid = null;
        if (method_exists($menuEvent, 'getPid')) {
            $pid = $menuEvent->getPid();
        } elseif (method_exists($menuEvent, 'getPatientId')) {
            $pid = $menuEvent->getPatientId();
        }

        $menuItem = new stdClass();
        $menuItem->label = xlt("E-Prescribe (MEFTii)");
        $webRoot = class_exists(OEGlobalsBag::class) ? OEGlobalsBag::getInstance()->getWebRoot() : ($GLOBALS['web_root'] ?? '');
        $url = $webRoot . "/interface/modules/custom_modules/oe-module-mediscript/index.php?context=patient";
        if (!empty($pid)) {
            $url .= "&pid=" . urlencode($pid);
        }
        $menuItem->url = $url;
        $menuItem->menu_id = "mod_mediscript_rx";
        $menuItem->target = "_blank";

        $existingMenu[] = $menuItem;

        $menuEvent->setMenu($existingMenu);
        return $menuEvent;
    }
}

/**
 * @var EventDispatcherInterface $eventDispatcher
 */
if (isset($eventDispatcher)) {
    $eventDispatcher->addListener(MenuEvent::MENU_UPDATE, 'oe_module_mediscript_add_menu_item');
    $eventDispatcher->addListener(PatientMenuEvent::MENU_UPDATE, 'oe_module_mediscript_patient_menu_item');
}
