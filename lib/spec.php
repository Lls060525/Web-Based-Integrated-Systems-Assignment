<?php
// ============================================================
// lib/spec.php
// Product specifications.
//
// Specs are DATA, not schema. A phone needs RAM, storage, screen and
// battery; a cable needs length, wattage and connector; a watch needs
// strap size and a water rating. Putting all of those in the products
// table means most columns are NULL for most rows, and every new spec
// is an ALTER TABLE.
//
// So there are two tables: spec_attributes says what a spec IS, and
// product_specs holds one value per product per attribute. That shape
// is called EAV, and its well-known weakness is that the database can
// no longer type-check anything. Two things push back on that here:
//
//   1. Every attribute carries a data_type, and PHP validates against
//      it on the way in.
//   2. Numeric values are stored a second time in a DECIMAL column, so
//      range filters and sorting use an index instead of casting text.
// ============================================================

/** True once the migration has been run. */
function spec_module_ready(): bool
{
    return db_table_exists('spec_attributes') && db_table_exists('product_specs');
}

/** True once migration_22 has made the display style explicit. */
function spec_render_ready(): bool
{
    return spec_options_ready()
        && db_column_exists('spec_attributes', 'render_style');
}

/** How a selectable spec is drawn on the storefront. */
function spec_render_styles(): array
{
    return [
        'tile'   => 'Tiles - name and price difference (storage, warranty)',
        'swatch' => 'Colour swatches - a circle of colour (colour only)',
    ];
}

/** True once migration_21 has added swatches, option photos and availability. */
function spec_style_ready(): bool
{
    return spec_options_ready()
        && db_column_exists('product_spec_options', 'swatch_hex');
}

/** True once migration_20 has added product scoping and options. */
function spec_options_ready(): bool
{
    return spec_module_ready()
        && db_table_exists('product_spec_options')
        && db_column_exists('spec_attributes', 'product_id');
}

/** The types an attribute can have, and how each is described. */
function spec_data_types(): array
{
    return [
        'text'    => 'Text (free form)',
        'number'  => 'Number (filterable and sortable)',
        'boolean' => 'Yes / No',
        'enum'    => 'Choice from a fixed list',
    ];
}

// ------------------------------------------------------------
// Attribute definitions
// ------------------------------------------------------------

/**
 * Attributes that apply to a category.
 *
 * Includes the global ones (category_id IS NULL), because something
 * like "Warranty" belongs on everything and should not have to be
 * created once per category.
 */
function spec_attributes_for_category(?int $categoryId): array
{
    if (!spec_module_ready()) {
        return [];
    }

    // Before migration_20 there is no product scoping, so global means
    // "category_id IS NULL" and nothing else.
    $productClause = spec_options_ready() ? ' AND product_id IS NULL' : '';

    return db_all(
        "SELECT * FROM spec_attributes
          WHERE (category_id IS NULL OR category_id = ?)$productClause
          ORDER BY sort_order ASC, name ASC",
        [$categoryId]
    );
}

/**
 * Every attribute that applies to one product.
 *
 * Three scopes, widest first:
 *   category_id IS NULL and product_id IS NULL  -> the whole shop
 *   category_id = this product's category       -> the category
 *   product_id  = this product                  -> this product alone
 *
 * The third is what makes "just let me add one" possible without
 * every other product in the category growing an empty field.
 */
function spec_attributes_for_product(array $product): array
{
    if (!spec_module_ready()) {
        return [];
    }

    $categoryId = $product['category_id'] === null ? null : (int)$product['category_id'];

    if (!spec_options_ready()) {
        return spec_attributes_for_category($categoryId);
    }

    return db_all(
        'SELECT * FROM spec_attributes
          WHERE product_id = ?
             OR (product_id IS NULL AND (category_id IS NULL OR category_id = ?))
          ORDER BY product_id IS NULL DESC, sort_order ASC, name ASC',
        [(int)$product['id'], $categoryId]
    );
}

/** How wide an attribute reaches, for display. */
function spec_scope_label(array $attribute): string
{
    if (!empty($attribute['product_id'])) {
        return 'This product only';
    }

    return $attribute['category_id'] === null ? 'All categories' : 'Category';
}

/**
 * Create an attribute that belongs to one product only.
 * Returns the new attribute id.
 */
function create_product_attribute(int $productId, string $name, string $dataType,
                                  string $unit = '', bool $selectable = false,
                                  string $renderStyle = 'tile'): int
{
    $code = spec_slug($name);

    // The code only has to be unique within this product, because that
    // is the whole scope of the attribute.
    $existing = db_one(
        'SELECT id FROM spec_attributes WHERE product_id = ? AND code = ?',
        [$productId, $code]
    );

    if ($existing) {
        return (int)$existing['id'];
    }

    $nextOrder = (int)db_value(
        'SELECT COALESCE(MAX(sort_order), 500) + 10 FROM spec_attributes WHERE product_id = ?',
        [$productId]
    );

    // Colour is put first, because every phone site asks you to pick the
    // finish before the storage: the colour changes the picture, so seeing
    // the thing you are buying comes before configuring it.
    if ($renderStyle === 'swatch') {
        $nextOrder = 5;
    }

    $fields = [
        'product_id'    => $productId,
        'category_id'   => null,
        'name'          => $name,
        'code'          => $code,
        'data_type'     => $dataType,
        'unit'          => $unit !== '' ? $unit : null,
        'is_filterable' => 0,
        'is_comparable' => 1,
        'is_selectable' => $selectable ? 1 : 0,
        'sort_order'    => $nextOrder,
    ];

    if (spec_render_ready()) {
        $fields['render_style'] = $renderStyle === 'swatch' ? 'swatch' : 'tile';
    }

    $columns = array_keys($fields);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    db_exec(
        'INSERT INTO spec_attributes (' . implode(', ', $columns) . ") VALUES ($marks)",
        array_values($fields)
    );

    return (int)db_last_id();
}

/** Every attribute, with its category name, for the admin listing. */
function all_spec_attributes(?int $categoryId = null): array
{
    if (!spec_module_ready()) {
        return [];
    }

    $sql = 'SELECT sa.*, c.name AS category_name,
                   (SELECT COUNT(*) FROM product_specs ps WHERE ps.attribute_id = sa.id) AS value_count
              FROM spec_attributes sa
              LEFT JOIN categories c ON c.id = sa.category_id';
    $params = [];

    if ($categoryId !== null) {
        $sql     .= ' WHERE sa.category_id = ?';
        $params[] = $categoryId;
    }

    $sql .= ' ORDER BY c.name IS NULL DESC, c.name ASC, sa.sort_order ASC, sa.name ASC';

    return db_all($sql, $params);
}

function find_spec_attribute(int $id): ?array
{
    $row = db_one('SELECT * FROM spec_attributes WHERE id = ?', [$id]);

    return $row ?: null;
}

/**
 * Turn a display name into a code.
 * The code is what stays stable when somebody renames "RAM" to
 * "Memory (RAM)", so filter links in the wild keep working.
 */
function spec_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('~[^a-z0-9]+~', '_', $slug);

    return trim((string)$slug, '_');
}

/** The choices for an enum attribute, as an array. */
function spec_options(array $attribute): array
{
    if ($attribute['data_type'] !== 'enum' || empty($attribute['options'])) {
        return [];
    }

    $options = array_map('trim', explode(',', (string)$attribute['options']));

    return array_values(array_filter($options, static fn($o) => $o !== ''));
}

// ------------------------------------------------------------
// Values
// ------------------------------------------------------------

/**
 * Normalise a submitted value for storage.
 *
 * Returns the pair that goes into the two columns. value_number is only
 * populated for numeric attributes; leaving it NULL elsewhere is what
 * keeps the numeric index meaningful.
 *
 * @return array{text: string, number: ?float, error: ?string}
 */
function spec_normalise_value(array $attribute, string $raw): array
{
    $raw = trim($raw);

    if ($raw === '') {
        return ['text' => '', 'number' => null, 'error' => null];
    }

    switch ($attribute['data_type']) {

        case 'number':
            // "8 GB", "6,000 mAh" and "6.1in" all arrive from humans.
            $clean = str_replace(',', '', $raw);
            $clean = preg_replace('~[^0-9.\-]~', '', $clean);

            if ($clean === '' || !is_numeric($clean)) {
                return ['text' => $raw, 'number' => null,
                        'error' => $attribute['name'] . ' must be a number.'];
            }

            $number = (float)$clean;

            // Stored WITHOUT the unit. The unit belongs to the attribute,
            // so keeping it out of the value means "8" and "8 GB" cannot
            // both exist and fail to match each other in a filter.
            $text = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

            return ['text' => $text === '' ? '0' : $text, 'number' => $number, 'error' => null];

        case 'boolean':
            $yes = in_array(strtolower($raw), ['1', 'yes', 'y', 'true', 'on'], true);

            return ['text' => $yes ? 'Yes' : 'No', 'number' => $yes ? 1.0 : 0.0, 'error' => null];

        case 'enum':
            $options = spec_options($attribute);

            foreach ($options as $option) {
                if (strcasecmp($option, $raw) === 0) {
                    return ['text' => $option, 'number' => null, 'error' => null];
                }
            }

            return ['text' => $raw, 'number' => null,
                    'error' => $attribute['name'] . ' must be one of: ' . implode(', ', $options)];

        default:
            if (mb_strlen($raw) > 255) {
                return ['text' => mb_substr($raw, 0, 255), 'number' => null,
                        'error' => $attribute['name'] . ' is longer than 255 characters.'];
            }

            return ['text' => $raw, 'number' => null, 'error' => null];
    }
}

/**
 * Write one value.
 *
 * Both columns are always written together here. That is the whole
 * defence against the duplicated value drifting apart: there is exactly
 * one function in the project that touches product_specs.
 */
function save_product_spec(int $productId, array $attribute, string $raw): void
{
    $value = spec_normalise_value($attribute, $raw);

    // An emptied field removes the row rather than storing "". A missing
    // spec and a spec that is blank are the same thing to a reader, and
    // one of them makes the comparison table harder to read.
    if ($value['text'] === '') {
        db_exec('DELETE FROM product_specs WHERE product_id = ? AND attribute_id = ?',
                [$productId, $attribute['id']]);
        return;
    }

    db_exec(
        'INSERT INTO product_specs (product_id, attribute_id, value_text, value_number)
              VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE value_text = VALUES(value_text),
                                 value_number = VALUES(value_number)',
        [$productId, $attribute['id'], $value['text'], $value['number']]
    );
}

/**
 * Every spec of one product, ready to display.
 * Attributes with no value are left out.
 */
function product_specs(int $productId): array
{
    if (!spec_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT ps.value_text, ps.value_number,
                sa.id AS attribute_id, sa.name, sa.code, sa.unit, sa.data_type, sa.sort_order
           FROM product_specs ps
           JOIN spec_attributes sa ON sa.id = ps.attribute_id
          WHERE ps.product_id = ?
          ORDER BY sa.sort_order ASC, sa.name ASC',
        [$productId]
    );
}

/** Values keyed by attribute id, for prefilling the admin form. */
function product_spec_map(int $productId): array
{
    $map = [];

    foreach (product_specs($productId) as $spec) {
        $map[(int)$spec['attribute_id']] = $spec['value_text'];
    }

    return $map;
}

/** "6.1 inch", "Yes", "Snapdragon 8 Gen 3". */
function spec_display(array $spec): string
{
    $text = (string)$spec['value_text'];

    if (!empty($spec['unit'])) {
        return $text . ' ' . $spec['unit'];
    }

    return $text;
}

/** How many products have at least one spec recorded. */
function spec_coverage(): array
{
    if (!spec_module_ready()) {
        return ['with' => 0, 'total' => 0];
    }

    return [
        'with'  => (int)db_value('SELECT COUNT(DISTINCT product_id) FROM product_specs'),
        'total' => (int)db_value('SELECT COUNT(*) FROM products'),
    ];
}

// ------------------------------------------------------------
// Filtering
// ------------------------------------------------------------

/**
 * Filterable attributes for a category, each with the values products
 * actually have. An option nobody's stock matches is not offered,
 * because a filter that always returns nothing is worse than no filter.
 */
function spec_filter_options(?int $categoryId): array
{
    if (!spec_module_ready()) {
        return [];
    }

    $attributes = db_all(
        'SELECT * FROM spec_attributes
          WHERE is_filterable = 1 AND (category_id IS NULL OR category_id = ?)
          ORDER BY sort_order ASC, name ASC',
        [$categoryId]
    );

    $out = [];

    foreach ($attributes as $attribute) {
        if ($attribute['data_type'] === 'number') {
            $range = db_one(
                "SELECT MIN(ps.value_number) AS min_value, MAX(ps.value_number) AS max_value
                   FROM product_specs ps
                   JOIN products p ON p.id = ps.product_id
                  WHERE ps.attribute_id = ? AND p.status = 'active'",
                [$attribute['id']]
            );

            if ($range === false || $range['min_value'] === null) {
                continue;
            }

            $attribute['range'] = [
                'min' => (float)$range['min_value'],
                'max' => (float)$range['max_value'],
            ];

            // Nothing to choose between when every product is identical.
            if ($attribute['range']['min'] >= $attribute['range']['max']) {
                continue;
            }

        } else {
            $values = db_all(
                "SELECT ps.value_text, COUNT(*) AS product_count
                   FROM product_specs ps
                   JOIN products p ON p.id = ps.product_id
                  WHERE ps.attribute_id = ? AND p.status = 'active'
                  GROUP BY ps.value_text
                  ORDER BY product_count DESC, ps.value_text ASC",
                [$attribute['id']]
            );

            if (count($values) < 2) {
                continue;
            }

            $attribute['values'] = $values;
        }

        $out[] = $attribute;
    }

    return $out;
}

/**
 * Build the SQL fragment for the spec filters in the query string.
 *
 * One EXISTS subquery per active filter. EXISTS rather than a JOIN
 * because two filters on two different attributes would otherwise need
 * two joins onto the same table and produce duplicate rows that a
 * DISTINCT then has to clean up.
 *
 * The numeric comparison hits value_number, which is indexed. Casting
 * value_text instead would defeat the index and, worse, compare "12"
 * as less than "8" because that is how strings sort.
 *
 * @return array{sql: string, params: array, active: array}
 */
function spec_filter_sql(array $query): array
{
    if (!spec_module_ready()) {
        return ['sql' => '', 'params' => [], 'active' => []];
    }

    $sql    = '';
    $params = [];
    $active = [];

    foreach ($query as $key => $value) {
        if (!str_starts_with($key, 'spec_') || $value === '' || is_array($value)) {
            continue;
        }

        $parts = explode('_', substr($key, 5));
        $mode  = array_pop($parts);          // eq | min | max
        $id    = (int)implode('_', $parts);

        if ($id <= 0 || !in_array($mode, ['eq', 'min', 'max'], true)) {
            continue;
        }

        if ($mode === 'eq') {
            $sql     .= ' AND EXISTS (SELECT 1 FROM product_specs sf
                                       WHERE sf.product_id = p.id
                                         AND sf.attribute_id = ?
                                         AND sf.value_text = ?)';
            $params[] = $id;
            $params[] = $value;

        } else {
            if (!is_numeric($value)) {
                continue;
            }

            $operator = $mode === 'min' ? '>=' : '<=';

            $sql     .= " AND EXISTS (SELECT 1 FROM product_specs sf
                                       WHERE sf.product_id = p.id
                                         AND sf.attribute_id = ?
                                         AND sf.value_number $operator ?)";
            $params[] = $id;
            $params[] = (float)$value;
        }

        $active[$key] = $value;
    }

    return ['sql' => $sql, 'params' => $params, 'active' => $active];
}

// ------------------------------------------------------------
// Comparison
// ------------------------------------------------------------

/**
 * Build the comparison table for a set of products.
 *
 * Rows where every product says the same thing are flagged, so the page
 * can offer to hide them. On phones from one brand most rows are
 * identical and the two that differ are the whole reason somebody
 * opened the page.
 *
 * @return array{products: array, rows: array}
 */
function spec_comparison(array $productIds): array
{
    if (!spec_module_ready() || $productIds === []) {
        return ['products' => [], 'rows' => []];
    }

    $marks = implode(',', array_fill(0, count($productIds), '?'));

    $products = db_all(
        "SELECT p.*, c.name AS category_name
           FROM products p
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE p.id IN ($marks)",
        $productIds
    );

    // Kept in the order the user asked for, not the order MySQL returned.
    $byId = [];
    foreach ($products as $product) {
        $byId[(int)$product['id']] = $product;
    }

    $ordered = [];
    foreach ($productIds as $id) {
        if (isset($byId[$id])) {
            $ordered[] = $byId[$id];
        }
    }

    if ($ordered === []) {
        return ['products' => [], 'rows' => []];
    }

    $ids   = array_column($ordered, 'id');
    $marks = implode(',', array_fill(0, count($ids), '?'));

    $rows = db_all(
        "SELECT ps.product_id, ps.value_text,
                sa.id AS attribute_id, sa.name, sa.unit, sa.sort_order, sa.data_type
           FROM product_specs ps
           JOIN spec_attributes sa ON sa.id = ps.attribute_id
          WHERE ps.product_id IN ($marks) AND sa.is_comparable = 1
          ORDER BY sa.sort_order ASC, sa.name ASC",
        $ids
    );

    $table = [];

    foreach ($rows as $row) {
        $attributeId = (int)$row['attribute_id'];

        if (!isset($table[$attributeId])) {
            $table[$attributeId] = [
                'name'   => $row['name'],
                'unit'   => $row['unit'],
                'values' => [],
            ];
        }

        $table[$attributeId]['values'][(int)$row['product_id']] = $row['value_text'];
    }

    // Mark which rows actually differ.
    foreach ($table as $attributeId => $row) {
        $seen = [];

        foreach ($ids as $id) {
            $seen[] = $row['values'][(int)$id] ?? null;
        }

        // SORT_STRING, not SORT_REGULAR. A product with no value for this
        // attribute contributes null, and SORT_REGULAR's loose comparison
        // of null against a string is exactly the kind of edge case that
        // makes "identical" quietly wrong. Casting to string first means a
        // missing value reliably counts as a difference.
        $table[$attributeId]['same'] = count(array_unique($seen, SORT_STRING)) === 1;
    }

    return ['products' => $ordered, 'rows' => $table];
}

/** Products worth comparing against this one: same category, active. */
function spec_comparable_products(array $product, int $limit = 8): array
{
    return db_all(
        "SELECT id, name, price FROM products
          WHERE category_id = ? AND id <> ? AND status = 'active'
          ORDER BY name ASC
          LIMIT " . (int)$limit,
        [$product['category_id'], $product['id']]
    );
}

// ============================================================
// CUSTOMER-SELECTABLE SPECS (variants)
// ============================================================
//
// A selectable attribute is one the customer picks at purchase time:
// colour, storage size, warranty length. The CHOICES belong to the
// product, not to the attribute, because "Colour" means black and white
// on one phone and blue and green on another.
//
// The selection travels as a SIGNATURE: a canonical string built from
// the chosen options, used both to tell cart lines apart and to look
// the choices back up.

/** Selectable attributes for a product that actually have choices defined. */
function product_selectable_specs(int $productId): array
{
    if (!spec_options_ready()) {
        return [];
    }

    $attributes = db_all(
        'SELECT DISTINCT sa.*
           FROM spec_attributes sa
           JOIN product_spec_options o ON o.attribute_id = sa.id AND o.product_id = ?
          WHERE sa.is_selectable = 1
          ORDER BY sa.sort_order ASC, sa.name ASC',
        [$productId]
    );

    foreach ($attributes as $index => $attribute) {
        $attributes[$index]['choices'] = db_all(
            'SELECT * FROM product_spec_options
              WHERE product_id = ? AND attribute_id = ?
              ORDER BY sort_order ASC, id ASC',
            [$productId, $attribute['id']]
        );

        // Read from the attribute, not inferred from its options.
        //
        // The first version guessed: any option carrying a colour turned
        // the whole group into swatches. That was wrong, because an
        // <input type="color"> posts #000000 even when it was never
        // touched, so a RAM option quietly acquired a black swatch and
        // RAM started rendering as colour circles. How a spec is drawn
        // is a property of the spec, not something to reverse-engineer
        // from its data.
        $attributes[$index]['is_swatch'] = spec_render_ready()
            && ($attribute['render_style'] ?? 'tile') === 'swatch';
    }

    return $attributes;
}

/** Every option row a product has, for the admin editor. */
function product_options(int $productId, ?int $attributeId = null): array
{
    if (!spec_options_ready()) {
        return [];
    }

    $sql    = 'SELECT o.*, sa.name AS attribute_name, sa.unit
                 FROM product_spec_options o
                 JOIN spec_attributes sa ON sa.id = o.attribute_id
                WHERE o.product_id = ?';
    $params = [$productId];

    if ($attributeId !== null) {
        $sql     .= ' AND o.attribute_id = ?';
        $params[] = $attributeId;
    }

    $sql .= ' ORDER BY sa.sort_order ASC, o.sort_order ASC, o.id ASC';

    return db_all($sql, $params);
}

/**
 * Build the canonical signature for a selection.
 *
 * Sorted by attribute id, always. "Black + 256GB" and "256GB + Black"
 * are the same product; without sorting they would produce two
 * different strings, so the cart would show two identical lines whose
 * quantities never merge.
 *
 * @param array $selection attributeId => value
 */
function spec_signature(array $selection): string
{
    $clean = [];

    foreach ($selection as $attributeId => $value) {
        $attributeId = (int)$attributeId;
        $value       = trim((string)$value);

        if ($attributeId > 0 && $value !== '') {
            $clean[$attributeId] = $value;
        }
    }

    ksort($clean, SORT_NUMERIC);

    $parts = [];

    foreach ($clean as $attributeId => $value) {
        // The separators are stripped from the value so a colour called
        // "Red|Blue" cannot forge an extra pair into the signature.
        $parts[] = $attributeId . ':' . str_replace(['|', ':'], ' ', $value);
    }

    return implode('|', $parts);
}

/**
 * Read a signature back into attributeId => value.
 *
 * @return array<int, string>
 */
function spec_parse_signature(string $signature): array
{
    $selection = [];

    if (trim($signature) === '') {
        return $selection;
    }

    foreach (explode('|', $signature) as $pair) {
        $cut = strpos($pair, ':');

        if ($cut === false) {
            continue;
        }

        $attributeId = (int)substr($pair, 0, $cut);
        $value       = substr($pair, $cut + 1);

        if ($attributeId > 0 && $value !== '') {
            $selection[$attributeId] = $value;
        }
    }

    return $selection;
}

/**
 * Validate a selection against what the product actually offers.
 *
 * Never trusts the posted values. A form can be edited, so every choice
 * is checked against product_spec_options, and a product that offers a
 * choice requires one to be made.
 *
 * @return array{ok: bool, signature: string, label: string, delta: float, error: string}
 */
function spec_validate_selection(int $productId, array $posted): array
{
    $result = ['ok' => true, 'signature' => '', 'label' => '', 'delta' => 0.0, 'error' => ''];

    $attributes = product_selectable_specs($productId);

    if ($attributes === []) {
        return $result;
    }

    $selection = [];
    $labels    = [];
    $delta     = 0.0;

    foreach ($attributes as $attribute) {
        $attributeId = (int)$attribute['id'];
        $chosen      = trim((string)($posted[$attributeId] ?? ''));

        if ($chosen === '') {
            return ['ok' => false, 'signature' => '', 'label' => '', 'delta' => 0.0,
                    'error' => 'Please choose a ' . $attribute['name'] . '.'];
        }

        $match = null;

        foreach ($attribute['choices'] as $choice) {
            if ($choice['value_text'] === $chosen) {
                $match = $choice;
                break;
            }
        }

        if ($match === null) {
            return ['ok' => false, 'signature' => '', 'label' => '', 'delta' => 0.0,
                    'error' => '"' . $chosen . '" is not an available ' . $attribute['name'] . '.'];
        }

        // Greying a pill out in the browser is a hint, not a control.
        if (spec_style_ready() && (int)($match['is_available'] ?? 1) === 0) {
            return ['ok' => false, 'signature' => '', 'label' => '', 'delta' => 0.0,
                    'error' => $attribute['name'] . ' "' . $chosen . '" is sold out at the moment.'];
        }

        $selection[$attributeId] = $match['value_text'];
        $labels[]                = $attribute['name'] . ': ' . $match['value_text'];
        $delta                  += (float)$match['price_delta'];
    }

    $result['signature'] = spec_signature($selection);
    $result['label']     = implode(', ', $labels);
    $result['delta']     = round($delta, 2);

    return $result;
}

/**
 * Turn a stored signature into a readable label and a price adjustment.
 *
 * Recomputed from the current options rather than cached on the cart
 * line, so an admin editing a price delta is reflected before checkout
 * instead of after. Orders snapshot the result at purchase time; carts
 * deliberately do not.
 *
 * @return array{label: string, delta: float, valid: bool}
 */
function spec_describe_signature(int $productId, string $signature): array
{
    $out = ['label' => '', 'delta' => 0.0, 'valid' => true];

    $selection = spec_parse_signature($signature);

    if ($selection === []) {
        return $out;
    }

    if (!spec_options_ready()) {
        return ['label' => '', 'delta' => 0.0, 'valid' => false];
    }

    $labels = [];
    $delta  = 0.0;

    foreach ($selection as $attributeId => $value) {
        $row = db_one(
            'SELECT o.price_delta, o.value_text, sa.name
               FROM product_spec_options o
               JOIN spec_attributes sa ON sa.id = o.attribute_id
              WHERE o.product_id = ? AND o.attribute_id = ? AND o.value_text = ?',
            [$productId, $attributeId, $value]
        );

        if (!$row) {
            // The option was deleted after it went into the cart. The
            // line is flagged rather than silently repriced.
            $out['valid'] = false;
            $labels[]     = $value;
            continue;
        }

        $labels[] = $row['name'] . ': ' . $row['value_text'];
        $delta   += (float)$row['price_delta'];
    }

    $out['label'] = implode(', ', $labels);
    $out['delta'] = round($delta, 2);

    return $out;
}

/** Cheapest total adjustment, so the product card can show a "from" price. */
function spec_min_delta(int $productId): float
{
    if (!spec_options_ready()) {
        return 0.0;
    }

    $rows = db_all(
        'SELECT attribute_id, MIN(price_delta) AS min_delta
           FROM product_spec_options
          WHERE product_id = ?
          GROUP BY attribute_id',
        [$productId]
    );

    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float)$row['min_delta'];
    }

    return round($total, 2);
}
