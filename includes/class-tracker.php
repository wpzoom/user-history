<?php
/**
 * Change tracking for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles tracking of user profile and meta changes.
 */
class WPZOOM_User_History_Tracker {

    /**
     * Reference to main plugin instance.
     *
     * @var WPZOOM_User_History
     */
    private $plugin;

    /**
     * Fields to track in wp_users table.
     *
     * @var array
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
     * User meta fields to track (capabilities key is added dynamically in constructor).
     *
     * @var array
     */
    private $tracked_meta = [
        'first_name'   => 'First Name',
        'last_name'    => 'Last Name',
        'nickname'     => 'Nickname',
        'description'  => 'Biographical Info',
    ];

    /**
     * The capabilities meta key (set dynamically based on table prefix).
     *
     * @var string
     */
    private $capabilities_key = '';

    /**
     * Temporarily store old user data before update.
     *
     * @var array
     */
    private $old_user_data = [];

    /**
     * Temporarily store old user meta before update.
     *
     * @var array
     */
    private $old_user_meta = [];

    /**
     * Track which users have had role changes logged this request
     * (to prevent duplicate logging from set_user_role and updated_user_meta).
     *
     * @var array
     */
    private $role_logged = [];

    /**
     * Pending role changes to log at shutdown (to capture final state).
     * Format: [user_id => ['old_value' => string, 'changed_by' => int]]
     *
     * @var array
     */
    private $pending_role_changes = [];

    /**
     * Constructor.
     *
     * @param WPZOOM_User_History $plugin Main plugin instance.
     */
    public function __construct($plugin) {
        global $wpdb;

        $this->plugin = $plugin;

        // Set the capabilities meta key dynamically based on table prefix
        $this->capabilities_key = $wpdb->prefix . 'capabilities';
        $this->tracked_meta[$this->capabilities_key] = 'Role';

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
    }

    /**
     * Capture old user data before update.
     *
     * @param array $data     Data to be inserted/updated.
     * @param bool  $update   Whether this is an update.
     * @param int   $user_id  User ID.
     * @param array $userdata Raw userdata array.
     * @return array Unmodified $data.
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
     * Placeholder for query-based capture (if needed).
     *
     * @param WP_User_Query $query The user query.
     */
    public function capture_old_data_on_query($query) {
        // Reserved for future use
    }

    /**
     * Capture old meta value before update.
     *
     * @param int    $meta_id    Meta ID.
     * @param int    $user_id    User ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New meta value.
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
     * Log user profile changes.
     *
     * @param int     $user_id            User ID.
     * @param WP_User $old_user_data_param Old user data (from WP core).
     * @param array   $userdata           Raw userdata array.
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
                    $this->plugin->log_change($user_id, $changed_by, $field, $label, '', '', 'update');
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
                $this->plugin->log_change($user_id, $changed_by, $field, $label, $old_value, $new_value, 'update');
            }
        }

        // Clean up
        unset($this->old_user_data[$user_id]);
    }

    /**
     * Log meta field change.
     *
     * @param int    $meta_id    Meta ID.
     * @param int    $user_id    User ID.
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value New meta value.
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
            $this->plugin->log_change(
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
     * Format capabilities array to readable string.
     *
     * @param string|array $caps Capabilities.
     * @return string Comma-separated role names.
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
     * Log role change (fires when set_role() is called).
     *
     * @param int    $user_id   The user ID.
     * @param string $role      The new role.
     * @param array  $old_roles The old roles.
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

        $this->plugin->log_change(
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
     * Log pending role changes at shutdown.
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
                $this->plugin->log_change(
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
     * Log new user creation.
     *
     * @param int   $user_id  User ID.
     * @param array $userdata Userdata array.
     */
    public function log_user_creation($user_id, $userdata = []) {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        $changed_by = get_current_user_id() ?: $user_id;

        $this->plugin->log_change(
            $user_id,
            $changed_by,
            'user_created',
            'Account Created',
            '',
            $user->user_email,
            'create'
        );
    }
}
