<?php
// ============================================================
// includes/batch_nav.php - sub-navigation shared by the three
// batch pages, so the sidebar keeps one entry instead of three.
// ============================================================

$batch_current = basename($_SERVER['SCRIPT_NAME']);

$batch_tabs = [
    'batch_import.php' => ['label' => 'Insert',  'icon' => 'fa-file-import'],
    'batch_price.php'  => ['label' => 'Update',  'icon' => 'fa-tags'],
    'batch_delete.php' => ['label' => 'Delete',  'icon' => 'fa-trash-can'],
];
?>
<nav class="batch-tabs">
    <?php foreach ($batch_tabs as $file => $tab): ?>
        <a href="/admin/<?= e($file) ?>"
           class="batch-tab <?= $batch_current === $file ? 'is-active' : '' ?>">
            <i class="fas <?= e($tab['icon']) ?>"></i> <?= e($tab['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
