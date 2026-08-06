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

        $btn.text('Adding...').prop('disabled', true);

        $.ajax({
            url: '/api/cart_action.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'add',
                product_id: $btn.data('id'),
                csrf_token: $('meta[name="csrf-token"]').attr('content')
            },
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

    /* ---------- Profile: sidebar tab switching ---------- */

    $('.profile-nav a[href^="#"]').on('click', function (e) {
        e.preventDefault();

        $('.profile-nav a').removeClass('active');
        $(this).addClass('active');

        $('.profile-content .card').hide();
        $($(this).attr('href')).fadeIn(300);
    });

    /* ---------- Profile: live photo preview ---------- */

    $('#photoInput').on('change', function () {
        var file = this.files[0];
        if (!file) { return; }

        var reader = new FileReader();
        reader.onload = function (evt) {
            $('#avatarPreview').attr('src', evt.target.result);
            $('#uploadBtn').fadeIn(200);
        };
        reader.readAsDataURL(file);
    });

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

    /* ---------- Cart: quantity change and item removal ---------- */

    $('.update-qty-trigger').on('change', function () {
        $('#updateCartForm').trigger('submit');
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

        if ($btn.data('busy')) { return; }
        $btn.data('busy', true);

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
                $btn.data('busy', false);
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
