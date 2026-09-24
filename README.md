# HTML in Posts

This plugin adds the possibility to use HTML in posts.

- You can restrict usage to certain usergroups, users, and forums.
- Note: Restricting by user ID will automatically override the group setting.

## Requirements

- MyBB 1.8.x; use the latest maintained 1.8 release on production forums.
- PHP 8.2 or newer.

The security regression suite is tested on PHP 8.5.9. Plugin hook compatibility was reviewed against the MyBB 1.8.40 post data handler; a live MyBB integration test is still recommended before production deployment.

## Install

1. Upload contents of the `Upload` folder to the root of your MyBB installation.
2. Go to **Admin CP -> Configuration -> Plugins** and select **Install & Activate** for "HTML in Posts".
3. Go to **Settings → HTML in Posts** and change anything you need.

## Upgrade

**From 1.9:**

- Upload the contents of the `Upload` folder, then deactivate and reactivate the plugin once to update its settings. Settings are preserved during deactivation.
- HTML permission is evaluated dynamically. Granting or removing a configured user, group, or forum immediately affects all matching existing posts.

**From 1.5/1.6 to 1.7:**

- Upload contents of the `Upload` folder to the root of your MyBB installation.

**From 1.7 to 1.8.x:**

- Upload contents of the `Upload` folder to the root of your MyBB installation.

## Deactivation and Uninstall

- Deactivating the plugin preserves its settings so it can be safely reactivated later.
- Uninstalling the plugin permanently removes its settings. Post content is not modified.

## Support

This plugin is **partially maintained**.  
Support is available only for issues reported at:  
[https://github.com/sickprodigy/mybb_html-in-posts/issues](https://github.com/sickprodigy/mybb_html-in-posts/issues)

No support will be provided via other channels.

## Forked From

This plugin is based on or forked from:  
[Original HTML in Posts MyBB Plugin](https://community.mybb.com/mods.php?action=view&pid=16)  
By: Diogo Parrinha

---

© 2025 SickProdigy
All rights reserved.
