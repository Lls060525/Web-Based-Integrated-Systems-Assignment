<?php
// ============================================================
// lib/role.php
// Roles, permissions, and the checks that enforce them.
//
// The model is deliberately small:
//
//   a role has many permissions
//   an admin account has one role
//   can('products.manage') asks whether the signed-in admin's role
//   carries that permission
//
// There is no per-user override and no permission inheritance between
// roles. Both are easy to add and neither earns its complexity here --
// an override makes "why can this person do that?" unanswerable without
// reading two tables, and inheritance makes deleting a role a question
// about its children.
//
// Everything degrades if migration_25_roles.sql has not been run:
// role_module_ready() is false, can() returns true for any admin, and
// the site behaves exactly as it did before the module existed. That is
// the same pattern the other optional modules use, and it means a
// half-migrated database is inconvenient rather than locked.
// ============================================================

/**
 * True once migration_26_role_landing.sql has been applied.
 *
 * Separate from role_module_ready() because the column arrives in a
 * later migration. Somebody who has run 25 but not 26 gets automatic
 * landing pages rather than an error about a missing column.
 */
function role_landing_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = role_module_ready() && db_column_exists('roles', 'landing_permission');
    }

    return $ready;
}

/** True once migration_25_roles.sql has been applied. */
function role_module_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        $ready = db_table_exists('roles')
              && db_table_exists('permissions')
              && db_table_exists('role_permissions')
              && db_column_exists('users', 'role_id');
    }

    return $ready;
}

// ------------------------------------------------------------
// The permission catalogue
// ------------------------------------------------------------

/**
 * Every permission, grouped by area, in display order.
 *
 * Read from the database rather than hard-coded so the role form and the
 * migration cannot disagree about what exists.
 *
 * @return array<string, array<int, array>>  area => rows
 */
function permission_catalogue(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    if (!role_module_ready()) {
        return $cache;
    }

    foreach (db_all('SELECT * FROM permissions ORDER BY area ASC, sort_order ASC, label ASC') as $row) {
        $cache[$row['area']][] = $row;
    }

    return $cache;
}

/** Flat map of code => label, for summaries. */
function permission_labels(): array
{
    static $map = null;

    if ($map === null) {
        $map = [];

        foreach (permission_catalogue() as $rows) {
            foreach ($rows as $row) {
                $map[$row['code']] = $row['label'];
            }
        }
    }

    return $map;
}

// ------------------------------------------------------------
// Asking what the current admin may do
// ------------------------------------------------------------

/**
 * The permission codes held by the signed-in user.
 *
 * Read from the database on each request rather than stored in the
 * session. That costs one query and buys the thing that matters: taking
 * a permission away takes effect immediately, instead of waiting for the
 * person to log out. An admin who has just been demoted should not keep
 * their old access because they left a tab open.
 *
 * @return string[]
 */
function current_permissions(): array
{
    static $cache = null;
    static $generation = -1;

    // Recomputed when the signed-in identity has changed since this was
    // last answered -- see role_reset_cache().
    if ($cache !== null && $generation === role_cache_generation()) {
        return $cache;
    }

    $generation = role_cache_generation();
    $cache      = [];

    if (!role_module_ready() || !is_logged_in()) {
        return $cache;
    }

    $rows = db_all(
        'SELECT p.code
           FROM users u
           JOIN role_permissions rp ON rp.role_id = u.role_id
           JOIN permissions p       ON p.id = rp.permission_id
          WHERE u.id = ?',
        [current_user_id()]
    );

    $cache = array_column($rows, 'code');

    return $cache;
}

/**
 * Forget everything cached about who is signed in.
 *
 * current_permissions() and current_role_name() hold a static for the
 * life of the request, which is right: the permission set is asked for
 * many times per page and must not cost a query each time.
 *
 * But "the life of the request" spans the moment of logging in. If
 * anything asks can() before the session is populated -- and the login
 * page does ask, to decide whether to show a CAPTCHA -- the empty answer
 * would be cached and the redirect straight after login_user() would
 * send a Super Admin to their profile page.
 *
 * Called by login_user() and logout_user(), which are the only two
 * points where the answer legitimately changes mid-request.
 */
function role_reset_cache(): void
{
    // A static cannot be unset from outside, so the store is a static of
    // this function instead and the readers consult it. Simpler than it
    // sounds: bumping the generation invalidates what they cached.
    role_cache_generation(true);
}

/** Bumped whenever the signed-in identity changes. */
function role_cache_generation(bool $bump = false): int
{
    static $generation = 0;

    if ($bump) {
        $generation++;
    }

    return $generation;
}

/**
 * May the signed-in user do this?
 *
 * Members always get false: this is an admin-side model, and a member
 * reaching a permission check at all means a guard is missing further up.
 *
 * Before the migration is run, any admin gets true. The alternative --
 * defaulting to false -- would lock the whole panel the moment someone
 * pulled the code without the database, which is a worse failure than
 * behaving as it did last week.
 */
function can(string $permission): bool
{
    if (!is_admin()) {
        return false;
    }

    if (!role_module_ready()) {
        return true;
    }

    return in_array($permission, current_permissions(), true);
}

/** True when the user holds at least one of these. */
function can_any(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }

    return false;
}

/**
 * Guard for an admin page. Call it directly under admin_auth.php.
 *
 * Refuses rather than redirecting into a loop: someone with no
 * permissions at all has nowhere useful to be sent, so they get a plain
 * 403 page telling them who to ask.
 */
function require_permission(string $permission): void
{
    if (can($permission)) {
        return;
    }

    http_response_code(403);

    $title = 'Not authorised - ' . APP_NAME;
    $label = permission_labels()[$permission] ?? $permission;

    include __DIR__ . '/../includes/admin_header.php';

    echo '<div class="admin-container admin-container-narrow">'
       . '<div class="card card-padded mt-4 permission-denied">'
       . '<h2 class="section-heading"><i class="fas fa-lock"></i> Not authorised</h2>'
       . '<p>Your role does not include <strong>' . e($label) . '</strong>, '
       . 'so this page is not available to you.</p>'
       . '<p class="muted small-note">Your role is <strong>'
       . e(current_role_name() ?? 'not set') . '</strong>. '
       . 'If you need this access, ask a Super Admin to add it in Roles.</p>'
       . '<a href="' . e(admin_landing_url()) . '" class="btn-primary mt-2">Go to a page you can open</a>'
       . '</div></div>';

    include __DIR__ . '/../includes/admin_footer.php';
    exit;
}

/** Name of the signed-in user's role, or null. */
function current_role_name(): ?string
{
    if (!role_module_ready() || !is_logged_in()) {
        return null;
    }

    static $name = false;
    static $generation = -1;

    if ($name === false || $generation !== role_cache_generation()) {
        $generation = role_cache_generation();

        $name = db_value(
            'SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [current_user_id()]
        ) ?: null;
    }

    return $name;
}

// ------------------------------------------------------------
// The admin areas
// ------------------------------------------------------------

/**
 * Every area of the admin panel: the permission that opens it, where it
 * lives, what to call it, and its icon.
 *
 * This is the SINGLE source of truth. The sidebar is built from it, the
 * "page after login" dropdown is built from it, and admin_landing_url()
 * resolves against it. They used to be three separate lists, which is
 * three chances for one of them to fall out of step with the others.
 *
 * The order matters TWICE, which is why it is worth thinking about:
 *
 *   1. it is the sidebar order
 *   2. it is the priority order used when a role has no landing page
 *      configured -- a role that can do several things lands on the one
 *      nearest the top
 *
 * ------------------------------------------------------------
 * HOW THE ORDER WAS CHOSEN
 * ------------------------------------------------------------
 *
 * Two sensible orderings pull in opposite directions:
 *
 *   BY DEPENDENCY   a product needs a category and its specification
 *                   attributes to exist first, so Categories and Specs
 *                   ought to come before Products
 *
 *   BY FREQUENCY    Orders are worked every day; Categories are set up
 *                   once and barely touched again, so putting them at
 *                   the top buries the daily work below them
 *
 * Neither wins outright, so the list does both at different levels:
 *
 *   GROUPS are ordered by frequency   Orders before Catalogue
 *   ITEMS inside a group by dependency  Categories -> Specs -> Products
 *
 * That also keeps rule 2 sensible. The landing fallback picks the first
 * openable area, and with groups in frequency order that is the page
 * somebody is most likely to have signed in to use -- not whichever
 * setup screen happened to sort first.
 *
 * Worth knowing before changing this order: all five seeded roles have
 * an EXPLICIT landing_permission (migration 26 sets 'dashboard.view'
 * for the three office roles, migration 27 sets 'qr.scan' for Vendor
 * and Delivery Man), so reordering cannot move any of them. The
 * fallback only decides for a custom role somebody creates without
 * picking a landing page.
 *
 * The 'group' key drives the headings in the sidebar. A heading is only
 * drawn when the group CHANGES while walking the permitted list, so a
 * group in which a role can open nothing never appears at all -- no
 * empty "Catalogue" heading for a Delivery Man.
 *
 * @return array<string, array{url: string, label: string, icon: string, group: string}>
 */
function admin_areas(): array
{
    return [
        // ---- Overview ----
        'dashboard.view'     => ['group' => 'Overview',  'url' => '/admin/dashboard.php',      'label' => 'Dashboard',      'icon' => 'fa-chart-line'],

        // ---- Orders: the daily work, in the order a parcel moves ----
        'orders.manage'      => ['group' => 'Orders',    'url' => '/admin/orders.php',         'label' => 'Orders',         'icon' => 'fa-shopping-cart'],
        'qr.scan'            => ['group' => 'Orders',    'url' => '/admin/qr_scan.php',        'label' => 'Scan QR',        'icon' => 'fa-qrcode'],
        'orders.approve_cancel' => ['group' => 'Orders', 'url' => '/admin/cancellations.php',  'label' => 'Cancellations',  'icon' => 'fa-ban'],

        // ---- Catalogue: strict dependency order ----
        // Categories and Specs are what a product is BUILT FROM, so they
        // come first. Stock is a property of a product that already
        // exists, and Batch Tools act on products in bulk, so both come
        // after. Following this order top to bottom is a working recipe
        // for setting the shop up from empty.
        'categories.manage'  => ['group' => 'Catalogue', 'url' => '/admin/categories.php',     'label' => 'Categories',     'icon' => 'fa-tags'],
        'specs.manage'       => ['group' => 'Catalogue', 'url' => '/admin/specs.php',          'label' => 'Specs',          'icon' => 'fa-list-check'],
        'products.manage'    => ['group' => 'Catalogue', 'url' => '/admin/products.php',       'label' => 'Products',       'icon' => 'fa-box'],
        'stock.manage'       => ['group' => 'Catalogue', 'url' => '/admin/stock.php',          'label' => 'Stock',          'icon' => 'fa-boxes-stacked'],
        'batch.manage'       => ['group' => 'Catalogue', 'url' => '/admin/batch_import.php',   'label' => 'Batch Tools',    'icon' => 'fa-layer-group'],

        // ---- Customers ----
        'members.manage'     => ['group' => 'Customers', 'url' => '/admin/members.php',        'label' => 'Members',        'icon' => 'fa-users'],
        'reviews.manage'     => ['group' => 'Customers', 'url' => '/admin/reviews.php',        'label' => 'Reviews',        'icon' => 'fa-star'],

        // ---- Marketing ----
        'vouchers.manage'    => ['group' => 'Marketing', 'url' => '/admin/vouchers.php',       'label' => 'Vouchers',       'icon' => 'fa-ticket'],
        'stores.manage'      => ['group' => 'Marketing', 'url' => '/admin/stores.php',         'label' => 'Stores',         'icon' => 'fa-location-dot'],

        // ---- System: rarely opened, and mostly by one person ----
        'admins.manage'      => ['group' => 'System',    'url' => '/admin/admins.php',         'label' => 'Admins',         'icon' => 'fa-user-shield'],
        'roles.manage'       => ['group' => 'System',    'url' => '/admin/roles.php',          'label' => 'Roles',          'icon' => 'fa-key'],
        'security.view'      => ['group' => 'System',    'url' => '/admin/login_attempts.php', 'label' => 'Login Security', 'icon' => 'fa-shield-alt'],
        'mail.test'          => ['group' => 'System',    'url' => '/admin/mail_test.php',      'label' => 'Mail & PDF',     'icon' => 'fa-envelope-open-text'],
    ];
}

/** Just the areas the signed-in admin may open, in sidebar order. */
function permitted_admin_areas(): array
{
    return array_filter(
        admin_areas(),
        static fn(string $permission): bool => can($permission),
        ARRAY_FILTER_USE_KEY
    );
}

/**
 * Which areas a ROLE may open. Used by the role form's landing dropdown,
 * where the question is about a role being edited rather than about the
 * person doing the editing.
 *
 * @param int[] $permissionIds the set being considered, which on the
 *                             form is what is currently ticked rather
 *                             than what is saved
 */
function areas_for_permission_ids(array $permissionIds): array
{
    if ($permissionIds === [] || !role_module_ready()) {
        return [];
    }

    $marks = implode(',', array_fill(0, count($permissionIds), '?'));

    $codes = array_column(
        db_all("SELECT code FROM permissions WHERE id IN ($marks)", array_map('intval', $permissionIds)),
        'code'
    );

    return array_filter(
        admin_areas(),
        static fn(string $permission): bool => in_array($permission, $codes, true),
        ARRAY_FILTER_USE_KEY
    );
}

/**
 * Where this user should land after signing in.
 *
 * Three steps, in order:
 *
 *   1. the landing page configured on their role, but ONLY if the role
 *      still holds that permission. A Super Admin can untick an area
 *      long after choosing it as somebody's landing page, and the
 *      result must not be a 403 on login.
 *   2. the first area they can open, in admin_areas() order.
 *   3. their own profile, which every admin can always reach.
 *
 * Step 3 is what makes this total: there is no combination of
 * permissions, including none at all, that produces a page they cannot
 * open.
 */
function admin_landing_url(): string
{
    // Cached: the sidebar logo calls this on every admin page render, so
    // without it every page pays for an extra query to answer a question
    // that cannot change mid-request. Keyed on the same generation as the
    // permission cache, so logging in still re-resolves it.
    static $cache = null;
    static $generation = -1;

    if ($cache !== null && $generation === role_cache_generation()) {
        return $cache;
    }

    $generation = role_cache_generation();
    $areas      = permitted_admin_areas();

    // ---- 1. The role's configured choice ----
    if (role_landing_ready() && is_logged_in()) {
        $configured = db_value(
            'SELECT r.landing_permission
               FROM users u
               JOIN roles r ON r.id = u.role_id
              WHERE u.id = ?',
            [current_user_id()]
        );

        if (is_string($configured) && $configured !== '' && isset($areas[$configured])) {
            return $cache = $areas[$configured]['url'];
        }
    }

    // ---- 2. The first thing they can open ----
    foreach ($areas as $area) {
        return $cache = $area['url'];
    }

    // ---- 3. Always available ----
    return $cache = '/admin/profile.php';
}

// ------------------------------------------------------------
// Links that know whether they can be followed
// ------------------------------------------------------------

/**
 * Which permission governs an admin URL.
 *
 * Form pages are not areas in their own right -- product_form.php is
 * part of Products, member_detail.php is part of Members -- so the
 * lookup falls back to the page's own require_permission() mapping.
 * Keeping that mapping here rather than re-reading the files means one
 * table to update when a page moves.
 */
function admin_url_permission(string $url): ?string
{
    static $map = null;

    if ($map === null) {
        $map = [];

        // The areas themselves.
        foreach (admin_areas() as $permission => $area) {
            $map[basename($area['url'])] = $permission;
        }

        // The pages that belong to an area without being its front door.
        $map += [
            'product_form.php'    => 'products.manage',
            'product_photos.php'  => 'products.manage',
            'product_specs.php'   => 'products.manage',
            'product_options.php' => 'products.manage',
            'photo_edit.php'      => 'products.manage',
            'category_form.php'   => 'categories.manage',
            'spec_form.php'       => 'specs.manage',
            'store_form.php'      => 'stores.manage',
            'voucher_form.php'    => 'vouchers.manage',
            'member_detail.php'   => 'members.manage',
            'admin_form.php'      => 'admins.manage',
            'role_form.php'       => 'roles.manage',
            'order_detail.php'    => 'orders.manage',
            'evidence.php'        => 'orders.manage',
            'cancellations.php'   => 'orders.approve_cancel',
            'batch_price.php'     => 'batch.manage',
            'batch_delete.php'    => 'batch.manage',

            // Every admin can always reach their own profile.
            'profile.php'         => null,
        ];
    }

    $file = basename(parse_url($url, PHP_URL_PATH) ?? $url);

    return array_key_exists($file, $map) ? $map[$file] : null;
}

/** True when the signed-in admin could actually open this URL. */
function can_open(string $url): bool
{
    $permission = admin_url_permission($url);

    // Unknown or unguarded (profile) -- nothing to refuse.
    return $permission === null || can($permission);
}

/**
 * A link that turns into plain text when the reader cannot follow it.
 *
 * The permission checks make the panel correct; this makes it honest.
 * A Delivery Man reading an order should not be offered a link to the
 * customer's account only to be told off for clicking it -- the name is
 * still useful information, the navigation is not.
 *
 * Plain text rather than a hidden element on purpose. Removing the name
 * entirely would leave a hole where a fact used to be, and the reader
 * would not know whether the customer is unknown or merely off-limits.
 *
 * @param string $url    where it would go
 * @param string $label  the visible text, escaped here
 * @param array  $attr   attributes for the anchor, ignored for plain text
 * @param bool   $html   set when $label is already-escaped markup
 */
function admin_link(string $url, string $label, array $attr = [], bool $html = false): void
{
    $text = $html ? $label : e($label);

    if (!can_open($url)) {
        // title explains the flat text on hover; the class lets the CSS
        // give it the same weight as the link it replaces.
        echo '<span class="link-denied" title="Your role cannot open this page">'
           . $text . '</span>';
        return;
    }

    echo '<a href="' . e($url) . '"' . html_attr($attr) . '>' . $text . '</a>';
}

/**
 * A dashboard figure that is a shortcut when it can be, and just a
 * figure when it cannot.
 *
 * The number is the point; the link is a convenience. Hiding the whole
 * tile from a role that cannot open the target would take the figure
 * away too, which is a worse trade -- knowing there are 412 members is
 * useful even if you may not browse them.
 *
 * A helper rather than a ternary in the markup, because choosing
 * between <a> and <div> inline means writing the tag name in four
 * places and getting the closing tag right by hand.
 */
function admin_stat_tile(string $url, string $icon, string $value, string $label): void
{
    $open = can_open($url);

    echo $open
        ? '<a href="' . e($url) . '" class="card stat-tile">'
        : '<div class="card stat-tile is-static" title="Your role cannot open this page">';

    echo   '<span class="stat-icon"><i class="fas ' . e($icon) . '"></i></span>'
         . '<span class="stat-value">' . e($value) . '</span>'
         . '<span class="stat-label">' . e($label) . '</span>';

    echo $open ? '</a>' : '</div>';
}

/**
 * Whole blocks that only make sense to somebody who can follow them --
 * an "Edit" button, a row of shortcuts, a whole dashboard panel.
 *
 * Unlike admin_link() this hides rather than degrades, because a button
 * with nothing behind it is not information.
 */
function if_can_open(string $url, callable $render): void
{
    if (can_open($url)) {
        $render();
    }
}

// ------------------------------------------------------------
// Reading roles
// ------------------------------------------------------------

/**
 * Every role, with how many permissions and accounts it has.
 *
 * The two counts are correlated subqueries rather than joins, because
 * joining both at once multiplies the rows and the counts come out as
 * the product of each other -- a role with 6 permissions and 2 accounts
 * would report 12 of each.
 */
function all_roles(): array
{
    if (!role_module_ready()) {
        return [];
    }

    return db_all(
        'SELECT r.*,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id)              AS account_count
           FROM roles r
          ORDER BY r.is_system DESC, r.name ASC'
    );
}

/** One role, or null. */
function find_role(?int $id): ?array
{
    if (!role_module_ready() || $id === null) {
        return null;
    }

    return db_one('SELECT * FROM roles WHERE id = ?', [$id]) ?: null;
}

/** The permission ids granted to a role. */
function role_permission_ids(int $roleId): array
{
    if (!role_module_ready()) {
        return [];
    }

    return array_map(
        'intval',
        array_column(
            db_all('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]),
            'permission_id'
        )
    );
}

/** The permission rows granted to a role, grouped by area for display. */
function role_permissions_grouped(int $roleId): array
{
    if (!role_module_ready()) {
        return [];
    }

    $rows = db_all(
        'SELECT p.*
           FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
          WHERE rp.role_id = ?
          ORDER BY p.area ASC, p.sort_order ASC',
        [$roleId]
    );

    $out = [];

    foreach ($rows as $row) {
        $out[$row['area']][] = $row;
    }

    return $out;
}

/** Roles as id => name, for a dropdown. */
function role_options(): array
{
    $out = [];

    foreach (all_roles() as $role) {
        $out[(int)$role['id']] = $role['name'];
    }

    return $out;
}

// ------------------------------------------------------------
// Writing roles
// ------------------------------------------------------------

/**
 * Create or update a role and replace its permission set.
 *
 * Written in one transaction because a role whose name saved but whose
 * permissions did not is a role that silently grants the wrong things.
 *
 * @param  int[] $permissionIds
 * @return int   the role id
 */
function save_role(
    ?int $id,
    string $name,
    string $description,
    array $permissionIds,
    ?string $landing = null
): int {
    db()->beginTransaction();

    try {
        if ($id === null) {
            db_exec(
                'INSERT INTO roles (name, description) VALUES (?, ?)',
                [$name, $description !== '' ? $description : null]
            );
            $id = (int)db_last_id();
        } else {
            db_exec(
                'UPDATE roles SET name = ?, description = ? WHERE id = ?',
                [$name, $description !== '' ? $description : null, $id]
            );
        }

        // Written separately and only when the column exists, so a
        // database with migration 25 but not 26 still saves everything
        // else rather than failing on an unknown column.
        if (role_landing_ready()) {
            $landing = ($landing !== null && $landing !== '' && isset(admin_areas()[$landing]))
                ? $landing
                : null;

            db_exec('UPDATE roles SET landing_permission = ? WHERE id = ?', [$landing, $id]);
        }

        // Replaced wholesale rather than diffed. The set is at most a few
        // dozen rows, and "delete then insert" cannot leave a stale grant
        // behind the way a partial diff can.
        db_exec('DELETE FROM role_permissions WHERE role_id = ?', [$id]);

        $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

        if ($permissionIds !== []) {
            // Filtered against the catalogue BEFORE inserting.
            //
            // The ids come from checkboxes, so a stale form or a hand-made
            // POST can carry an id that no longer exists. Letting that
            // reach the insert would fail the foreign key and roll back
            // the whole save, losing the valid grants too. Selecting the
            // real ones first means an unknown id is simply ignored.
            $marks = implode(',', array_fill(0, count($permissionIds), '?'));

            $valid = array_column(
                db_all("SELECT id FROM permissions WHERE id IN ($marks)", $permissionIds),
                'id'
            );

            foreach ($valid as $permissionId) {
                db_exec(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$id, (int)$permissionId]
                );
            }
        }

        db()->commit();

    } catch (\Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return $id;
}

/**
 * Why this role cannot be deleted, or null when it can.
 *
 * Returns a reason rather than a boolean so the screen can explain
 * itself. "Delete is greyed out" with no reason is the kind of thing
 * people file bugs about.
 */
function role_delete_blocker(array $role): ?string
{
    if ((int)$role['is_system'] === 1) {
        return 'This is a system role and cannot be deleted.';
    }

    $accounts = (int)db_value('SELECT COUNT(*) FROM users WHERE role_id = ?', [$role['id']]);

    if ($accounts > 0) {
        return $accounts . ' admin account' . ($accounts === 1 ? '' : 's')
             . ' still use this role. Move them to another role first.';
    }

    return null;
}

/**
 * Would saving this permission set leave nobody able to manage roles?
 *
 * The specific way to lock everyone out is to edit the only role that
 * holds roles.manage and untick it. Nobody can then reach this screen to
 * put it back, and the only repair is SQL.
 *
 * Checked against the role being saved rather than the current state,
 * so it catches the mistake before it is written rather than after.
 *
 * @param int[] $permissionIds what is about to be saved
 */
function role_save_lockout_reason(int $roleId, array $permissionIds): ?string
{
    if (!role_module_ready()) {
        return null;
    }

    $manageId = (int)db_value("SELECT id FROM permissions WHERE code = 'roles.manage'");

    if ($manageId === 0 || in_array($manageId, array_map('intval', $permissionIds), true)) {
        return null;   // still granted, nothing to worry about
    }

    // Somebody else who can still manage roles, and has an account?
    $others = (int)db_value(
        'SELECT COUNT(DISTINCT u.id)
           FROM users u
           JOIN role_permissions rp ON rp.role_id = u.role_id
          WHERE rp.permission_id = ?
            AND u.role_id <> ?
            AND u.status = ?',
        [$manageId, $roleId, 'active']
    );

    if ($others > 0) {
        return null;
    }

    return 'Removing "Roles" from this role would leave no active account '
         . 'able to manage roles, and the only way back would be to edit the '
         . 'database directly. Grant it to another role first.';
}

/**
 * Guard for changing an admin's own role.
 *
 * Same class of problem as above, from the other direction: an admin
 * moving themselves to a weaker role while being the last person holding
 * roles.manage.
 */
function role_assignment_lockout_reason(int $userId, ?int $newRoleId): ?string
{
    if (!role_module_ready()) {
        return null;
    }

    $manageId = (int)db_value("SELECT id FROM permissions WHERE code = 'roles.manage'");

    if ($manageId === 0) {
        return null;
    }

    $newRoleHasIt = $newRoleId !== null && (int)db_value(
        'SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?',
        [$newRoleId, $manageId]
    ) > 0;

    if ($newRoleHasIt) {
        return null;
    }

    $others = (int)db_value(
        'SELECT COUNT(DISTINCT u.id)
           FROM users u
           JOIN role_permissions rp ON rp.role_id = u.role_id
          WHERE rp.permission_id = ?
            AND u.id <> ?
            AND u.status = ?',
        [$manageId, $userId, 'active']
    );

    return $others > 0
        ? null
        : 'That would leave no active account able to manage roles. '
        . 'Give another account a role that includes "Roles" first.';
}
