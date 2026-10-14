# Demo Growth Memberships

WordPress plugin for a multisite demo. It prefills a demo login, blocks outgoing email, blocks orders and payments, shows a dismissible demo banner, and keeps the `democlient` user on the `client` role.

The plugin is network-only (`Network: true`). Activate it from **Network Admin > Plugins**. It runs on every site in the network, including the demo site (site ID 3). It is not limited to that site ID.

Current release: **1.0.1**.

## Setup

1. Install the plugin from the `demo-growth-memberships.zip` release asset, or copy this folder to `wp-content/plugins/demo-growth-memberships`.
2. Network-activate **Demo Growth Memberships**.
3. Turn demo mode on for each site that should behave as a demo. From **Network Admin > Plugins**, open **Settings** on this plugin to edit every site. On an individual site, use **Settings > Demo Growth Memberships**.

Demo mode starts off. Enabling it on a site prefills the `democlient` login, blocks outgoing email, blocks orders and payments, shows the demo banner, and keeps `democlient` on the `client` role. Other sites are unchanged.

The demo password is saved for that site only. Leave the password field blank to keep the saved value. The login page includes the password in its source so the form can be prefilled, so do not reuse a password that matters anywhere else.

The WordPress user `democlient` and the `client` role need to exist already. The plugin does not create them. The saved password should match that user’s password.

Turning demo mode off for a site restores email, orders, and the rest of the demo behavior there. GitHub updates stay available from **Network Admin > Plugins**.

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

### 1.0.1

- Save demo mode and the demo password on each site. Network Admin can edit every site from the plugin’s Settings screen. An individual site uses **Settings > Demo Growth Memberships**.
- Demo mode starts off, so the rest of the network keeps sending email and processing orders.
- Prefill the `democlient` login, block outgoing email, block orders and payments, show a dismissible demo banner, and keep `democlient` on the `client` role.
- Check for updates from GitHub release assets in **Network Admin > Plugins**.
