<?php
/**
 * Plugin Name: User History
 * Plugin URI: https://www.wpzoom.com
 * Description: Tracks changes made to user accounts (name, email, username, etc.) and displays a history log on the user edit page.
 * Version: 1.0.2
 * Author: WPZOOM
 * Author URI: https://www.wpzoom.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: user-history
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('USER_HISTORY_VERSION', '1.0.2');
define('USER_HISTORY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('USER_HISTORY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main User History Class
 */
class User_History {

    /**
     * Database table name (without prefix)
     */
    const TABLE_NAME = 'user_history';

    /**
     * Fields to track in wp_users table
     */
    private $tracked_fields = [
        'user_login'    => 'Username',
        'user_email'    => 'Email',
        'user_pass'     => 'Password',
        'user_nicename' => 'Nicename',
        'display_name'  => 'Display Name',
        'user_url'      => 'Website',
    ];

    /**
     * User meta fields to track (capabilities key is added dynamically in init)
     */
    private $tracked_meta = [
        'first_name'   => 'First Name',
        'last_name'    => 'Last Name',
        'nickname'     => 'Nickname',
        'description'  => 'Biographical Info',
    ];

    /**
     * The capabilities meta key (set dynamically based on table prefix)
     */
    private $capabilities_key = '';

    /**
     * Temporarily store old user data before update
     */
    private $old_user_data = [];
    private $old_user_meta = [];

    /**
     * Track which users have had role changes logged this request
     * (to prevent duplicate logging from set_user_role and updated_user_meta)
     */
    private $role_logged = [];

    /**
     * Pending role changes to log at shutdown (to capture final state)
     * Format: [user_id => old_roles_string]
     */
    private $pending_role_changes = [];

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Initialize hooks
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        $this->create_table();
        update_option('user_history_version', USER_HISTORY_VERSION);
    }

    /**
     * Create database table
     */
    private function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            changed_by bigint(20) unsigned NOT NULL,
            field_name varchar(100) NOT NULL,
            field_label varchar(100) NOT NULL,
            old_value longtext,
            new_value longtext,
            change_type varchar(50) NOT NULL DEFAULT 'update',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY changed_by (changed_by),
            KEY field_name (field_name),
            KEY created_at (created_at),
            KEY old_value_search (field_name, old_value(100))
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Initialize plugin
     */
    public function init() {
        global $wpdb;

        // Set the capabilities meta key dynamically based on table prefix
        $this->capabilities_key = $wpdb->prefix . 'capabilities';
        $this->tracked_meta[$this->capabilities_key] = 'Role';

        // Check for database updates
        $this->maybe_upgrade();

        // Hook before user update to capture old values
        add_action('pre_user_query', [$this, 'capture_old_data_on_query']);
        add_filter('wp_pre_insert_user_data', [$this, 'capture_old_user_data'], 10, 4);

        // Hook after user update to log changes
        add_action('profile_update', [$this, 'log_user_changes'], 10, 3);

        // Hook for user meta changes
        add_action('update_user_meta', [$this, 'capture_old_meta'], 10, 4);
        add_action('updated_user_meta', [$this, 'log_meta_change'], 10, 4);

        // Hook specifically for role changes (fires when set_role() is called)
        add_action('set_user_role', [$this, 'log_role_change'], 10, 3);

        // Log pending role changes at shutdown (to capture final state after all plugins finish)
        add_action('shutdown', [$this, 'log_pending_role_changes']);

        // Hook for new user registration
        add_action('user_register', [$this, 'log_user_creation'], 10, 2);

        // Admin UI hooks
        add_action('edit_user_profile', [$this, 'display_history_section'], 99);
        add_action('show_user_profile', [$this, 'display_history_section'], 99);

        // Add delete user button to user edit page
        add_action('edit_user_profile', [$this, 'display_delete_user_button'], 100);
        add_action('show_user_profile', [$this, 'display_delete_user_button'], 100);

        // Enqueue admin styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handler for loading more history
        add_action('wp_ajax_load_more_user_history', [$this, 'ajax_load_more_history']);

        // AJAX handler for changing username
        add_action('wp_ajax_user_history_change_username', [$this, 'ajax_change_username']);

        // AJAX handler for clearing user history
        add_action('wp_ajax_clear_user_history', [$this, 'ajax_clear_history']);

        // Extend user search to include history
        add_action('pre_user_query', [$this, 'extend_user_search']);
    }

    /**
     * Maybe upgrade database
     */
    private function maybe_upgrade() {
        $current_version = get_option('user_history_version', '0');

        if (version_compare($current_version, USER_HISTORY_VERSION, '<')) {
            $this->create_table();
            update_option('user_history_version', USER_HISTORY_VERSION);
        }
    }

    /**
     * Capture old user data before update
     */
    public function capture_old_user_data($data, $update, $user_id, $userdata) {
        if ($update && $user_id) {
            $old_user = get_userdata($user_id);
            if ($old_user) {
                $this->old_user_data[$user_id] = $old_user;

                // Capture meta too
                foreach (array_keys($this->tracked_meta) as $meta_key) {
                    $this->old_user_meta[$user_id][$meta_key] = get_user_meta($user_id, $meta_key, true);
                }
            }
        }
        return $data;
    }

    /**
     * Placeholder for query-based capture (if needed)
     */
    public function capture_old_data_on_query($query) {
        // Reserved for future use
    }

    /**
     * Extend user search to include historical values
     *
     * When searching users in admin, also search through old_value in history table
     * to find users by their previous usernames, emails, names, etc.
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

        $history_table = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists (plugin may not be activated yet)
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $history_table)) !== $history_table) {
            return;
        }

        // Find user IDs that have matching old values in history
        $user_ids_from_history = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM $history_table
            WHERE old_value LIKE %s
            AND field_name IN ('user_login', 'user_email', 'first_name', 'last_name', 'display_name', 'nickname')",
            '%' . $wpdb->esc_like($search_term) . '%'
        ));

        if (empty($user_ids_from_history)) {
            return;
        }

        // Add these user IDs to the search results by modifying the WHERE clause
        // We inject an OR condition: (original search conditions) OR (ID IN history matches)
        $ids_list = implode(',', array_map('intval', $user_ids_from_history));

        // Append our condition to include users found in history
        $query->query_where .= " OR {$wpdb->users}.ID IN ($ids_list)";
    }

    /**
     * Capture old meta value before update
     */
    public function capture_old_meta($meta_id, $user_id, $meta_key, $meta_value) {
        if (isset($this->tracked_meta[$meta_key])) {
            if (!isset($this->old_user_meta[$user_id])) {
                $this->old_user_meta[$user_id] = [];
            }
            $this->old_user_meta[$user_id][$meta_key] = get_user_meta($user_id, $meta_key, true);
        }
    }

    /**
     * Log user profile changes
     */
    public function log_user_changes($user_id, $old_user_data_param, $userdata) {
        // Get the old data we captured
        $old_user = isset($this->old_user_data[$user_id]) ? $this->old_user_data[$user_id] : $old_user_data_param;

        if (!$old_user) {
            return;
        }

        $changed_by = get_current_user_id();

        // Compare tracked fields
        foreach ($this->tracked_fields as $field => $label) {
            $old_value = '';
            $new_value = '';

            if ($field === 'user_pass') {
                // Only log when password actually changed (new hash differs from old hash)
                // Never log any password values - just the event
                $old_pass = '';
                if (is_object($old_user) && isset($old_user->user_pass)) {
                    $old_pass = $old_user->user_pass;
                } elseif (is_object($old_user) && isset($old_user->data->user_pass)) {
                    $old_pass = $old_user->data->user_pass;
                }

                $new_pass = isset($userdata['user_pass']) ? $userdata['user_pass'] : '';

                // Only log if password hash actually changed
                if (!empty($new_pass) && $old_pass !== $new_pass) {
                    $this->log_change($user_id, $changed_by, $field, $label, '', '', 'update');
                }
                continue;
            }

            // Get old value
            if (is_object($old_user) && isset($old_user->$field)) {
                $old_value = $old_user->$field;
            } elseif (is_object($old_user) && isset($old_user->data->$field)) {
                $old_value = $old_user->data->$field;
            }

            // Get new value
            if (isset($userdata[$field])) {
                $new_value = $userdata[$field];
            } else {
                // Fetch current value from database
                $current_user = get_userdata($user_id);
                if ($current_user && isset($current_user->$field)) {
                    $new_value = $current_user->$field;
                }
            }

            // Log if changed
            if ($old_value !== $new_value) {
                $this->log_change($user_id, $changed_by, $field, $label, $old_value, $new_value, 'update');
            }
        }

        // Clean up
        unset($this->old_user_data[$user_id]);
    }

    /**
     * Log meta field change
     */
    public function log_meta_change($meta_id, $user_id, $meta_key, $meta_value) {
        if (!isset($this->tracked_meta[$meta_key])) {
            return;
        }

        $old_value = isset($this->old_user_meta[$user_id][$meta_key])
            ? $this->old_user_meta[$user_id][$meta_key]
            : '';

        // Handle role/capabilities specially - defer to shutdown to capture final state
        if ($meta_key === $this->capabilities_key) {
            // Skip if already logged by set_user_role hook
            if (isset($this->role_logged[$user_id])) {
                return;
            }

            // Store old value for later comparison at shutdown
            // Only store if not already pending (first change captures original state)
            if (!isset($this->pending_role_changes[$user_id])) {
                $this->pending_role_changes[$user_id] = [
                    'old_value'  => $this->format_capabilities($old_value),
                    'changed_by' => get_current_user_id(),
                ];
            }
            return;
        }

        // Only log if actually changed
        if ($old_value !== $meta_value) {
            $this->log_change(
                $user_id,
                get_current_user_id(),
                $meta_key,
                $this->tracked_meta[$meta_key],
                is_array($old_value) ? wp_json_encode($old_value) : $old_value,
                is_array($meta_value) ? wp_json_encode($meta_value) : $meta_value,
                'update'
            );
        }

        // Clean up
        if (isset($this->old_user_meta[$user_id][$meta_key])) {
            unset($this->old_user_meta[$user_id][$meta_key]);
        }
    }

    /**
     * Format capabilities array to readable string
     */
    private function format_capabilities($caps) {
        if (is_string($caps)) {
            $caps = maybe_unserialize($caps);
        }

        if (!is_array($caps)) {
            return '';
        }

        $roles = array_keys(array_filter($caps));
        return implode(', ', $roles);
    }

    /**
     * Log role change (fires when set_role() is called)
     *
     * @param int    $user_id   The user ID
     * @param string $role      The new role
     * @param array  $old_roles The old roles
     */
    public function log_role_change($user_id, $role, $old_roles) {
        // Skip if already logged by updated_user_meta hook
        if (isset($this->role_logged[$user_id])) {
            return;
        }

        $old_role = !empty($old_roles) ? implode(', ', $old_roles) : '';
        $new_role = $role;

        // Only log if actually changed
        if ($old_role === $new_role) {
            return;
        }

        // Mark as logged to prevent duplicate from updated_user_meta
        $this->role_logged[$user_id] = true;

        $this->log_change(
            $user_id,
            get_current_user_id(),
            $this->capabilities_key,
            'Role',
            $old_role,
            $new_role,
            'update'
        );
    }

    /**
     * Log pending role changes at shutdown
     *
     * This ensures we capture the final state after plugins like Members
     * have finished all their role modifications.
     */
    public function log_pending_role_changes() {
        foreach ($this->pending_role_changes as $user_id => $data) {
            // Skip if already logged by set_user_role hook
            if (isset($this->role_logged[$user_id])) {
                continue;
            }

            // Get the current (final) capabilities
            $current_caps = get_user_meta($user_id, $this->capabilities_key, true);
            $new_value = $this->format_capabilities($current_caps);

            // Only log if actually changed
            if ($data['old_value'] !== $new_value) {
                $this->log_change(
                    $user_id,
                    $data['changed_by'],
                    $this->capabilities_key,
                    'Role',
                    $data['old_value'],
                    $new_value,
                    'update'
                );
            }
        }
    }

    /**
     * Log new user creation
     */
    public function log_user_creation($user_id, $userdata = []) {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $changed_by = get_current_user_id() ?: $user_id;

        $this->log_change(
            $user_id,
            $changed_by,
            'user_created',
            'Account Created',
            '',
            $user->user_email,
            'create'
        );
    }

    /**
     * Insert a change log entry
     */
    private function log_change($user_id, $changed_by, $field_name, $field_label, $old_value, $new_value, $change_type = 'update') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->insert(
            $table_name,
            [
                'user_id'     => $user_id,
                'changed_by'  => $changed_by,
                'field_name'  => $field_name,
                'field_label' => $field_label,
                'old_value'   => $old_value,
                'new_value'   => $new_value,
                'change_type' => $change_type,
                'created_at'  => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Get history for a user
     */
    public function get_user_history($user_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name
                WHERE user_id = %d
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            )
        );

        return $results;
    }

    /**
     * Get total history count for a user
     */
    public function get_user_history_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
                $user_id
            )
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
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
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : get_current_user_id();

        wp_localize_script('user-history-admin', 'userHistoryData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('user_history_nonce'),
            'changeUsernameNonce' => wp_create_nonce('user_history_change_username'),
            'clearHistoryNonce' => wp_create_nonce('user_history_clear'),
            'userId'  => $user_id,
            'i18n'    => [
                'change'        => __('Change', 'user-history'),
                'cancel'        => __('Cancel', 'user-history'),
                'pleaseWait'    => __('Please wait...', 'user-history'),
                'errorGeneric'  => __('Something went wrong. Please try again.', 'user-history'),
                'confirmClear'  => __('Are you sure you want to clear all history for this user? This cannot be undone.', 'user-history'),
                'clearing'      => __('Clearing...', 'user-history'),
                'clearLog'      => __('Clear Log', 'user-history'),
            ],
        ]);
    }

    /**
     * Display history section on user edit page
     */
    public function display_history_section($user) {
        // Only show to admins
        if (!current_user_can('edit_users')) {
            return;
        }

        $history = $this->get_user_history($user->ID, 20);
        $total_count = $this->get_user_history_count($user->ID);
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

    /**
     * Display delete user button on user edit page
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

    /**
     * Render history table rows
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
     * Truncate long values for display
     */
    private function truncate_value($value, $length = 50) {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, $length) . '...';
    }

    /**
     * AJAX handler for loading more history
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

        $history = $this->get_user_history($user_id, 20, $offset);

        if (empty($history)) {
            wp_send_json_success(['html' => '', 'hasMore' => false]);
        }

        $html = $this->render_history_rows($history);
        $total = $this->get_user_history_count($user_id);
        $has_more = ($offset + 20) < $total;

        wp_send_json_success([
            'html'    => $html,
            'hasMore' => $has_more,
            'newOffset' => $offset + 20,
        ]);
    }

    /**
     * AJAX handler for clearing user history
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
        $table_name = $wpdb->prefix . self::TABLE_NAME;

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
     * AJAX handler for changing username
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

        $new_username = sanitize_user(trim($_POST['new_username']), true);
        $old_username = sanitize_user(trim($_POST['current_username']), true);

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
     * Change a user's username
     */
    private function change_username($user_id, $old_username, $new_username) {
        global $wpdb;

        // Log the change before making it
        $this->log_change(
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
         * Fires after a username has been changed
         *
         * @param int    $user_id      The user ID
         * @param string $old_username The old username
         * @param string $new_username The new username
         */
        do_action('user_history_username_changed', $user_id, $old_username, $new_username);
    }
}

// Initialize the plugin
User_History::get_instance();
