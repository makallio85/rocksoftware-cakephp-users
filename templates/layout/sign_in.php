<?php
/**
 * The plugin's own layout for the sign-in and emergency pages, used when the
 * application loads no UI plugin and names no layout of its own
 * (`UsersPlugin::signInLayout()`). The same card as the UI plugin's `auth`
 * layout, with `RockSoftwareUsers.appName` as its heading.
 *
 * Self-contained: the few Bootstrap classes the pages use are styled here,
 * with no stylesheet from the application or a CDN, and there is no script
 * at all. Flash messages are rendered here as alerts rather than through the
 * application's flash elements, which may carry inline event handlers.
 *
 * @var \Cake\View\View $this
 */
use Cake\Core\Configure;
use Cake\I18n\I18n;

$appName = Configure::read('RockSoftwareUsers.appName');
$flashes = (array)$this->getRequest()->getSession()->consume('Flash.flash');
$alertType = static fn(string $element): string => match (basename($element)) {
    'error' => 'danger',
    'success' => 'success',
    'warning' => 'warning',
    default => 'info',
};
?>
<!DOCTYPE html>
<html lang="<?= h(substr((string)I18n::getLocale(), 0, 2) ?: 'en') ?>">
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($this->fetch('title')) ?><?= $appName ? ' · ' . h($appName) : '' ?></title>
    <style>
        :root { color-scheme: light; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; background: #f7f7f7; color: #212529;
            font: 1rem/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
        .auth-shell { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .auth-card { width: 100%; max-width: 400px; padding: 2rem 1.75rem; background: #fff;
            border: 1px solid #e2e8f0; border-radius: .9rem; box-shadow: 0 12px 32px rgba(15, 23, 42, .08); }
        .h5 { margin: 0 0 .25rem; font-size: 1.25rem; font-weight: 500; line-height: 1.2; }
        .h6 { margin: 0 0 .5rem; font-size: 1rem; font-weight: 500; line-height: 1.2; }
        p { margin: 0 0 1rem; }
        .text-center { text-align: center; }
        .text-body-secondary { color: rgba(33, 37, 41, .75); }
        .small { font-size: .875em; }
        .mb-0 { margin-bottom: 0; } .mb-2 { margin-bottom: .5rem; } .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; } .mt-3 { margin-top: 1rem; } .my-3 { margin: 1rem 0; } .me-1 { margin-right: .25rem; }
        .d-grid { display: grid; }
        .btn { display: inline-block; padding: .375rem .75rem; border: 1px solid transparent; border-radius: .375rem;
            font: inherit; font-weight: 400; line-height: 1.5; text-align: center; text-decoration: none; cursor: pointer; }
        .btn-lg { padding: .5rem 1rem; font-size: 1.25rem; border-radius: .5rem; }
        .btn-primary { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #4338ca; border-color: #4338ca; }
        .btn-outline-secondary { background: #fff; border-color: #6c757d; color: #6c757d; }
        .btn-outline-secondary:hover { background: #6c757d; color: #fff; }
        .btn:focus-visible, .form-control:focus { outline: 0; box-shadow: 0 0 0 .25rem rgba(79, 70, 229, .25); }
        .form-label { display: inline-block; margin-bottom: .5rem; }
        .form-control { display: block; width: 100%; padding: .375rem .75rem; border: 1px solid #dee2e6;
            border-radius: .375rem; font: inherit; line-height: 1.5; }
        .alert { margin-bottom: 1rem; padding: 1rem; border: 1px solid transparent; border-radius: .375rem; }
        .alert-danger { background: #f8d7da; border-color: #f1aeb5; color: #58151c; }
        .alert-success { background: #d1e7dd; border-color: #a3cfbb; color: #0a3622; }
        .alert-warning { background: #fff3cd; border-color: #ffe69c; color: #664d03; }
        .alert-info { background: #cff4fc; border-color: #9eeaf9; color: #055160; }
        code { font-size: .875em; color: #d63384; overflow-wrap: anywhere; }
    </style>
    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
</head>
<body>
<main class="auth-shell">
    <div class="auth-card">
        <?php if ($appName) : ?>
        <div class="text-center mb-4">
            <h1 class="h5 mb-0"><?= h($appName) ?></h1>
        </div>
        <?php endif; ?>
        <?php foreach ($flashes as $flash) : ?>
            <?php $message = (string)($flash['message'] ?? ''); ?>
        <div class="alert alert-<?= $alertType((string)($flash['element'] ?? '')) ?>" role="alert">
            <?= ($flash['params']['escape'] ?? true) === false ? $message : h($message) ?>
        </div>
        <?php endforeach; ?>
        <?= $this->fetch('content') ?>
    </div>
</main>
</body>
</html>
