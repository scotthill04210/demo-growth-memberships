# Demo Growth Memberships

WordPress plugin for a multisite demo. It prefills a demo login, blocks outgoing email, blocks orders and payments, shows a demo banner, and keeps the `democlient` user on the `client` role.

The plugin is network-only (`Network: true`). Activate it from **Network Admin > Plugins**. It runs on every site in the network, including the demo site (site ID 3). It is not limited to that site ID.

Current release: **1.1.9**.

## Setup

1. Install the plugin from the `demo-growth-memberships.zip` release asset, or copy this folder to `wp-content/plugins/demo-growth-memberships`.
2. Network-activate **Demo Growth Memberships**.
3. Turn demo mode on for each site that should behave as a demo. From **Network Admin > Plugins**, open **Settings** on this plugin to edit every site. On an individual site, use **Settings > Demo Growth Memberships**.

Demo mode starts off. Enabling it on a site prefills the `democlient` login, sends signed-out visitors to My Account, blocks outgoing email, blocks orders and payments, shows the demo banner along the bottom of every page, and keeps `democlient` on the `client` role. Other sites are unchanged.

The demo password is saved for that site only. Leave the password field blank to keep the saved value. The login page includes the password in its source so the form can be prefilled, so do not reuse a password that matters anywhere else.

The WordPress user `democlient` and the `client` role need to exist already. The plugin does not create them. The saved password should match that user’s password.

Turning demo mode off for a site restores email, orders, and the rest of the demo behavior there. GitHub updates stay available from **Network Admin > Plugins**.

## Snapshot and nightly reset

The demo is a sandbox. People logged in as `democlient` can add members, edit records, and submit forms. A saved database snapshot is restored every night at 3:00 AM America/Chicago so the day’s changes do not remain.

Each site has its own snapshot under `wp-content/dgm-snapshots/<site-id>/`. The folder is not inside uploads. Open **Settings > Demo Growth Memberships** on that site (`manage_options`) to save a snapshot, restore it, turn on the nightly reset, and set failure alert emails. **Restore database now** runs that restore immediately and does not wait for 3:00 AM. Nightly reset can stay off. Nightly reset stays off until a snapshot exists. Sandbox limits start on and apply only while demo mode is on. Administrator and super admin accounts keep their normal dashboard, menus, and capabilities. The democlient account does not.

The snapshot includes only that site’s tables, plus the network `users` and `usermeta` rows for people who have a role on that site. Transients, sessions, and this plugin’s `dgm_` options are left out, and a restore writes those options back so it cannot change the plugin’s own settings, schedule, or reset log.

### First snapshot

1. Turn on demo mode for the site.
2. Open **Settings > Demo Growth Memberships** on that site.
3. Click **Save snapshot**. The page shows the snapshot date, size, and table count.
4. Turn on **Nightly reset** and save. Add failure alert emails, one per line or separated by commas (up to 10).
5. Click **Send test alert** and confirm each saved address.

`DGM_ALERT_EMAIL` in `wp-config.php` is optional. One or more comma-separated addresses are always added to the saved list. If the list is still empty, the site admin email is used. Optional SMTP settings, used only for these alerts, are `DGM_SMTP_HOST`, `DGM_SMTP_PORT`, `DGM_SMTP_USER`, and `DGM_SMTP_PASS`.

### Test a restore

On a test site, with demo mode on and a snapshot saved:

```bash
wp dgm restore --url=https://demo.example.com --dry-run
wp dgm restore --url=https://demo.example.com
```

`--dry-run` checks demo mode, the snapshot, the checksum, the table names, and free disk space, then exits without changing data. A real restore returns a non-zero status on failure.

To test the failure path, copy `current.json`, the snapshot file, and its `.sha256` file out of `wp-content/dgm-snapshots/<site-id>/`. Change one byte of the snapshot copy. `wp dgm restore` then stops on the checksum and does not change tables. To exercise rollback, set the `.sha256` file and the `sha256` value in `current.json` to the new SHA-256 of the damaged file, then run the restore again. The load fails, the site is put back from the failsafe copy, and the plugin retries once after 5 minutes before emailing the alert list. Put the original files back afterward.

### Cron

On a server where you can edit crontab, this is the primary schedule. `CRON_TZ` keeps 3:00 AM on America/Chicago through daylight saving time:

```cron
CRON_TZ=America/Chicago
0 3 * * * cd /path/to/wordpress && wp dgm restore --url=https://demo.example.com --scheduled
0 9 * * * cd /path/to/wordpress && wp dgm watchdog --url=https://demo.example.com
```

`--scheduled` does nothing when nightly reset or demo mode is off, and exits 0. Without `--scheduled`, `wp dgm restore` always attempts a restore.

WPMU DEV managed hosting does not let you edit crontab. Its servers run WP-CLI cron about every 5 minutes ([System Cron & Custom Cron Jobs](https://wpmudev.com/docs/using-wordpress/system-cron-custom-cron-jobs/)). While nightly reset is on, this plugin schedules the restore for 3:00 AM America/Chicago and the watchdog for 9:00 AM America/Chicago, so that existing cron runs them within about 5 minutes. An hourly fallback restores the site only if no restore has succeeded in the last 26 hours. Do not add a separate Hub cron URL.

### Nginx

Apache gets a deny-all `.htaccess` in the snapshot folder. Nginx ignores that file. Deny the folder in the server block:

```nginx
location ^~ /wp-content/dgm-snapshots/ {
    deny all;
}
```

### Uninstall

Deleting the plugin removes its `dgm_` options and scheduled events. It leaves `wp-content/dgm-snapshots/` in place. Remove that directory yourself when you no longer need the snapshots:

```bash
rm -rf /path/to/wordpress/wp-content/dgm-snapshots
```

## Updates

Updates use [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT), bundled in `vendor/plugin-update-checker/`. The repository is public, so no token is required.

The checker uses the `main` branch as the fallback. It prefers the latest GitHub release and downloads the `demo-growth-memberships.zip` release asset, not GitHub’s generated source archive. On a network-activated install, the update appears in **Network Admin > Plugins**.

The version in the plugin header is the release version. Tag `v1.0.1` means the header says `Version: 1.0.1`.

## Release steps

1. Bump `Version:` in `demo-growth-memberships.php` so it matches the tag you are about to create, without the leading `v`.
2. Commit the change.
3. Tag that commit: `git tag vX.Y.Z` (for example `v1.0.1`).
4. Before you publish, build the zip locally with the same commands the workflow uses and verify the structure (see below).
5. Push the commit and the tag, then publish a GitHub Release for that tag. Do not mark it as a pre-release.
6. Wait for the **Release zip** workflow to attach `demo-growth-memberships.zip` to the release. Download that asset and check the structure again before you rely on it for updates.

### Verify the zip before publishing

From the repository root:

```bash
mkdir -p build/demo-growth-memberships
rsync -a --exclude='.git' --exclude='.github' --exclude='.cursor' --exclude='build' --exclude='.gitignore' ./ build/demo-growth-memberships/
cd build && zip -r ../demo-growth-memberships.zip demo-growth-memberships
```

`unzip -l demo-growth-memberships.zip` should show exactly one top-level folder:

```text
demo-growth-memberships/demo-growth-memberships.php
```

The main file sits directly inside `demo-growth-memberships/`. There is no extra folder above it, and the archive does not contain `.git`, `.github`, `.cursor`, `node_modules`, `build`, or `.gitignore`.

## Changelog

### 1.1.9

- Show the demo notice at the bottom of every front-end page.
- Send signed-out visitors to My Account, show the demo username and password, and keep a successful login from returning to the home page.

### 1.1.8

- Keep the demo notice on the page. It no longer has a Dismiss control, and a previous dismissal does not hide it.

### 1.1.7

- Show the demo login on the site home page for signed-out visitors even when the theme does not mark that page as the front page.

### 1.1.6

- Show a demo login on the home page for signed-out visitors. The username and password are filled in, the security plugin’s reCAPTCHA still runs, and a successful login returns to the home page.
- Pin the demo notice to the bottom of the front end and center its text.

### 1.1.5

- Save a snapshot from the settings screen without reloading. A progress bar shows tables finished, turns green, and the snapshot date updates when the save completes.

### 1.1.4

- Save a snapshot in short steps so a large site finishes, and show that new snapshot when the last step completes. Until then, the page keeps the previous snapshot date and shows how many tables are done.

### 1.1.3

- Apply sandbox limits only to the demo client. An Administrator can open every wp-admin screen, including Plugins, Themes, Users, and Settings.

### 1.1.2

- Keep the WordPress dashboard available to administrators and super admins, including while a restore is running.
- Fill the demo username and password only when **Demo login** is clicked, so an administrator can sign in with their own account.

### 1.1.1

- Leave Administrator and super admin accounts out of the sandbox, so turning on demo mode does not change their dashboard, menus, or capabilities.
- A restore does not overwrite or remove those accounts. The `democlient` account stays sandboxed.

### 1.1.0

- Save a per-site database snapshot and restore it on demand or at 3:00 AM America/Chicago.
- Keep a failsafe copy, roll back if a restore fails, and email the saved alert list.
- Sandbox mode lets the demo client edit content while blocking plugins, themes, user changes, and other settings.

### 1.0.1

- Save demo mode and the demo password on each site. Network Admin can edit every site from the plugin’s Settings screen. An individual site uses **Settings > Demo Growth Memberships**.
- Demo mode starts off, so the rest of the network keeps sending email and processing orders.
- Prefill the `democlient` login, block outgoing email, block orders and payments, show a dismissible demo banner, and keep `democlient` on the `client` role.
- Check for updates from GitHub release assets in **Network Admin > Plugins**.
