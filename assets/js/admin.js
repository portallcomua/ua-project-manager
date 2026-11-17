(function ($) {
    'use strict';

    $(function () {
        // Підтвердження небезпечних дій.
        $(document).on('click', '[data-uapm-confirm]', function (event) {
            var message = $(this).attr('data-uapm-confirm');

            if (message && !window.confirm(message)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                return false;
            }
        });

        // Показ/приховування паролів і токенів.
        $(document).on('click', '[data-uapm-toggle-password]', function () {
            var selector = $(this).attr('data-uapm-toggle-password');
            var field = selector ? document.querySelector(selector) : null;

            if (!field) {
                return;
            }

            field.type = field.type === 'password' ? 'text' : 'password';
            $(this).attr('aria-pressed', field.type === 'text' ? 'true' : 'false');
        });

        // Позначення всіх рядків таблиці.
        $(document).on('change', '[data-uapm-select-all]', function () {
            var selector = $(this).attr('data-uapm-select-all');
            if (!selector) {
                return;
            }

            $(selector).prop('checked', this.checked).trigger('change');
        });

        // Пошук у таблицях без перезавантаження сторінки.
        $(document).on('input', '[data-uapm-table-search]', function () {
            var selector = $(this).attr('data-uapm-table-search');
            var query = String($(this).val() || '').toLocaleLowerCase();

            if (!selector) {
                return;
            }

            $(selector).find('tbody tr').each(function () {
                var text = $(this).text().toLocaleLowerCase();
                $(this).toggle(text.indexOf(query) !== -1);
            });
        });

        // Перемикання блоків налаштувань.
        $(document).on('change', '[data-uapm-toggle-section]', function () {
            var selector = $(this).attr('data-uapm-toggle-section');
            if (!selector) {
                return;
            }

            $(selector).toggle(this.checked);
        });

        // Закриття повідомлень.
        $(document).on('click', '[data-uapm-dismiss]', function () {
            var target = $(this).attr('data-uapm-dismiss');
            var element = target ? document.querySelector(target) : this.closest('.uapm-notice');

            if (element) {
                element.remove();
            }
        });
    });
})(jQuery);
