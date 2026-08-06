<?php
// ============================================================
// includes/address_parts.php
// Reusable address blocks, shared by the address book and checkout.
// ============================================================

if (!function_exists('render_address_card')) {

    /**
     * One saved address as a card.
     *
     * @param string $mode 'manage'  full card with Edit / Delete / Set default
     *                     'select'  radio button for choosing at checkout
     */
    function render_address_card(array $a, string $mode = 'manage', bool $checked = false): void
    {
        $isDefault = (int)$a['is_default'] === 1;
        ?>
        <div class="address-card <?= $isDefault ? 'is-default' : '' ?>">

            <?php if ($mode === 'select'): ?>
                <label class="address-choice">
                    <input type="radio" name="address_id" value="<?= (int)$a['id'] ?>"
                           <?= $checked ? 'checked' : '' ?> required>
                    <span class="address-choice-body">
            <?php endif; ?>

            <div class="address-head">
                <span class="badge"><?= e($a['label']) ?></span>
                <?php if ($isDefault): ?>
                    <span class="badge badge-success">Default</span>
                <?php endif; ?>
            </div>

            <div class="address-body">
                <strong><?= e($a['recipient_name']) ?></strong>
                <span class="muted"><?= e($a['phone']) ?></span>
                <div class="muted address-lines"><?= nl2br(e(format_address_short($a))) ?></div>
            </div>

            <?php if ($mode === 'select'): ?>
                    </span>
                </label>
            <?php else: ?>
                <div class="address-actions">
                    <a href="/member/address_form.php?id=<?= (int)$a['id'] ?>" class="btn-outline btn-sm">Edit</a>

                    <?php if (!$isDefault): ?>
                        <form action="/member/addresses.php" method="POST" class="inline-form">
                            <?php csrf_field(); ?>
                            <?php html_hidden('action', 'set_default'); ?>
                            <?php html_hidden('id', $a['id']); ?>
                            <?php html_submit('Set as Default', ['class' => 'btn-outline btn-sm']); ?>
                        </form>

                        <form action="/member/addresses.php" method="POST" class="inline-form"
                              data-confirm="Delete this address?&#10;&#10;Past orders keep their own copy, so your order history will not change.">
                            <?php csrf_field(); ?>
                            <?php html_hidden('action', 'delete'); ?>
                            <?php html_hidden('id', $a['id']); ?>
                            <?php html_submit('Delete', ['class' => 'btn-outline btn-sm btn-danger']); ?>
                        </form>
                    <?php else: ?>
                        <span class="muted small-note">Set another address as default before deleting this one.</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The whole address book, or an empty state. */
    function render_address_list(array $addresses, string $mode = 'manage', ?int $selectedId = null): void
    {
        if (count($addresses) === 0) {
            echo '<div class="card empty-state-box">'
               . '<h3 class="empty-state-title">No saved addresses yet.</h3>'
               . '<p>Add one so checkout only takes a click.</p>'
               . '<a href="/member/address_form.php" class="btn-primary shop-now-btn">Add an Address</a>'
               . '</div>';
            return;
        }

        echo '<div class="address-grid">';
        foreach ($addresses as $a) {
            $checked = $selectedId === null
                ? (int)$a['is_default'] === 1
                : $selectedId === (int)$a['id'];

            render_address_card($a, $mode, $checked);
        }
        echo '</div>';
    }
}
