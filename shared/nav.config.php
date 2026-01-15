<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('role_level')) {
    function role_level(string $role): int {
        return match ($role) {
            'admin_plus' => 3,
            'admin'      => 2,
            default      => 1,
        };
    }
}

if (!function_exists('nav_items')) {
    function nav_items(): array {
        $base = suite_base();

        return [
            [
                'label'    => 'Planning',
                'icon'     => '📅',
                'min_role' => 'public',
                'href'     => $base . '/planning/',
            ],
            [
                'label'    => 'Convois',
                'icon'     => '🚚',
                'min_role' => 'public',
                'children' => [
                    ['label' => 'Vue convois', 'href' => $base . '/logistique/'],
                    ['label' => 'Colisage douane', 'href' => $base . '/logistique/convoys/customs.php?id='],
                ],
            ],
            [
                'label'    => 'Stock local',
                'icon'     => '📦',
                'min_role' => 'public',
                'children' => [
                    ['label' => 'Stock famille', 'href' => $base . '/logistique/stock/index.php'],
                    ['label' => 'Réservation stock', 'href' => $base . '/logistique/families'],
                ],
            ],
            [
                'label'    => 'Étiquettes',
                'icon'     => '🏷️',
                'min_role' => 'public',
                'href'     => $base . '/logistique/labels/labels.php',
            ],
            [
                'label'    => 'Caisse',
                'icon'     => '💶',
                'min_role' => 'public',
                'href'     => $base . '/caisse/',
            ],
            [
                'label'    => 'Dons',
                'icon'     => '🎁',
                'min_role' => 'admin',
                'href'     => $base . '/planning/admin/donations.php',
            ],
            [
                'label'    => 'Rapport d’activité',
                'icon'     => '📊',
                'min_role' => 'admin_plus',
                'href'     => $base . '/planning/admin/report_activity.php',
            ],
        ];
    }
}

if (!function_exists('nav_visible_items')) {
    function nav_visible_items(): array {
        $items = nav_items();
        $curLevel = role_level(current_role());

        $out = [];
        foreach ($items as $it) {
            $min = $it['min_role'] ?? 'public';
            if ($curLevel < role_level($min)) continue;

            if (!empty($it['children']) && is_array($it['children'])) {
                // enfants héritent du rôle parent
                $it['children'] = array_values($it['children']);
            }
            $out[] = $it;
        }
        return $out;
    }
}
