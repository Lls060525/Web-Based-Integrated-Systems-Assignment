/* ============================================================
 * assets/js/main.js
 * Shared jQuery behaviour for the whole application.
 *
 * Convention: no page contains inline onclick / onchange / onsubmit.
 * Markup declares intent with a class or data-* attribute and the
 * handlers below are bound with jQuery.
 *
 *   data-confirm="message"   ask before following a link / submitting
 *   .js-auto-submit          submit the owning form when the value changes
 *   .js-submit-form          button that submits the form named in data-target
 *   .add-to-cart             AJAX add to cart, data-id holds the product id
 * ============================================================ */

$(function () {

    /* ---------- Toast notifications ---------- */

    function showToast(message, type) {
        $('.js-toast').remove();

        var cssClass = (type === 'success') ? 'toast-success' : 'toast-error';
        var $toast = $('<div>')
            .addClass('toast-message js-toast ' + cssClass)
            .text(message);

        $('body').append($toast);
        $toast.fadeIn(300).delay(3000).fadeOut(300, function () {
            $(this).remove();
        });
    }

    // Server-rendered flash messages fade in and out on their own.
    $('.toast-message').not('.js-toast').fadeIn(400).delay(3000).fadeOut(400);

    /* ---------- Mobile navigation ---------- */
    /*
     * The panel is closed by CSS (display: none) and opened by adding a
     * class, so with JavaScript off the links are simply always in the
     * flow rather than being unreachable behind a button that does
     * nothing. That is why .nav is hidden only inside the media query.
     */
    var $navToggle = $('#navToggle');

    if ($navToggle.length) {
        $navToggle.on('click', function () {
            var open = $('#mainNav').toggleClass('is-open').hasClass('is-open');

            $navToggle.attr('aria-expanded', open ? 'true' : 'false')
                      .find('i')
                      .toggleClass('fa-bars', !open)
                      .toggleClass('fa-xmark', open);
        });

        // Tapping outside, or pressing Escape, closes it.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.topbar-inner').length) {
                $('#mainNav').removeClass('is-open');
                $navToggle.attr('aria-expanded', 'false')
                          .find('i').addClass('fa-bars').removeClass('fa-xmark');
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#mainNav').hasClass('is-open')) {
                $navToggle.trigger('click');
            }
        });

        /* Growing past the breakpoint leaves .is-open applied to a nav
         * that is a plain row again. Harmless there, but it would come
         * back open on the way down, so it is cleared. */
        $(window).on('resize', function () {
            if (window.innerWidth > 720 && $('#mainNav').hasClass('is-open')) {
                $('#mainNav').removeClass('is-open');
                $navToggle.attr('aria-expanded', 'false')
                          .find('i').addClass('fa-bars').removeClass('fa-xmark');
            }
        });
    }

    /* ---------- Show / hide password ---------- */
    /*
     * Delegated, so it also covers any password field added to the page
     * later. The input's type is swapped rather than a second field
     * being shown, which keeps the value, the cursor position and the
     * browser's password manager all pointing at one element.
     */
    $(document).on('click', '.password-toggle', function () {
        var $btn   = $(this);
        var $input = $btn.closest('.password-field').find('input').first();

        if ($input.length === 0) { return; }

        var reveal = $input.attr('type') === 'password';

        $input.attr('type', reveal ? 'text' : 'password');

        $btn.attr('aria-pressed', reveal ? 'true' : 'false')
            .attr('aria-label', reveal ? 'Hide password' : 'Show password')
            .find('i')
            .toggleClass('fa-eye', !reveal)
            .toggleClass('fa-eye-slash', reveal);

        // Focus goes back to the field, at the end of the text, so the
        // person can keep typing. Without this the caret jumps to the
        // start on some browsers when the type changes.
        var value = $input.val();

        $input.trigger('focus').val('').val(value);
    });

    // A revealed password must not survive the page.
    // pageshow fires on a back/forward restore, which would otherwise
    // bring the form back with the password still in plain sight.
    $(window).on('pageshow', function () {
        $('.password-field input[type="text"]').attr('type', 'password');
        $('.password-toggle')
            .attr('aria-pressed', 'false')
            .attr('aria-label', 'Show password')
            .find('i').addClass('fa-eye').removeClass('fa-eye-slash');
    });

    /* ---------- Generic confirmation ---------- */
    // Replaces every inline onclick="return confirm(...)".

    // Links and standalone buttons carry data-confirm themselves.
    $(document).on('click', 'a[data-confirm], button[data-confirm]', function (e) {
        if (!window.confirm($(this).data('confirm'))) {
            e.preventDefault();
            // Stop any other handler on this element (e.g. .js-submit-form).
            e.stopImmediatePropagation();
        }
    });

    // A form carries data-confirm so its submit button stays plain markup.
    $(document).on('submit', 'form[data-confirm]', function (e) {
        if (!window.confirm($(this).data('confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    });

    /* NOTE ON ORDER: this is registered AFTER the confirmation handlers
     * on purpose. Both are delegated on document, so they run in
     * registration order. If the guard ran first it would mark the form
     * busy and disable its buttons, and then a cancelled confirm dialog
     * would leave that form permanently dead. Cancelling calls
     * stopImmediatePropagation(), so with this order the guard is simply
     * never reached. */
    /* ---------- Double submit guard ---------- */
    /*
     * One click, one request. Applies to every form on the site.
     *
     * Two details that are easy to get wrong:
     *
     * 1. The button is NOT disabled synchronously. A disabled control is
     *    excluded from the form data, so disabling it before the browser
     *    serialises the form would silently drop the submit button's own
     *    name and value. Disabling happens on the next tick instead,
     *    after serialisation.
     *
     * 2. The form is flagged busy immediately, so a genuine double click
     *    is stopped even in the gap before the button is disabled.
     *
     * This is only the visible half. Actions that really matter also
     * carry a one-use nonce checked by PHP, because a disabled attribute
     * lasts exactly as long as it takes to open dev tools.
     */
    $(document).on('submit', 'form', function (e) {
        var $form = $(this);

        /* An AJAX form has already called preventDefault() by the time this
         * runs: handlers bound directly on the element fire before a
         * delegated one on document. Such a form is NOT navigating away,
         * so there is nothing to guard against -- and disabling its button
         * would leave it stuck on "Working..." forever, because no page
         * load ever arrives to reset it.
         *
         * That is exactly what happened to the admin live search: the
         * button span and never came back. */
        if (e.isDefaultPrevented()) {
            return;
        }

        if ($form.data('submitting')) {
            e.preventDefault();
            return false;
        }

        // A form the confirm dialog just cancelled never gets here,
        // because that handler stops propagation first.
        $form.data('submitting', true);

        var $buttons = $form.find('button[type="submit"], input[type="submit"]');

        window.setTimeout(function () {
            $buttons.each(function () {
                var $b = $(this);

                if ($b.is('button')) {
                    $b.data('idle-text', $b.text());
                    $b.text($b.data('busy') || 'Working...');
                } else {
                    $b.data('idle-text', $b.val());
                    $b.val($b.data('busy') || 'Working...');
                }

                $b.prop('disabled', true).addClass('is-busy');
            });
        }, 0);

        // A failed request or a validation redirect brings the page back
        // from cache with the button still disabled, which looks broken.
        // pageshow fires on a back/forward restore, so the form is reset.
        return true;
    });

    $(window).on('pageshow', function () {
        $('form').removeData('submitting');

        $('.is-busy').each(function () {
            var $b = $(this);
            var idle = $b.data('idle-text');

            if (idle !== undefined) {
                if ($b.is('button')) { $b.text(idle); } else { $b.val(idle); }
            }

            $b.prop('disabled', false).removeClass('is-busy');
        });
    });

    /* ---------- Auto-submitting controls ---------- */
    // Replaces every inline onchange="this.form.submit()".

    $(document).on('change', '.js-auto-submit', function () {
        $(this).closest('form').trigger('submit');
    });

    // Button that submits a form living elsewhere in the page.
    $(document).on('click', '.js-submit-form', function () {
        var targetId = $(this).data('target');
        $('#' + targetId).trigger('submit');
    });

    /* ---------- Search box focus state ---------- */

    $('.search input')
        .on('focus', function () { $(this).closest('.search').addClass('focused'); })
        .on('blur',  function () { $(this).closest('.search').removeClass('focused'); });

    /* ---------- Add to cart (AJAX) ---------- */

    $(document).on('click', '.add-to-cart', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var originalText = $btn.text();

        // Customer-selected specs travel as options[attributeId] = value.
        // The server re-checks every one of them against what the product
        // actually offers, so this is convenience, not trust.
        var payload = {
            action: 'add',
            product_id: $btn.data('id'),
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        };

        $('.js-spec-option').each(function () {
            var $field = $(this);

            if ($field.is(':radio') && !$field.prop('checked')) { return; }

            payload['options[' + $field.data('attribute') + ']'] = $field.val();
        });

        $btn.text('Adding...').prop('disabled', true);

        $.ajax({
            url: '/api/cart_action.php',
            type: 'POST',
            dataType: 'json',
            data: payload,
            success: function (res) {
                if (res.status === 'success') {
                    $('.cart-count').text(res.cart_count);
                    showToast(res.message, 'success');
                } else {
                    showToast(res.message, 'error');
                    if (res.redirect) {
                        window.setTimeout(function () {
                            window.location.href = res.redirect;
                        }, 1500);
                    }
                }
            },
            error: function () {
                showToast('Server error. Please try again.', 'error');
            },
            complete: function () {
                $btn.text(originalText).prop('disabled', false);
            }
        });
    });

    /* ---------- Product configurator ---------- */
    // Live price, a running summary, and swapping the main photo when a
    // colour is chosen. The authoritative total is still the server's;
    // everything here is the label the customer reads while deciding.

    var $productPrice = $('#productPrice');

    if ($productPrice.length && $('.js-spec-option').length) {
        var basePrice = parseFloat($productPrice.data('base')) || 0;

        // Matches PHP's money(): two decimals, then thousands separators,
        // so the page never shows 3349.00 beside RM 3,349.00.
        var asMoney = function (value) {
            var parts = value.toFixed(2).split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return 'RM ' + parts.join('.');
        };

        var refreshConfig = function () {
            var delta = 0;
            var $lines = $('#configSummaryLines').empty();

            $lines.append(
                $('<div>').addClass('config-line').append(
                    $('<span>').text($('.product-detail-title').first().text() || 'Base price'),
                    $('<span>').text(asMoney(basePrice))
                )
            );

            $('.js-spec-option:checked').each(function () {
                var $field = $(this);
                var d = parseFloat($field.data('delta')) || 0;

                delta += d;

                // The chip beside the step heading, Apple style.
                $('.config-chosen[data-for="' + $field.data('attribute') + '"]')
                    .text($field.data('label'));

                $lines.append(
                    $('<div>').addClass('config-line').append(
                        $('<span>').text($field.data('label')),
                        $('<span>').text(d === 0 ? 'Included' : (d > 0 ? '+' : '') + asMoney(d))
                    )
                );
            });

            var total = basePrice + delta;

            $productPrice.text(asMoney(total));
            $('#configTotal').text(asMoney(total));

            // The sticky bar repeats the selection and the total, so the
            // customer can keep scrolling through specs and reviews
            // without losing sight of what they have configured.
            $('#configBarTotal').text(asMoney(total));
            $('#configBarSpec').text(
                $('.js-spec-option:checked').map(function () {
                    return $(this).data('label');
                }).get().join(' / ')
            );

            // Revealed only once it has something to say, so it does not
            // flash an unconfigured state while the page is still loading.
            $('#configBar').prop('hidden', false);
        };

        // Choosing a colour drives the existing gallery rather than a
        // second image widget: clicking the thumbnail reuses all of the
        // slider's own logic, including the counter and the active state.
        var showSlide = function ($field) {
            var slide = $field.data('slide');

            if (slide === undefined || slide === null) { return; }

            $('#productGallery').find('.gallery-thumb[data-index="' + slide + '"]').trigger('click');
        };

        $(document).on('change', '.js-spec-option', function () {
            refreshConfig();
            showSlide($(this));
        });

        refreshConfig();

        // On load, jump to the photo of whichever option starts selected.
        $('.js-spec-option:checked').each(function () { showSlide($(this)); });
    }

    /* ---------- Profile: sidebar tab switching ---------- */

    $('.profile-nav a[href^="#"]').on('click', function (e) {
        e.preventDefault();

        $('.profile-nav a').removeClass('active');
        $(this).addClass('active');

        $('.profile-content .card').hide();
        $($(this).attr('href')).fadeIn(300);
    });

    /* Profile photo preview is handled by assets/js/dropzone.js. */

    /* ---------- Profile: only offer Save when something changed ---------- */

    var $profileName    = $('#profileName');
    var $profileEmail   = $('#profileEmail');
    var $saveProfileBtn = $('#saveProfileBtn');

    function checkProfileChanges() {
        var changed = $profileName.val().trim() !== $profileName.data('original')
                   || $profileEmail.val().trim() !== $profileEmail.data('original');

        if (changed && !$saveProfileBtn.is(':visible')) {
            $saveProfileBtn.fadeIn(200);
        } else if (!changed && $saveProfileBtn.is(':visible')) {
            $saveProfileBtn.fadeOut(200);
        }
    }

    if ($profileName.length && $profileEmail.length) {
        $profileName.on('input', checkProfileChanges);
        $profileEmail.on('input', checkProfileChanges);
    }

    /* ---------- Cart: quantity change ---------- */
    /* This used to submit the whole form, which meant a full page load
     * for one digit. Three separate movements came out of that: the
     * scroll position reset, a "Cart updated." banner appeared at the
     * top and pushed the page down, and the table re-laid itself out.
     *
     * Now the server is asked for the new numbers and they are written
     * into the cells that already exist. Nothing is inserted, nothing is
     * removed, so nothing moves.
     *
     * Every figure comes from the response. Working the totals out in
     * JavaScript would be faster to write and would eventually disagree
     * with what checkout charges. */

    var qtyTimers   = {};
    var qtySeq      = 0;
    var qtyRendered = 0;

    /* The last value the SERVER confirmed for this line.
     *
     * Needed because the box can be left in a state the server never
     * saw: clearing it to type a new number leaves it empty, and an
     * empty box has no number to fall back to on its own. Seeded from
     * the rendered value in data-last-quantity. */
    function lastQuantity($input) {
        var last = parseInt($input.attr('data-last-quantity'), 10);

        return isNaN(last) || last < 1 ? 1 : last;
    }

    function updateCartQuantity($input) {
        var cartId   = $input.data('cart-id');
        var quantity = parseInt($input.val(), 10);

        // Nothing usable typed yet. Deliberately does NOT correct the box
        // here -- somebody clearing it in order to type "12" is mid-edit,
        // and rewriting the field under their cursor would fight them.
        // The blur handler below tidies up once they have finished.
        if (!cartId || isNaN(quantity) || quantity < 1) { return; }

        var $row    = $input.closest('tr');
        var $total  = $('[data-line-total="' + cartId + '"]');
        var mySeq   = ++qtySeq;

        $row.addClass('is-updating');

        $.ajax({
            url: '/api/cart_update.php',
            type: 'POST',
            dataType: 'json',
            data: {
                cart_id: cartId,
                quantity: quantity,
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            }
        }).done(function (res) {
            // A slow earlier request must not overwrite a faster later
            // one. Same guard as the admin live search.
            if (mySeq < qtyRendered) { return; }
            qtyRendered = mySeq;

            if (res.status !== 'ok') {
                showToast(res.message || 'Could not update the quantity.', 'error');
                return;
            }

            $total.text(res.line_total);
            $('[data-cart-total-price]').text(res.total_price);
            $('[data-cart-total-items]').text(res.total_items);
            $('.cart-count').text(res.cart_count);

            // The server may have reduced the number to what is actually
            // in stock. Write it back so the box agrees with the total
            // beside it, and say why -- silently changing what someone
            // typed is worse than the extra message.
            if (res.quantity !== quantity) {
                $input.val(res.quantity);
            }

            // Remember what was actually saved, so an empty box can be
            // restored to it rather than guessed at.
            $input.attr('data-last-quantity', res.quantity);

            if (res.clamped && res.message) {
                showToast(res.message, 'error');
            }

        }).fail(function () {
            // Fall back to the old full-page submit rather than leaving
            // the member looking at a number that was never saved.
            showToast('Could not reach the server. Reloading your cart.', 'error');
            $('#updateCartForm').trigger('submit');

        }).always(function () {
            $row.removeClass('is-updating');
        });
    }

    $('.update-qty-trigger').on('change input', function () {
        var $input = $(this);
        var cartId = $input.data('cart-id');

        // Holding down a spinner arrow fires an event per step. Without
        // this, going from 1 to 8 would send eight requests and the
        // totals would flicker through seven wrong values on the way.
        window.clearTimeout(qtyTimers[cartId]);

        qtyTimers[cartId] = window.setTimeout(function () {
            updateCartQuantity($input);
        }, 350);
    });

    /* Leaving the box empty is a dead end without this.
     *
     * Selecting the contents and deleting them is how most people start
     * typing a new number, so an empty box has to be allowed WHILE the
     * field has focus. But if they then click away -- or clear it and
     * change their mind -- nothing would ever put a number back, and the
     * line would sit there blank next to a subtotal that no longer
     * explains itself.
     *
     * On blur the box is put back to whatever the server last confirmed,
     * which is also what the cart is actually holding. Nothing is saved
     * here: this only makes the field tell the truth again. */
    $('.update-qty-trigger').on('blur', function () {
        var $input   = $(this);
        var quantity = parseInt($input.val(), 10);

        if (isNaN(quantity) || quantity < 1) {
            // A pending debounce would fire against the restored value
            // and send a request that changes nothing.
            window.clearTimeout(qtyTimers[$input.data('cart-id')]);

            $input.val(lastQuantity($input));
        }
    });

    /* ---------- Order cancellation form ---------- */
    // Choosing "Other reason" makes the note compulsory. The server
    // enforces the same rule; this is only to guide the user.

    var $cancelReason = $('#cancelReason');
    var $cancelNote   = $('#cancelNote');

    if ($cancelReason.length && $cancelNote.length) {
        $cancelReason.on('change', function () {
            var needsNote = $(this).val() === 'other';

            $cancelNote
                .prop('required', needsNote)
                .attr('placeholder', needsNote
                    ? 'Please describe your reason.'
                    : 'Optional, unless you selected "Other reason".');

            $cancelNote.closest('.form-group').toggleClass('is-highlighted', needsNote);

            if (needsNote) {
                $cancelNote.trigger('focus');
            }
        });
    }

    /* ---------- Wishlist toggle (AJAX) ---------- */
    // The heart flips optimistically only after the server confirms,
    // so the icon can never disagree with the database.

    $(document).on('click', '.js-wishlist', function (e) {
        e.preventDefault();

        var $btn = $(this);

        // 'submitting', not 'busy': data-busy is now the attribute that
        // holds a button's working LABEL, so reusing the name for an
        // in-flight flag would be a trap for the next reader.
        if ($btn.data('submitting')) { return; }
        $btn.data('submitting', true);

        $.ajax({
            url: '/api/wishlist_action.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'toggle',
                product_id: $btn.data('id'),
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function (res) {
                if (res.status !== 'success') {
                    showToast(res.message, 'error');

                    if (res.redirect) {
                        window.setTimeout(function () {
                            window.location.href = res.redirect;
                        }, 1500);
                    }
                    return;
                }

                // Every heart for this product on the page stays in sync.
                var $all = $('.js-wishlist[data-id="' + $btn.data('id') + '"]');

                $all.toggleClass('is-saved', res.added)
                    .attr('aria-pressed', res.added ? 'true' : 'false')
                    .attr('title', res.added ? 'Remove from wishlist' : 'Save to wishlist');

                $all.find('i')
                    .toggleClass('fas', res.added)
                    .toggleClass('far', !res.added);

                $all.find('.wishlist-label')
                    .text(res.added ? 'Saved to Wishlist' : 'Save to Wishlist');

                $('.wishlist-count').text(res.wishlist_count);

                if (res.added) {
                    $btn.addClass('just-saved');
                    window.setTimeout(function () { $btn.removeClass('just-saved'); }, 400);
                }

                showToast(res.message, 'success');
            },
            error: function () {
                showToast('Server error. Please try again.', 'error');
            },
            complete: function () {
                $btn.data('submitting', false);
            }
        });
    });

    /* ---------- Discount voucher (AJAX) ---------- */
    // The server returns the recalculated totals, so this code never
    // does any arithmetic of its own.

    function voucherRequest(data, $btn) {
        var $err = $('#voucherError');

        $err.text('');
        if ($btn) { $btn.prop('disabled', true); }

        data.csrf_token = $('meta[name="csrf-token"]').attr('content');

        $.ajax({
            url: '/api/voucher_action.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res.status !== 'success') {
                    $err.text(res.message);

                    if (res.redirect) {
                        window.setTimeout(function () {
                            window.location.href = res.redirect;
                        }, 1500);
                    }
                    return;
                }

                // Totals come straight from the server.
                $('.js-subtotal').text(res.subtotal);
                $('.js-total').text(res.total);

                if (res.applied) {
                    $('#voucherCode').text(res.code);
                    $('#voucherSummary').text(res.summary);
                    $('#voucherApplied').removeClass('is-hidden');
                    $('#voucherEntry').addClass('is-hidden');
                    $('#voucherOffers').addClass('is-hidden');

                    $('.js-discount').text('\u2212 ' + res.discount);
                    $('.summary-discount').removeClass('is-hidden');
                } else {
                    $('#voucherApplied').addClass('is-hidden');
                    $('#voucherEntry').removeClass('is-hidden');
                    $('#voucherInput').val('');
                    $('.summary-discount').addClass('is-hidden');
                }

                showToast(res.message, 'success');
            },
            error: function () {
                $err.text('Server error. Please try again.');
            },
            complete: function () {
                if ($btn) { $btn.prop('disabled', false); }
            }
        });
    }

    $(document).on('click', '.js-voucher-apply', function () {
        var code = $('#voucherInput').val().trim();

        if (code === '') {
            $('#voucherError').text('Please enter a voucher code.');
            return;
        }

        voucherRequest({ action: 'apply', code: code }, $(this));
    });

    // Enter inside the code box applies rather than submitting the order.
    $(document).on('keydown', '#voucherInput', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('.js-voucher-apply').trigger('click');
        }
    });

    $(document).on('click', '.js-voucher-remove', function () {
        voucherRequest({ action: 'remove' }, $(this));
    });

    $(document).on('click', '.js-toggle-offers', function () {
        $('#voucherOffers').slideToggle(180);
    });

    $(document).on('click', '.js-use-offer', function () {
        var code = $(this).data('code');
        $('#voucherInput').val(code);
        voucherRequest({ action: 'apply', code: code }, null);
    });

    // Voucher codes are stored uppercase, so show them that way as you type.
    $(document).on('input', '#voucherInput, .voucher-code-input', function () {
        var pos = this.selectionStart;
        $(this).val($(this).val().toUpperCase());
        this.setSelectionRange(pos, pos);
    });

    /* ---------- Login lockout countdown ---------- */
    // Cosmetic only. The server refuses a locked login regardless of
    // what this timer says, so disabling the form is a courtesy, not
    // the security control.

    var $lockPanel = $('.lockout-panel');

    if ($lockPanel.length) {
        var secondsLeft = parseInt($lockPanel.data('seconds'), 10) || 0;
        var $countdown  = $('#lockCountdown');
        var $submit     = $('#loginSubmit');

        $submit.prop('disabled', true).text('Locked');

        var formatLeft = function (total) {
            if (total < 60) {
                return total + ' second' + (total === 1 ? '' : 's');
            }

            var mins = Math.floor(total / 60);
            var secs = total % 60;
            var text = mins + ' minute' + (mins === 1 ? '' : 's');

            if (secs > 0) {
                text += ' ' + secs + ' second' + (secs === 1 ? '' : 's');
            }
            return text;
        };

        var tick = window.setInterval(function () {
            secondsLeft -= 1;

            if (secondsLeft <= 0) {
                window.clearInterval(tick);
                $lockPanel.slideUp(200);
                $submit.prop('disabled', false).text('Log In');
                return;
            }

            $countdown.text(formatLeft(secondsLeft));
        }, 1000);
    }

    /* ---------- Reward points (AJAX) ---------- */
    // Same contract as the voucher box: the server returns every total,
    // this code only paints them.

    function pointsRequest(data, $btn) {
        var $err = $('#pointsError');

        $err.text('');
        if ($btn) { $btn.prop('disabled', true); }

        data.csrf_token = $('meta[name="csrf-token"]').attr('content');

        $.ajax({
            url: '/api/points_action.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res.status !== 'success') {
                    $err.text(res.message);

                    if (res.redirect) {
                        window.setTimeout(function () {
                            window.location.href = res.redirect;
                        }, 1500);
                    }
                    return;
                }

                $('.js-subtotal').text(res.subtotal);
                $('.js-total').text(res.total);
                $('.js-points-balance').text(res.balance);
                $('.js-points-max').text(res.max_points.toLocaleString());
                $('#pointsInput').attr('max', res.max_points);

                if (res.applied) {
                    $('.js-points-used').text(res.points.toLocaleString());
                    $('.js-points-value').text(res.points_discount);
                    $('.js-points-discount').text('\u2212 ' + res.points_discount);

                    $('#pointsApplied').removeClass('is-hidden');
                    $('#pointsEntry').addClass('is-hidden');
                    $('.summary-points').removeClass('is-hidden');
                } else {
                    $('#pointsApplied').addClass('is-hidden');
                    $('#pointsEntry').removeClass('is-hidden');
                    $('.summary-points').addClass('is-hidden');
                }

                showToast(res.message, 'success');
            },
            error: function () {
                $err.text('Server error. Please try again.');
            },
            complete: function () {
                if ($btn) { $btn.prop('disabled', false); }
            }
        });
    }

    $(document).on('click', '.js-points-apply', function () {
        var points = parseInt($('#pointsInput').val(), 10);

        if (!points || points <= 0) {
            $('#pointsError').text('Enter how many points you would like to use.');
            return;
        }

        pointsRequest({ action: 'apply', points: points }, $(this));
    });

    $(document).on('keydown', '#pointsInput', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('.js-points-apply').trigger('click');
        }
    });

    $(document).on('click', '.js-points-remove', function () {
        pointsRequest({ action: 'remove' }, $(this));
    });

    /* ---------- Star rating picker ---------- */
    // Progressive enhancement: the radio buttons work on their own,
    // this only makes them look and behave like stars.

    var $starInput = $('#starInput');

    if ($starInput.length) {
        var labels = {
            1: 'Poor', 2: 'Fair', 3: 'Good', 4: 'Very good', 5: 'Excellent'
        };

        var paintStars = function (value) {
            $starInput.find('i').each(function () {
                var star = parseInt($(this).data('star'), 10);
                $(this).toggleClass('fas', star <= value)
                       .toggleClass('far', star > value);
            });
        };

        var selectedValue = function () {
            return parseInt($starInput.find('input:checked').val(), 10) || 0;
        };

        // Hovering previews, leaving restores the real choice.
        $starInput.on('mouseenter', 'i', function () {
            paintStars(parseInt($(this).data('star'), 10));
        });

        $starInput.on('mouseleave', function () {
            paintStars(selectedValue());
        });

        $starInput.on('change', 'input', function () {
            var value = parseInt($(this).val(), 10);
            paintStars(value);
            $('#starLabel').text(labels[value] || '');
        });

        paintStars(selectedValue());
    }

    /* ---------- Review body character counter ---------- */

    var $reviewBody = $('#reviewBody');

    if ($reviewBody.length) {
        var updateCount = function () {
            $('#bodyCount').text($reviewBody.val().length);
        };

        $reviewBody.on('input', updateCount);
        updateCount();
    }

    /* ---------- Product photo slider ---------- */
    // Hand-written: it is a list of slides plus an active index.

    var $gallery = $('#productGallery');

    if ($gallery.length) {
        var $slides = $gallery.find('.gallery-slide');
        var $thumbs = $gallery.find('.gallery-thumb');
        var total   = $slides.length;
        var index   = 0;

        function show(next) {
            // Wrap around at both ends.
            index = (next + total) % total;

            $slides.removeClass('is-active').eq(index).addClass('is-active');
            $thumbs.removeClass('is-active').eq(index).addClass('is-active');
            $gallery.find('.gallery-current').text(index + 1);

            // Let the video handler know it should stop playing.
            $(document).trigger('gallery:change');
        }

        $gallery.on('click', '.gallery-next', function () { show(index + 1); });
        $gallery.on('click', '.gallery-prev', function () { show(index - 1); });

        $gallery.on('click', '.gallery-thumb', function (e) {
            e.preventDefault();
            show(parseInt($(this).data('index'), 10));
        });

        // Arrow keys, but only while the gallery has focus, so they do
        // not hijack the page for someone scrolling normally.
        $gallery.attr('tabindex', 0).on('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); show(index + 1); }
            if (e.key === 'ArrowLeft')  { e.preventDefault(); show(index - 1); }
        });
    }

    /* ---------- YouTube facade ---------- */
    // The iframe is created only when the visitor presses play, so a
    // page with a product video makes no request to YouTube, loads no
    // third-party script and sets no cookie unless it is watched.

    $(document).on('click', '.js-play-video', function () {
        var $facade  = $(this).closest('.video-facade');
        var videoId  = $facade.data('video-id');

        if (!videoId) { return; }

        // youtube-nocookie.com is the privacy-enhanced host.
        var src = 'https://www.youtube-nocookie.com/embed/'
                + encodeURIComponent(videoId)
                + '?autoplay=1&rel=0&modestbranding=1&playsinline=1';

        var $frame = $('<iframe>')
            .attr({
                src: src,
                title: 'Product video',
                allow: 'accelerometer; autoplay; encrypted-media; picture-in-picture',
                referrerpolicy: 'strict-origin-when-cross-origin',
                allowfullscreen: 'allowfullscreen',
                frameborder: '0'
            })
            .addClass('video-frame');

        $facade.replaceWith($frame);
    });

    // YouTube thumbnails are remote, so fall back to the product photo
    // if the network is unavailable rather than showing a broken image.
    $('img[data-fallback]').on('error', function () {
        var fallback = $(this).data('fallback');

        if (fallback && this.src !== fallback) {
            this.src = fallback;
        }
    });

    // Leaving a slide should stop whatever is playing on it, otherwise
    // audio keeps going from a photo the visitor has scrolled past.
    $(document).on('gallery:change', function () {
        $('.video-frame').each(function () {
            var $frame = $(this);
            var src    = $frame.attr('src');

            if (src && src.indexOf('autoplay=1') !== -1) {
                $frame.attr('src', src.replace('autoplay=1', 'autoplay=0'));
            }
        });
    });

    /* ---------- CAPTCHA refresh ---------- */
    // Requesting the endpoint again generates a brand new challenge on
    // the server, so the old answer stops working the moment this runs.

    $(document).on('click', '.js-captcha-refresh', function () {
        var $img = $(this).closest('.captcha-image-wrap').find('.js-captcha-image');

        $img.attr('src', '/api/captcha_image.php?form=' + encodeURIComponent($img.data('form'))
                       + '&t=' + Date.now());

        $(this).closest('.captcha-group').find('.captcha-input').val('').trigger('focus');
    });

    // Clicking the image itself is the habit most people have.
    $(document).on('click', '.js-captcha-image', function () {
        $(this).closest('.captcha-image-wrap').find('.js-captcha-refresh').trigger('click');
    });

    /* ---------- Printable receipt ---------- */

    $(document).on('click', '.js-print', function (e) {
        e.preventDefault();
        window.print();
    });

    /* ---------- Back/forward cache ---------- */
    // A logged-out page must not be restorable from the bfcache.

    $(window).on('pageshow', function (event) {
        if (event.originalEvent && event.originalEvent.persisted) {
            window.location.reload();
        }
    });

});
