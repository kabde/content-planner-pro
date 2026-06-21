/**
 * Content Planner Pro — Admin JS
 * Handles settings page interactions.
 */
(function($) {
    'use strict';

    $(function() {

        // Live preview of status colors in the Statuts tab
        $('.cpp-statuses-table .cpp-status-dot').each(function() {
            var $dot = $(this);
            var color = $dot.css('background-color');
            $dot.css({
                'box-shadow': '0 0 0 3px ' + color + '33',
                'transition': 'transform 150ms ease'
            });

            // Hover effect
            $dot.closest('tr').on('mouseenter', function() {
                $dot.css('transform', 'scale(1.3)');
            }).on('mouseleave', function() {
                $dot.css('transform', 'scale(1)');
            });
        });

        // Highlight active status row on hover
        $('.cpp-statuses-table tbody tr').on('mouseenter', function() {
            $(this).css('background-color', '#f0f9ff');
        }).on('mouseleave', function() {
            $(this).css('background-color', '');
        });

    });

})(jQuery);
