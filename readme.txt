=== User History ===
Contributors: wpzoom
Tags: user history, user log, audit log, change username, user tracking
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track changes made to user accounts and allow admins to change usernames.

== Description ==

User History tracks all changes made to user profiles and displays a complete history log on the user edit page. This is especially useful when users change their email or username, making it easy to find them by their previous details.

**Features:**

* **Track Profile Changes** - Automatically logs changes to username, email, display name, first/last name, nickname, website, bio, and role
* **Password Change Logging** - Records when passwords are changed (without storing any password data)
* **Change Username** - Allows admins to change usernames directly from the user edit page (WordPress normally doesn't allow this)
* **Search by Previous Values** - Find users on the All Users page by their old email or username
* **See Who Made Changes** - Each log entry shows whether the user changed their own profile or if an admin made the change
* **Clear History** - Admins can clear the history log for any user
* **Multisite Compatible** - Works with WordPress multisite installations
* **Members Plugin Compatible** - Works with the Members plugin for multiple role assignments

**Use Cases:**

* Find customers who changed their email after making a purchase
* Track when and who changed user roles
* Audit user profile modifications for security
* Allow username changes without database access

== Installation ==

1. Upload the `user-history` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Visit any user's edit page to see their Account History section

== Frequently Asked Questions ==

= Where can I see the user history? =

Go to Users > All Users, click on any user to edit their profile, and scroll down to the "Account History" section.

= How do I change a username? =

On the user edit page, click the "Change" link next to the username field. Enter the new username and click "Change" to save.

= Does this plugin store passwords? =

No. The plugin only logs that a password was changed, along with the date and who changed it. No password values (hashed or otherwise) are ever stored.

= Can I search for users by their old email? =

Yes! On the All Users page, use the search box to search for any previous email, username, or name. Users who previously had matching values will appear in the results.

= What user fields are tracked? =

* Username (user_login)
* Email (user_email)
* Password (change event only)
* Display Name
* Nicename
* Website URL
* First Name
* Last Name
* Nickname
* Biographical Info
* Role (including multiple roles with Members plugin)

= Is this plugin multisite compatible? =

Yes. The plugin works on multisite installations and properly handles super admin username changes.

= Does it work with the Members plugin? =

Yes. The plugin correctly tracks role changes when using the Members plugin, which allows assigning multiple roles to users.

= How do I clear a user's history? =

On the user edit page, scroll down to the Account History section and click the "Clear Log" button.

== Screenshots ==

1. Account History section on the user edit page
2. Change username feature
3. Search results showing users found by previous email/username

== Changelog ==

= 1.0.2 =
* Added Clear Log button to delete history for a user
* Improved role change tracking for Members plugin compatibility
* Fixed false positive password change logging when saving without changes
* Fixed duplicate role change entries

= 1.0.1 =
* Added search functionality to find users by previous email/username
* Added database index for faster history searches
* Improved password change logging (no values stored)
* Added table existence check to prevent errors

= 1.0.0 =
* Initial release
* Track user profile changes
* Change username feature
* Account History display on user edit page

== Upgrade Notice ==

= 1.0.2 =
Adds Clear Log button, fixes role tracking with Members plugin, and fixes false positive password logging.

= 1.0.1 =
Adds ability to search users by their previous email or username on the All Users page.
