/**
 * User History Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initLoadMore();
    });

    /**
     * Initialize load more functionality
     */
    function initLoadMore() {
        var $loadMoreBtn = $('#user-history-load-more');
        var $tbody = $('#user-history-tbody');

        if (!$loadMoreBtn.length) {
            return;
        }

        $loadMoreBtn.on('click', function(e) {
            e.preventDefault();

            var $btn = $(this);
            var offset = parseInt($btn.data('offset'), 10);
            var total = parseInt($btn.data('total'), 10);

            if ($btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading').text('Loading...');

            $.ajax({
                url: userHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'load_more_user_history',
                    nonce: userHistoryData.nonce,
                    user_id: userHistoryData.userId,
                    offset: offset
                },
                success: function(response) {
                    if (response.success && response.data.html) {
                        $tbody.append(response.data.html);

                        if (response.data.hasMore) {
                            $btn.data('offset', response.data.newOffset);
                            $btn.removeClass('loading').text('Load More');
                        } else {
                            $btn.parent().remove();
                        }
                    } else {
                        $btn.parent().remove();
                    }
                },
                error: function() {
                    $btn.removeClass('loading').text('Error - Try Again');
                }
            });
        });
    }

})(jQuery);
