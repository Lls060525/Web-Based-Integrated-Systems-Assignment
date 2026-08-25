<?php
// ============================================================
// member/wishlist.php - Favorites / Wishlist (Member)
//
// ------------------------------------------------------------
// THREE ACTIONS ON ONE PAGE
// ------------------------------------------------------------
//
// remove / clear / move_to_cart, told apart by a hidden `action`
// field. That is the pattern used by every multi-action page in this
// project: one POST target, a switch on `action`, and a redirect at
// the end.
//
// All three are POST rather than links, and each carries a CSRF
// token. A GET that changes something -- /wishlist.php?remove=7 --
// looks harmless and is not: a browser will follow it from an <img>
// tag on any other site, and a link prefetcher will follow it without
// anyone clicking. The rule this project holds to is that GET reads
// and POST changes, with a token proving the request came from a page
// we served.
//
// ------------------------------------------------------------
// THE INTERESTING ONE IS move_to_cart
// ------------------------------------------------------------
//
// A wishlist is a list of things a customer wanted at some point in
// the past, so by the time they move it to the cart some of it will
// have sold out or been withdrawn. The code below deals with that by
// moving what it can and COUNTING what it could not, then saying so.
//
// The two tempting alternatives are both worse. Refusing the whole
// operation because one item is out of stock punishes the customer
// for the shop's problem. Moving everything and letting checkout fail
// later hides the problem until the worst possible moment. Partial
// success, reported honestly, is the only version that respects what
// the customer was trying to do.
// ============================================================

require_once __DIR__ . '/../lib/init.php';
require_once __DIR__ . '/../includes/product_card.php';

require_member();

$title  = 'My Wishlist - ' . APP_NAME;
$userId = current_user_id();

if (!wishlist_module_ready()) {
    flash_error('Wishlist is not available yet: run database/migration_09_wishlist.sql.');
    redirect('/member/profile.php');
}

// ---------- Actions ----------
if (is_post()) {
    csrf_check();

    $action = post('action');

    if ($action === 'remove') {
        $productId = post_int('product_id');

        if ($productId !== null) {
            remove_from_wishlist($userId, $productId);
            flash_success('Removed from your wishlist.');
        }

    } elseif ($action === 'clear') {
        $removed = clear_wishlist($userId);
        flash_success($removed . ' item' . ($removed === 1 ? '' : 's') . ' removed from your wishlist.');

    } elseif ($action === 'move_to_cart') {
        // Move everything that is actually buyable into the cart,
        // and report honestly on anything that was skipped.
        $items   = wishlist_items($userId);
        $moved   = 0;
        $skipped = 0;

        foreach ($items as $item) {
            // Gate 1: is it still sellable at all? A withdrawn product
            // or one with no stock is skipped and counted.
            if ($item['status'] !== 'active' || (int)$item['stock'] <= 0) {
                $skipped++;
                continue;
            }

            // Is it already in the cart? The cart holds ONE row per
            // product with a quantity, not one row per click -- so
            // moving a wishlist item the customer already has must add
            // to the existing line rather than create a duplicate.
            $line = db_one(
                'SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?',
                [$userId, $item['id']]
            );

            if ($line) {
                // Gate 2, and easy to miss: they may already hold every
                // unit that exists. Adding one more would put the cart
                // over available stock, and checkout would refuse it
                // later with an error the customer cannot act on.
                // Better to skip and say so now.
                if ((int)$line['quantity'] >= (int)$item['stock']) {
                    $skipped++;
                    continue;
                }

                // quantity = quantity + 1, computed by the DATABASE.
                //
                // Not "read the value, add one in PHP, write it back".
                // Two requests doing that both read 2, both write 3,
                // and one increment is lost. Letting the database do
                // the arithmetic makes the whole thing a single atomic
                // statement with nothing to lose.
                db_exec('UPDATE cart SET quantity = quantity + 1 WHERE id = ?', [$line['id']]);
            } else {
                db_exec(
                    'INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1)',
                    [$userId, $item['id']]
                );
            }

            // Only after the cart write succeeded. Removing first would
            // mean a failure between the two lines loses the item from
            // both places -- the customer's saved item simply gone.
            remove_from_wishlist($userId, (int)$item['id']);
            $moved++;
        }

        if ($moved > 0) {
            flash_success($moved . ' item' . ($moved === 1 ? '' : 's') . ' moved to your cart.'
                . ($skipped > 0 ? ' ' . $skipped . ' could not be moved (out of stock or unavailable).' : ''));
        } else {
            flash_error('Nothing could be moved. The saved items are out of stock or no longer available.');
        }
    }

    redirect('/member/wishlist.php');
}

$items = wishlist_items($userId);

// Anything still buyable? Controls whether "Move all to cart" is offered.
$buyable = 0;
foreach ($items as $item) {
    if ($item['status'] === 'active' && (int)$item['stock'] > 0) {
        $buyable++;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/profile.php">My Profile</a> &gt; <span>My Wishlist</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">
        My Wishlist
        <span class="muted title-count"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
    </h2>

    <?php if (count($items) > 0): ?>
        <div class="row-actions">
            <?php if ($buyable > 0): ?>
                <form action="/member/wishlist.php" method="POST" class="inline-form"
                      data-confirm="Move all available items to your cart?">
                    <?php csrf_field(); ?>
                    <?php html_hidden('action', 'move_to_cart'); ?>
                    <?php html_submit('Move All to Cart', ['class' => 'btn-outline']); ?>
                </form>
            <?php endif; ?>

            <form action="/member/wishlist.php" method="POST" class="inline-form"
                  data-confirm="Remove every item from your wishlist? This cannot be undone.">
                <?php csrf_field(); ?>
                <?php html_hidden('action', 'clear'); ?>
                <?php html_submit('Clear Wishlist', ['class' => 'btn-outline btn-danger']); ?>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php if (count($items) === 0): ?>

    <div class="card empty-state-box">
        <h3 class="empty-state-title">Your wishlist is empty.</h3>
        <p>Tap the heart on any product to save it for later.</p>
        <a href="/products.php" class="btn-primary shop-now-btn">Browse Products</a>
    </div>

<?php else: ?>

    <div class="grid section-margin-top">
        <?php foreach ($items as $item): ?>
            <?php
                $inStock     = $item['status'] === 'active' && (int)$item['stock'] > 0;
                $unavailable = $item['status'] !== 'active';
            ?>
            <div class="card product-card <?= $unavailable ? 'is-unavailable' : '' ?>">
                <a href="/product_detail.php?id=<?= (int)$item['id'] ?>" class="product-card-link">
                    <div class="card-img">
                        <img src="<?= e(product_image($item['image'])) ?>" alt="<?= e($item['name']) ?>">
                    </div>
                    <div class="card-info">
                        <h3><?= e($item['name']) ?></h3>
                        <?php if (!empty($item['category_name'])): ?>
                            <span class="badge"><?= e($item['category_name']) ?></span>
                        <?php endif; ?>
                        <div class="price"><?= e(money($item['price'])) ?></div>

                        <?php if ($unavailable): ?>
                            <span class="badge badge-danger">No longer available</span>
                        <?php elseif (stock_state($item) === 'out'): ?>
                            <span class="badge badge-danger">Out of stock</span>
                        <?php elseif (stock_state($item) === 'low'): ?>
                            <span class="badge badge-warning">Only <?= (int)$item['stock'] ?> left</span>
                        <?php endif; ?>

                        <div class="muted small-note wishlist-added">
                            Saved <?= e(fmt_date($item['added_at'])) ?>
                        </div>
                    </div>
                </a>

                <div class="card-actions wishlist-card-actions">
                    <?php if ($inStock): ?>
                        <button type="button" class="add-to-cart btn-primary btn-block"
                                data-id="<?= (int)$item['id'] ?>">Add to Cart</button>
                    <?php else: ?>
                        <button type="button" class="btn-outline btn-block" disabled>Unavailable</button>
                    <?php endif; ?>

                    <form action="/member/wishlist.php" method="POST" class="mt-2">
                        <?php csrf_field(); ?>
                        <?php html_hidden('action', 'remove'); ?>
                        <?php html_hidden('product_id', $item['id']); ?>
                        <?php html_submit('Remove', ['class' => 'btn-outline btn-sm btn-block']); ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
