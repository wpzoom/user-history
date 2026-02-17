<?php
/**
 * Admin UI for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin UI for user history display, AJAX endpoints for
 * history loading/clearing, username changes, user search extension,
 * and the delete user button.
 */
class User_History_Admin {

    /**
     * Reference to main plugin instance.
     *
     * @var User_History
     */
    private $plugin;

    /**
     * Constructor — registers admin hooks.
     *
     * @param User_History $plugin Main plugin instance.
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;

        // Admin UI hooks
        add_action('edit_user_profile', [$this, 'display_history_section'], 99);
        add_action('show_user_profile', [$this, 'display_history_section'], 99);
        add_action('edit_user_profile', [$this, 'display_delete_user_button'], 100);
        add_action('show_user_profile', [$this, 'display_delete_user_button'], 100);

        // Enqueue admin styles and scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_load_more_user_history', [$this, 'ajax_load_more_history']);
        add_action('wp_ajax_user_history_change_username', [$this, 'ajax_change_username']);
        add_action('wp_ajax_clear_user_history', [$this, 'ajax_clear_history']);

        // Extend user search to include history
        add_action('pre_user_query', [$this, 'extend_user_search']);
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * Enqueue admin assets on user-edit.php and profile.php.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets($hook) {
        // Only load on user edit/profile pages (users.php CSS is handled by Lock class)
        if (!in_array($hook, ['user-edit.php', 'profile.php'])) {
            return;
        }

        wp_enqueue_style(
            'user-history-admin',
            USER_HISTORY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            USER_HISTORY_VERSION
        );

        wp_enqueue_script(
            'user-history-admin',
            USER_HISTORY_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            USER_HISTORY_VERSION,
            true
        );

        // Get user ID from URL or current user
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not required for reading user_id, value is cast to int
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : get_current_user_id();

        wp_localize_script('user-history-admin', 'userHistoryData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('user_history_nonce'),
            'changeUsernameNonce' => wp_create_nonce('user_history_change_username'),
            'clearHistoryNonce' => wp_create_nonce('user_history_clear'),
            'lockNonce' => wp_create_nonce('user_history_lock'),
            'userId'  => $user_id,
            'i18n'    => [
                'change'        => __('Change', 'user-history'),
                'cancel'        => __('Cancel', 'user-history'),
                'pleaseWait'    => __('Please wait...', 'user-history'),
                'errorGeneric'  => __('Something went wrong. Please try again.', 'user-history'),
                'confirmClear'  => __('Are you sure you want to clear all history for this user? This cannot be undone.', 'user-history'),
                'clearing'      => __('Clearing...', 'user-history'),
                'clearLog'      => __('Clear Log', 'user-history'),
                'lockAccount'   => __('Lock Account', 'user-history'),
                'unlockAccount' => __('Unlock Account', 'user-history'),
                'confirmLock'   => __('Are you sure you want to lock this user? They will be logged out immediately.', 'user-history'),
                'confirmUnlock' => __('Are you sure you want to unlock this user?', 'user-history'),
            ],
        ]);
    }

    // =========================================================================
    // User Edit Page — History Section
    // =========================================================================

    /**
     * Display history section on user edit page.
     *
     * @param WP_User $user The user being edited.
     */
    public function display_history_section($user) {
        // Only show to admins
        if (!current_user_can('edit_users')) {
            return;
        }

        $history = $this->plugin->get_user_history($user->ID, 20);
        $total_count = $this->plugin->get_user_history_count($user->ID);
        ?>
        <div class="user-history-section">
            <h2><?php esc_html_e('Account History', 'user-history'); ?></h2>
            <p class="description">
                <?php esc_html_e('A log of changes made to this account.', 'user-history'); ?>
                <?php if ($total_count > 0): ?>
                    <span class="user-history-count">
                        <?php printf(
                            esc_html(_n('%d change recorded', '%d changes recorded', $total_count, 'user-history')),
                            (int) $total_count
                        ); ?>
                    </span>
                <?php endif; ?>
            </p>

            <div class="user-history-log" id="user-history-log" data-user-id="<?php echo esc_attr($user->ID); ?>">
                <?php if (empty($history)): ?>
                    <p class="user-history-empty">
                        <?php esc_html_e('No changes have been recorded yet.', 'user-history'); ?>
                    </p>
                <?php else: ?>
                    <table class="widefat user-history-table">
                        <thead>
                            <tr>
                                <th class="column-date"><?php esc_html_e('Date', 'user-history'); ?></th>
                                <th class="column-field"><?php esc_html_e('Field', 'user-history'); ?></th>
                                <th class="column-change"><?php esc_html_e('Change', 'user-history'); ?></th>
                                <th class="column-by"><?php esc_html_e('Changed By', 'user-history'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="user-history-tbody">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_history_rows()
                            echo $this->render_history_rows($history);
                            ?>
                        </tbody>
                    </table>

                    <div class="user-history-actions">
                        <?php if ($total_count > 20): ?>
                            <button type="button" class="button" id="user-history-load-more"
                                    data-offset="20" data-total="<?php echo esc_attr($total_count); ?>">
                                <?php esc_html_e('Load More', 'user-history'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button user-history-clear-log" id="user-history-clear-log">
                            <?php esc_html_e('Clear Log', 'user-history'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // User Edit Page — Delete Button
    // =========================================================================

    /**
     * Display delete user button on user edit page.
     *
     * @param WP_User $user The user being edited.
     */
    public function display_delete_user_button($user) {
        // Only show to users who can delete users
        if (!current_user_can('delete_users')) {
            return;
        }

        // Don't allow deleting yourself
        if ($user->ID === get_current_user_id()) {
            return;
        }

        // Don't show for super admins on multisite (they can't be deleted this way)
        if (is_multisite() && is_super_admin($user->ID)) {
            return;
        }

        $delete_url = wp_nonce_url(
            admin_url('users.php?action=delete&user=' . $user->ID),
            'bulk-users'
        );
        ?>
        <div class="user-history-section user-history-delete-section">
            <h2><?php esc_html_e('Delete User', 'user-history'); ?></h2>
            <p class="description">
                <?php esc_html_e('Permanently delete this user account. You will be able to reassign their content to another user.', 'user-history'); ?>
            </p>
            <p>
                <a href="<?php echo esc_url($delete_url); ?>" class="button button-link-delete">
                    <?php esc_html_e('Delete User', 'user-history'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // History Rendering
    // =========================================================================

    /**
     * Render history table rows.
     *
     * @param array $history Array of history entry objects.
     * @return string HTML output.
     */
    public function render_history_rows($history) {
        $output = '';

        foreach ($history as $entry) {
            $changed_by_user = get_userdata($entry->changed_by);
            $changed_by_name = $changed_by_user ? $changed_by_user->display_name : __('Unknown', 'user-history');
            $changed_by_link = $changed_by_user ? get_edit_user_link($entry->changed_by) : '#';

            $is_self = ($entry->user_id == $entry->changed_by);

            $output .= '<tr class="user-history-entry type-' . esc_attr($entry->change_type) . '">';

            // Date column
            $output .= '<td class="column-date">';
            $output .= '<span class="history-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '<span class="history-time">' . esc_html(date_i18n(get_option('time_format'), strtotime($entry->created_at))) . '</span>';
            $output .= '</td>';

            // Field column
            $output .= '<td class="column-field">';
            $output .= '<strong>' . esc_html($entry->field_label) . '</strong>';
            $output .= '</td>';

            // Change column
            $output .= '<td class="column-change">';
            if ($entry->change_type === 'create') {
                $output .= '<span class="history-new-value">' . esc_html($entry->new_value) . '</span>';
            } elseif ($entry->field_name === 'account_locked') {
                $output .= '<span class="history-new-value">' . esc_html($entry->field_label) . '</span>';
            } elseif ($entry->field_name === 'user_pass') {
                // Password changes just show "Changed" - no values ever stored
                $output .= '<span class="history-new-value">' . esc_html__('Changed', 'user-history') . '</span>';
            } else {
                if (!empty($entry->old_value)) {
                    $output .= '<span class="history-old-value">' . esc_html($this->truncate_value($entry->old_value)) . '</span>';
                    $output .= ' <span class="history-arrow">&rarr;</span> ';
                }
                $output .= '<span class="history-new-value">' . esc_html($this->truncate_value($entry->new_value)) . '</span>';
            }
            $output .= '</td>';

            // Changed by column
            $output .= '<td class="column-by">';
            if ($is_self) {
                $output .= '<span class="history-self">' . esc_html__('Self', 'user-history') . '</span>';
            } else {
                $output .= '<a href="' . esc_url($changed_by_link) . '">' . esc_html($changed_by_name) . '</a>';
            }
            $output .= '</td>';

            $output .= '</tr>';
        }

        return $output;
    }

    /**
     * Truncate long values for display.
     *
     * @param string $value  Value to truncate.
     * @param int    $length Max length.
     * @return string
     */
    private function truncate_value($value, $length = 50) {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, $length) . '...';
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for loading more history.
     */
    public function ajax_load_more_history() {
        check_ajax_referer('user_history_nonce', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

        if (!$user_id) {
            wp_send_json_error(['message' => 'Invalid user ID']);
        }

        $history = $this->plugin->get_user_history($user_id, 20, $offset);

        if (empty($history)) {
            wp_send_json_success(['html' => '', 'hasMore' => false]);
        }

        $html = $this->render_history_rows($history);
        $total = $this->plugin->get_user_history_count($user_id);
        $has_more = ($offset + 20) < $total;

        wp_send_json_success([
            'html'    => $html,
            'hasMore' => $has_more,
            'newOffset' => $offset + 20,
        ]);
    }

    /**
     * AJAX handler for clearing user history.
     */
    public function ajax_clear_history() {
        check_ajax_referer('user_history_clear', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('Unauthorized', 'user-history')]);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if (!$user_id) {
            wp_send_json_error(['message' => __('Invalid user ID', 'user-history')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . User_History::TABLE_NAME;

        $deleted = $wpdb->delete(
            $table_name,
            ['user_id' => $user_id],
            ['%d']
        );

        if ($deleted === false) {
            wp_send_json_error(['message' => __('Failed to clear history', 'user-history')]);
        }

        wp_send_json_success([
            'message' => __('History cleared successfully', 'user-history'),
        ]);
    }

    /**
     * AJAX handler for changing username.
     */
    public function ajax_change_username() {
        $response = [
            'success'   => false,
            'new_nonce' => wp_create_nonce('user_history_change_username'),
        ];

        // Check capability
        if (!current_user_can('edit_users')) {
            $response['message'] = __('You do not have permission to change usernames.', 'user-history');
            wp_send_json($response);
        }

        // Validate nonce
        if (!check_ajax_referer('user_history_change_username', '_ajax_nonce', false)) {
            $response['message'] = __('Security check failed. Please refresh the page.', 'user-history');
            wp_send_json($response);
        }

        // Validate request
        if (empty($_POST['new_username']) || empty($_POST['current_username'])) {
            $response['message'] = __('Invalid request.', 'user-history');
            wp_send_json($response);
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_user handles sanitization
        $new_username = sanitize_user(trim(wp_unslash($_POST['new_username'])), true);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_user handles sanitization
        $old_username = sanitize_user(trim(wp_unslash($_POST['current_username'])), true);

        // Old username must exist
        $user_id = username_exists($old_username);
        if (!$user_id) {
            $response['message'] = __('Invalid request.', 'user-history');
            wp_send_json($response);
        }

        // If same username, nothing to do
        if ($new_username === $old_username) {
            $response['success'] = true;
            $response['message'] = __('Username unchanged.', 'user-history');
            wp_send_json($response);
        }

        // Validate username length
        if (mb_strlen($new_username) < 3 || mb_strlen($new_username) > 60) {
            $response['message'] = __('Username must be between 3 and 60 characters.', 'user-history');
            wp_send_json($response);
        }

        // Validate username characters
        if (!validate_username($new_username)) {
            $response['message'] = __('This username contains invalid characters.', 'user-history');
            wp_send_json($response);
        }

        // Check illegal logins (using WordPress core filter)
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP filter
        $illegal_logins = array_map('strtolower', (array) apply_filters('illegal_user_logins', []));
        if (in_array(strtolower($new_username), $illegal_logins, true)) {
            $response['message'] = __('Sorry, that username is not allowed.', 'user-history');
            wp_send_json($response);
        }

        // Check if new username already exists
        if (username_exists($new_username)) {
            $response['message'] = sprintf(__('The username "%s" is already taken.', 'user-history'), $new_username);
            wp_send_json($response);
        }

        // Change the username
        $this->change_username($user_id, $old_username, $new_username);

        $response['success'] = true;
        $response['message'] = sprintf(__('Username changed to "%s".', 'user-history'), $new_username);
        wp_send_json($response);
    }

    /**
     * Change a user's username.
     *
     * @param int    $user_id      User ID.
     * @param string $old_username Old username.
     * @param string $new_username New username.
     */
    private function change_username($user_id, $old_username, $new_username) {
        global $wpdb;

        // Log the change before making it
        $this->plugin->log_change(
            $user_id,
            get_current_user_id(),
            'user_login',
            'Username',
            $old_username,
            $new_username,
            'update'
        );

        // Update user_login
        $wpdb->update(
            $wpdb->users,
            ['user_login' => $new_username],
            ['ID' => $user_id],
            ['%s'],
            ['%d']
        );

        // Update user_nicename if it matches the old username
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->users SET user_nicename = %s WHERE ID = %d AND user_nicename = %s",
            sanitize_title($new_username),
            $user_id,
            sanitize_title($old_username)
        ));

        // Update display_name if it matches the old username
        $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->users SET display_name = %s WHERE ID = %d AND display_name = %s",
            $new_username,
            $user_id,
            $old_username
        ));

        // Handle multisite super admin
        if (is_multisite()) {
            $super_admins = (array) get_site_option('site_admins', ['admin']);
            $key = array_search($old_username, $super_admins);
            if ($key !== false) {
                $super_admins[$key] = $new_username;
                update_site_option('site_admins', $super_admins);
            }
        }

        // Clear user cache
        clean_user_cache($user_id);

        /**
         * Fires after a username has been changed.
         *
         * @param int    $user_id      The user ID.
         * @param string $old_username The old username.
         * @param string $new_username The new username.
         */
        do_action('user_history_username_changed', $user_id, $old_username, $new_username);
    }

    // =========================================================================
    // User Search Extension
    // =========================================================================

    /**
     * Extend user search to include historical values.
     *
     * When searching users in admin, also search through old_value in history table
     * to find users by their previous usernames, emails, names, etc.
     *
     * @param WP_User_Query $query The user query.
     */
    public function extend_user_search($query) {
        global $wpdb, $pagenow;

        // Only run on users.php admin page with a search
        if (!is_admin() || $pagenow !== 'users.php') {
            return;
        }

        // Check if there's a search term
        $search = $query->get('search');
        if (empty($search)) {
            return;
        }

        // Remove the wildcard characters that WordPress adds
        $search_term = trim($search, '*');
        if (empty($search_term)) {
            return;
        }

        $history_table = $wpdb->prefix . User_History::TABLE_NAME;

        // Check if table exists (plugin may not be activated yet)
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table)) !== $history_table) {
            return;
        }

        // Find user IDs that have matching old values in history
        $user_ids_from_history = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
            $wpdb->prepare(
                "SELECT DISTINCT user_id FROM $history_table
                WHERE old_value LIKE %s
                AND field_name IN ('user_login', 'user_email', 'first_name', 'last_name', 'display_name', 'nickname')",
                '%' . $wpdb->esc_like($search_term) . '%'
            )
        );

        if (empty($user_ids_from_history)) {
            return;
        }

        // Add these user IDs to the search results by modifying the WHERE clause
        // We inject an OR condition: (original search conditions) OR (ID IN history matches)
        $ids_list = implode(',', array_map('intval', $user_ids_from_history));

        // Append our condition to include users found in history
        $query->query_where .= " OR {$wpdb->users}.ID IN ($ids_list)";
    }
}
