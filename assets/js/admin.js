/* ============================================================
 * assets/js/admin.js
 * jQuery behaviour used only by the admin panel.
 * Loaded by includes/admin_header.php after main.js.
 * ============================================================ */

$(function () {

    /* ---------- Collapsible sidebar ---------- */

    $('#sidebarToggle').on('click', function (e) {
        e.preventDefault();
        $('#sidebar').toggleClass('collapsed');
    });

    /* ---------- Live table search (AJAX + debounce) ----------
     * Any admin listing page opts in by rendering:
     *   <form class="admin-search-form" data-target="#someTbody"> ... </form>
     *   <input class="admin-search-input">
     * The server returns only the <tr> rows for an AJAX request.
     */

    var searchTimer = null;

    $('.admin-search-form').on('submit', function (e) {
        e.preventDefault();
    });

    $('.admin-search-input').on('input', function () {
        var query    = $(this).val();
        var selector = $(this).closest('.admin-search-form').data('target') || '.admin-table tbody';
        var $tbody   = $(selector);
        var colspan  = $tbody.closest('table').find('thead th').length || 6;

        window.clearTimeout(searchTimer);

        searchTimer = window.setTimeout(function () {
            $tbody.html(
                '<tr><td colspan="' + colspan + '" class="table-empty">Searching...</td></tr>'
            );

            $.ajax({
                url: window.location.pathname,
                type: 'GET',
                data: { q: query },
                success: function (response) {
                    $tbody.html(response);

                    // Keep the address bar in sync without reloading the page.
                    var newUrl = window.location.pathname
                               + (query ? '?q=' + encodeURIComponent(query) : '');
                    window.history.replaceState({ path: newUrl }, '', newUrl);
                },
                error: function () {
                    $tbody.html(
                        '<tr><td colspan="' + colspan + '" class="table-empty is-error">'
                        + 'Error fetching data.</td></tr>'
                    );
                }
            });
        }, 300);
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

    /* ---------- Live preview for image pickers ---------- */
    // Each pair is [file input, <img> to update].

    function bindImagePreview(inputSelector, previewSelector) {
        $(inputSelector).on('change', function () {
            var file = this.files[0];
            if (!file) { return; }

            var reader = new FileReader();
            reader.onload = function (evt) {
                $(previewSelector).attr('src', evt.target.result).show();
            };
            reader.readAsDataURL(file);
        });
    }

    bindImagePreview('#productImageInput', '#productImagePreview');
    bindImagePreview('#adminPhotoInput',   '#adminPhotoPreview');

});
