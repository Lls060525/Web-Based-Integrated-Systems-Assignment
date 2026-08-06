<?php
// ============================================================
// member/address_form.php - Add / edit a shipping address
// ============================================================

require_once __DIR__ . '/../lib/init.php';

require_member();

$userId = current_user_id();

if (!address_module_ready()) {
    flash_error('Address book is not available yet: run database/migration_08_address.sql.');
    redirect('/member/profile.php');
}

$id     = get_int('id');
$isEdit = $id !== null;

$address = [
    'label'          => 'Home',
    'recipient_name' => current_user()['name'] ?? '',
    'phone'          => '',
    'line1'          => '',
    'line2'          => '',
    'postcode'       => '',
    'city'           => '',
    'state'          => '',
    'is_default'     => 0,
];

if ($isEdit) {
    $found = find_user_address($id, $userId);

    if (!$found) {
        flash_error('Address not found.');
        redirect('/member/addresses.php');
    }
    $address = $found;
}

// Adding beyond the cap is refused on the server, not just hidden in the UI.
if (!$isEdit && address_count($userId) >= ADDRESS_MAX_PER_USER) {
    flash_error('You already have ' . ADDRESS_MAX_PER_USER . ' saved addresses. Delete one first.');
    redirect('/member/addresses.php');
}

if (is_post()) {
    csrf_check();

    $data        = validate_address_input();
    $makeDefault = post('is_default') === '1';

    if (no_err()) {
        if ($isEdit) {
            update_address($id, $userId, $data, $makeDefault);
            flash_success('Address updated.');
        } else {
            create_address($userId, $data, $makeDefault);
            flash_success('Address saved.');
        }

        // Coming from checkout? Go straight back there.
        redirect(get('return') === 'checkout' ? '/checkout.php' : '/member/addresses.php');
    }
}

$title = ($isEdit ? 'Edit' : 'Add') . ' Address - ' . APP_NAME;

include __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumb">
    <a href="/member/addresses.php">My Addresses</a> &gt;
    <span><?= $isEdit ? 'Edit' : 'Add New' ?></span>
</nav>

<div class="form-page">
    <div class="page-title-row">
        <h2 class="page-title"><?= $isEdit ? 'Edit Address' : 'Add New Address' ?></h2>
    </div>

    <div class="card card-padded">

        <?php err_summary(); ?>

        <form action="" method="POST" class="form-standard">
            <?php csrf_field(); ?>

            <?php field('label', 'Label', function () use ($address) {
                html_select('label', ADDRESS_LABELS, $address['label'], ['required' => true]);
            }, true); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('recipient_name', 'Recipient Name', function () use ($address) {
                        html_text('recipient_name', $address['recipient_name'], ['required' => true, 'maxlength' => 100]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('phone', 'Phone Number', function () use ($address) {
                        html_text('phone', $address['phone'], [
                            'required'    => true,
                            'maxlength'   => 20,
                            'placeholder' => '012-345 6789',
                        ]);
                    }, true); ?>
                </div>
            </div>

            <?php field('line1', 'Address Line 1', function () use ($address) {
                html_text('line1', $address['line1'], [
                    'required'    => true,
                    'maxlength'   => 150,
                    'placeholder' => 'Unit number, street name',
                ]);
            }, true); ?>

            <?php field('line2', 'Address Line 2 (optional)', function () use ($address) {
                html_text('line2', $address['line2'], [
                    'maxlength'   => 150,
                    'placeholder' => 'Building, area, landmark',
                ]);
            }); ?>

            <div class="form-row">
                <div class="form-col">
                    <?php field('postcode', 'Postcode', function () use ($address) {
                        html_text('postcode', $address['postcode'], [
                            'required'    => true,
                            'maxlength'   => 5,
                            'inputmode'   => 'numeric',
                            'placeholder' => '50400',
                        ]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('city', 'City', function () use ($address) {
                        html_text('city', $address['city'], ['required' => true, 'maxlength' => 60]);
                    }, true); ?>
                </div>

                <div class="form-col">
                    <?php field('state', 'State', function () use ($address) {
                        html_select('state', MY_STATES, $address['state'], ['required' => true], '-- Select state --');
                    }, true); ?>
                </div>
            </div>

            <?php field('country', 'Country', function () {
                html_text('country', 'Malaysia', ['readonly' => true, 'disabled' => true]);
            }); ?>

            <?php if (!(int)$address['is_default']): ?>
                <div class="form-group form-check">
                    <label for="is_default" class="check-label">
                        <input type="checkbox" name="is_default" id="is_default" value="1"
                               <?= post('is_default') === '1' ? 'checked' : '' ?>>
                        <span>Use this as my default shipping address</span>
                    </label>
                </div>
            <?php else: ?>
                <p class="muted small-note">This is currently your default shipping address.</p>
            <?php endif; ?>

            <div class="form-actions text-right">
                <a href="/member/addresses.php" class="btn-outline">Cancel</a>
                <?php html_submit($isEdit ? 'Save Changes' : 'Save Address'); ?>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
