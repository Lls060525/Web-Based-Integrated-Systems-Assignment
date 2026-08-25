<?php
// ============================================================
// admin/batch_import.php - Batch Insertion (Admin)
//
// Paste text or upload a CSV, see exactly what will happen, then
// confirm. Nothing is written until the second submit.
//
// ------------------------------------------------------------
// HOW TO READ THIS FILE
// ------------------------------------------------------------
//
// It is ONE page that serves three different requests, told apart by
// what arrives:
//
//   GET  ?template=csv    -> sends a sample file and exits
//   POST action=preview   -> parses, validates, shows step 2
//   POST action=commit    -> writes to the database
//
// The page therefore reads top to bottom as: template download, then
// the POST handlers, then the HTML, which renders either the preview
// (when $preview is set) or the upload form (when it is not).
//
// ------------------------------------------------------------
// WHY TWO STEPS INSTEAD OF ONE
// ------------------------------------------------------------
//
// Importing 200 products is not undoable by hand. A single-step
// importer gives you a result you did not expect and no way back --
// you cannot tell by looking which of 200 rows it changed.
//
// So parsing and writing are split. Step 1 works out exactly what
// WOULD happen and shows it as a table; step 2 carries it out. The
// interesting consequence is that step 2 must not re-parse the file:
// if it did, the file could differ from the one that produced the
// preview, and the confirmation would be a confirmation of something
// else. Instead step 1 STAGES its result under a one-use token (see
// batch_stage / batch_take_stage in lib/batch.php) and step 2 applies
// exactly those rows.
//
// The heavy lifting lives in lib/batch.php so that this page, and
// batch_price.php and batch_delete.php beside it, share one parser
// and one staging mechanism rather than three that drift apart.
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

// $preview stays null on a plain GET, and the HTML at the bottom uses
// that to decide which of the two steps to draw. One variable is the
// whole "which screen am I on" state.
$preview  = null;
$parsed   = null;

// Namespaces this page's staged data in the session, so an import
// preview and a price-change preview can exist at the same time
// without one overwriting the other.
$stageKey = 'import';

// ---------- The three import options ----------
// Read from the POST every time rather than held in the session, so
// the preview always reflects the boxes as they are ticked NOW.
$options = [
    'duplicates'        => post('duplicates', 'skip'),
    'create_categories' => post('create_categories') === '1',

    // Reads oddly, and has to.
    //
    // An unticked checkbox sends NOTHING -- it is simply absent from
    // the POST. So "absent" has two different meanings depending on
    // how we arrived:
    //
    //   first visit (GET)  -> nobody has expressed a view yet, and the
    //                         safe default for a destructive bulk
    //                         operation is all-or-nothing ON
    //   after a submit     -> absent means the user deliberately
    //                         UNTICKED it, and must stay off
    //
    // is_post() is what tells those two cases apart. Defaulting to '1'
    // unconditionally would make the box impossible to turn off; to ''
    // would silently drop the safer default on the screen where it
    // matters most.
    'stop_on_error'     => post('stop_on_error', is_post() ? '' : '1') === '1',
];

$delimiter = post('delimiter', 'auto');
$rawText   = post('raw_text');

if (is_post()) {
    csrf_check();

    $action = post('action');

    // ---------- Step 2: commit ----------
    if ($action === 'commit') {
        // TAKE, not read: batch_take_stage() returns the staged rows
        // and deletes them in the same breath, so the token is good for
        // exactly one use.
        //
        // That is what stops a double-click, or a browser retry, from
        // importing the same 200 products twice. Checking a flag
        // instead would leave a window between the check and the write
        // where a second request could pass the same check.
        //
        // It also means the rows applied here are the exact rows the
        // preview was built from -- the uploaded file is never read a
        // second time, so what you confirmed is what runs.
        $staged = batch_take_stage($stageKey, post('token'));

        if ($staged === null) {
            flash_error('That preview has expired or was already applied. Please generate it again.');
            redirect('/admin/batch_import.php');
        }

        // Everything below this line is reporting. The database work --
        // the transaction, the duplicate handling, the category
        // creation -- is all inside batch_insert_products().
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
    //
    // Five gates, each one refusing before the next is attempted:
    //
    //   1. is there any input at all      (file or textarea)
    //   2. can it be parsed as delimited  (batch_parse_delimited)
    //   3. is it within the row limit     (BATCH_MAX_ROWS)
    //   4. does the header carry the      (batch_map_columns)
    //      required columns
    //   5. is each row individually valid (batch_validate_products)
    //
    // The order matters: complaining that "price is not a number" on
    // 300 rows is useless if the real problem is that the file was
    // semicolon-separated and every row is one big column. Each gate
    // is cheaper and more general than the one after it.
    if ($action === 'preview') {
        $source = '';

        // An uploaded file wins over the textarea when both are filled.
        //
        // is_uploaded_file() as well as the tmp_name check, because the
        // former asks PHP whether this path really came from an upload.
        // Without it, a crafted request naming a local path could get
        // this page to read a file off the server's disk.
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
                    // Decides insert / update / skip / error per row and
                    // counts them up. Nothing is written.
                    $validated = batch_validate_products($parsed['rows'], $columns['map'], $options);

                    // Park the decision, get a one-use token back. The
                    // token travels to the browser in a hidden field and
                    // comes back with the confirmation.
                    //
                    // The categories to be created are merged into the
                    // stored options so step 2 creates exactly the ones
                    // the preview promised -- if another admin adds one
                    // of them in the meantime, we must not silently
                    // create a second.
                    $token = batch_stage($stageKey, [
                        'rows'    => $validated['rows'],
                        'options' => $options + ['new_categories' => $validated['summary']['new_categories']],
                    ]);

                    // Setting $preview is what makes the HTML below draw
                    // step 2 instead of the upload form.
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
    // Reached by BOTH outcomes of the preview step, which is why it is
    // not inside an else.
    //
    // redirect_back() only redirects when something went wrong. On
    // success it returns immediately and execution carries on into the
    // HTML below, where $preview is now set and step 2 gets drawn.
    // (Read the top of redirect_back() in lib/prg.php -- the early
    // return is deliberate and this page is one of the reasons it
    // exists.)
    //
    // On failure it parks the errors and the typed values in the
    // session and redirects here as a GET, so the browser's history
    // entry is a GET and pressing F5 cannot resubmit the upload. That
    // is the Post/Redirect/Get pattern; without it a refresh on this
    // page would re-run an import.
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
