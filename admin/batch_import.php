<?php
// ============================================================
// admin/batch_import.php - Batch Insertion (Admin)
//
// Paste text or upload a CSV, see exactly what will happen, then
// confirm. Nothing is written until the second submit.
// ============================================================

require_once __DIR__ . '/admin_auth.php';

require_permission('batch.manage');

$title = 'Batch Insert Products - Admin';

// ---------- Downloadable template ----------
// Generated with fputcsv rather than string concatenation, so the file
// this page hands out is quoted the same way the parser expects to read
// it back. The sample deliberately contains a name with a comma in it.
if (get('template') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="product_import_template.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens it as UTF-8

    fputcsv($out, ['name', 'category', 'price', 'stock', 'description', 'status']);
    fputcsv($out, ['Galaxy S24 Ultra, 512GB', 'Smartphones', '5499.00', '12',
                   'Flagship handset with titanium frame.', 'active']);
    fputcsv($out, ['AirBuds Pro 2', 'Accessories', '899.90', '40',
                   "Noise cancelling earbuds.\nIncludes charging case.", 'active']);
    fputcsv($out, ['Type-C Cable 1m', 'Accessories', '29.90', '250', 'Braided 60W cable.', 'active']);
    fclose($out);
    exit;
}

$preview  = null;
$parsed   = null;
$stageKey = 'import';

$options = [
    'duplicates'        => post('duplicates', 'skip'),
    'create_categories' => post('create_categories') === '1',
    'stop_on_error'     => post('stop_on_error', is_post() ? '' : '1') === '1',
];

$delimiter = post('delimiter', 'auto');
$rawText   = post('raw_text');

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- Step 2: commit ----------
    if ($action === 'commit') {
        $staged = batch_take_stage($stageKey, post('token'));

        if ($staged === null) {
            flash_error('That preview has expired or was already applied. Please generate it again.');
            redirect('/admin/batch_import.php');
        }

        $result = batch_insert_products($staged['rows'], $staged['options'], (int)current_user_id());

        if (!empty($result['rolled_back'])) {
            flash_error('Nothing was imported. The batch was rolled back at the first bad row: '
                      . $result['message']);
        } else {
            $parts = [];
            if ($result['inserted'])   { $parts[] = $result['inserted'] . ' inserted'; }
            if ($result['updated'])    { $parts[] = $result['updated'] . ' updated'; }
            if ($result['skipped'])    { $parts[] = $result['skipped'] . ' skipped'; }
            if ($result['failed'])     { $parts[] = $result['failed'] . ' failed'; }
            if ($result['categories']) { $parts[] = $result['categories'] . ' new categor'
                                                  . ($result['categories'] === 1 ? 'y' : 'ies'); }

            flash_success('Batch import finished: ' . implode(', ', $parts) . '.');
        }

        redirect('/admin/batch_import.php');
    }

    // ---------- Step 1: parse and validate ----------
    if ($action === 'preview') {
        $source = '';

        // An uploaded file wins over the textarea when both are filled.
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $file = $_FILES['csv_file'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                add_err('csv_file', 'The file could not be uploaded (error code ' . $file['error'] . ').');
            } elseif ($file['size'] > BATCH_MAX_UPLOAD) {
                add_err('csv_file', 'That file is larger than ' . round(BATCH_MAX_UPLOAD / 1024 / 1024, 1) . ' MB.');
            } else {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($extension, ['csv', 'txt', 'tsv'], true)) {
                    add_err('csv_file', 'Please upload a .csv, .tsv or .txt file.');
                } else {
                    $source = (string)file_get_contents($file['tmp_name']);
                }
            }
        } elseif (trim($rawText) !== '') {
            $source = $rawText;
        } else {
            add_err('raw_text', 'Paste some rows or choose a file to import.');
        }

        if (no_err()) {
            $parsed = batch_parse_delimited($source, $delimiter);

            if ($parsed['error'] !== null) {
                add_err('raw_text', $parsed['error']);

            } elseif (count($parsed['rows']) > BATCH_MAX_ROWS) {
                add_err('raw_text', 'That file has ' . count($parsed['rows']) . ' rows. '
                                  . 'The limit is ' . BATCH_MAX_ROWS . ' per batch.');
            } else {
                $columns = batch_map_columns($parsed['header'], batch_product_columns());

                if ($columns['missing'] !== []) {
                    add_err('raw_text', 'These required columns are missing from the header row: '
                                      . implode(', ', $columns['missing'])
                                      . '. Found: ' . implode(', ', $parsed['header']) . '.');
                } else {
                    $validated = batch_validate_products($parsed['rows'], $columns['map'], $options);

                    $token = batch_stage($stageKey, [
                        'rows'    => $validated['rows'],
                        'options' => $options + ['new_categories' => $validated['summary']['new_categories']],
                    ]);

                    $preview = [
                        'token'   => $token,
                        'rows'    => $validated['rows'],
                        'summary' => $validated['summary'],
                        'header'  => $parsed['header'],
                    ];
                }
            }
        }
    }
    // Validation failed. Answer with a redirect rather than a page, so
    // the browser's history entry is a GET and F5 cannot resubmit.
    // The errors and what was typed are carried across the redirect.
    redirect_back();
}

$duplicateOptions = [
    'skip'   => 'Skip it, keep the existing product',
    'update' => 'Update the existing product (price, category, description)',
    'error'  => 'Treat it as an error',
];

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-container">
    <div class="admin-header">
        <h2>Batch Tools</h2>
        <div class="admin-header-actions">
            <a href="/admin/batch_import.php?template=csv" class="btn-outline">
                <i class="fas fa-download"></i> Download CSV template
            </a>
        </div>
    </div>

    <?php include __DIR__ . '/../includes/batch_nav.php'; ?>

    <?php err_summary(); ?>

    <?php if ($preview !== null): ?>

        <?php $s = $preview['summary']; ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Step 2 of 2 &mdash; review before importing</h3>
            <p class="muted small-note">
                Nothing has been written yet. This is what will happen when you confirm.
            </p>

            <div class="batch-summary">
                <div class="batch-stat is-insert">
                    <span class="batch-stat-value"><?= (int)$s['insert'] ?></span>
                    <span class="batch-stat-label">To insert</span>
                </div>
                <div class="batch-stat is-update">
                    <span class="batch-stat-value"><?= (int)$s['update'] ?></span>
                    <span class="batch-stat-label">To update</span>
                </div>
                <div class="batch-stat is-skip">
                    <span class="batch-stat-value"><?= (int)$s['skip'] ?></span>
                    <span class="batch-stat-label">To skip</span>
                </div>
                <div class="batch-stat <?= $s['error'] > 0 ? 'is-error' : '' ?>">
                    <span class="batch-stat-value"><?= (int)$s['error'] ?></span>
                    <span class="batch-stat-label">With errors</span>
                </div>
            </div>

            <?php if ($s['new_categories'] !== []): ?>
                <div class="alert alert-info">
                    <i class="fas fa-folder-plus"></i>
                    These categories do not exist yet and will be created:
                    <strong><?= e(implode(', ', $s['new_categories'])) ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($s['error'] > 0): ?>
                <div class="alert alert-<?= $options['stop_on_error'] ? 'error' : 'warning' ?>">
                    <i class="fas fa-triangle-exclamation"></i>
                    <?php if ($options['stop_on_error']): ?>
                        <strong>All-or-nothing is on.</strong>
                        Because <?= (int)$s['error'] ?> row(s) failed validation, confirming will
                        roll the whole batch back and import nothing. Fix the rows below, or
                        switch to <em>keep the good rows</em> and try again.
                    <?php else: ?>
                        <strong><?= (int)$s['error'] ?> row(s) will be skipped</strong>
                        and the rest imported.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="table-responsive mt-4">
                <table class="admin-table batch-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Action</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Stock</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['rows'] as $row): ?>
                            <tr class="batch-row is-<?= e($row['action']) ?>">
                                <td><?= (int)$row['line'] ?></td>
                                <td>
                                    <span class="badge badge-<?= e(match ($row['action']) {
                                        'insert' => 'success',
                                        'update' => 'info',
                                        'skip'   => 'muted',
                                        default  => 'danger',
                                    }) ?>"><?= e(ucfirst($row['action'])) ?></span>
                                </td>
                                <td><?= e($row['data']['name']) ?></td>
                                <td><?= e($row['data']['category_name']) ?></td>
                                <td class="text-right"><?= e(money($row['data']['price'])) ?></td>
                                <td class="text-right"><?= (int)$row['data']['stock'] ?></td>
                                <td><?= e(ucfirst($row['data']['status'])) ?></td>
                                <td class="batch-notes">
                                    <?php if ($row['errors'] !== []): ?>
                                        <?= e(implode('; ', $row['errors'])) ?>
                                    <?php elseif ($row['action'] === 'skip'): ?>
                                        Already exists
                                    <?php elseif ($row['action'] === 'update'): ?>
                                        Existing product will be updated; stock left untouched
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions batch-actions">
                <a href="/admin/batch_import.php" class="btn-outline">Cancel</a>

                <form action="/admin/batch_import.php" method="POST" class="inline-form"
                      data-confirm="Import this batch now?">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'commit'); ?>
                    <?php html_hidden('token', $preview['token']); ?>
                    <?php html_submit('Confirm Import', ['class' => 'btn-primary', 'data-busy' => 'Importing...']); ?>
                </form>
            </div>
        </div>

    <?php else: ?>

        <div class="card card-padded mt-4">
            <h3 class="section-heading">Step 1 of 2 &mdash; provide the rows</h3>

            <form action="/admin/batch_import.php" method="POST"
                  enctype="multipart/form-data" class="form-standard">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'preview'); ?>

                <?php field('csv_file', 'Upload a CSV / TSV / TXT file', function () {
                    html_file('csv_file', ['accept' => '.csv,.tsv,.txt']);
                    echo '<small class="form-hint">Maximum '
                       . round(BATCH_MAX_UPLOAD / 1024 / 1024, 1) . ' MB, up to '
                       . BATCH_MAX_ROWS . ' rows. Excel files must be saved as CSV first.</small>';
                }); ?>

                <p class="batch-or"><span>or paste the rows directly</span></p>

                <?php field('raw_text', 'Paste text', function () use ($rawText) {
                    html_textarea('raw_text', $rawText, [
                        'rows'        => 8,
                        'class'       => 'batch-textarea',
                        'spellcheck'  => 'false',
                        'placeholder' => "name,category,price,stock\nGalaxy S24, 512GB,Smartphones,5499.00,12\nType-C Cable,Accessories,29.90,250",
                    ]);
                    echo '<small class="form-hint">The first line must be a header row. '
                       . 'Required columns: <code>name</code>, <code>category</code>, <code>price</code>. '
                       . 'Optional: <code>stock</code>, <code>description</code>, '
                       . '<code>reorder_level</code>, <code>status</code>. '
                       . 'Column order does not matter.</small>';
                }); ?>

                <div class="batch-options">
                    <?php field('delimiter', 'Separator', function () use ($delimiter) {
                        html_select('delimiter', batch_delimiter_options(), $delimiter);
                    }); ?>

                    <?php field('duplicates', 'If a product name already exists',
                        function () use ($duplicateOptions, $options) {
                            html_select('duplicates', $duplicateOptions, $options['duplicates']);
                        }); ?>
                </div>

                <div class="form-group form-check">
                    <label for="create_categories" class="check-label">
                        <input type="checkbox" name="create_categories" id="create_categories" value="1"
                               <?= $options['create_categories'] ? 'checked' : '' ?>>
                        <span>
                            Create categories that do not exist yet
                            <small class="form-hint">
                                Off by default, so a typo in a category name is reported
                                instead of quietly becoming a new category.
                            </small>
                        </span>
                    </label>
                </div>

                <div class="form-group form-check">
                    <label for="stop_on_error" class="check-label">
                        <input type="checkbox" name="stop_on_error" id="stop_on_error" value="1"
                               <?= $options['stop_on_error'] ? 'checked' : '' ?>>
                        <span>
                            All or nothing
                            <small class="form-hint">
                                If any row fails, import none of them. Recommended: a
                                half-loaded price list is harder to undo than an empty one,
                                because you cannot tell by looking which half landed.
                            </small>
                        </span>
                    </label>
                </div>

                <div class="form-actions">
                    <?php html_submit('Preview Import', ['class' => 'btn-primary']); ?>
                </div>
            </form>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
