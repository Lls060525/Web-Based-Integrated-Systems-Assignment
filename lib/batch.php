<?php
// ============================================================
// lib/batch.php
// Shared engine for the three batch operations:
//   admin/batch_import.php   insert many products from text or CSV
//   admin/batch_price.php    update many prices at once
//   admin/batch_delete.php   remove many products at once
//
// Two ideas run through all three.
//
// 1. NOTHING IS WRITTEN ON THE FIRST SUBMIT.
//    Every operation parses, validates and reports what it *would*
//    do, and only touches the database after the admin confirms the
//    preview. A batch mistake is not a small mistake: one wrong
//    multiplier repriced the whole catalogue.
//
// 2. THE PREVIEW IS HELD SERVER-SIDE.
//    The confirm step reads the staged rows back out of the session,
//    not out of a hidden field. If the browser could hand back the
//    row list, the confirm step would be a way to write arbitrary
//    rows that never went through validation. The token also means
//    a stale confirm (back button, double submit) is rejected
//    instead of applied twice.
// ============================================================

// ------------------------------------------------------------
// Text and CSV parsing
// ------------------------------------------------------------

/**
 * Remove the UTF-8 byte order mark.
 *
 * Excel writes one at the start of every CSV it exports. Left in, it
 * becomes part of the first header name, so "name" arrives as
 * "\xEF\xBB\xBFname" and the column is reported missing. This is the
 * single most common reason a perfectly good spreadsheet "does not
 * import".
 */
function batch_strip_bom(string $text): string
{
    if (str_starts_with($text, "\xEF\xBB\xBF")) {
        return substr($text, 3);
    }

    return $text;
}

/**
 * Guess the separator from the header line.
 *
 * Semicolons matter in practice: Excel on a machine with a European
 * locale writes CSV with semicolons, because the comma is the decimal
 * separator there.
 */
function batch_detect_delimiter(string $firstLine): string
{
    $counts = [
        ','  => substr_count($firstLine, ','),
        ';'  => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
        '|'  => substr_count($firstLine, '|'),
    ];

    arsort($counts);
    $best = array_key_first($counts);

    return $counts[$best] > 0 ? $best : ',';
}

/**
 * The separator choices offered in the UI.
 *
 * These are word tokens, not the characters themselves, because the
 * form helpers trim() every posted value. A literal "\t" option would
 * come back as an empty string and silently fall back to a comma.
 */
function batch_delimiter_options(): array
{
    return [
        'auto'      => 'Detect automatically',
        'comma'     => 'Comma  ,',
        'semicolon' => 'Semicolon  ;',
        'tab'       => 'Tab',
        'pipe'      => 'Pipe  |',
    ];
}

/** Turn a token from the form into the character fgetcsv needs. */
function batch_delimiter_char(string $token): string
{
    return match ($token) {
        'semicolon' => ';',
        'tab'       => "\t",
        'pipe'      => '|',
        default     => ',',
    };
}

/**
 * Parse delimited text into a header row plus data rows.
 *
 * This uses fgetcsv() over an in-memory stream rather than
 * explode(',') on each line. explode() cannot cope with a quoted
 * field that itself contains the delimiter or a line break:
 *
 *     name,price
 *     "Galaxy S24, 256GB Model",3299.00
 *
 * splits into three fields with explode() and two with fgetcsv().
 * Product names contain commas often enough that the naive version
 * corrupts real data rather than failing loudly.
 *
 * @return array{header: array, rows: array, delimiter: string, error: ?string}
 */
function batch_parse_delimited(string $raw, string $delimiterToken = 'auto'): array
{
    $raw = batch_strip_bom($raw);
    $raw = str_replace("\r\n", "\n", $raw);   // Windows line endings
    $raw = str_replace("\r", "\n", $raw);     // old Mac line endings
    $raw = trim($raw);

    $out = ['header' => [], 'rows' => [], 'delimiter' => ',', 'error' => null];

    if ($raw === '') {
        $out['error'] = 'There is nothing to import.';
        return $out;
    }

    if ($delimiterToken === 'auto') {
        $firstLine = substr($raw, 0, strpos($raw . "\n", "\n"));
        $delimiter = batch_detect_delimiter($firstLine);
    } else {
        $delimiter = batch_delimiter_char($delimiterToken);
    }

    $out['delimiter'] = $delimiter;

    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $raw);
    rewind($handle);

    $lineNo = 0;

    while (($fields = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNo++;

        // fgetcsv returns [null] for a blank line.
        if ($fields === [null] || (count($fields) === 1 && trim((string)$fields[0]) === '')) {
            continue;
        }

        $fields = array_map(static fn($f) => trim((string)$f), $fields);

        if ($out['header'] === []) {
            $out['header'] = array_map('batch_normalise_header', $fields);
            continue;
        }

        // The physical line number is kept so an error message can point
        // the admin at the right row of their spreadsheet.
        $out['rows'][] = ['line' => $lineNo, 'fields' => $fields];
    }

    fclose($handle);

    if ($out['header'] === []) {
        $out['error'] = 'No header row was found.';
    } elseif ($out['rows'] === []) {
        $out['error'] = 'The header row was read, but there are no data rows under it.';
    }

    return $out;
}

/** "Product Name" and "product_name" are the same column. */
function batch_normalise_header(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('~[\s\-]+~', '_', $name);

    return preg_replace('~[^a-z0-9_]~', '', $name);
}

/**
 * Match the header row against the columns we want.
 *
 * Mapping by NAME rather than by position means the admin can reorder
 * or add columns in their spreadsheet without silently shifting every
 * value into the wrong field.
 *
 * @param array $spec  canonical => [accepted aliases]
 * @return array{map: array, missing: array}
 */
function batch_map_columns(array $header, array $spec): array
{
    $map     = [];
    $missing = [];

    foreach ($spec as $canonical => $info) {
        $found = null;

        foreach ($info['aliases'] as $alias) {
            $index = array_search(batch_normalise_header($alias), $header, true);

            if ($index !== false) {
                $found = $index;
                break;
            }
        }

        if ($found === null) {
            if (!empty($info['required'])) {
                $missing[] = $canonical;
            }
            continue;
        }

        $map[$canonical] = $found;
    }

    return ['map' => $map, 'missing' => $missing];
}

/** Read one mapped column out of a row, or '' when absent. */
function batch_cell(array $fields, array $map, string $column, string $default = ''): string
{
    if (!isset($map[$column])) {
        return $default;
    }

    return trim((string)($fields[$map[$column]] ?? $default));
}

// ------------------------------------------------------------
// Staging: hold a validated preview between the two requests
// ------------------------------------------------------------

/**
 * Park a validated payload in the session and return a one-use token.
 * The token is what the confirm form posts back, so the browser never
 * gets to restate the data itself.
 */
function batch_stage(string $kind, array $payload): string
{
    $token = bin2hex(random_bytes(16));

    $_SESSION['batch_stage'][$kind] = [
        'token'   => $token,
        'payload' => $payload,
        'created' => time(),
    ];

    return $token;
}

/**
 * Retrieve a staged payload, but only for the matching token and only
 * within BATCH_STAGE_TTL. Returns null for a stale or replayed confirm.
 */
function batch_take_stage(string $kind, string $token): ?array
{
    $stage = $_SESSION['batch_stage'][$kind] ?? null;

    if (!$stage || !hash_equals($stage['token'], $token)) {
        return null;
    }

    if (time() - $stage['created'] > BATCH_STAGE_TTL) {
        batch_clear_stage($kind);
        return null;
    }

    // Consumed on read, so a double submit cannot apply the same batch twice.
    batch_clear_stage($kind);

    return $stage['payload'];
}

function batch_clear_stage(string $kind): void
{
    unset($_SESSION['batch_stage'][$kind]);
}

// ============================================================
// BATCH INSERTION
// ============================================================

/** The columns an import file may carry. */
function batch_product_columns(): array
{
    return [
        'name'          => ['required' => true,  'aliases' => ['name', 'product_name', 'product', 'title']],
        'category'      => ['required' => true,  'aliases' => ['category', 'category_name', 'cat']],
        'price'         => ['required' => true,  'aliases' => ['price', 'unit_price', 'rrp']],
        'description'   => ['required' => false, 'aliases' => ['description', 'desc', 'details']],
        'stock'         => ['required' => false, 'aliases' => ['stock', 'quantity', 'qty', 'stock_quantity']],
        'reorder_level' => ['required' => false, 'aliases' => ['reorder_level', 'reorder', 'min_stock']],
        'status'        => ['required' => false, 'aliases' => ['status', 'active']],
    ];
}

/**
 * Validate every parsed row without writing anything.
 *
 * The rules here are deliberately the same ones admin/product_form.php
 * applies to a single product. A bulk importer that skips validation is
 * a hole straight through every rule the rest of the system enforces,
 * so the price bounds, the length limits and the category check are all
 * repeated rather than waived because the data "came from a file".
 *
 * @return array{rows: array, summary: array}
 */
function batch_validate_products(array $parsedRows, array $map, array $options): array
{
    $duplicateMode = $options['duplicates'] ?? 'skip';   // skip | update | error
    $createMissing = !empty($options['create_categories']);

    // Looked up once rather than per row.
    $categories = db_all('SELECT id, name FROM categories');
    $byName     = [];

    foreach ($categories as $c) {
        $byName[mb_strtolower($c['name'])] = (int)$c['id'];
    }

    $existing = [];
    foreach (db_all('SELECT id, name FROM products') as $p) {
        $existing[mb_strtolower($p['name'])] = (int)$p['id'];
    }

    // Names appearing twice inside the file itself, which the database
    // cannot warn about because neither row exists yet.
    $seenInFile = [];

    $rows    = [];
    $newCats = [];

    $summary = ['insert' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'new_categories' => []];

    foreach ($parsedRows as $parsed) {
        $fields = $parsed['fields'];
        $line   = $parsed['line'];

        $row = [
            'line'   => $line,
            'errors' => [],
            'action' => 'insert',
            'data'   => [],
        ];

        $name        = batch_cell($fields, $map, 'name');
        $categoryRaw = batch_cell($fields, $map, 'category');
        $price       = batch_cell($fields, $map, 'price');
        $description = batch_cell($fields, $map, 'description');
        $stock       = batch_cell($fields, $map, 'stock', '0');
        $reorder     = batch_cell($fields, $map, 'reorder_level', (string)STOCK_DEFAULT_REORDER_LEVEL);
        $status      = mb_strtolower(batch_cell($fields, $map, 'status', 'active'));

        // ---- Name ----
        if ($name === '') {
            $row['errors'][] = 'Name is required';
        } elseif (mb_strlen($name) > 150) {
            $row['errors'][] = 'Name is longer than 150 characters';
        }

        // ---- Price ----
        // Tolerate "RM1,299.00" and "1 299.00": a human-maintained
        // spreadsheet is full of these, and rejecting them all would
        // make the importer useless in practice.
        $priceClean = preg_replace('~[^0-9.\-]~', '', str_replace(',', '', $price));

        if ($price === '') {
            $row['errors'][] = 'Price is required';
        } elseif (!is_numeric($priceClean)) {
            $row['errors'][] = 'Price "' . $price . '" is not a number';
        } elseif ((float)$priceClean < 0.01 || (float)$priceClean > 999999.99) {
            $row['errors'][] = 'Price must be between 0.01 and 999999.99';
        }

        // ---- Stock ----
        $stockClean = $stock === '' ? '0' : preg_replace('~[^0-9\-]~', '', $stock);

        if (!ctype_digit($stockClean) || (int)$stockClean > 100000) {
            $row['errors'][] = 'Stock must be a whole number from 0 to 100000';
            $stockClean = '0';
        }

        $reorderClean = $reorder === '' ? (string)STOCK_DEFAULT_REORDER_LEVEL
                                        : preg_replace('~[^0-9]~', '', $reorder);

        if ($reorderClean === '' || (int)$reorderClean > 100000) {
            $reorderClean = (string)STOCK_DEFAULT_REORDER_LEVEL;
        }

        // ---- Status ----
        $status = match ($status) {
            'active', 'yes', 'y', '1', 'true'    => 'active',
            'inactive', 'no', 'n', '0', 'false'  => 'inactive',
            ''                                   => 'active',
            default                              => 'invalid',
        };

        if ($status === 'invalid') {
            $row['errors'][] = 'Status must be Active or Inactive';
            $status = 'active';
        }

        // ---- Description ----
        if (mb_strlen($description) > 2000) {
            $row['errors'][] = 'Description is longer than 2000 characters';
        }

        // ---- Category ----
        $categoryId = null;

        if ($categoryRaw === '') {
            $row['errors'][] = 'Category is required';
        } else {
            $key = mb_strtolower($categoryRaw);

            if (isset($byName[$key])) {
                $categoryId = $byName[$key];
            } elseif ($createMissing) {
                // Marked for creation now so two rows naming the same new
                // category do not queue it twice.
                $categoryId = null;
                if (!in_array($categoryRaw, $newCats, true)) {
                    $newCats[] = $categoryRaw;
                }
            } else {
                $row['errors'][] = 'Category "' . $categoryRaw . '" does not exist';
            }
        }

        // ---- Duplicates ----
        $key = mb_strtolower($name);

        if ($name !== '' && isset($seenInFile[$key])) {
            $row['errors'][] = 'Duplicated inside this file (also on line ' . $seenInFile[$key] . ')';
        } elseif ($name !== '') {
            $seenInFile[$key] = $line;
        }

        if ($name !== '' && isset($existing[$key])) {
            if ($duplicateMode === 'update') {
                $row['action']      = 'update';
                $row['existing_id'] = $existing[$key];
            } elseif ($duplicateMode === 'skip') {
                $row['action'] = 'skip';
            } else {
                $row['errors'][] = 'A product named "' . $name . '" already exists';
            }
        }

        $row['data'] = [
            'name'          => $name,
            'category_id'   => $categoryId,
            'category_name' => $categoryRaw,
            'description'   => $description !== '' ? $description : $name,
            'price'         => round((float)$priceClean, 2),
            'stock'         => (int)$stockClean,
            'reorder_level' => (int)$reorderClean,
            'status'        => $status,
        ];

        if ($row['errors'] !== []) {
            $row['action'] = 'error';
        }

        $summary[$row['action']]++;
        $rows[] = $row;
    }

    $summary['new_categories'] = $newCats;

    return ['rows' => $rows, 'summary' => $summary];
}

/**
 * Write a validated batch.
 *
 * $stopOnError decides the failure policy:
 *   true  - one bad row rolls the whole file back. The default, because
 *           a half-loaded price list is harder to recover from than an
 *           empty one: you cannot tell by looking which half landed.
 *   false - good rows are kept and bad ones reported.
 *
 * @return array{inserted:int, updated:int, skipped:int, failed:int, categories:int, errors:array}
 */
function batch_insert_products(array $rows, array $options, int $adminId): array
{
    $stopOnError = !empty($options['stop_on_error']);
    $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
               'categories' => 0, 'errors' => []];

    db()->beginTransaction();

    try {
        // Create any new categories first so the rows can point at them.
        $newCategoryIds = [];

        foreach ($options['new_categories'] ?? [] as $categoryName) {
            $existingId = db_value('SELECT id FROM categories WHERE name = ?', [$categoryName]);

            if ($existingId) {
                $newCategoryIds[mb_strtolower($categoryName)] = (int)$existingId;
                continue;
            }

            db_exec(
                'INSERT INTO categories (name, description) VALUES (?, ?)',
                [$categoryName, 'Created by batch import on ' . date('d M Y')]
            );

            $newCategoryIds[mb_strtolower($categoryName)] = (int)db_last_id();
            $result['categories']++;
        }

        foreach ($rows as $row) {
            if ($row['action'] === 'skip') {
                $result['skipped']++;
                continue;
            }

            if ($row['action'] === 'error') {
                $result['failed']++;
                $result['errors'][] = 'Line ' . $row['line'] . ': ' . implode('; ', $row['errors']);

                if ($stopOnError) {
                    throw new RuntimeException('Line ' . $row['line'] . ' failed validation.');
                }
                continue;
            }

            $data = $row['data'];

            if ($data['category_id'] === null) {
                $data['category_id'] = $newCategoryIds[mb_strtolower($data['category_name'])] ?? null;
            }

            if ($data['category_id'] === null) {
                $result['failed']++;
                $result['errors'][] = 'Line ' . $row['line'] . ': category could not be resolved.';

                if ($stopOnError) {
                    throw new RuntimeException('Line ' . $row['line'] . ' has no category.');
                }
                continue;
            }

            if ($row['action'] === 'update') {
                db_exec(
                    'UPDATE products
                        SET category_id = ?, description = ?, price = ?, status = ?
                      WHERE id = ?',
                    [$data['category_id'], $data['description'], $data['price'],
                     $data['status'], $row['existing_id']]
                );

                // Stock is deliberately NOT overwritten on update. The file
                // says what the supplier listed; the database knows what has
                // since been sold. Trusting the file here would silently undo
                // every sale and every stock movement recorded against it.
                $result['updated']++;
                continue;
            }

            db_exec(
                'INSERT INTO products (name, category_id, description, price, stock, status)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$data['name'], $data['category_id'], $data['description'],
                 $data['price'], $data['stock'], $data['status']]
            );

            $productId = (int)db_last_id();

            if (reorder_level_ready()) {
                db_exec('UPDATE products SET reorder_level = ? WHERE id = ?',
                        [$data['reorder_level'], $productId]);
            }

            // The stock ledger has to know where the opening quantity came
            // from, otherwise the reconciliation report on Admin -> Stock
            // starts reporting every imported product as a mismatch.
            if ($data['stock'] > 0) {
                record_stock_movement($productId, 'initial', $data['stock'],
                                      'Opening stock from batch import', null, $adminId);
            }

            $result['inserted']++;
        }

        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();

        $result['rolled_back'] = true;
        $result['message']     = $e->getMessage();
        $result['inserted']    = 0;
        $result['updated']     = 0;
        $result['categories']  = 0;
    }

    return $result;
}

// ============================================================
// BATCH UPDATING
// ============================================================

/** The operations the price tool offers. */
function batch_price_operations(): array
{
    return [
        'percent_up'   => 'Increase by percentage',
        'percent_down' => 'Decrease by percentage',
        'amount_up'    => 'Increase by fixed amount',
        'amount_down'  => 'Decrease by fixed amount',
        'set'          => 'Set all to a fixed price',
        'multiply'     => 'Multiply by a factor',
    ];
}

/** How the result is tidied up after the arithmetic. */
function batch_rounding_modes(): array
{
    return [
        'none'  => 'Exact value (2 decimals)',
        'whole' => 'Round to the nearest whole ringgit',
        'p99'   => 'End in .99 (charm pricing)',
        'p50'   => 'Round to the nearest 0.50',
    ];
}

/**
 * Work out the new price for one product.
 * Kept separate so it can be unit-checked on its own and so the preview
 * and the commit can never drift apart: both call this.
 */
function batch_new_price(float $old, string $operation, float $value, string $rounding): float
{
    $raw = match ($operation) {
        'percent_up'   => $old * (1 + $value / 100),
        'percent_down' => $old * (1 - $value / 100),
        'amount_up'    => $old + $value,
        'amount_down'  => $old - $value,
        'set'          => $value,
        'multiply'     => $old * $value,
        default        => $old,
    };

    $new = match ($rounding) {
        'whole' => round($raw),

        // Nearest X.99, not floor()+0.99.
        //
        // floor(95.00) + 0.99 gives 95.99, so "reduce by RM5 and end in
        // .99" would hand back a price HIGHER than the arithmetic asked
        // for and quietly eat part of the discount. Shifting by 0.99
        // before rounding picks the closest .99 on either side instead:
        // 95.00 -> 94.99, 95.60 -> 95.99.
        'p99'   => round($raw - 0.99) + 0.99,

        'p50'   => round($raw * 2) / 2,
        default => round($raw, 2),
    };

    // Rounding tidies a number; it must never reverse the change.
    //
    // On a cheap product the .99 grid is coarser than the adjustment
    // itself: RM1.98 less 5% is RM1.881, whose nearest .99 is RM1.99 --
    // an increase, from a discount. Whenever the tidied value lands on
    // the far side of the original price, the exact value is kept
    // instead. Better a price that does not end in .99 than a discount
    // that raises it.
    if (($raw < $old && $new > $old) || ($raw > $old && $new < $old)) {
        $new = round($raw, 2);
    }

    // The column is DECIMAL(10,2) and the single-product form enforces the
    // same window, so a bulk edit must not be a way around it. A discount
    // large enough to go negative clamps to the floor rather than writing
    // a nonsense price.
    return min(999999.99, max(0.01, round($new, 2)));
}

/**
 * Fetch the products a filter selects, with their proposed new price.
 *
 * @return array{rows: array, totals: array}
 */
function batch_price_preview(array $filter, string $operation, float $value, string $rounding): array
{
    $sql    = 'SELECT p.id, p.name, p.price, p.stock, p.status, c.name AS category_name
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                WHERE 1 = 1';
    $params = [];

    if (!empty($filter['category_id'])) {
        $sql     .= ' AND p.category_id = ?';
        $params[] = (int)$filter['category_id'];
    }

    if (!empty($filter['status']) && $filter['status'] !== 'all') {
        $sql     .= ' AND p.status = ?';
        $params[] = $filter['status'];
    }

    if (!empty($filter['q'])) {
        $sql     .= ' AND p.name LIKE ?';
        $params[] = '%' . $filter['q'] . '%';
    }

    if (isset($filter['price_min']) && $filter['price_min'] !== '') {
        $sql     .= ' AND p.price >= ?';
        $params[] = (float)$filter['price_min'];
    }

    if (isset($filter['price_max']) && $filter['price_max'] !== '') {
        $sql     .= ' AND p.price <= ?';
        $params[] = (float)$filter['price_max'];
    }

    $sql .= ' ORDER BY p.name ASC';

    $products = db_all($sql, $params);

    $rows   = [];
    $totals = ['count' => 0, 'old_total' => 0.0, 'new_total' => 0.0,
               'clamped' => 0, 'unchanged' => 0, 'stock_value_delta' => 0.0];

    foreach ($products as $p) {
        $old = (float)$p['price'];
        $new = batch_new_price($old, $operation, $value, $rounding);

        $rows[] = [
            'id'            => (int)$p['id'],
            'name'          => $p['name'],
            'category_name' => $p['category_name'],
            'status'        => $p['status'],
            'stock'         => (int)$p['stock'],
            'old'           => $old,
            'new'           => $new,
            'delta'         => round($new - $old, 2),
            'clamped'       => ($new <= 0.01 && $old > 0.01) || $new >= 999999.99,
        ];

        $totals['count']++;
        $totals['old_total'] += $old;
        $totals['new_total'] += $new;
        $totals['stock_value_delta'] += ($new - $old) * (int)$p['stock'];

        if (abs($new - $old) < 0.005) {
            $totals['unchanged']++;
        }

        if (($new <= 0.01 && $old > 0.01) || $new >= 999999.99) {
            $totals['clamped']++;
        }
    }

    $totals['old_total']         = round($totals['old_total'], 2);
    $totals['new_total']         = round($totals['new_total'], 2);
    $totals['stock_value_delta'] = round($totals['stock_value_delta'], 2);

    return ['rows' => $rows, 'totals' => $totals];
}

/**
 * Apply a staged price change.
 *
 * The new price is taken from the staged preview rather than recomputed,
 * so what the admin approved is exactly what is written even if someone
 * edited a product in another tab in between. Each row carries the price
 * it was previewed against, and a row whose price has moved since is
 * skipped rather than overwritten.
 *
 * @return array{updated:int, skipped:int, conflicts:array}
 */
function batch_apply_prices(array $rows, int $adminId): array
{
    $result = ['updated' => 0, 'skipped' => 0, 'conflicts' => []];

    db()->beginTransaction();

    try {
        foreach ($rows as $row) {
            // Optimistic concurrency: the WHERE clause carries the price the
            // admin was shown. If another edit changed it, zero rows match
            // and this product is left alone instead of being clobbered.
            $affected = db_exec(
                'UPDATE products SET price = ? WHERE id = ? AND price = ?',
                [$row['new'], $row['id'], $row['old']]
            );

            if ($affected === 1) {
                $result['updated']++;
            } else {
                $result['skipped']++;
                $result['conflicts'][] = $row['name'];
            }
        }

        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        $result['rolled_back'] = true;
        $result['message']     = $e->getMessage();
        $result['updated']     = 0;
    }

    return $result;
}

// ============================================================
// BATCH DELETION
// ============================================================

/**
 * Report what deleting each product would drag with it.
 *
 * The order count is the one that matters. member order history renders
 * its lines with an INNER JOIN onto products, so removing a product that
 * somebody has bought does not just lose the product: those lines vanish
 * from the customer's past orders and the order total no longer adds up
 * against the items shown. That is silent corruption of records the
 * business is required to keep, so this function exists to make the
 * distinction visible before anything is deleted.
 *
 * @return array{rows: array, safe: array, blocked: array}
 */
function batch_delete_analysis(array $ids): array
{
    if ($ids === []) {
        return ['rows' => [], 'safe' => [], 'blocked' => []];
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));

    $products = db_all(
        "SELECT p.id, p.name, p.price, p.stock, p.status, p.image, c.name AS category_name
           FROM products p
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.id IN ($marks)
          ORDER BY p.name ASC",
        $ids
    );

    $rows = $safe = $blocked = [];

    foreach ($products as $p) {
        $id = (int)$p['id'];

        $orderCount = (int)db_value(
            'SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id]
        );

        $row = [
            'id'            => $id,
            'name'          => $p['name'],
            'category_name' => $p['category_name'],
            'price'         => (float)$p['price'],
            'stock'         => (int)$p['stock'],
            'status'        => $p['status'],
            'orders'        => $orderCount,
            'cart'          => (int)db_value('SELECT COUNT(*) FROM cart WHERE product_id = ?', [$id]),
            'wishlist'      => db_table_exists('wishlist')
                ? (int)db_value('SELECT COUNT(*) FROM wishlist WHERE product_id = ?', [$id]) : 0,
            'reviews'       => db_table_exists('reviews')
                ? (int)db_value('SELECT COUNT(*) FROM reviews WHERE product_id = ?', [$id]) : 0,
            'photos'        => db_table_exists('product_photos')
                ? (int)db_value('SELECT COUNT(*) FROM product_photos WHERE product_id = ?', [$id]) : 0,
            'movements'     => db_table_exists('stock_movements')
                ? (int)db_value('SELECT COUNT(*) FROM stock_movements WHERE product_id = ?', [$id]) : 0,
        ];

        $row['deletable'] = $orderCount === 0;

        $rows[] = $row;

        if ($row['deletable']) {
            $safe[] = $id;
        } else {
            $blocked[] = $id;
        }
    }

    return ['rows' => $rows, 'safe' => $safe, 'blocked' => $blocked];
}

/**
 * Deactivate products. The recommended action for anything with order
 * history: the record survives, the storefront stops offering it.
 */
function batch_deactivate_products(array $ids): int
{
    if ($ids === []) {
        return 0;
    }

    $marks = implode(',', array_fill(0, count($ids), '?'));

    return db_exec("UPDATE products SET status = 'inactive' WHERE id IN ($marks)", $ids);
}

/**
 * Permanently remove products and everything hanging off them.
 *
 * Refuses any product with order history no matter what was requested,
 * because that check is the point of the module and a caller should not
 * be able to talk it out of it.
 *
 * @return array{deleted:int, refused:int, files:int, refused_names:array}
 */
function batch_delete_products(array $ids, int $adminId): array
{
    $result = ['deleted' => 0, 'refused' => 0, 'files' => 0, 'refused_names' => []];

    if ($ids === []) {
        return $result;
    }

    $analysis = batch_delete_analysis($ids);

    foreach ($analysis['rows'] as $row) {
        if (!$row['deletable']) {
            $result['refused']++;
            $result['refused_names'][] = $row['name'];
        }
    }

    $deletable = $analysis['safe'];

    if ($deletable === []) {
        return $result;
    }

    // Collected before the rows go, so the files can be removed after the
    // transaction commits. Deleting files inside the transaction would
    // leave them gone but the rows restored if the commit failed.
    $files = [];

    foreach ($deletable as $id) {
        if (db_table_exists('product_photos')) {
            foreach (db_all('SELECT filename, original_filename FROM product_photos WHERE product_id = ?', [$id]) as $ph) {
                $files[] = $ph['filename'];

                if (!empty($ph['original_filename'])) {
                    $files[] = $ph['original_filename'];
                }
            }
        }

        $legacy = db_value('SELECT image FROM products WHERE id = ?', [$id]);

        if ($legacy) {
            $files[] = $legacy;
        }
    }

    $marks = implode(',', array_fill(0, count($deletable), '?'));

    db()->beginTransaction();

    try {
        // Children first. Several of these have ON DELETE CASCADE, but the
        // explicit deletes keep the behaviour identical on a schema where a
        // constraint was never created, which is the situation on any copy
        // of this project that skipped a migration.
        foreach (['cart', 'wishlist', 'reviews', 'product_photos', 'stock_movements'] as $table) {
            if (db_table_exists($table)) {
                db_exec("DELETE FROM $table WHERE product_id IN ($marks)", $deletable);
            }
        }

        $result['deleted'] = db_exec("DELETE FROM products WHERE id IN ($marks)", $deletable);

        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        $result['rolled_back'] = true;
        $result['message']     = $e->getMessage();
        $result['deleted']     = 0;

        return $result;
    }

    // Only now that the rows are certainly gone.
    foreach (array_unique($files) as $file) {
        if ($file === '' || $file === null) {
            continue;
        }

        // A photo edit can leave two rows pointing at one file, so a file
        // still referenced elsewhere must stay.
        if (function_exists('photo_file_in_use') && photo_file_in_use($file)) {
            continue;
        }

        $path = DIR_UPLOAD_PRODUCTS . $file;

        if (is_file($path) && @unlink($path)) {
            $result['files']++;
        }
    }

    return $result;
}
