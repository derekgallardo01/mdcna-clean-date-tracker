# MDCNA Clean Date Tracker — Plugin Documentation

**Version:** 1.0.0  
**For:** Miami-Dade Convention of Narcotics Anonymous (MDCNA 2026)  
**Form:** Fluent Forms ID #13

---

## Installation

1. Upload the `mdcna-clean-date-tracker/` folder to `/wp-content/plugins/`
2. Activate in **Plugins → Installed Plugins**
3. The plugin automatically creates the `wp_mdcna_clean_dates` database table on activation

---

## How It Works

Every time someone submits **Fluent Form #13**, the plugin:

1. **Captures** first name, last name, email, phone, clean date, quantity, donation, merch selections
2. **Parses** the `datetime` field (supports `m/d/y`, `m/d/Y`, `Y-m-d`, and more)
3. **Stores** everything in `wp_mdcna_clean_dates`
4. **Matches** the registrant to a WordPress user by email (if they have a WP account)
5. **Emails** the admin a new registration notification
6. **Emails** the registrant a confirmation with their clean time
7. **Logs** all activity to `/wp-content/mdcna-cdt.log`

---

## Shortcodes

### `[mdcna_clean_time]`
Shows the **currently logged-in user's** clean time as a styled card.

```
[mdcna_clean_time show_date="yes"]
[mdcna_clean_time show_date="no"]
```

**Output:** A gradient card showing days, formatted time, and clean date.

---

### `[mdcna_total_time]`
Shows the **combined total clean time** of all registered attendees.

```
[mdcna_total_time]
```

**Output:** Large number display — total days + combined years + registrant count.

---

### `[mdcna_leaderboard]`
Shows a ranked list of all attendees by longest clean time.

```
[mdcna_leaderboard limit="20" anonymous="no"]
[mdcna_leaderboard limit="50" anonymous="yes"]
```

| Attribute   | Default | Description                            |
|-------------|---------|----------------------------------------|
| `limit`     | `20`    | Max registrants to show                |
| `anonymous` | `no`    | `yes` = show initials only (A.B.)      |

---

## Admin Pages

Navigate to **MDCNA Clean Dates** in the WordPress admin sidebar.

### All Registrations
- Full table of every registrant
- Columns: Name, Email, Phone, Clean Date, Time Clean, Days, Qty, Donation, Merch, Registered
- Live search by name or email
- Pagination (25 per page)
- **Export CSV** button (top right)

### Stats & Reports
- Total registrants, total days clean, average days, combined years
- Total donations + registration revenue
- Milestone breakdown (1yr+, 5yr+, 10yr+, newcomers ≤90 days)
- Breakdown by clean year

### View Log
- Last 200 log entries in reverse order
- Shows full path to log file

---

## Notifications

| Event                    | Recipients         |
|--------------------------|--------------------|
| New registration         | Admin + Registrant |
| Clean date parse error   | Admin only         |
| DB insert failure        | Admin only         |
| Annual/milestone achieved | Registrant        |

### Milestones (checked daily via WP Cron)
30 days, 60, 90, 180, 1 year, 2 years, 3 years, 5 years, 10 years

---

## Database Table: `wp_mdcna_clean_dates`

| Column        | Type         | Description                        |
|---------------|--------------|------------------------------------|
| `id`          | BIGINT       | Auto-increment primary key         |
| `entry_id`    | BIGINT       | Fluent Forms entry ID              |
| `user_id`     | BIGINT       | WP user ID (0 if no WP account)    |
| `first_name`  | VARCHAR(100) |                                    |
| `last_name`   | VARCHAR(100) |                                    |
| `email`       | VARCHAR(200) | Indexed                            |
| `phone`       | VARCHAR(50)  |                                    |
| `clean_date`  | DATE         | Parsed & normalized to `Y-m-d`     |
| `qty`         | SMALLINT     | Number of registrations purchased  |
| `donation`    | DECIMAL      | Optional donation amount           |
| `merch_json`  | TEXT         | JSON of merch items selected       |
| `raw_data`    | LONGTEXT     | Full raw form submission JSON      |
| `ip_address`  | VARCHAR(45)  |                                    |
| `status`      | ENUM         | `active` or `deleted`              |
| `created_at`  | DATETIME     | Auto-set on insert                 |
| `updated_at`  | DATETIME     | Auto-updated on change             |

---

## Error Logging

All events are written to `/wp-content/mdcna-cdt.log`:

```
[2026-07-01 14:22:31] [INFO]  FF Submission received. entry_id=47
[2026-07-01 14:22:31] [INFO]  Record inserted id=23 for john@example.com clean_date=2019-03-15
[2026-07-01 14:22:32] [ERROR] Could not parse clean_date 'not-a-date' for entry 48
```

Errors also write to the WordPress debug log if `WP_DEBUG_LOG` is enabled.

---

## Clean Date Field Mapping

The plugin reads the `datetime` field (Name Attribute from Fluent Forms).  
Supported input formats:
- `04/28/18` (m/d/y)
- `04/28/2018` (m/d/Y)
- `2018-04-28` (Y-m-d)
- `28/04/2018` (d/m/Y)
- Most other common date strings via `strtotime()`

> **Important:** Dates in the future are rejected and logged as errors.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Fluent Forms plugin (free or pro)
- WP Cron enabled (for milestone emails)

---

## Changelog

### 1.0.0
- Initial release
- Fluent Forms form #13 integration
- Clean date capture, storage, and aggregation
- Admin reporting with search, pagination, CSV export
- Three frontend shortcodes
- Email notifications (admin + registrant)
- Daily milestone cron job
- Full error logging
