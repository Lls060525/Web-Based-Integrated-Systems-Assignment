/* ============================================================
 * assets/js/admin.js
 * jQuery behaviour used only by the admin panel.
 * Loaded by includes/admin_header.php after main.js.
 * ============================================================ */

$(function () {

    /* ---------- Collapsible sidebar ----------
     *
     * Two behaviours from one button, because the sidebar means
     * different things at different widths:
     *
     *   desktop  a column that can be collapsed to reclaim space
     *            -> .collapsed
     *   mobile   an overlay that is hidden until asked for
     *            -> .is-open
     *
     * The CSS decides which of the two applies at the current width;
     * this only has to set both classes and let the media query pick.
     */
    var MOBILE_SHELL = 900;

    function isMobileShell() {
        return window.matchMedia('(max-width: ' + MOBILE_SHELL + 'px)').matches;
    }

    $('#sidebarToggle').on('click', function (e) {
        e.preventDefault();

        if (isMobileShell()) {
            $('#sidebar').toggleClass('is-open');
        } else {
            $('#sidebar').toggleClass('collapsed');
        }
    });

    // Tapping the dimmed backdrop, or any nav link, closes the overlay.
    $(document).on('click', '.admin-main-content', function (e) {
        if (isMobileShell() && $('#sidebar').hasClass('is-open')
            && !$(e.target).closest('#sidebarToggle').length) {
            $('#sidebar').removeClass('is-open');
        }
    });

    $('#sidebar').on('click', '.nav-link', function () {
        if (isMobileShell()) { $('#sidebar').removeClass('is-open'); }
    });

    // Escape closes it, which is what a keyboard user will try.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#sidebar').hasClass('is-open')) {
            $('#sidebar').removeClass('is-open');
        }
    });

    /* Crossing the breakpoint leaves the wrong class applied: collapse
     * the desktop column, then rotate the phone, and the overlay is
     * stuck open. Clearing both on resize keeps the two states from
     * leaking into each other. */
    var lastShell = isMobileShell();

    $(window).on('resize', function () {
        var nowShell = isMobileShell();

        if (nowShell !== lastShell) {
            $('#sidebar').removeClass('is-open collapsed');
            lastShell = nowShell;
        }
    });

    /* ---------- Live table search (AJAX + debounce) ----------
     * Any admin listing page opts in by rendering:
     *   <form class="admin-search-form" data-target="#someTbody"> ... </form>
     *   <input class="admin-search-input">
     * The server returns only the <tr> rows for an AJAX request.
     */

    var searchTimer    = null;
    var searchSeq      = 0;
    var searchRendered = 0;

    /* OPT-IN IS STRICT.
     *
     * This used to bind to every .admin-search-input on the site and fall
     * back to '.admin-table tbody' when no target was declared. On a page
     * whose PHP has no is_ajax() branch, the request came back as the
     * WHOLE page -- layout, sidebar and all -- and got injected into the
     * table body, so the admin panel appeared nested inside itself.
     *
     * Four pages were in that state: stock, reviews, login security and
     * batch delete. A page now opts in by declaring BOTH halves of the
     * contract:
     *
     *   PHP : if (is_ajax()) { ...render rows only...; exit; }
     *   HTML: <form class="admin-search-form" data-target="#someTbody">
     *
     * Anything without data-target is left alone and submits normally,
     * which is a working plain search rather than a broken clever one. */
    var $liveForms = $('.admin-search-form[data-target]');

    /* One function, three callers: typing (debounced), pressing the Search
     * button, and pressing Enter. The button used to be inert -- its submit
     * was swallowed and nothing else happened -- so on a slow first
     * keystroke it looked like the search had simply not worked. */
    function runSearch($form) {
        var $input   = $form.find('.admin-search-input');
        var query    = $input.val();
        var $tbody   = $($form.data('target'));

        if ($tbody.length === 0) { return; }

        var colspan = $tbody.closest('table').find('thead th').length || 6;

        // Ignore an answer that is no longer the current query. Without
        // this a slow early request can land after a fast later one and
        // put stale rows back on screen.
        var mySeq = ++searchSeq;

        $tbody.html(
            '<tr><td colspan="' + colspan + '" class="table-empty">Searching...</td></tr>'
        );

        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { q: query },
            dataType: 'html'
        }).done(function (response) {
            if (mySeq < searchRendered) { return; }
            searchRendered = mySeq;

            $tbody.html(response);

            // Keep the address bar in sync without reloading the page.
            var newUrl = window.location.pathname
                       + (query ? '?q=' + encodeURIComponent(query) : '');
            window.history.replaceState({ path: newUrl }, '', newUrl);

        }).fail(function (xhr, status) {
            if (status === 'abort') { return; }

            $tbody.html(
                '<tr><td colspan="' + colspan + '" class="table-empty is-error">'
                + 'Could not load results. Please try again.</td></tr>'
            );
        });
    }

    $liveForms.on('submit', function (e) {
        // Prevented BEFORE the search runs, so the double-submit guard in
        // main.js sees isDefaultPrevented() and leaves the button alone.
        // Without that it would disable the button and swap its label to
        // "Working...", and nothing would ever put it back -- there is no
        // page load coming.
        e.preventDefault();

        window.clearTimeout(searchTimer);
        runSearch($(this));
    });

    $liveForms.find('.admin-search-input').on('input', function () {
        var $form = $(this).closest('.admin-search-form');

        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () { runSearch($form); }, 300);
    });

    /* ---------- Admin profile tabs ---------- */

    var $navItems = $('.profile-nav-item');
    var $panes    = $('.tab-pane');

    function switchTab(tabId) {
        var $nav  = $('#nav-' + tabId);
        var $pane = $('#tab-' + tabId);

        if (!$nav.length || !$pane.length) { return; }

        $navItems.removeClass('active');
        $panes.hide();

        $nav.addClass('active');
        $pane.fadeIn(200);
    }

    if ($navItems.length) {
        var params = new URLSearchParams(window.location.search);
        switchTab(params.get('tab') || 'profile');

        $navItems.on('click', function (e) {
            e.preventDefault();
            var tabId = $(this).attr('href').replace('#', '');
            switchTab(tabId);
            window.history.replaceState(null, '', '?tab=' + tabId);
        });
    }

    /* ---------- Product photo reordering ---------- */
    // Native HTML5 drag and drop on the tiles. The new order is written
    // into a hidden field and saved by a normal form POST, so the server
    // side stays an ordinary CSRF-protected request.

    var $grid = $('#photoGrid');

    if ($grid.length) {
        var dragged = null;

        $('#saveOrderBtn').prop('disabled', true);

        $grid.on('dragstart', '.photo-tile', function (e) {
            dragged = this;
            $(this).addClass('is-dragging');

            // Firefox will not start a drag without data being set.
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', '');
        });

        $grid.on('dragend', '.photo-tile', function () {
            $(this).removeClass('is-dragging');
            $grid.find('.photo-tile').removeClass('is-over');
        });

        $grid.on('dragover', '.photo-tile', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';

            if (this !== dragged) {
                $(this).addClass('is-over');
            }
        });

        $grid.on('dragleave', '.photo-tile', function () {
            $(this).removeClass('is-over');
        });

        $grid.on('drop', '.photo-tile', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-over');

            if (!dragged || this === dragged) { return; }

            // Insert before or after depending on which way it moved.
            var tiles      = $grid.find('.photo-tile').toArray();
            var fromIndex  = tiles.indexOf(dragged);
            var toIndex    = tiles.indexOf(this);

            if (fromIndex < toIndex) {
                $(this).after(dragged);
            } else {
                $(this).before(dragged);
            }

            var order = $grid.find('.photo-tile').map(function () {
                return $(this).data('id');
            }).get();

            $('#photoOrder').val(order.join(','));
            $('#saveOrderBtn').prop('disabled', false).addClass('is-pending');
        });
    }

    /* ---------- Voucher form: the value field means two things ---------- */

    var $voucherType = $('#voucherType');

    if ($voucherType.length) {
        var syncVoucherType = function () {
            var isPercent = $voucherType.val() === 'percent';

            $('#maxDiscountCol').toggle(isPercent);
            $('#valueHint').text(isPercent
                ? 'Percentage off, for example 10 means 10%.'
                : 'Fixed amount off in RM, for example 50 means RM 50.00.');
            $('#voucherValue').attr('max', isPercent ? '100' : '');
        };

        syncVoucherType();
        $voucherType.on('change', syncVoucherType);
    }

    /* Image previews now live in assets/js/dropzone.js, which handles
     * every upload field in one place. The old per-field handlers were
     * removed rather than left to fight over the same elements. */

    /* ---------- Batch tools ---------- */
    // Select-all, live count, and a guard against submitting an empty
    // selection. All delegated, so nothing here cares whether the table
    // was rendered by PHP or swapped in by the AJAX search.

    var $selectAll = $('#batchSelectAll');

    if ($selectAll.length) {
        var refreshBatchCount = function () {
            var $boxes   = $('.batch-select');
            var $checked = $boxes.filter(':checked');
            var n        = $checked.length;

            $('#batchCount').text(n + ' selected');

            // Indeterminate is the honest state when some but not all are
            // ticked; without it the header box lies about the selection.
            $selectAll.prop('checked', n > 0 && n === $boxes.length);
            $selectAll.prop('indeterminate', n > 0 && n < $boxes.length);

            $('.batch-row-selected').removeClass('batch-row-selected');
            $checked.closest('tr').addClass('batch-row-selected');
        };

        $(document).on('change', '#batchSelectAll', function () {
            $('.batch-select').prop('checked', $(this).prop('checked'));
            refreshBatchCount();
        });

        $(document).on('change', '.batch-select', refreshBatchCount);

        // Shift-click ticks a whole run, which is the difference between
        // this being usable on 200 rows and not.
        var lastIndex = null;

        $(document).on('click', '.batch-select', function (e) {
            var $boxes = $('.batch-select');
            var index  = $boxes.index(this);

            if (e.shiftKey && lastIndex !== null) {
                var start = Math.min(lastIndex, index);
                var end   = Math.max(lastIndex, index);
                var state = $(this).prop('checked');

                $boxes.slice(start, end + 1).prop('checked', state);
                refreshBatchCount();
            }

            lastIndex = index;
        });

        $(document).on('submit', 'form', function (e) {
            var $form = $(this);

            if ($form.find('.batch-select').length === 0) {
                return;
            }

            if ($form.find('.batch-select:checked').length === 0) {
                e.preventDefault();
                window.alert('Select at least one product first.');
            }
        });

        refreshBatchCount();
    }

    // Percentage operations get a different hint from ringgit ones.
    var $batchOperation = $('#operation');

    if ($batchOperation.length && $('#value').length) {
        var syncBatchOperation = function () {
            var op = $batchOperation.val();
            var isPercent = op === 'percent_up' || op === 'percent_down';
            var isFactor  = op === 'multiply';

            $('#value').attr('max', isPercent ? '100' : (isFactor ? '100' : '999999.99'));
        };

        syncBatchOperation();
        $batchOperation.on('change', syncBatchOperation);
    }

});