/**
 * User History Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initLoadMore();
        initChangeUsername();
        initClearHistory();
        initLockToggle();
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
                url: wpzoomUserHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpzoom_user_history_load_more',
                    nonce: wpzoomUserHistoryData.nonce,
                    user_id: wpzoomUserHistoryData.userId,
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
        var i18n = wpzoomUserHistoryData.i18n || {};

        var $changeLink = $('<a>', {
            href: '#',
            class: 'user-history-change-username-link',
            text: i18n.change || 'Change'
        });

        var $newInput = $('<input type="text" class="regular-text user-history-new-username">')
            .val(currentUsername)
            .attr('autocomplete', 'off');

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
                url: wpzoomUserHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpzoom_user_history_change_username',
                    _ajax_nonce: wpzoomUserHistoryData.changeUsernameNonce,
                    current_username: oldUsername,
                    new_username: newUsername
                },
                success: function(response) {
                    // Update nonce for next request
                    if (response.new_nonce) {
                        wpzoomUserHistoryData.changeUsernameNonce = response.new_nonce;
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

    /**
     * Initialize clear history functionality
     */
    function initClearHistory() {
        var $clearBtn = $('#user-history-clear-log');

        if (!$clearBtn.length) {
            return;
        }

        var i18n = wpzoomUserHistoryData.i18n || {};

        $clearBtn.on('click', function(e) {
            e.preventDefault();

            if (!confirm(i18n.confirmClear || 'Are you sure you want to clear all history for this user? This cannot be undone.')) {
                return;
            }

            var $btn = $(this);

            if ($btn.hasClass('loading')) {
                return;
            }

            $btn.addClass('loading').prop('disabled', true).text(i18n.clearing || 'Clearing...');

            $.ajax({
                url: wpzoomUserHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpzoom_user_history_clear',
                    nonce: wpzoomUserHistoryData.clearHistoryNonce,
                    user_id: wpzoomUserHistoryData.userId
                },
                success: function(response) {
                    if (response.success) {
                        // Replace the history section with empty message
                        var $historyLog = $('#user-history-log');
                        $historyLog.html('<p class="user-history-empty">No changes have been recorded yet.</p>');

                        // Update the count
                        $('.user-history-count').remove();
                    } else {
                        alert(response.data.message || i18n.errorGeneric);
                        $btn.removeClass('loading').prop('disabled', false).text(i18n.clearLog || 'Clear Log');
                    }
                },
                error: function() {
                    alert(i18n.errorGeneric || 'Something went wrong.');
                    $btn.removeClass('loading').prop('disabled', false).text(i18n.clearLog || 'Clear Log');
                }
            });
        });
    }

    /**
     * Initialize lock/unlock toggle functionality
     */
    function initLockToggle() {
        var $btn = $('#user-history-lock-toggle');

        if (!$btn.length) {
            return;
        }

        var i18n = wpzoomUserHistoryData.i18n || {};

        $btn.on('click', function(e) {
            e.preventDefault();

            var isLocked = $btn.data('locked') === 'yes';
            var action = isLocked ? 'unlock' : 'lock';

            var confirmMsg = isLocked
                ? (i18n.confirmUnlock || 'Are you sure you want to unlock this user?')
                : (i18n.confirmLock || 'Are you sure you want to lock this user? They will be logged out immediately.');

            if (!confirm(confirmMsg)) {
                return;
            }

            $btn.prop('disabled', true).text(i18n.pleaseWait || 'Please wait...');

            $.ajax({
                url: wpzoomUserHistoryData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpzoom_user_history_toggle_lock',
                    nonce: wpzoomUserHistoryData.lockNonce,
                    user_id: wpzoomUserHistoryData.userId,
                    lock_action: action
                },
                success: function(response) {
                    var $msg = $('.user-history-lock-message');

                    if (response.success) {
                        var nowLocked = response.data.isLocked;
                        $btn.data('locked', nowLocked ? 'yes' : 'no');

                        // Update badge
                        var $badge = $('.user-history-lock-badge');
                        if (nowLocked) {
                            $badge.removeClass('active').addClass('locked')
                                .text(i18n.locked || 'Locked');
                            $btn.removeClass('button-link-delete');
                        } else {
                            $badge.removeClass('locked').addClass('active')
                                .text(i18n.active || 'Active');
                            $btn.addClass('button-link-delete');
                        }

                        $msg.removeClass('success error').addClass('success')
                            .text(response.data.message).show();
                        setTimeout(function() { $msg.fadeOut(); }, 3000);
                    } else {
                        $msg.removeClass('success error').addClass('error')
                            .text(response.data.message).show();
                    }
                },
                error: function() {
                    var $msg = $('.user-history-lock-message');
                    $msg.removeClass('success error').addClass('error')
                        .text(i18n.errorGeneric || 'Something went wrong.').show();
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    var isNowLocked = $btn.data('locked') === 'yes';
                    $btn.text(isNowLocked
                        ? (i18n.unlockAccount || 'Unlock Account')
                        : (i18n.lockAccount || 'Lock Account'));
                }
            });
        });
    }

})(jQuery);
