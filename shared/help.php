<?php
declare(strict_types=1);
/**
 * shared/help.php — centre d'aide : chargement du contenu, rendu des fiches, bouton « ? » + panneau.
 *
 * Le contenu vit dans help/content/*.php (un fichier par sujet, versionné avec le code).
 * Un sujet lié à un module n'est visible que par les utilisateurs qui ont accès à ce module.
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('help_e')) {
    function help_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('help_risk_labels')) {
    /** @return array<string,string> */
    function help_risk_labels(): array {
        return [
            'safe'       => 'Sans risque',
            'reversible' => 'Modifiable après coup',
            'careful'    => 'Attention : irréversible',
        ];
    }
}

if (!function_exists('help_text')) {
    /** Texte d'une fiche : échappé, avec **gras** pour mettre en valeur un bouton ou un mot. */
    function help_text(string $s): string {
        $s = help_e($s);
        return (string)preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    }
}

if (!function_exists('help_topics_for_user')) {
    /**
     * Sujets accessibles à l'utilisateur connecté, triés.
     * @return array<string,array{key:string,label:string,module:?string,order:int,context:string[],entries:array<string,array<string,mixed>>}>
     */
    function help_topics_for_user(): array {
        $topics = [];
        foreach (glob(__DIR__ . '/../help/content/*.php') ?: [] as $file) {
            $t = require $file;
            if (!is_array($t) || empty($t['key'])) continue;
            $module = $t['module'] ?? null;
            if ($module !== null && !module_access((string)$module)) continue;
            $topics[(string)$t['key']] = [
                'key'     => (string)$t['key'],
                'label'   => (string)($t['label'] ?? $t['key']),
                'module'  => $module !== null ? (string)$module : null,
                'order'   => (int)($t['order'] ?? 100),
                'context' => array_map('strval', $t['context'] ?? []),
                'entries' => (array)($t['entries'] ?? []),
            ];
        }
        uasort($topics, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
        return $topics;
    }
}

if (!function_exists('help_render_entry')) {
    /** Fiche repliable (<details>). $ctx : vrai si elle concerne la page en cours (utilisé par le panneau). */
    function help_render_entry(array $topic, string $slug, array $e, bool $ctx = false, bool $panel = false): string {
        $base  = function_exists('suite_base') ? suite_base() : '';
        $id    = $topic['key'] . '-' . $slug;
        $risk  = (string)($e['risk'] ?? 'safe');
        $risks = help_risk_labels();
        $search = mb_strtolower($topic['label'] . ' ' . $e['q'] . ' ' . ($e['keywords'] ?? '') . ' '
            . implode(' ', (array)($e['steps'] ?? [])) . ' ' . ($e['undo'] ?? ''), 'UTF-8');

        $h  = '<details class="hp-entry" id="' . ($panel ? 'hp-' : 'help-') . help_e($id) . '"'
            . ' data-topic="' . help_e($topic['key']) . '" data-ctx="' . ($ctx ? '1' : '0') . '"'
            . ' data-search="' . help_e($search) . '">';
        $h .= '<summary><span class="hp-q">' . help_e((string)$e['q']) . '</span>'
            . '<span class="hp-risk hp-risk-' . help_e($risk) . '">' . help_e($risks[$risk] ?? $risks['safe']) . '</span></summary>';
        $h .= '<div class="hp-body">';
        if (!empty($e['intro'])) $h .= '<p class="hp-intro">' . help_text((string)$e['intro']) . '</p>';
        if (!empty($e['steps'])) {
            $h .= '<ol class="hp-steps">';
            foreach ((array)$e['steps'] as $step) $h .= '<li>' . help_text((string)$step) . '</li>';
            $h .= '</ol>';
        }
        if (!empty($e['undo'])) {
            $h .= '<div class="hp-undo"><span class="hp-undo-t">Si vous vous êtes trompé</span>' . help_text((string)$e['undo']) . '</div>';
        }
        if (!empty($e['link']['path'])) {
            $h .= '<a class="hp-link" href="' . help_e($base . $e['link']['path']) . '">'
                . help_e((string)($e['link']['label'] ?? 'Ouvrir')) . ' →</a>';
        }
        return $h . '</div></details>';
    }
}

if (!function_exists('help_widget')) {
    /** Bouton « ? » flottant + panneau latéral. Appelé par la sidebar commune. */
    function help_widget(string $activeModule = ''): void {
        if (!function_exists('is_admin') || !is_admin() || $activeModule === 'aide') return;
        $topics = help_topics_for_user();
        if (!$topics) return;

        $base   = function_exists('suite_base') ? suite_base() : '';
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $module = suite_module_from_path($script) ?? ($activeModule !== '' ? $activeModule : null);

        // Contexte : sujets rattachés au module de la page ; à défaut, le sujet « général »
        $ctxKeys = [];
        foreach ($topics as $t) {
            if ($module !== null && in_array($module, $t['context'], true)) $ctxKeys[] = $t['key'];
        }
        if (!$ctxKeys && isset($topics['general'])) $ctxKeys[] = 'general';
        $ctxLabels = array_map(static fn(string $k): string => $topics[$k]['label'], $ctxKeys);

        $v = static fn(string $rel): string => (string)(int)@filemtime(dirname(__DIR__) . '/' . $rel);
        ?>
<link rel="stylesheet" href="<?= help_e($base) ?>/assets/css/suite_help.css?v=<?= $v('assets/css/suite_help.css') ?>">
<button type="button" class="hp-fab" id="hp-fab" aria-label="Ouvrir l'aide" aria-controls="hp-panel" title="Aide">?</button>
<div class="hp-overlay" id="hp-overlay"></div>
<aside class="hp-panel" id="hp-panel" role="dialog" aria-label="Aide" aria-hidden="true">
  <div class="hp-head">
    <div>
      <div class="hp-title">Aide</div>
      <div class="hp-sub"><?= help_e($ctxLabels ? implode(' · ', $ctxLabels) : 'Toutes les questions') ?></div>
    </div>
    <button type="button" class="hp-close" id="hp-close" aria-label="Fermer l'aide">×</button>
  </div>
  <div class="hp-searchbar"><input type="search" id="hp-q" placeholder="Rechercher une question…" autocomplete="off"></div>
  <div class="hp-scroll" id="hp-list">
    <?php
      $script_base = basename($script);
      $ordered = [];
      foreach ($topics as $t) {
          $isCtx = in_array($t['key'], $ctxKeys, true);
          foreach ($t['entries'] as $slug => $e) {
              $here = false;
              foreach ((array)($e['pages'] ?? []) as $p) { if ($p !== '' && str_contains($script, (string)$p)) $here = true; }
              $ordered[] = [$isCtx ? ($here ? 0 : 1) : 2, $t, (string)$slug, $e, $isCtx];
          }
      }
      usort($ordered, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
      foreach ($ordered as [, $t, $slug, $e, $isCtx]) echo help_render_entry($t, $slug, $e, $isCtx, true);
    ?>
    <div class="hp-empty" id="hp-empty" hidden>Aucune question ne correspond. Essayez un autre mot.</div>
    <button type="button" class="hp-more" id="hp-more" hidden>Voir les questions des autres modules</button>
  </div>
  <div class="hp-foot"><a href="<?= help_e($base) ?>/help/index.php">Ouvrir toute l'aide →</a></div>
</aside>
<script src="<?= help_e($base) ?>/assets/js/suite_help.js?v=<?= $v('assets/js/suite_help.js') ?>" defer></script>
        <?php
    }
}
