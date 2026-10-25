<?php
/**
 * The signed-in person's menu as a dropdown: `<?= $this->element('RockSoftware/Users.user_menu') ?>`
 * in the application's layout. No script and no stylesheet of the
 * application's; colours come from CSS variables the application may set:
 * `--rsu-menu-accent`, `--rsu-menu-surface`, `--rsu-menu-text`,
 * `--rsu-menu-muted`, `--rsu-menu-border`.
 *
 * An application that renders its own menu uses `$this->UserMenu->items()`.
 *
 * @var \Cake\View\View $this
 */
$this->loadHelper('RockSoftware/Users.UserMenu');
$items = $this->UserMenu->items();
if ($items === []) {
    return;
}
$header = $items[0];
$apps = array_filter($items, fn(array $item): bool => $item['type'] === 'app');
$initials = '';
foreach (preg_split('/[\s@.]+/u', (string)$header['label'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
    $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    if (mb_strlen($initials) === 2) {
        break;
    }
}
?>
<details class="rsu-user-menu">
    <style>
        .rsu-user-menu { position: relative; display: inline-block; font: inherit;
            --rsu-menu-accent-default: #0b5cad; --rsu-menu-surface-default: #fff; --rsu-menu-text-default: #1f2328;
            --rsu-menu-muted-default: #57606a; --rsu-menu-border-default: #d0d7de; }
        .rsu-user-menu > summary { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 10px;
            list-style: none; cursor: pointer; border-radius: 6px; color: var(--rsu-menu-text, var(--rsu-menu-text-default));
            border: 1px solid var(--rsu-menu-border, var(--rsu-menu-border-default)); background: var(--rsu-menu-surface, var(--rsu-menu-surface-default)); }
        .rsu-user-menu > summary::-webkit-details-marker { display: none; }
        .rsu-user-menu > summary:focus-visible { outline: 2px solid var(--rsu-menu-accent, var(--rsu-menu-accent-default)); outline-offset: 2px; }
        .rsu-user-menu .rsu-avatar { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center;
            justify-content: center; font-size: 12px; font-weight: 600; color: #fff; background: var(--rsu-menu-accent, var(--rsu-menu-accent-default)); }
        .rsu-user-menu .rsu-panel { position: absolute; right: 0; top: calc(100% + 6px); z-index: 1050; width: min(320px, calc(100vw - 32px));
            background: var(--rsu-menu-surface, var(--rsu-menu-surface-default)); color: var(--rsu-menu-text, var(--rsu-menu-text-default));
            border: 1px solid var(--rsu-menu-border, var(--rsu-menu-border-default)); border-radius: 8px; box-shadow: 0 8px 24px rgba(31, 35, 40, .15); }
        .rsu-user-menu .rsu-head { padding: 14px 16px; border-bottom: 1px solid var(--rsu-menu-border, var(--rsu-menu-border-default)); }
        .rsu-user-menu .rsu-head strong { display: block; font-weight: 600; }
        .rsu-user-menu .rsu-muted { font-size: 13px; color: var(--rsu-menu-muted, var(--rsu-menu-muted-default)); }
        .rsu-user-menu .rsu-group { padding: 8px; border-bottom: 1px solid var(--rsu-menu-border, var(--rsu-menu-border-default)); }
        .rsu-user-menu .rsu-group:last-child { border-bottom: 0; }
        .rsu-user-menu .rsu-group-title { display: block; padding: 4px 8px; font-size: 12px; font-weight: 600; }
        .rsu-user-menu .rsu-item { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 8px; border-radius: 6px;
            color: inherit; text-decoration: none; }
        .rsu-user-menu .rsu-item:hover, .rsu-user-menu .rsu-item:focus-visible { background: rgba(127, 127, 127, .12); }
        .rsu-user-menu .rsu-item[aria-current] { font-weight: 600; }
        .rsu-user-menu .rsu-item .rsu-muted { margin-left: auto; }
    </style>
    <summary aria-label="<?= h(__d('rock_software_users', 'Menu of {0}', $header['label'])) ?>">
        <span class="rsu-avatar" aria-hidden="true"><?= h($initials) ?></span>
        <span class="rsu-name"><?= h($header['label']) ?></span>
    </summary>
    <div class="rsu-panel">
        <div class="rsu-head">
            <strong><?= h($header['label']) ?></strong>
            <span class="rsu-muted"><?= h($header['detail']) ?></span>
        </div>
        <?php if ($apps !== []) : ?>
            <nav class="rsu-group" aria-label="<?= h(__d('rock_software_users', 'Applications')) ?>">
                <span class="rsu-group-title rsu-muted"><?= __d('rock_software_users', 'Applications') ?></span>
                <?php foreach ($apps as $app) : ?>
                    <a class="rsu-item" href="<?= h($app['url']) ?>"<?= $app['current'] ? ' aria-current="page"' : '' ?>>
                        <?= h($app['label']) ?>
                        <?php if ($app['current']) : ?><span class="rsu-muted"><?= __d('rock_software_users', 'this one') ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="rsu-group">
            <?php foreach ($items as $item) : ?>
                <?php if ($item['type'] === 'link') : ?>
                    <a class="rsu-item" href="<?= h($item['url']) ?>"><?= h($item['label']) ?></a>
                <?php elseif ($item['type'] === 'sign_out') : ?>
                    <?= $this->Form->postLink($item['label'], $item['url'], ['class' => 'rsu-item']) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</details>
