# Role Manager

A Drupal module for bulk role management and automated, cron-based scheduled role assignments.

## Features

### Role Manager (Bulk Operations)

- **Add**, **remove**, or **convert** roles in bulk.
- Target users four ways: by role, by email domain, by individual selection, or all users.
- Filter users by active/blocked status.
- Batch API processing for large user sets.
- Double-click protection on the execute button.

### Scheduled Roles

- Config entity-based schedules that run automatically via cron.
- Recurrence options: one-time, daily, weekly (specific days), monthly (specific dates).
- Date range and daily time window support.
- Three targeting modes: by role, individual users (with a full AJAX user browser), or all users.
- Real-time active/inactive status display on the schedule list.
- Dry-run preview to see what cron would do without making changes.
- Clone/duplicate schedules as a starting point for new ones.
- Quick in-place role creation from the schedule form.

### Revision History

- Every schedule save captures a snapshot of the previous state.
- Full history page per schedule with timestamps, who changed it, and a change summary.
- One-click revert to any prior version.

### Audit Log

- All role changes (from bulk operations and cron) are recorded.
- Searchable, sortable log page with pagination.

### Permissions

- Dedicated `manage role schedules` permission for fine-grained access control.

### Help & Guide

- Built-in tabbed tutorial with visual diagrams and plain-language explanations.

## Requirements

- Drupal 10.3+ or 11.x
- PHP 8.1+

## Installation

Add the repository to your project's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/DanePete/drupal-role-manager"
        }
    ]
}
```

Then install:

```bash
composer require danepete/drupal-role-manager
drush en role_converter
```

## Configuration

- **Role Manager:** Administration > People > Role Manager (`/admin/people/role-manager`)
- **Scheduled Roles:** Administration > People > Scheduled Roles (`/admin/people/scheduled-roles`)
- **Audit Log:** Administration > People > Audit Log (`/admin/people/role-manager/log`)

## License

GPL-2.0-or-later
