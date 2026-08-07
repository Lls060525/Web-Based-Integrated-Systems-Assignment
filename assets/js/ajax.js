/* ============================================================
 * assets/js/ajax.js
 * The AJAX features that are about the page rather than about one
 * widget: live search suggestions, load-more, and the inline email
 * check on registration.
 *
 * Three problems this file exists to solve properly:
 *
 *   1. DEBOUNCING. A request per keystroke means "iphone" fires six
 *      of them and five are thrown away.
 *
 *   2. OUT-OF-ORDER RESPONSES. This is the bug that makes live search
 *      feel broken. Type "iph", then "iphone". If the "iph" query is
 *      slower, its response arrives LAST and overwrites the correct
 *      results with stale ones. Debouncing reduces it; it does not fix
 *      it. Every request carries a sequence number and anything older
 *      than what has already been rendered is dropped.
 *
 *   3. ESCAPING. Everything here is inserted with .text(), never
 *      .html(), except the one endpoint that deliberately returns
 *      server-rendered markup.
 * ============================================================ */

$(function () {

    /* ---------- A small debounce ---------- */

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var context = this;
            var args = arguments;

            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(context, args);
            }, wait);
        };
    }

    /* ============================================================
     * Live search suggestions
     * ============================================================ */

    var $searchForm = $('form.search').first();

    if ($searchForm.length) {
        var $input = $searchForm.find('input[name="q"]');
        var $panel = $('<div>').addClass('suggest-panel').attr('hidden', true);

        $searchForm.addClass('has-suggest').append($panel);

        // The input drives a listbox, so it is a combobox. Without these
        // a screen reader announces a plain text field and never mentions
        // that results appeared underneath it.
        $input.attr({
            'role': 'combobox',
            'aria-autocomplete': 'list',
            'aria-expanded': 'false',
            'autocomplete': 'off'
        });
        $panel.attr('role', 'listbox');

        var seq       = 0;   // incremented per request
        var rendered  = 0;   // the newest sequence already drawn
        var inFlight  = null;
        var activeIdx = -1;

        function closePanel() {
            $panel.attr('hidden', true).empty();
            $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            activeIdx = -1;
        }

        function highlight(index) {
            var $items = $panel.find('.suggest-item');

            if ($items.length === 0) { return; }

            // Wraps at both ends, so Up from the first goes to the last.
            activeIdx = (index + $items.length) % $items.length;

            $items.removeClass('is-active').attr('aria-selected', 'false');

            var $chosen = $items.eq(activeIdx)
                .addClass('is-active')
                .attr('aria-selected', 'true');

            $input.attr('aria-activedescendant', $chosen.attr('id'));

            // Keeps the highlighted row inside the scrollable panel.
            var top = $chosen.position().top;
            var h   = $chosen.outerHeight();

            if (top < 0) {
                $panel.scrollTop($panel.scrollTop() + top);
            } else if (top + h > $panel.height()) {
                $panel.scrollTop($panel.scrollTop() + top + h - $panel.height());
            }
        }

        function render(data) {
            $panel.empty();
            activeIdx = -1;

            var hasAnything = (data.products && data.products.length)
                           || (data.categories && data.categories.length);

            if (!hasAnything) {
                $panel.append(
                    $('<div>').addClass('suggest-empty')
                              .text('Nothing matches "' + data.term + '".')
                );

                $panel.removeAttr('hidden');
                $input.attr('aria-expanded', 'true');
                return;
            }

            var index = 0;

            $.each(data.categories || [], function (i, cat) {
                $panel.append(
                    $('<a>')
                        .attr({ 'href': cat.url, 'id': 'sg' + (index++), 'role': 'option',
                                'aria-selected': 'false' })
                        .addClass('suggest-item suggest-category')
                        .append(
                            $('<i>').addClass('fas fa-folder'),
                            $('<span>').text('Browse ' + cat.name)
                        )
                );
            });

            $.each(data.products || [], function (i, product) {
                var $row = $('<a>')
                    .attr({ 'href': product.url, 'id': 'sg' + (index++), 'role': 'option',
                            'aria-selected': 'false' })
                    .addClass('suggest-item');

                $row.append($('<img>').attr({ 'src': product.image, 'alt': '' })
                                      .addClass('suggest-thumb'));

                var $text = $('<span>').addClass('suggest-text');

                // .text(), not .html(): a product name is user-visible
                // data and must never be parsed as markup here.
                $text.append($('<span>').addClass('suggest-name').text(product.name));

                if (product.category) {
                    $text.append($('<span>').addClass('suggest-meta').text(product.category));
                }

                $row.append($text);
                $row.append(
                    $('<span>').addClass('suggest-price')
                        .text(product.in_stock ? product.price : 'Out of stock')
                );

                $panel.append($row);
            });

            if (data.total > (data.products || []).length) {
                $panel.append(
                    $('<a>').attr({ 'href': data.more_url, 'id': 'sg' + (index++),
                                    'role': 'option', 'aria-selected': 'false' })
                            .addClass('suggest-item suggest-more')
                            .text('See all ' + data.total + ' results')
                );
            }

            $panel.removeAttr('hidden');
            $input.attr('aria-expanded', 'true');
        }

        var lookup = debounce(function () {
            var term = $.trim($input.val());

            if (term.length < 2) {
                closePanel();
                return;
            }

            // An in-flight request whose answer is already obsolete is
            // aborted rather than left to finish and be discarded.
            if (inFlight) { inFlight.abort(); }

            var mySeq = ++seq;

            inFlight = $.ajax({
                url: '/api/search_suggest.php',
                data: { q: term },
                dataType: 'json'
            }).done(function (data) {
                // THE ORDERING GUARD. A slower earlier request must not
                // overwrite the results of a later one.
                if (mySeq < rendered) { return; }

                rendered = mySeq;

                if (data && data.status === 'ok') { render(data); }

            }).fail(function (xhr, status) {
                // An abort is not a failure; it is this code's own doing.
                if (status !== 'abort') { closePanel(); }

            }).always(function () {
                inFlight = null;
            });
        }, 220);

        $input.on('input', lookup);

        $input.on('focus', function () {
            if ($panel.children().length) {
                $panel.removeAttr('hidden');
                $input.attr('aria-expanded', 'true');
            }
        });

        $input.on('keydown', function (e) {
            var open = !$panel.attr('hidden') && $panel.find('.suggest-item').length > 0;

            if (e.key === 'Escape') { closePanel(); return; }
            if (!open) { return; }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                highlight(activeIdx + 1);

            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                highlight(activeIdx - 1);

            } else if (e.key === 'Enter' && activeIdx >= 0) {
                // Only hijack Enter when a suggestion is actually
                // highlighted; otherwise the form submits as normal and
                // the page still works without any of this.
                e.preventDefault();
                window.location.href = $panel.find('.suggest-item').eq(activeIdx).attr('href');
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.search').length) { closePanel(); }
        });
    }

    /* ============================================================
     * Load more products
     * ============================================================ */

    var $loadMore = $('#loadMore');

    if ($loadMore.length) {
        var $grid = $('#productGrid');
        var busy  = false;

        $loadMore.on('click', function () {
            if (busy) { return; }

            busy = true;

            var nextPage = parseInt($loadMore.data('next'), 10);
            var params   = $loadMore.data('query') || '';

            $loadMore.prop('disabled', true).addClass('is-busy').text('Loading...');

            $.ajax({
                url: '/api/products_page.php?' + params + '&page=' + nextPage,
                dataType: 'json'
            }).done(function (data) {
                if (!data || data.status !== 'ok') {
                    $loadMore.text('Could not load more. Try again.');
                    return;
                }

                // .html() here IS deliberate: this response is markup the
                // server rendered with the same template the first page
                // used, not data being pasted into a string.
                var $new = $(data.html);

                $grid.append($new);

                $loadMore.data('next', data.page + 1);
                $('#resultCount').text(data.shown + ' of ' + data.total);

                if (data.has_more) {
                    $loadMore.text('Load More');
                } else {
                    $loadMore.replaceWith(
                        $('<p>').addClass('muted small-note text-center')
                                .text('That is everything (' + data.total + ' products).')
                    );
                }

            }).fail(function () {
                $loadMore.text('Could not load more. Try again.');

            }).always(function () {
                busy = false;
                $loadMore.prop('disabled', false).removeClass('is-busy');
            });
        });
    }

    /* ============================================================
     * Inline email availability on registration
     * ============================================================ */

    var $emailField = $('#registerEmail');

    if ($emailField.length) {
        var $hint = $('<small>').addClass('form-hint email-check').attr('aria-live', 'polite');

        $emailField.after($hint);

        var emailSeq = 0;

        var checkEmail = debounce(function () {
            var value = $.trim($emailField.val());

            if (value.indexOf('@') === -1 || value.length < 5) {
                $hint.text('').removeClass('is-taken is-free');
                return;
            }

            var mySeq = ++emailSeq;

            $.ajax({
                url: '/api/check_email.php',
                data: { email: value },
                dataType: 'json'
            }).done(function (data) {
                if (mySeq !== emailSeq) { return; }      // same ordering guard
                if (!data || !data.checked) { $hint.text(''); return; }

                $hint.text(data.message)
                     .toggleClass('is-taken', data.known)
                     .toggleClass('is-free', !data.known);
            });
        }, 400);

        $emailField.on('input blur', checkEmail);
    }
});
