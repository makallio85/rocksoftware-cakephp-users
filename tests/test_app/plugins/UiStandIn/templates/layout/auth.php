<?php
/**
 * A stand-in for the UI plugin's `auth` layout: its card heading comes from
 * the `Ui.*` settings, as the real one's does.
 *
 * @var \Cake\View\View $this
 */
use Cake\Core\Configure;

?>
<!doctype html>
<html><body class="kk-auth"><div class="auth-card">
<h1 class="h5"><?= h(Configure::read('Ui.appName')) ?></h1>
<p class="ui-organization"><?= h(Configure::read('Ui.organizationName')) ?></p>
<?= $this->Flash->render() ?>
<?= $this->fetch('content') ?>
</div></body></html>
