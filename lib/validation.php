<?php
// ============================================================
// lib/validation.php
// Reusable server-side validation rules.
// Every rule records its message through add_err() and returns
// a boolean so rules can be chained inside an if-statement.
// ============================================================

/** The field must not be blank. */
function v_required(string $key, string $value, string $label): bool
{
    if ($value === '') {
        add_err($key, $label . ' is required.');
        return false;
    }
    return true;
}

/** The field must not exceed $max characters. */
function v_max(string $key, string $value, int $max, string $label): bool
{
    if (mb_strlen($value) > $max) {
        add_err($key, $label . ' must not exceed ' . $max . ' characters.');
        return false;
    }
    return true;
}

/** The field must be at least $min characters long. */
function v_min(string $key, string $value, int $min, string $label): bool
{
    if (mb_strlen($value) < $min) {
        add_err($key, $label . ' must be at least ' . $min . ' characters.');
        return false;
    }
    return true;
}

/** The field must be a syntactically valid email address. */
function v_email(string $key, string $value, string $label = 'Email'): bool
{
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        add_err($key, $label . ' is not a valid email address.');
        return false;
    }
    return true;
}

/** The field must be a number within an inclusive range. */
function v_number(string $key, $value, float $min, float $max, string $label): bool
{
    if (!is_numeric($value) || $value < $min || $value > $max) {
        add_err($key, $label . ' must be a number between ' . $min . ' and ' . $max . '.');
        return false;
    }
    return true;
}

/** The field must be a whole number within an inclusive range. */
function v_integer(string $key, $value, int $min, int $max, string $label): bool
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
        add_err($key, $label . ' must be a whole number between ' . $min . ' and ' . $max . '.');
        return false;
    }
    return true;
}

/** The value must be one of an allowed list. */
function v_in(string $key, $value, array $allowed, string $label): bool
{
    if (!in_array($value, $allowed, true)) {
        add_err($key, $label . ' contains an invalid selection.');
        return false;
    }
    return true;
}

/** Two fields must match (password confirmation). */
function v_same(string $key, string $value, string $other, string $label): bool
{
    if ($value !== $other) {
        add_err($key, $label . ' does not match.');
        return false;
    }
    return true;
}

/**
 * The email must not already belong to another user.
 * Pass $exceptId when updating an existing account.
 */
function v_email_unique(string $key, string $email, ?int $exceptId = null): bool
{
    $sql    = 'SELECT id FROM users WHERE email = ?';
    $params = [$email];

    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }

    if (db_one($sql, $params)) {
        add_err($key, 'This email address is already registered.');
        return false;
    }
    return true;
}

/** A password must be reasonably strong before we accept it. */
function v_password(string $key, string $value, string $label = 'Password'): bool
{
    if (!v_required($key, $value, $label)) {
        return false;
    }
    if (mb_strlen($value) < 8) {
        add_err($key, $label . ' must be at least 8 characters.');
        return false;
    }
    if (!preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value)) {
        add_err($key, $label . ' must contain at least one letter and one number.');
        return false;
    }
    return true;
}
