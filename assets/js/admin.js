(function ($) {
    'use strict';

    $(document).ready(function () {

        /* Confirm actions */
        $(document).on('click', '[data-uapm-confirm]', function (e) {
            var message = $(this).data('uapm-confirm');

            if (!message) {
                message = UAPM_Admin.confirmAction || 'Are you sure?';
            }

            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });

        /* Confirm delete */
        $(document).on('click', '[data-uapm-delete]', function (e) {
            var message = $(this).data('uapm-delete');

            if (!message) {
                message = UAPM_Admin.confirmDelete || 'Are you sure you want to delete this item?';
            }

            if (!window.confirm(message)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        });

        /* Password visibility */
        $(document).on('click', '.uapm-password-toggle', function (e) {
            e.preventDefault();

            var wrapper = $(this).closest('.uapm-password-wrapper');
            var input = wrapper.find('input').first();

            if (!input.length) {
                return;
            }

            var type = input.attr('type');

            if (type === 'password') {
                input.attr('type', 'text');
                $(this).text('Hide');
            } else {
                input.attr('type', 'password');
                $(this).text('Show');
            }
        });

        /* Select all table checkboxes */
        $(document).on('change', '.uapm-select-all', function () {
            var checked = $(this).prop('checked');
            var table = $(this).closest('table');

            table.find('.uapm-select-item').prop('checked', checked);
        });

        /* Update select-all state */
        $(document).on('change', '.uapm-select-item', function () {
            var table = $(this).closest('table');
            var items = table.find('.uapm-select-item');
            var selected = table.find('.uapm-select-item:checked');

            table.find('.uapm-select-all').prop(
                'checked',
                items.length > 0 && items.length === selected.length
            );
        });

        /* Table search */
        $(document).on('input', '[data-uapm-search]', function () {
            var selector = $(this).data('uapm-search');
            var query = $(this).val().toLowerCase().trim();

            var table = selector
                ? $(selector)
                : $(this).closest('.uapm-wrap').find('.uapm-table').first();

            table.find('tbody tr').each(function () {
                var text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(query) !== -1);
            });
        });

        /* Section toggles */
        $(document).on('click', '[data-uapm-toggle]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-toggle');
            var element = $(target);

            if (!element.length) {
                return;
            }

            element.toggleClass('uapm-hidden');
            element.slideToggle(150);

            $(this).toggleClass('active');
        });

        /* Accordion */
        $(document).on('click', '.uapm-accordion-header', function () {
            var accordion = $(this).closest('.uapm-accordion');

            accordion.toggleClass('active');
        });

        /* Dismiss notices */
        $(document).on('click', '[data-uapm-dismiss]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-dismiss');

            if (target) {
                $(target).fadeOut(150);
            } else {
                $(this).closest('.uapm-notice, .uapm-alert').fadeOut(150);
            }
        });

        /* Copy to clipboard */
        $(document).on('click', '[data-uapm-copy]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-copy');
            var text = target ? $(target).val() || $(target).text() : '';

            if (!text) {
                return;
            }

            copyText(text, $(this));
        });

        /* Copy current element text */
        $(document).on('click', '.uapm-copy-button', function (e) {
            e.preventDefault();

            var target = $(this).data('copy');
            var text = target ? $(target).text() : '';

            if (!text) {
                return;
            }

            copyText(text, $(this));
        });

        /* Modal open */
        $(document).on('click', '[data-uapm-modal-open]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-modal-open');

            $(target).addClass('active');
            $('body').addClass('uapm-modal-open');
        });

        /* Modal close */
        $(document).on('click', '[data-uapm-modal-close]', function (e) {
            e.preventDefault();

            $(this).closest('.uapm-modal').removeClass('active');
            $('body').removeClass('uapm-modal-open');
        });

        /* Modal background click */
        $(document).on('click', '.uapm-modal', function (e) {
            if ($(e.target).is('.uapm-modal')) {
                $(this).removeClass('active');
                $('body').removeClass('uapm-modal-open');
            }
        });

        /* Tabs */
        $(document).on('click', '[data-uapm-tab]', function (e) {
            e.preventDefault();

            var tab = $(this);
            var group = tab.closest('.uapm-tabs');
            var target = tab.data('uapm-tab');

            group.find('[data-uapm-tab]').removeClass('active');
            tab.addClass('active');

            var container = group.parent();

            container.find('[data-uapm-tab-content]').addClass('uapm-hidden');
            container.find('[data-uapm-tab-content="' + target + '"]').removeClass('uapm-hidden');
        });

        /* Toggle checkbox controls */
        $(document).on('change', '[data-uapm-toggle-field]', function () {
            var target = $(this).data('uapm-toggle-field');
            var element = $(target);

            if ($(this).is(':checked')) {
                element.removeClass('uapm-hidden');
            } else {
                element.addClass('uapm-hidden');
            }
        });

        /* Loading state */
        $(document).on('click', '[data-uapm-loading]', function () {
            var button = $(this);

            if (button.data('loading-active')) {
                return;
            }

            button.data('loading-active', true);
            button.addClass('uapm-loading');

            var originalText = button.html();

            button.data('original-text', originalText);
            button.html(
                '<span class="uapm-spinner"></span> ' +
                (UAPM_Admin.loading || 'Loading...')
            );

            if (button.is('button')) {
                button.prop('disabled', true);
            }
        });

        /* Reset loading state */
        $(document).on('uapm-reset-loading', '[data-uapm-loading]', function () {
            var button = $(this);

            button.removeClass('uapm-loading');
            button.data('loading-active', false);

            if (button.data('original-text')) {
                button.html(button.data('original-text'));
            }

            button.prop('disabled', false);
        });

        /* Password strength */
        $(document).on('input', '[data-uapm-password-strength]', function () {
            var password = $(this).val();
            var target = $(this).data('uapm-password-strength');

            if (!target) {
                return;
            }

            var strength = calculatePasswordStrength(password);
            var container = $(target);

            container.removeClass('weak medium strong very-strong');

            if (strength < 2) {
                container.addClass('weak');
            } else if (strength < 3) {
                container.addClass('medium');
            } else if (strength < 4) {
                container.addClass('strong');
            } else {
                container.addClass('very-strong');
            }

            container.attr('data-strength', strength);
        });

        /* Conditional form fields */
        $(document).on('change', '[data-uapm-condition]', function () {
            var field = $(this);
            var condition = field.data('uapm-condition');
            var value = field.val();

            $('[data-uapm-condition-target="' + condition + '"]').each(function () {
                var target = $(this);
                var expected = target.data('uapm-condition-value');

                if (String(value) === String(expected)) {
                    target.removeClass('uapm-hidden');
                } else {
                    target.addClass('uapm-hidden');
                }
            });
        });

        /* Character counters */
        $(document).on('input', '[data-uapm-counter]', function () {
            var max = parseInt($(this).data('uapm-counter'), 10);
            var count = $(this).val().length;
            var target = $(this).data('uapm-counter-target');

            if (target) {
                $(target).text(count + '/' + max);
            }
        });

        /* Select all matching items */
        $(document).on('click', '[data-uapm-select-all-target]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-select-all-target');
            $(target).prop('checked', true);
        });

        /* Deselect all matching items */
        $(document).on('click', '[data-uapm-deselect-all-target]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-deselect-all-target');
            $(target).prop('checked', false);
        });

        /* Filter list */
        $(document).on('input', '[data-uapm-filter]', function () {
            var filter = $(this).val().toLowerCase();
            var target = $(this).data('uapm-filter');

            $(target).each(function () {
                var item = $(this);
                var text = item.text().toLowerCase();

                item.toggle(text.indexOf(filter) !== -1);
            });
        });

        /* Copy code */
        $(document).on('click', '[data-uapm-copy-code]', function (e) {
            e.preventDefault();

            var code = $(this).data('uapm-copy-code');

            if (!code) {
                return;
            }

            copyText(code, $(this));
        });

        /* File input name */
        $(document).on('change', '[data-uapm-file]', function () {
            var input = $(this);
            var files = input[0].files;
            var target = input.data('uapm-file');

            if (!target || !files.length) {
                return;
            }

            var names = [];

            for (var i = 0; i < files.length; i++) {
                names.push(files[i].name);
            }

            $(target).text(names.join(', '));
        });

        /* Toggle all sections */
        $(document).on('click', '[data-uapm-expand-all]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-expand-all');

            $(target).addClass('active');
        });

        $(document).on('click', '[data-uapm-collapse-all]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-collapse-all');

            $(target).removeClass('active');
        });

        /* AJAX form helper */
        $(document).on('submit', '[data-uapm-ajax-form]', function (e) {
            e.preventDefault();

            var form = $(this);
            var action = form.attr('action');
            var method = form.attr('method') || 'POST';
            var data = form.serialize();
            var button = form.find('[type="submit"]').first();

            if (!action) {
                return;
            }

            if (form.data('uapm-request-active')) {
                return;
            }

            form.data('uapm-request-active', true);
            button.prop('disabled', true);

            $.ajax({
                url: action,
                type: method,
                data: data,
                success: function (response) {
                    form.trigger('uapm-ajax-success', [response]);
                },
                error: function (xhr) {
                    form.trigger('uapm-ajax-error', [xhr]);
                },
                complete: function () {
                    form.data('uapm-request-active', false);
                    button.prop('disabled', false);
                }
            });
        });

        /* Numeric inputs */
        $(document).on('input', '[data-uapm-numeric]', function () {
            var value = $(this).val();
            value = value.replace(/[^0-9]/g, '');
            $(this).val(value);
        });

        /* URL validation */
        $(document).on('blur', '[data-uapm-url]', function () {
            var input = $(this);
            var value = input.val();

            if (!value) {
                input.removeClass('uapm-invalid');
                return;
            }

            try {
                var url = new URL(value);

                if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                    input.addClass('uapm-invalid');
                } else {
                    input.removeClass('uapm-invalid');
                }
            } catch (e) {
                input.addClass('uapm-invalid');
            }
        });

        /* Scroll to element */
        $(document).on('click', '[data-uapm-scroll]', function (e) {
            e.preventDefault();

            var target = $(this).data('uapm-scroll');

            if (!target || !$(target).length) {
                return;
            }

            $('html, body').animate({
                scrollTop: $(target).offset().top - 50
            }, 300);
        });

    });

    function copyText(text, button) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                showCopyFeedback(button);
            }).catch(function () {
                fallbackCopy(text, button);
            });
        } else {
            fallbackCopy(text, button);
        }
    }

    function fallbackCopy(text, button) {
        var textarea = $('<textarea>');
        textarea.val(text);
        textarea.css({
            position: 'fixed',
            opacity: 0,
            left: '-9999px',
            top: '-9999px'
        });

        $('body').append(textarea);
        textarea[0].select();

        try {
            document.execCommand('copy');
            showCopyFeedback(button);
        } catch (e) {
            alert('Copy failed');
        }

        textarea.remove();
    }

    function showCopyFeedback(button) {
        var original = button.data('copy-original');

        if (!original) {
            original = button.text();
            button.data('copy-original', original);
        }

        button.text('Copied!');

        setTimeout(function () {
            button.text(original);
        }, 1500);
    }

    function calculatePasswordStrength(password) {
        var strength = 0;

        if (password.length >= 8) {
            strength++;
        }

        if (password.length >= 12) {
            strength++;
        }

        if (/[a-z]/.test(password)) {
            strength++;
        }

        if (/[A-Z]/.test(password)) {
            strength++;
        }

        if (/[0-9]/.test(password)) {
            strength++;
        }

        if (/[^a-zA-Z0-9]/.test(password)) {
            strength++;
        }

        return Math.min(strength, 4);
    }

})(jQuery);
