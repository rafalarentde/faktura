<?php
declare(strict_types=1);

/*
 * Sidebar navigation of layout.twig (see App\Http\Shell::navItems()).
 *
 * Item keys:
 *   key        stable identifier (also used as a CSS hook: data-nav="key")
 *   label      translation key
 *   path       link target; also the active-state prefix unless 'match' is given
 *   match      optional path prefix that marks the item active (e.g. '/admin' for all admin pages)
 *   icon       icon name from templates/_icons.twig
 *   enabled    false = not rendered at all (no dead links). Flip to true in the task that adds the route.
 *   admin_only shown only to users with users.is_admin = 1
 * ['separator' => true] draws a divider; leading, trailing and doubled dividers are dropped.
 */
return [
    ['key' => 'dashboard', 'label' => 'nav.dashboard', 'path' => '/', 'icon' => 'home', 'enabled' => true, 'admin_only' => false],
    ['key' => 'new_invoice', 'label' => 'nav.new_invoice', 'path' => '/invoices/new', 'match' => '/invoices', 'icon' => 'invoice', 'enabled' => false, 'admin_only' => false],
    ['key' => 'offers', 'label' => 'nav.offers', 'path' => '/offers', 'icon' => 'offers', 'enabled' => false, 'admin_only' => false],
    ['key' => 'dunning', 'label' => 'nav.dunning', 'path' => '/dunning', 'icon' => 'alert', 'enabled' => false, 'admin_only' => false],
    ['separator' => true],
    ['key' => 'customers', 'label' => 'nav.customers', 'path' => '/customers', 'icon' => 'users', 'enabled' => true, 'admin_only' => false],
    ['key' => 'items', 'label' => 'nav.items', 'path' => '/items', 'icon' => 'box', 'enabled' => false, 'admin_only' => false],
    ['key' => 'settings', 'label' => 'nav.settings', 'path' => '/settings', 'icon' => 'gear', 'enabled' => false, 'admin_only' => false],
    ['separator' => true],
    ['key' => 'admin', 'label' => 'nav.admin', 'path' => '/admin/companies', 'match' => '/admin', 'icon' => 'shield', 'enabled' => true, 'admin_only' => true],
];
