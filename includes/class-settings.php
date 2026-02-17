<?php
/**
 * Settings page for User History plugin.
 *
 * @package UserHistory
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the plugin settings page (Settings > User History).
 */
class User_History_Settings {

    /**
     * Constructor — registers hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Add settings page under Settings menu.
     */
    public function add_settings_page() {
        add_options_page(
            __('User History', 'user-history'),
            __('User History', 'user-history'),
            'manage_options',
            'user-history',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {
        register_setting('user_history_settings', 'user_history_locked_message', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            'user_history_lock_section',
            __('Lock Account', 'user-history'),
            [$this, 'render_lock_section_description'],
            'user-history'
        );

        add_settings_field(
            'user_history_locked_message',
            __('Locked Account Message', 'user-history'),
            [$this, 'render_locked_message_field'],
            'user-history',
            'user_history_lock_section'
        );
    }

    /**
     * Render the lock settings section description.
     */
    public function render_lock_section_description() {
        echo '<p>' . esc_html__('Configure the message shown when a locked user tries to log in.', 'user-history') . '</p>';
    }

    /**
     * Render the locked message settings field.
     */
    public function render_locked_message_field() {
        $value = get_option('user_history_locked_message', '');
        ?>
        <input type="text" name="user_history_locked_message" class="regular-text"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr__('Your account has been locked. Please contact the administrator.', 'user-history'); ?>" />
        <p class="description">
            <?php esc_html_e('This message is displayed on the login screen when a locked user attempts to log in. Leave empty to use the default message.', 'user-history'); ?>
        </p>
        <?php
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('User History Settings', 'user-history'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('user_history_settings');
                do_settings_sections('user-history');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
