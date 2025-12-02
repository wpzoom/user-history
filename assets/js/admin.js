/**
 * User History Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initLoadMore();
        initChangeUsername();
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

    /**
     * Initialize change username functionality
     */
    function initChangeUsername() {
        var $usernameInput = $('#user_login');
        var $usernameWrap = $('.user-user-login-wrap td');

        if (!$usernameInput.length || !$usernameWrap.length) {
            return;
        }

        // Remove the default description
        $usernameWrap.find('.description').remove();

        // Create the change username UI
        var currentUsername = $usernameInput.val();
        var i18n = userHistoryData.i18n || {};

        var $changeLink = $('<a>', {
            href: '#',
            class: 'user-history-change-username-link',
            text: i18n.change || 'Change'
        });

        var $newInput = $('<input>', {
            type: 'text',
            class: 'regular-text user-history-new-username',
            value: currentUsername,
            autocomplete: 'off'
        });

        var $submitBtn = $('<button>', {
            type: 'button',
            class: 'button user-history-change-username-submit',
            text: i18n.change || 'Change'
        });

        var $cancelBtn = $('<button>', {
            type: 'button',
            class: 'button user-history-change-username-cancel',
            text: i18n.cancel || 'Cancel'
        });

        var $message = $('<span>', {
            class: 'user-history-change-username-message'
        });

        var $form = $('<div>', {
            class: 'user-history-change-username-form',
            css: { display: 'none' }
        }).append($newInput, ' ', $submitBtn, ' ', $cancelBtn, $message);

        // Insert after the username input
        $usernameInput.after($changeLink, $form);

        // Toggle form visibility
        function showForm() {
            $changeLink.hide();
            $usernameInput.hide();
            $form.show();
            $newInput.val($usernameInput.val()).focus();
            $message.hide().text('');
        }

        function hideForm() {
            $form.hide();
            $usernameInput.show();
            $changeLink.show();
            $message.hide().text('');
        }

        // Event handlers
        $changeLink.on('click', function(e) {
            e.preventDefault();
            showForm();
        });

        $cancelBtn.on('click', function(e) {
            e.preventDefault();
            hideForm();
        });

        // ESC to close
        $newInput.on('keydown', function(e) {
            if (e.keyCode === 27) {
                hideForm();
            } else if (e.keyCode === 13) {
                e.preventDefault();
                $submitBtn.trigger('click');
            }
        });

        // Submit handler
        $submitBtn.on('click', function(e) {
            e.preventDefault();

            var newUsername = $newInput.val().trim();
            var oldUsername = $usernameInput.val();

            if (!newUsername) {
                showMessage(i18n.errorGeneric || 'Please enter a username.', false);
                return;
            }

            if (newUsername === oldUsername) {
                hideForm();
                return;
            }

            // Disable form during request
            $submitBtn.prop('disabled', true).text(i18n.pleaseWait || 'Please wait...');
            $cancelBtn.prop('disabled', true);
            $newInput.prop('disabled', true);

            $.ajax({
                url: userHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'user_history_change_username',
                    _ajax_nonce: userHistoryData.changeUsernameNonce,
                    current_username: oldUsername,
                    new_username: newUsername
                },
                success: function(response) {
                    // Update nonce for next request
                    if (response.new_nonce) {
                        userHistoryData.changeUsernameNonce = response.new_nonce;
                    }

                    if (response.success) {
                        // Update the username input and hide form
                        $usernameInput.val(newUsername);
                        showMessage(response.message, true);
                        setTimeout(hideForm, 2000);
                    } else {
                        showMessage(response.message || i18n.errorGeneric, false);
                    }
                },
                error: function() {
                    showMessage(i18n.errorGeneric || 'Something went wrong.', false);
                },
                complete: function() {
                    $submitBtn.prop('disabled', false).text(i18n.change || 'Change');
                    $cancelBtn.prop('disabled', false);
                    $newInput.prop('disabled', false);
                }
            });
        });

        function showMessage(text, isSuccess) {
            $message
                .removeClass('success error')
                .addClass(isSuccess ? 'success' : 'error')
                .text(text)
                .show();
        }
    }

})(jQuery);
