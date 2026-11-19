<?php
declare(strict_types=1);

/*
 * Tabs of the settings area (templates/settings/_tabs.twig), in display order. One place for all
 * tabs: a later task adds its tab (e.g. the numbering tab of Task 13) here, registers its routes
 * and renders its page with the same `tabs`/`active_tab` variables via SettingsController::page().
 *
 *   key         stable identifier; the controller passes it as `active_tab`
 *   label       translation key
 *   path        GET route of the tab
 *   owner_only  true = only the 'owner' role of the active company sees and may use it;
 *               false = every logged-in member (personal settings)
 */
return [
    ['key' => 'company', 'label' => 'settings.tab_company', 'path' => '/settings/company', 'owner_only' => true],
    ['key' => 'dunning', 'label' => 'settings.tab_dunning', 'path' => '/settings/dunning', 'owner_only' => true],
    ['key' => 'mail', 'label' => 'settings.tab_mail', 'path' => '/settings/mail', 'owner_only' => true],
    ['key' => 'security', 'label' => 'settings.tab_security', 'path' => '/settings/security', 'owner_only' => false],
];
