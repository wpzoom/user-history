<?php
/**
 * User lock/unlock feature for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles user account locking/unlocking, authentication blocking,
 * and all lock-related admin UI (user edit page, users list column,
 * bulk actions, row actions, filter view).
 */
class WPZOOM_User_History_Lock {

    /**
     * Reference to main plugin instance.
     *
     * @var WPZOOM_User_History
     */
    private $plugin;

    /**
     * Constructor — registers all lock-related hooks.
     *
     * @param WPZOOM_User_History $plugin Main plugin instance.
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;

        // Authentication blocking (runs on all requests, not just admin)
        add_filter('authenticate', [$this, 'block_locked_user_auth'], 1000, 3);
        add_action('wp_authenticate_application_password_errors', [$this, 'block_locked_user_app_password'], 10, 2);
        add_filter('determine_current_user', [$this, 'block_locked_user_session'], 9999);

        // User edit page UI
        add_action('edit_user_profile', [$this, 'display_lock_user_section'], 98);
        add_action('show_user_profile', [$this, 'display_lock_user_section'], 98);

        // AJAX handler
        add_action('wp_ajax_wpzoom_user_history_toggle_lock', [$this, 'ajax_toggle_lock']);

        // Users list: column, row actions, bulk actions, filter view, admin notices
        add_filter('user_row_actions', [$this, 'add_lock_row_action'], 10, 2);
        add_action('admin_init', [$this, 'process_lock_row_action']);
        add_filter('manage_users_columns', [$this, 'add_locked_column']);
        add_filter('manage_users_custom_column', [$this, 'render_locked_column'], 10, 3);
        add_filter('bulk_actions-users', [$this, 'add_lock_bulk_actions']);
        add_filter('handle_bulk_actions-users', [$this, 'handle_lock_bulk_actions'], 10, 3);
        add_filter('views_users', [$this, 'add_locked_users_view']);
        add_action('pre_get_users', [$this, 'filter_locked_users_query']);
        add_action('admin_notices', [$this, 'lock_bulk_action_admin_notice']);

        // CSS on users.php for lock badge column
        add_action('admin_enqueue_scripts', [$this, 'enqueue_lock_assets']);
    }

    // =========================================================================
    // Core Lock Logic
    // =========================================================================

    /**
     * Check if a user account is locked.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function is_user_locked($user_id) {
        return get_user_meta($user_id, WPZOOM_User_History::LOCKED_META_KEY, true) === '1';
    }

    /**
     * Lock a user account.
     *
     * @param int $user_id User ID.
     * @return true|WP_Error
     */
    public function lock_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        if ($user_id === get_current_user_id()) {
            return new WP_Error('self_lock', __('You cannot lock your own account.', 'wpzoom-user-history'));
        }

        if (is_multisite() && is_super_admin($user_id)) {
            return new WP_Error('super_admin_lock', __('Super admins cannot be locked.', 'wpzoom-user-history'));
        }

        if ($this->is_user_locked($user_id)) {
            return true;
        }

        update_user_meta($user_id, WPZOOM_User_History::LOCKED_META_KEY, '1');

        // Destroy all sessions immediately
        $sessions = WP_Session_Tokens::get_instance($user_id);
        $sessions->destroy_all();

        $this->plugin->log_change($user_id, get_current_user_id(), 'account_locked', 'Account Locked', '', 'Locked', 'lock');

        return true;
    }

    /**
     * Unlock a user account.
     *
     * @param int $user_id User ID.
     * @return true|false
     */
    public function unlock_user($user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        if (!$this->is_user_locked($user_id)) {
            return true;
        }

        update_user_meta($user_id, WPZOOM_User_History::LOCKED_META_KEY, '');

        $this->plugin->log_change($user_id, get_current_user_id(), 'account_locked', 'Account Unlocked', 'Locked', '', 'unlock');

        return true;
    }

    /**
     * Get the locked account error message.
     *
     * @return string
     */
    private function get_locked_message() {
        $message = get_option('wpzoom_user_history_locked_message', '');

        // Fall back to old lock-user-account plugin option
        if (empty($message)) {
            $message = get_option('baba_locked_message', '');
        }

        if (empty($message)) {
            $message = __('Your account has been locked. Please contact the administrator.', 'wpzoom-user-history');
        }

        return $message;
    }

    // =========================================================================
    // Authentication Blocking
    // =========================================================================

    /**
     * Block locked users from authenticating (all methods including app passwords).
     *
     * @param WP_User|WP_Error $user     Authentication result.
     * @param string           $username  Username.
     * @param string           $password  Password.
     * @return WP_User|WP_Error
     */
    public function block_locked_user_auth($user, $username, $password) {
        if (!($user instanceof WP_User)) {
            return $user;
        }

        if ($this->is_user_locked($user->ID)) {
            return new WP_Error('user_locked', $this->get_locked_message());
        }

        return $user;
    }

    /**
     * Block locked users from using application passwords.
     *
     * @param WP_Error $error Error object.
     * @param WP_User  $user  User object.
     */
    public function block_locked_user_app_password($error, $user) {
        if ($this->is_user_locked($user->ID)) {
            $error->add('user_locked', $this->get_locked_message());
        }
    }

    /**
     * Invalidate sessions for locked users on any request.
     *
     * @param int|false $user_id User ID or false.
     * @return int|false
     */
    public function block_locked_user_session($user_id) {
        if (!$user_id) {
            return $user_id;
        }

        // Allow WP-CLI access for locked admins
        if (defined('WP_CLI') && WP_CLI) {
            return $user_id;
        }

        if ($this->is_user_locked($user_id)) {
            return false;
        }

        return $user_id;
    }

    // =========================================================================
    // AJAX Handler
    // =========================================================================

    /**
     * AJAX handler for lock/unlock toggle.
     */
    public function ajax_toggle_lock() {
        check_ajax_referer('wpzoom_user_history_lock', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('Unauthorized', 'wpzoom-user-history')]);
        }

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $action  = isset($_POST['lock_action']) ? sanitize_key($_POST['lock_action']) : '';

        if (!$user_id || !in_array($action, ['lock', 'unlock'], true)) {
            wp_send_json_error(['message' => __('Invalid request.', 'wpzoom-user-history')]);
        }

        if ($action === 'lock') {
            $result = $this->lock_user($user_id);
        } else {
            $result = $this->unlock_user($user_id);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $is_locked = $this->is_user_locked($user_id);

        wp_send_json_success([
            'message'  => $action === 'lock'
                ? __('Account locked. All sessions have been destroyed.', 'wpzoom-user-history')
                : __('Account unlocked.', 'wpzoom-user-history'),
            'isLocked' => $is_locked,
        ]);
    }

    // =========================================================================
    // User Edit Page UI
    // =========================================================================

    /**
     * Display lock/unlock section on user edit page.
     *
     * @param WP_User $user The user being edited.
     */
    public function display_lock_user_section($user) {
        if (!current_user_can('edit_users')) {
            return;
        }

        // Don't show on own profile
        if ($user->ID === get_current_user_id()) {
            return;
        }

        // Don't show for super admins on multisite
        if (is_multisite() && is_super_admin($user->ID)) {
            return;
        }

        $is_locked = $this->is_user_locked($user->ID);
        ?>
        <div class="user-history-section user-history-lock-section">
            <h2><?php esc_html_e('Account Status', 'wpzoom-user-history'); ?></h2>
            <p class="description">
                <?php esc_html_e('Lock this account to prevent the user from logging in.', 'wpzoom-user-history'); ?>
            </p>
            <p class="user-history-lock-status">
                <?php if ($is_locked): ?>
                    <span class="user-history-lock-badge locked"><?php esc_html_e('Locked', 'wpzoom-user-history'); ?></span>
                <?php else: ?>
                    <span class="user-history-lock-badge active"><?php esc_html_e('Active', 'wpzoom-user-history'); ?></span>
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button <?php echo $is_locked ? '' : 'button-link-delete'; ?>" id="user-history-lock-toggle"
                        data-user-id="<?php echo esc_attr($user->ID); ?>"
                        data-locked="<?php echo $is_locked ? 'yes' : 'no'; ?>">
                    <?php echo $is_locked
                        ? esc_html__('Unlock Account', 'wpzoom-user-history')
                        : esc_html__('Lock Account', 'wpzoom-user-history'); ?>
                </button>
                <span class="user-history-lock-message"></span>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // Users List — Column, Row Actions, Bulk Actions, Filter
    // =========================================================================

    /**
     * Add "Status" column to users list.
     *
     * @param array $columns Column headers.
     * @return array
     */
    public function add_locked_column($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'role') {
                $new_columns['user_locked'] = __('Status', 'wpzoom-user-history');
            }
        }
        return $new_columns;
    }

    /**
     * Render the locked column content.
     *
     * @param string $output      Column output.
     * @param string $column_name Column name.
     * @param int    $user_id     User ID.
     * @return string
     */
    public function render_locked_column($output, $column_name, $user_id) {
        if ($column_name !== 'user_locked') {
            return $output;
        }

        if ($this->is_user_locked($user_id)) {
            return '<span class="user-history-lock-badge locked">' . esc_html__('Locked', 'wpzoom-user-history') . '</span>';
        }

        return '&mdash;';
    }

    /**
     * Add lock/unlock row action to users list.
     *
     * @param array   $actions     Row actions.
     * @param WP_User $user_object User object.
     * @return array
     */
    public function add_lock_row_action($actions, $user_object) {
        if (!current_user_can('edit_users')) {
            return $actions;
        }

        // Don't show for own account
        if ($user_object->ID === get_current_user_id()) {
            return $actions;
        }

        // Don't show for super admins on multisite
        if (is_multisite() && is_super_admin($user_object->ID)) {
            return $actions;
        }

        $is_locked = $this->is_user_locked($user_object->ID);

        if ($is_locked) {
            $url = wp_nonce_url(
                add_query_arg([
                    'action'  => 'wpzoom_user_history_unlock',
                    'user'    => $user_object->ID,
                ], admin_url('users.php')),
                'wpzoom_user_history_lock_' . $user_object->ID
            );
            $actions['unlock'] = '<a href="' . esc_url($url) . '">' . esc_html__('Unlock', 'wpzoom-user-history') . '</a>';
        } else {
            $url = wp_nonce_url(
                add_query_arg([
                    'action'  => 'wpzoom_user_history_lock',
                    'user'    => $user_object->ID,
                ], admin_url('users.php')),
                'wpzoom_user_history_lock_' . $user_object->ID
            );
            $actions['lock'] = '<a href="' . esc_url($url) . '">' . esc_html__('Lock', 'wpzoom-user-history') . '</a>';
        }

        return $actions;
    }

    /**
     * Process lock/unlock row action from users list.
     */
    public function process_lock_row_action() {
        global $pagenow;

        if ($pagenow !== 'users.php') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below with check_admin_referer
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        if (!in_array($action, ['wpzoom_user_history_lock', 'wpzoom_user_history_unlock'], true)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below with check_admin_referer
        $user_id = isset($_GET['user']) ? (int) $_GET['user'] : 0;

        if (!$user_id) {
            return;
        }

        check_admin_referer('wpzoom_user_history_lock_' . $user_id);

        if ($action === 'wpzoom_user_history_lock') {
            $this->lock_user($user_id);
        } else {
            $this->unlock_user($user_id);
        }

        wp_safe_redirect(remove_query_arg(['action', 'user', '_wpnonce'], wp_get_referer() ?: admin_url('users.php')));
        exit;
    }

    /**
     * Add lock/unlock bulk actions.
     *
     * @param array $actions Bulk actions.
     * @return array
     */
    public function add_lock_bulk_actions($actions) {
        $actions['lock_users']   = __('Lock', 'wpzoom-user-history');
        $actions['unlock_users'] = __('Unlock', 'wpzoom-user-history');
        return $actions;
    }

    /**
     * Handle lock/unlock bulk actions.
     *
     * @param string $redirect_url Redirect URL.
     * @param string $action       Bulk action name.
     * @param array  $user_ids     Selected user IDs.
     * @return string
     */
    public function handle_lock_bulk_actions($redirect_url, $action, $user_ids) {
        if (!in_array($action, ['lock_users', 'unlock_users'], true)) {
            return $redirect_url;
        }

        $count = 0;
        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;

            if ($action === 'lock_users') {
                $result = $this->lock_user($user_id);
            } else {
                $result = $this->unlock_user($user_id);
            }

            if ($result === true) {
                $count++;
            }
        }

        return add_query_arg([
            'wpzoom_user_history_lock_action' => $action,
            'wpzoom_user_history_lock_count'  => $count,
        ], $redirect_url);
    }

    /**
     * Show admin notice after bulk lock/unlock.
     */
    public function lock_bulk_action_admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading action result from URL, value is sanitized
        $action = isset($_GET['wpzoom_user_history_lock_action']) ? sanitize_key($_GET['wpzoom_user_history_lock_action']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading count from URL, value is cast to int
        $count  = isset($_GET['wpzoom_user_history_lock_count']) ? (int) $_GET['wpzoom_user_history_lock_count'] : 0;

        if (empty($action) || !$count) {
            return;
        }

        if ($action === 'lock_users') {
            /* translators: %d: number of users locked */
            $message = sprintf(
                _n('%d user locked.', '%d users locked.', $count, 'wpzoom-user-history'),
                $count
            );
        } else {
            /* translators: %d: number of users unlocked */
            $message = sprintf(
                _n('%d user unlocked.', '%d users unlocked.', $count, 'wpzoom-user-history'),
                $count
            );
        }

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($message));
    }

    /**
     * Add "Locked" filter view to users list.
     *
     * @param array $views User list views.
     * @return array
     */
    public function add_locked_users_view($views) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting locked users from usermeta
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                WPZOOM_User_History::LOCKED_META_KEY,
                '1'
            )
        );

        if (!$count) {
            return $views;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter from URL, value is sanitized
        $current_filter = isset($_GET['wpzoom_user_history_filter']) ? sanitize_key($_GET['wpzoom_user_history_filter']) : '';
        $class = ($current_filter === 'locked') ? 'current' : '';

        $views['locked'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url(add_query_arg('wpzoom_user_history_filter', 'locked', admin_url('users.php'))),
            $class,
            esc_html__('Locked', 'wpzoom-user-history'),
            $count
        );

        return $views;
    }

    /**
     * Filter users list query for locked users view.
     *
     * @param WP_User_Query $query The user query.
     */
    public function filter_locked_users_query($query) {
        global $pagenow;

        if (!is_admin() || $pagenow !== 'users.php') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter from URL, value is sanitized
        $filter = isset($_GET['wpzoom_user_history_filter']) ? sanitize_key($_GET['wpzoom_user_history_filter']) : '';

        if ($filter === 'locked') {
            $query->set('meta_key', WPZOOM_User_History::LOCKED_META_KEY);
            $query->set('meta_value', '1');
        }
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * Enqueue CSS on users.php for lock badge column.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_lock_assets($hook) {
        if ($hook !== 'users.php') {
            return;
        }

        wp_enqueue_style(
            'wpzoom-user-history-admin',
            WPZOOM_USER_HISTORY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPZOOM_USER_HISTORY_VERSION
        );
    }
}
