<?php
/**
 * Plugin Name: User History
 * Plugin URI: https://github.com/wpzoom/user-history
 * Description: Tracks changes made to user accounts (name, email, username, etc.) and displays a history log on the user edit page.
 * Version: 1.1.0
 * Author: WPZOOM
 * Author URI: https://www.wpzoom.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: user-history
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('USER_HISTORY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('USER_HISTORY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('USER_HISTORY_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);

/**
 * Main User History Class — Orchestrator + shared database layer.
 */
class User_History {

    /**
     * Database table name (without prefix).
     */
    const TABLE_NAME = 'user_history';

    /**
     * User meta key for lock status.
     */
    const LOCKED_META_KEY = 'user_history_locked';

    /**
     * Singleton instance.
     *
     * @var User_History
     */
    private static $instance = null;

    /**
     * Change tracker instance.
     *
     * @var User_History_Tracker
     */
    public $tracker;

    /**
     * Lock feature instance.
     *
     * @var User_History_Lock
     */
    public $lock;

    /**
     * Admin UI instance.
     *
     * @var User_History_Admin
     */
    public $admin;

    /**
     * Settings instance.
     *
     * @var User_History_Settings
     */
    public $settings;

    /**
     * Get singleton instance.
     *
     * @return User_History
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Initialize hooks
        add_action('plugins_loaded', [$this, 'init']);
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        $this->create_table();
        update_option('user_history_version', USER_HISTORY_VERSION);
    }

    /**
     * Create database table.
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
     * Initialize plugin: check for upgrades, include files, create feature instances.
     */
    public function init() {
        // Check for database updates
        $this->maybe_upgrade();

        // Include feature classes
        require_once USER_HISTORY_PLUGIN_DIR . 'includes/class-tracker.php';
        require_once USER_HISTORY_PLUGIN_DIR . 'includes/class-lock.php';
        require_once USER_HISTORY_PLUGIN_DIR . 'includes/class-admin.php';
        require_once USER_HISTORY_PLUGIN_DIR . 'includes/class-settings.php';

        // Create feature instances (each registers its own hooks in constructor)
        $this->tracker  = new User_History_Tracker($this);
        $this->lock     = new User_History_Lock($this);
        $this->admin    = new User_History_Admin($this);
        $this->settings = new User_History_Settings();
    }

    /**
     * Maybe upgrade database.
     */
    private function maybe_upgrade() {
        $current_version = get_option('user_history_version', '0');

        if (version_compare($current_version, USER_HISTORY_VERSION, '<')) {
            $this->create_table();
            $this->maybe_migrate_lock_data();
            update_option('user_history_version', USER_HISTORY_VERSION);
        }
    }

    /**
     * Migrate lock data from lock-user-account plugin (baba_user_locked meta key).
     */
    private function maybe_migrate_lock_data() {
        if (get_option('user_history_migrated_lock')) {
            return;
        }

        global $wpdb;

        // Find all users locked by the old plugin
        $locked_user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                'baba_user_locked',
                'yes'
            )
        );

        foreach ($locked_user_ids as $user_id) {
            update_user_meta((int) $user_id, self::LOCKED_META_KEY, '1');
        }

        update_option('user_history_migrated_lock', '1');
    }

    // =========================================================================
    // Shared Database Methods (used by Tracker, Lock, and Admin classes)
    // =========================================================================

    /**
     * Insert a change log entry.
     *
     * @param int    $user_id     User ID.
     * @param int    $changed_by  ID of user who made the change.
     * @param string $field_name  Database field name.
     * @param string $field_label Human-readable label.
     * @param string $old_value   Old value.
     * @param string $new_value   New value.
     * @param string $change_type Change type (update, create, lock, unlock).
     */
    public function log_change($user_id, $changed_by, $field_name, $field_label, $old_value, $new_value, $change_type = 'update') {
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
     * Get history for a user.
     *
     * @param int $user_id User ID.
     * @param int $limit   Number of entries.
     * @param int $offset  Offset.
     * @return array
     */
    public function get_user_history($user_id, $limit = 50, $offset = 0) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $results = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
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
     * Get total history count for a user.
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_user_history_count($user_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
                $user_id
            )
        );
    }
}

// Initialize the plugin
User_History::get_instance();
