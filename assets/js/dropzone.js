/* ============================================================
 * assets/js/dropzone.js
 * Drag-and-drop photo upload, written as a jQuery component.
 *
 * Behaviour:
 *   - drag a file onto the zone, or click browse
 *   - the file is validated in the browser for immediate feedback,
 *     then assigned to the real <input type="file">
 *   - the form still submits normally; the server re-validates
 *     everything because browser checks can be bypassed
 *   - when the form is submitted the upload is sent via XHR so a
 *     real progress bar can be shown
 *
 * Progressive enhancement: with JavaScript off the plain file input
 * is visible and the form works exactly as before.
 * ============================================================ */

$(function () {

    var $zones = $('.dropzone');

    if ($zones.length === 0) { return; }

    /* Assigning to input.files needs DataTransfer. Where it is missing
     * we keep click-to-browse and simply do not accept drops, rather
     * than silently pretending the drop worked. */
    var canAssignFiles = (function () {
        try {
            return typeof DataTransfer !== 'undefined' && new DataTransfer().items !== undefined;
        } catch (err) {
            return false;
        }
    })();

    /* Dropping a file anywhere else must not make the browser navigate
     * away to it, which is the default and loses the whole form. */
    $(document).on('dragover drop', function (e) {
        if (!$(e.target).closest('.dropzone').length) {
            e.preventDefault();
        }
    });

    $zones.each(function () {
        var $zone     = $(this);
        var $input    = $('#' + $zone.data('input'));
        var $image    = $zone.find('.dropzone-image');
        var $filename = $zone.find('.dropzone-filename');
        var $error    = $zone.find('.dropzone-error');
        var $clear    = $zone.find('.dropzone-clear');
        var maxBytes  = parseFloat($zone.data('max-mb')) * 1024 * 1024;
        var allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        var original  = $image.attr('src');

        $zone.addClass('is-enhanced');

        if (!canAssignFiles) {
            $zone.addClass('no-drop');
            $zone.find('.dropzone-title').html(
                'Click <button type="button" class="dropzone-browse">browse</button> to choose a photo'
            );
        }

        function showError(message) {
            $error.text(message);
            $zone.addClass('has-error');
        }

        function clearError() {
            $error.text('');
            $zone.removeClass('has-error');
        }

        function reset() {
            $input.val('');
            $image.attr('src', original);
            $filename.text('');
            $clear.attr('hidden', true);
            $zone.removeClass('has-file');
            clearError();
        }

        /* Browser-side checks are for fast feedback only. The server
         * checks the real MIME type with finfo regardless, because a
         * file can be renamed and this type comes from the browser. */
        function validate(file) {
            if (allowed.indexOf(file.type) === -1) {
                showError('That file type is not supported. Use JPG, PNG, GIF or WEBP.');
                return false;
            }

            if (file.size > maxBytes) {
                showError('That file is ' + (file.size / 1024 / 1024).toFixed(1)
                        + ' MB. The limit is ' + $zone.data('max-mb') + ' MB.');
                return false;
            }

            return true;
        }

        function accept(file) {
            if (!validate(file)) {
                reset();
                return;
            }

            clearError();

            var reader = new FileReader();
            reader.onload = function (evt) {
                $image.attr('src', evt.target.result).removeAttr('data-placeholder');
            };
            reader.readAsDataURL(file);

            $filename.text(file.name + '  (' + (file.size / 1024).toFixed(0) + ' KB)');
            $clear.removeAttr('hidden');
            $zone.addClass('has-file');
        }

        /* ---------- Click to browse ---------- */

        $zone.on('click', '.dropzone-browse', function (e) {
            e.preventDefault();
            $input.trigger('click');
        });

        $zone.on('click', '.dropzone-preview', function () {
            $input.trigger('click');
        });

        /* ---------- Choosing through the file dialog ---------- */

        $input.on('change', function () {
            if (this.files && this.files.length > 0) {
                accept(this.files[0]);
            } else {
                reset();
            }
        });

        /* ---------- Remove ---------- */

        $clear.on('click', function () {
            reset();
        });

        /* ---------- Drag and drop ---------- */

        if (canAssignFiles) {
            // dragover must be cancelled or the drop event never fires.
            $zone.on('dragenter dragover', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $zone.addClass('is-dragging');
            });

            $zone.on('dragleave dragend', function (e) {
                e.preventDefault();
                e.stopPropagation();

                // Moving over a child fires dragleave on the parent, so
                // only clear when the pointer has really left the zone.
                if (e.type === 'dragend' || !$zone[0].contains(e.relatedTarget)) {
                    $zone.removeClass('is-dragging');
                }
            });

            $zone.on('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                $zone.removeClass('is-dragging');

                // jQuery normalises the event, so the raw one is needed here.
                var dt = e.originalEvent.dataTransfer;

                if (!dt || !dt.files || dt.files.length === 0) {
                    showError('No file was found in that drop.');
                    return;
                }

                if (dt.files.length > 1) {
                    showError('Only one photo can be uploaded here. Using the first one.');
                }

                var transfer = new DataTransfer();
                transfer.items.add(dt.files[0]);
                $input[0].files = transfer.files;

                accept(dt.files[0]);
            });
        }
    });

    /* ---------- Multiple-file zones ---------- */
    // Used by the product gallery. Files accumulate rather than
    // replacing each other, so several drops build one selection.

    $('.dropzone-multi').each(function () {
        var $zone     = $(this);
        var $input    = $('#' + $zone.data('input'));
        var $thumbs   = $zone.find('.dropzone-thumbs');
        var $error    = $zone.find('.dropzone-error');
        var maxBytes  = parseFloat($zone.data('max-mb')) * 1024 * 1024;
        var maxFiles  = parseInt($zone.data('max-files'), 10) || 8;
        var allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        var picked    = [];

        function repaint() {
            $thumbs.empty();

            picked.forEach(function (file, index) {
                var $tile = $('<div class="dz-thumb">'
                            + '<img alt="">'
                            + '<button type="button" class="dz-thumb-remove" title="Remove">&times;</button>'
                            + '<span class="dz-thumb-name"></span>'
                            + '</div>');

                $tile.find('.dz-thumb-name').text(file.name);
                $tile.find('.dz-thumb-remove').data('index', index);

                var reader = new FileReader();
                reader.onload = function (evt) {
                    $tile.find('img').attr('src', evt.target.result);
                };
                reader.readAsDataURL(file);

                $thumbs.append($tile);
            });

            // Push the accumulated list back into the real input.
            var transfer = new DataTransfer();
            picked.forEach(function (f) { transfer.items.add(f); });
            $input[0].files = transfer.files;

            $zone.toggleClass('has-file', picked.length > 0);
        }

        function addFiles(fileList) {
            var rejected = [];

            $.each(fileList, function (_, file) {
                if (picked.length >= maxFiles) {
                    rejected.push(file.name + ' (limit is ' + maxFiles + ')');
                    return;
                }
                if (allowed.indexOf(file.type) === -1) {
                    rejected.push(file.name + ' (not an image)');
                    return;
                }
                if (file.size > maxBytes) {
                    rejected.push(file.name + ' (too large)');
                    return;
                }

                picked.push(file);
            });

            $error.text(rejected.length > 0 ? 'Skipped: ' + rejected.join(', ') : '');
            $zone.toggleClass('has-error', rejected.length > 0);

            repaint();
        }

        $zone.addClass('is-enhanced');

        $zone.on('click', '.dropzone-browse', function (e) {
            e.preventDefault();
            $input.trigger('click');
        });

        $input.on('change', function () {
            if (this.files && this.files.length) {
                // Take a copy before repaint() overwrites input.files.
                addFiles(Array.prototype.slice.call(this.files));
            }
        });

        $zone.on('click', '.dz-thumb-remove', function () {
            picked.splice($(this).data('index'), 1);
            repaint();
        });

        $zone.on('dragenter dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $zone.addClass('is-dragging');
        });

        $zone.on('dragleave dragend', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (e.type === 'dragend' || !$zone[0].contains(e.relatedTarget)) {
                $zone.removeClass('is-dragging');
            }
        });

        $zone.on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $zone.removeClass('is-dragging');

            var dt = e.originalEvent.dataTransfer;

            if (dt && dt.files && dt.files.length) {
                addFiles(Array.prototype.slice.call(dt.files));
            }
        });
    });

    /* ---------- Real upload progress ---------- */
    // Submitting through XHR is the only way to observe upload progress.
    // The server sees an ordinary multipart POST either way.

    $('form').filter(function () {
        return $(this).find('.dropzone').not('.dropzone-multi').length > 0;
    }).on('submit', function (e) {
        var $form  = $(this);
        var $zone  = $form.find('.dropzone').not('.dropzone-multi').first();
        var $input = $('#' + $zone.data('input'));

        // Nothing new to upload: let the browser submit normally.
        if (!$input[0] || !$input[0].files || $input[0].files.length === 0) {
            return;
        }

        if ($zone.hasClass('has-error')) {
            e.preventDefault();
            return;
        }

        e.preventDefault();

        var $progress = $zone.find('.dropzone-progress');
        var $bar      = $zone.find('.dropzone-progress-bar');
        var $text     = $zone.find('.dropzone-progress-text');
        var $submit   = $form.find('button[type="submit"]');

        $progress.removeAttr('hidden');
        $submit.prop('disabled', true);

        $.ajax({
            url: $form.attr('action') || window.location.href,
            type: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();

                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function (evt) {
                        if (!evt.lengthComputable) { return; }

                        var percent = Math.round((evt.loaded / evt.total) * 100);
                        $bar.css('width', percent + '%');
                        $text.text(percent + '%');
                    });
                }

                return xhr;
            },
            success: function () {
                // The page it returns is the redirect target, so just
                // reload to pick up the flash message and new state.
                window.location.reload();
            },
            error: function () {
                $progress.attr('hidden', true);
                $submit.prop('disabled', false);
                $zone.find('.dropzone-error').text('Upload failed. Please try again.');
                $zone.addClass('has-error');
            }
        });
    });

});
