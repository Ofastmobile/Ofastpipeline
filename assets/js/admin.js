/* OFast Pipeline — Admin JS */
(function($) {
    'use strict';

    // Toggle CRM plan selector visibility based on CRM checkbox
    function togglePlanField() {
        var $crmCheckbox  = $('input[name="want_crm"]');
        var $planField    = $('#ofp-plan-field');

        if ( $crmCheckbox.length && $planField.length ) {
            $planField.toggle( $crmCheckbox.is(':checked') );

            $crmCheckbox.on('change', function() {
                $planField.toggle( $(this).is(':checked') );
            });
        }
    }

    // Auto-dismiss notices after 5 seconds
    function autoDismissNotices() {
        setTimeout(function() {
            $('.ofp-notice.notice-success').fadeOut(400);
        }, 5000);
    }

    // Confirm before dangerous actions (belt and suspenders on top of onclick)
    function confirmDangerousActions() {
        $(document).on('submit', 'form:has([name="action"][value="ofp_delete_client"])', function(e) {
            if ( ! window.confirm('Are you sure you want to cancel this client? This action cannot be easily undone.') ) {
                e.preventDefault();
            }
        });
    }

    // Custom Dropdown JS
    function initOfpCustomSelects() {
        $('.ofp-select').each(function() {
            var $select = $(this);
            if ($select.closest('.ofp-custom-select-wrapper').length) return;

            var $wrapper = $('<div class="ofp-custom-select-wrapper"></div>');
            $select.wrap($wrapper);
            $wrapper = $select.parent(); // Get the actual wrapper in DOM

            var selectedOption = $select.find('option:selected');
            var triggerText = selectedOption.length ? selectedOption.text() : 'Select an option';
            var $trigger = $('<div class="ofp-custom-select-trigger">' + triggerText + '</div>');
            
            var $optionsContainer = $('<div class="ofp-custom-select-options"></div>');

            $select.find('option').each(function(index) {
                if ($(this).attr('hidden')) return;
                var $optDiv = $('<div class="ofp-custom-select-option"></div>');
                if ($(this).is(':selected')) $optDiv.addClass('selected');
                $optDiv.text($(this).text());
                $optDiv.attr('data-value', $(this).val());
                $optDiv.attr('data-index', index);

                $optDiv.on('click', function(e) {
                    e.stopPropagation();
                    $select.prop('selectedIndex', $(this).attr('data-index'));
                    $select.trigger('change');
                    
                    $trigger.text($(this).text());
                    $optionsContainer.find('.ofp-custom-select-option').removeClass('selected');
                    $(this).addClass('selected');
                    $wrapper.removeClass('open');
                });
                $optionsContainer.append($optDiv);
            });

            $wrapper.append($trigger).append($optionsContainer);

            $trigger.on('click', function(e) {
                e.stopPropagation();
                $('.ofp-custom-select-wrapper.open').not($wrapper).removeClass('open');
                $wrapper.toggleClass('open');
            });

            $select.on('change', function() {
                var $newSelected = $select.find('option:selected');
                if ($newSelected.length) {
                    $trigger.text($newSelected.text());
                    $optionsContainer.find('.ofp-custom-select-option').removeClass('selected');
                    $optionsContainer.find('.ofp-custom-select-option[data-index="' + $select.prop('selectedIndex') + '"]').addClass('selected');
                }
            });
        });

        $(document).on('click', function() {
            $('.ofp-custom-select-wrapper.open').removeClass('open');
        });
    }

    $(document).ready(function() {
        togglePlanField();
        autoDismissNotices();
        confirmDangerousActions();
        initOfpCustomSelects();
    });

})(jQuery);
