# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

User History is a WordPress plugin that tracks changes made to user accounts (email, name, username, roles, etc.) and displays a history log on the user edit admin page. This helps site admins find users even after they've changed their email or other identifying information.

## Architecture

**Single-file plugin pattern**: All PHP logic is in `user-history.php` using a singleton `User_History` class.

**Data flow for tracking changes**:
1. `wp_pre_insert_user_data` filter captures old user data before WordPress updates it
2. `update_user_meta` action captures old meta values before they change
3. `profile_update` action compares old vs new values and logs differences
4. `updated_user_meta` action logs meta field changes

**Database**: Custom table `{prefix}_user_history` stores change logs with fields for user_id, changed_by, field_name, old_value, new_value, change_type, and timestamp.

**Admin UI**: Hooks into `edit_user_profile` and `show_user_profile` to add "Account History" section at the bottom of user-edit.php and profile.php pages.

## Key Hooks Used

- `wp_pre_insert_user_data` - Capture old data before update
- `profile_update` - Log user table field changes
- `update_user_meta` / `updated_user_meta` - Track meta field changes
- `user_register` - Log account creation
- `edit_user_profile` / `show_user_profile` - Display history UI
- `wp_ajax_load_more_user_history` - AJAX pagination

## Tracked Fields

**wp_users table**: user_login, user_email, user_pass (hashed indicator only), user_nicename, display_name, user_url

**User meta**: first_name, last_name, nickname, description, wp_capabilities (role)

## Adding New Tracked Fields

To track additional fields, add them to `$tracked_fields` (for wp_users columns) or `$tracked_meta` (for user meta) arrays in the class. The key is the database field name, value is the display label.
