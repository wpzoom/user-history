# WPZOOM User History

A WordPress plugin that tracks changes made to user accounts and allows admins to change usernames.

<img width="1547" height="1071" alt="image" src="https://github.com/user-attachments/assets/1ba77413-4e63-416b-8ca4-242e499acadb" />


## Features

- **Track Profile Changes** - Automatically logs changes to username, email, display name, first/last name, nickname, website, bio, and role
- **Password Change Logging** - Records when passwords are changed (without storing any password data)
- **Change Username** - Allows admins to change usernames directly from the user edit page (WordPress normally doesn't allow this)
- **Search by Previous Values** - Find users on the All Users page by their old email or username
- **See Who Made Changes** - Each log entry shows whether the user changed their own profile or if an admin made the change
- **Clear History** - Admins can clear the history log for any user
- **Multisite Compatible** - Works with WordPress multisite installations
- **Members Plugin Compatible** - Works with the Members plugin for multiple role assignments

## Requirements

- WordPress 5.0+
- PHP 7.4+

## Installation

1. Download the plugin and upload to `/wp-content/plugins/user-history/`
2. Activate through the 'Plugins' menu in WordPress
3. Visit any user's edit page to see their Account History section

## Usage

### Viewing History

Go to **Users > All Users**, click on any user to edit their profile, and scroll down to the "Account History" section.

### Changing a Username

On the user edit page, click the "Change" link next to the username field. Enter the new username and click "Change" to save.

### Searching by Previous Values

On the All Users page, use the search box to search for any previous email, username, or name. Users who previously had matching values will appear in the results.

### Clearing History

On the user edit page, scroll down to the Account History section and click the "Clear Log" button.

## Tracked Fields

| Field | Description |
|-------|-------------|
| Username | user_login |
| Email | user_email |
| Password | Change event only (no values stored) |
| Display Name | display_name |
| Nicename | user_nicename |
| Website URL | user_url |
| First Name | first_name meta |
| Last Name | last_name meta |
| Nickname | nickname meta |
| Biographical Info | description meta |
| Role | Capabilities (supports multiple roles) |

## Hooks

### Actions

```php
// Fires after a username has been changed
do_action('user_history_username_changed', $user_id, $old_username, $new_username);
```

## Screenshots

### Account History Section
The history log appears at the bottom of the user edit page, showing all tracked changes with timestamps and who made each change.

### Change Username
Click "Change" next to the username field to reveal an inline form for changing the username.

## Changelog

### 1.0.3
- Added Delete User button on user edit page for quick access
- Added Requires at least and Requires PHP headers to plugin file
- Code improvements for WordPress.org plugin directory compliance

### 1.0.2
- Added Clear Log button to delete history for a user
- Improved role change tracking for Members plugin compatibility
- Fixed false positive password change logging when saving without changes
- Fixed duplicate role change entries

### 1.0.1
- Added search functionality to find users by previous email/username
- Added database index for faster history searches
- Improved password change logging (no values stored)
- Added table existence check to prevent errors

### 1.0.0
- Initial release
- Track user profile changes
- Change username feature
- Account History display on user edit page

## License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## Credits

Developed by [WPZOOM](https://www.wpzoom.com)
