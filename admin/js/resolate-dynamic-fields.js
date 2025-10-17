(function($) {
    'use strict';

    $(function() {
        const form = $('#post');
        if (!form.length) {
            return;
        }

        form.on('submit', function(e) {
            let isValid = true;
            $('.resolate-dynamic-fields .resolate-field').each(function() {
                const fieldContainer = $(this);
                const input = fieldContainer.find('input, textarea').first();

                if (input.length && input[0].willValidate) {
                    if (!input[0].checkValidity()) {
                        isValid = false;
                        let errorMessage = input.attr('title');
                        if (!errorMessage) {
                            errorMessage = input[0].validationMessage;
                        }

                        // Remove old error message
                        fieldContainer.find('.resolate-error-message').remove();

                        // Add new error message
                        const errorHTML = '<div class="resolate-error-message" style="color: #d63638; font-weight: bold; margin-top: 5px;">' + errorMessage + '</div>';
                        fieldContainer.append(errorHTML);
                    }
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert( 'Por favor, corrija los errores antes de guardar.' );
            }
        });

        // Clear error messages on input
        $('.resolate-dynamic-fields .resolate-field').on('input', 'input, textarea', function() {
            const fieldContainer = $(this).closest('.resolate-field');
            fieldContainer.find('.resolate-error-message').remove();
        });
    });

})(jQuery);