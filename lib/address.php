<?php
// ============================================================
// lib/address.php
// Shipping address book: lookup, validation, default handling
// and the text snapshot written onto an order.
// ============================================================

/** True once the addresses table exists (see the migration). */
function address_module_ready(): bool
{
    return db_table_exists('addresses');
}

// ------------------------------------------------------------
// Reading
// ------------------------------------------------------------

/** Every saved address for a member, default first. */
function user_addresses(int $userId): array
{
    if (!address_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT * FROM addresses
          WHERE user_id = ?
          ORDER BY is_default DESC, id ASC',
        [$userId]
    );
}

/**
 * One address, but only if it belongs to this member.
 * Ownership is part of the WHERE clause, never an afterthought.
 */
function find_user_address(int $addressId, int $userId): ?array
{
    if (!address_module_ready()) {
        return null;
    }

    return db_one('SELECT * FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]) ?: null;
}

/** The member's default address, or their first one, or null. */
function default_address(int $userId): ?array
{
    $all = user_addresses($userId);
    return $all[0] ?? null;
}

/** How many addresses a member has saved. */
function address_count(int $userId): int
{
    if (!address_module_ready()) {
        return 0;
    }
    return (int)db_value('SELECT COUNT(*) FROM addresses WHERE user_id = ?', [$userId]);
}

// ------------------------------------------------------------
// Formatting
// ------------------------------------------------------------

/**
 * The address as the multi-line text stored on an order.
 * This snapshot is what keeps order history correct when the
 * member later edits or deletes the saved address.
 */
function format_address(array $a): string
{
    $lines = [];

    $lines[] = $a['recipient_name'] . ' (' . $a['phone'] . ')';
    $lines[] = $a['line1'];

    if (!empty($a['line2'])) {
        $lines[] = $a['line2'];
    }

    $lines[] = $a['postcode'] . ' ' . $a['city'];
    $lines[] = $a['state'];
    $lines[] = $a['country'];

    return implode("\n", $lines);
}

/** A compact one-line version, for dropdowns and summaries. */
function format_address_short(array $a): string
{
    return $a['line1'] . ', ' . $a['postcode'] . ' ' . $a['city'] . ', ' . $a['state'];
}

// ------------------------------------------------------------
// Validation
// ------------------------------------------------------------

/**
 * Validate a posted address form.
 * Errors are recorded against the field names via add_err().
 *
 * @return array the cleaned values, safe to write once no_err() is true
 */
function validate_address_input(): array
{
    $data = [
        'label'          => post('label'),
        'recipient_name' => post('recipient_name'),
        'phone'          => post('phone'),
        'line1'          => post('line1'),
        'line2'          => post('line2'),
        'postcode'       => post('postcode'),
        'city'           => post('city'),
        'state'          => post('state'),
        'country'        => 'Malaysia',
    ];

    if (!array_key_exists($data['label'], ADDRESS_LABELS)) {
        add_err('label', 'Please choose a label for this address.');
    }

    if (v_required('recipient_name', $data['recipient_name'], 'Recipient name')) {
        v_max('recipient_name', $data['recipient_name'], 100, 'Recipient name');
    }

    // Malaysian mobile and landline numbers, with or without spaces or dashes.
    if (v_required('phone', $data['phone'], 'Phone number')) {
        $digits = preg_replace('/[^0-9]/', '', $data['phone']);

        if (!preg_match('/^(60|0)[0-9]{8,10}$/', $digits)) {
            add_err('phone', 'Enter a valid Malaysian phone number, for example 012-345 6789.');
        } else {
            $data['phone'] = $digits;
        }
    }

    if (v_required('line1', $data['line1'], 'Address line 1')) {
        v_max('line1', $data['line1'], 150, 'Address line 1');
    }

    v_max('line2', $data['line2'], 150, 'Address line 2');

    // Malaysian postcodes are exactly five digits.
    if (v_required('postcode', $data['postcode'], 'Postcode')) {
        if (!preg_match('/^[0-9]{5}$/', $data['postcode'])) {
            add_err('postcode', 'A Malaysian postcode is exactly 5 digits, for example 50400.');
        }
    }

    if (v_required('city', $data['city'], 'City')) {
        v_max('city', $data['city'], 60, 'City');
    }

    if (!array_key_exists($data['state'], MY_STATES)) {
        add_err('state', 'Please choose a state.');
    }

    return $data;
}

// ------------------------------------------------------------
// Writing
// ------------------------------------------------------------

/**
 * Make one address the member's default, clearing the others.
 * Wrapped in a transaction so there can never be two defaults.
 */
function set_default_address(int $addressId, int $userId): void
{
    db()->beginTransaction();

    try {
        db_exec('UPDATE addresses SET is_default = 0 WHERE user_id = ?', [$userId]);
        db_exec('UPDATE addresses SET is_default = 1 WHERE id = ? AND user_id = ?', [$addressId, $userId]);
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

/** Insert a new address, returning its id. */
function create_address(int $userId, array $data, bool $makeDefault): int
{
    // The very first address a member saves is their default whatever they ticked.
    $isFirst = address_count($userId) === 0;

    db_exec(
        'INSERT INTO addresses
                (user_id, label, recipient_name, phone, line1, line2, postcode, city, state, country, is_default)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
        [
            $userId, $data['label'], $data['recipient_name'], $data['phone'],
            $data['line1'], ($data['line2'] === '' ? null : $data['line2']),
            $data['postcode'], $data['city'], $data['state'], $data['country'],
        ]
    );

    $id = (int)db_last_id();

    if ($makeDefault || $isFirst) {
        set_default_address($id, $userId);
    }

    return $id;
}

/** Update an existing address that belongs to this member. */
function update_address(int $addressId, int $userId, array $data, bool $makeDefault): void
{
    db_exec(
        'UPDATE addresses
            SET label = ?, recipient_name = ?, phone = ?, line1 = ?, line2 = ?,
                postcode = ?, city = ?, state = ?, country = ?, updated_at = NOW()
          WHERE id = ? AND user_id = ?',
        [
            $data['label'], $data['recipient_name'], $data['phone'],
            $data['line1'], ($data['line2'] === '' ? null : $data['line2']),
            $data['postcode'], $data['city'], $data['state'], $data['country'],
            $addressId, $userId,
        ]
    );

    if ($makeDefault) {
        set_default_address($addressId, $userId);
    }
}

/**
 * Delete an address.
 * Past orders keep their text snapshot, and orders.shipping_address_id
 * is ON DELETE SET NULL, so order history is never damaged.
 */
function delete_address(int $addressId, int $userId): void
{
    $wasDefault = (int)db_value(
        'SELECT is_default FROM addresses WHERE id = ? AND user_id = ?',
        [$addressId, $userId]
    );

    db_exec('DELETE FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]);

    // Promote another address so the member always has a default.
    if ($wasDefault === 1) {
        $next = db_value('SELECT id FROM addresses WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$userId]);
        if ($next) {
            set_default_address((int)$next, $userId);
        }
    }
}
