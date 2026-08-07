<?php
// ============================================================
// checkout.php - Checkout review (Member)
//
// Step 1 of 2. The member confirms what they are buying and picks
// a shipping address from their address book. Only when they press
// Place Order does step 2 create the Stripe session.
//
// The chosen address travels to the success callback in Stripe
// metadata rather than the session, so a lost session or a different
// browser tab cannot lose it.
// ============================================================

require_once __DIR__ . '/lib/init.php';
require_once __DIR__ . '/includes/address_parts.php';

require_member();

$title  = 'Checkout - ' . APP_NAME;
$userId = current_user_id();

// ---------- Load and re-validate the cart on the server ----------
$sigColumn = db_column_exists('cart', 'options_signature')
    ? 'c.options_signature' : "'' AS options_signature";

$items = db_all(
    "SELECT c.quantity, $sigColumn,
            p.id AS product_id, p.name, p.price, p.stock, p.status, p.image
       FROM cart c
       JOIN products p ON p.id = c.product_id
      WHERE c.user_id = ?",
    [$userId]
);

if (count($items) === 0) {
    flash_error('Your cart is empty.');
    redirect('/cart.php');
}

$total = 0.0;
foreach ($items as $index => $item) {
    if ($item['status'] !== 'active') {
        flash_error($item['name'] . ' is no longer available. Please remove it from your cart.');
        redirect('/cart.php');
    }

    if ($item['quantity'] > $item['stock']) {
        flash_error('Insufficient stock for ' . $item['name'] . '. Only ' . (int)$item['stock'] . ' left.');
        redirect('/cart.php');
    }

    // Priced with the customer's chosen options folded in, using the
    // deltas as they stand right now rather than as they stood when the
    // line went into the cart.
    $chosen = spec_describe_signature((int)$item['product_id'], (string)$item['options_signature']);

    if (!$chosen['valid']) {
        flash_error('An option you chose for ' . $item['name'] . ' is no longer offered. '
                  . 'Please update your cart.');
        redirect('/cart.php');
    }

    $item['unit_price']    = (float)$item['price'] + $chosen['delta'];
    $item['options_label'] = $chosen['label'];

    $items[$index] = $item;

    $total += $item['unit_price'] * $item['quantity'];
}

$addresses = user_addresses($userId);

// The applied voucher is revalidated against the live subtotal on every
// render, so a basket that drops below the minimum spend silently loses
// the discount rather than charging the wrong amount.
$applied  = active_voucher($userId, $total);
$discount = $applied['discount'] ?? 0.0;

// Points are applied AFTER the voucher, on whatever is left. Doing it
// the other way round would let a percentage voucher discount an amount
// the member had already paid for with points.
$afterVoucher   = round($total - $discount, 2);
$pointsApplied  = active_points_redemption($userId, $afterVoucher);
$pointsDiscount = $pointsApplied['discount'] ?? 0.0;
$payable        = round($afterVoucher - $pointsDiscount, 2);

// ---------- Step 2: place the order ----------
if (is_post() && post('action') === 'place_order') {
    csrf_check();

    $addressId = post_int('address_id');
    $address   = $addressId === null ? null : find_user_address($addressId, $userId);

    if ($address === null) {
        add_err('address_id', 'Please choose a shipping address.');
    }

    if (STRIPE_SECRET_KEY === '' || !str_starts_with(STRIPE_SECRET_KEY, 'sk_')) {
        add_err('address_id', 'Payment is not configured yet. Set STRIPE_SECRET_KEY in lib/config.php.');
    }

    // Recalculate from the database at the moment of payment. Anything
    // the browser sent about the discount is ignored entirely.
    $applied  = active_voucher($userId, $total);
    $discount = $applied['discount'] ?? 0.0;

    $afterVoucher   = round($total - $discount, 2);
    $pointsApplied  = active_points_redemption($userId, $afterVoucher);
    $pointsDiscount = $pointsApplied['discount'] ?? 0.0;
    $payable        = round($afterVoucher - $pointsDiscount, 2);

    if ($payable <= 0) {
        add_err('address_id', 'The order total must be greater than zero.');
    }

    if (no_err()) {
        $lineItems = [];
        foreach ($items as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency'     => STRIPE_CURRENCY,
                    'product_data' => [
                        // The variant is named on the Stripe page too, so the
                        // customer sees the same thing there as in the cart.
                        'name' => $item['name']
                              . ($item['options_label'] !== '' ? ' (' . $item['options_label'] . ')' : ''),
                    ],
                    // Stripe works in the smallest currency unit: RM 1.00 = 100 sen.
                    // unit_price already has the option deltas folded in.
                    'unit_amount'  => (int)round($item['unit_price'] * 100),
                ],
                'quantity' => (int)$item['quantity'],
            ];
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $domain = $scheme . '://' . $_SERVER['HTTP_HOST'];

        try {
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

            $params = [
                'payment_method_types' => ['card', 'fpx', 'grabpay'],
                'line_items'           => $lineItems,
                'mode'                 => 'payment',
                'client_reference_id'  => (string)$userId,
                'customer_email'       => current_user()['email'] ?? null,

                // We collect the address ourselves, so Stripe does not need to.
                'metadata' => [
                    'address_id' => (string)$address['id'],
                    'user_id'    => (string)$userId,
                ],

                'success_url' => $domain . '/checkout_success.php?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'  => $domain . '/checkout_cancel.php',
            ];

            // The discount goes to Stripe as a one-off coupon rather than by
            // shaving the line items, so the customer sees the same breakdown
            // on Stripe's page as on ours and the rounding cannot drift.
            $totalDiscount = round($discount + $pointsDiscount, 2);

            if ($totalDiscount > 0) {
                $names = [];

                if ($applied !== null && $discount > 0) {
                    $names[] = 'Voucher ' . $applied['voucher']['code'];
                    $params['metadata']['voucher_code'] = $applied['voucher']['code'];
                }

                if ($pointsApplied !== null && $pointsDiscount > 0) {
                    $names[] = number_format($pointsApplied['points']) . ' reward points';
                    $params['metadata']['redeem_points'] = (string)$pointsApplied['points'];
                }

                // One coupon covering both, so Stripe charges exactly what
                // our summary showed and no rounding can drift between them.
                $coupon = \Stripe\Coupon::create([
                    'amount_off' => (int)round($totalDiscount * 100),
                    'currency'   => STRIPE_CURRENCY,
                    'duration'   => 'once',
                    'name'       => implode(' + ', $names),
                ]);

                $params['discounts'] = [['coupon' => $coupon->id]];
            }

            $session = \Stripe\Checkout\Session::create($params);

            header('HTTP/1.1 303 See Other');
            header('Location: ' . $session->url);
            exit;

        } catch (\Throwable $e) {
            error_log('Stripe checkout error: ' . $e->getMessage());
            add_err('address_id', 'We could not start the payment session. Please try again in a moment.');
        }
    }
}

// Pre-select the default address, or whatever was posted.
$selectedId = post_int('address_id') ?? (int)(default_address($userId)['id'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/cart.php">Cart</a> &gt; <span>Checkout</span>
</nav>

<div class="page-title-row">
    <h2 class="page-title">Checkout</h2>
</div>

<?php err_summary(); ?>

<?php if (!address_module_ready()): ?>
    <div class="alert alert-error">
        The address book is not set up yet. Run
        <code>database/migration_08_address.sql</code> in phpMyAdmin before checking out.
    </div>
<?php else: ?>

<form action="/checkout.php" method="POST">
    <?php csrf_field(); ?>
    <?php html_hidden('action', 'place_order'); ?>

    <div class="checkout-layout">

        <main class="checkout-main">

            <!-- Shipping address -->
            <div class="card card-padded">
                <div class="section-head">
                    <h3>Shipping Address</h3>
                    <a href="/member/address_form.php?return=checkout" class="btn-outline btn-sm">
                        + Add New Address
                    </a>
                </div>

                <?php if (count($addresses) === 0): ?>
                    <div class="alert alert-info">
                        You have no saved addresses yet.
                        <a href="/member/address_form.php?return=checkout">Add one</a> to continue.
                    </div>
                <?php else: ?>
                    <?php render_address_list($addresses, 'select', $selectedId); ?>
                    <?php err('address_id'); ?>

                    <p class="muted small-note mt-2">
                        Manage your saved addresses in
                        <a href="/member/addresses.php">My Addresses</a>.
                    </p>
                <?php endif; ?>
            </div>

            <!-- Voucher -->
            <div class="card card-padded mt-4" id="voucherPanel">
                <div class="section-head">
                    <h3>Discount Voucher</h3>
                    <?php $offers = available_vouchers($userId); ?>
                    <?php if (count($offers) > 0): ?>
                        <button type="button" class="btn-outline btn-sm js-toggle-offers">
                            See available vouchers
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Applied state -->
                <div class="voucher-applied <?= $applied === null ? 'is-hidden' : '' ?>" id="voucherApplied">
                    <div class="voucher-chip">
                        <i class="fas fa-ticket"></i>
                        <span class="voucher-chip-code" id="voucherCode">
                            <?= e($applied['voucher']['code'] ?? '') ?>
                        </span>
                        <span class="muted small-note" id="voucherSummary">
                            <?= e($applied !== null ? voucher_summary($applied['voucher']) : '') ?>
                        </span>
                    </div>
                    <button type="button" class="btn-outline btn-sm js-voucher-remove">Remove</button>
                </div>

                <!-- Entry state -->
                <div class="voucher-entry <?= $applied === null ? '' : 'is-hidden' ?>" id="voucherEntry">
                    <div class="voucher-input-row">
                        <input type="text" id="voucherInput" class="form-control"
                               placeholder="Enter voucher code" maxlength="30" autocomplete="off">
                        <button type="button" class="btn-outline js-voucher-apply">Apply</button>
                    </div>
                    <div class="err" id="voucherError"></div>
                </div>

                <?php if (count($offers) > 0): ?>
                    <ul class="voucher-offers is-hidden" id="voucherOffers">
                        <?php foreach ($offers as $v): ?>
                            <li>
                                <button type="button" class="voucher-offer js-use-offer"
                                        data-code="<?= e($v['code']) ?>">
                                    <span class="voucher-offer-code"><?= e($v['code']) ?></span>
                                    <span class="voucher-offer-desc">
                                        <?= e($v['description'] ?: voucher_summary($v)) ?>
                                    </span>
                                    <?php if (!empty($v['expires_at'])): ?>
                                        <span class="muted small-note">
                                            Expires <?= e(fmt_date($v['expires_at'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <!-- Reward points -->
            <?php if (points_module_ready()): ?>
                <?php
                    $balance   = points_balance($userId);
                    $maxPoints = max_redeemable_points($userId, $afterVoucher);
                ?>
                <div class="card card-padded mt-4" id="pointsPanel">
                    <div class="section-head">
                        <h3>Reward Points</h3>
                        <span class="points-balance">
                            <i class="fas fa-star"></i>
                            <strong class="js-points-balance"><?= number_format($balance) ?></strong> available
                        </span>
                    </div>

                    <?php if ($balance < POINTS_MIN_REDEEM): ?>
                        <p class="muted small-note">
                            Collect <?= number_format(POINTS_MIN_REDEEM) ?> points to start redeeming.
                            You earn <?= POINTS_EARNED_PER_RM ?> point for every RM 1 you spend.
                        </p>

                    <?php elseif ($maxPoints < POINTS_MIN_REDEEM): ?>
                        <p class="muted small-note">
                            This order is too small to redeem points against.
                            Points can cover up to <?= POINTS_MAX_REDEEM_PERCENT ?>% of an order.
                        </p>

                    <?php else: ?>
                        <!-- Applied state -->
                        <div class="points-applied <?= $pointsApplied === null ? 'is-hidden' : '' ?>" id="pointsApplied">
                            <div class="voucher-chip">
                                <i class="fas fa-star"></i>
                                <span class="voucher-chip-code">
                                    <span class="js-points-used"><?= number_format($pointsApplied['points'] ?? 0) ?></span> points
                                </span>
                                <span class="muted small-note">
                                    worth <span class="js-points-value"><?= e(money($pointsDiscount)) ?></span>
                                </span>
                            </div>
                            <button type="button" class="btn-outline btn-sm js-points-remove">Remove</button>
                        </div>

                        <!-- Entry state -->
                        <div class="points-entry <?= $pointsApplied === null ? '' : 'is-hidden' ?>" id="pointsEntry">
                            <p class="muted small-note">
                                <?= number_format(POINTS_PER_RM_REDEEMED) ?> points = RM 1.00.
                                You can use up to
                                <strong class="js-points-max"><?= number_format($maxPoints) ?></strong>
                                points on this order.
                            </p>

                            <div class="voucher-input-row">
                                <input type="number" id="pointsInput" class="form-control"
                                       min="<?= POINTS_MIN_REDEEM ?>"
                                       max="<?= $maxPoints ?>"
                                       step="<?= POINTS_REDEEM_STEP ?>"
                                       value="<?= $maxPoints ?>"
                                       placeholder="Points to use">
                                <button type="button" class="btn-outline js-points-apply">Use Points</button>
                            </div>
                            <div class="err" id="pointsError"></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Order items -->
            <div class="card card-padded mt-4">
                <h3>Order Items</h3>

                <table class="cart-table">
                    <thead>
                        <tr>
                            <th colspan="2">Product</th>
                            <th>Unit Price</th>
                            <th>Qty</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="cell-thumb">
                                <img src="<?= e(product_image($item['image'])) ?>" alt="" class="cart-thumb">
                            </td>
                            <td>
                                <strong><?= e($item['name']) ?></strong>
                                <?php if ($item['options_label'] !== ''): ?>
                                    <div class="cart-options"><?= e($item['options_label']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(money($item['unit_price'])) ?></td>
                            <td>&times; <?= (int)$item['quantity'] ?></td>
                            <td class="cell-price"><?= e(money($item['unit_price'] * $item['quantity'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="muted small-note mt-2">
                    Need to change quantities? <a href="/cart.php">Go back to your cart</a>.
                </p>
            </div>
        </main>

        <!-- Summary -->
        <aside class="checkout-summary">
            <div class="card cart-summary">
                <h3>Order Summary</h3>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong class="js-subtotal"><?= e(money($total)) ?></strong>
                </div>
                <div class="summary-row">
                    <span>Shipping</span>
                    <strong>Free</strong>
                </div>
                <div class="summary-row summary-discount <?= $discount > 0 ? '' : 'is-hidden' ?>">
                    <span>Voucher discount</span>
                    <strong class="js-discount discount-value">&minus; <?= e(money($discount)) ?></strong>
                </div>
                <div class="summary-row summary-points <?= $pointsDiscount > 0 ? '' : 'is-hidden' ?>">
                    <span>Reward points</span>
                    <strong class="js-points-discount discount-value">&minus; <?= e(money($pointsDiscount)) ?></strong>
                </div>
                <div class="summary-row summary-total">
                    <span>Total</span>
                    <strong class="price js-total"><?= e(money($payable)) ?></strong>
                </div>

                <?php if (count($addresses) > 0): ?>
                    <?php html_submit('Place Order and Pay', ['class' => 'btn-primary btn-block btn-lg',
                                        'data-busy' => 'Redirecting to payment...']); ?>
                    <p class="muted small-note mt-2">
                        You will be taken to Stripe to complete payment securely.
                    </p>
                <?php else: ?>
                    <a href="/member/address_form.php?return=checkout" class="btn-primary btn-block btn-lg">
                        Add an Address First
                    </a>
                <?php endif; ?>
            </div>
        </aside>

    </div>
</form>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
