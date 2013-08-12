# Changelog

V0.3.2: 

- Fixes.

V0.3.1:

- Fix helpers registration.

V0.3:

- Added dashboard widget (this requires Bolt 1.1, so please stick with V0.2.x of the extension till it gets released).
- Because of the above change, a new column was added to the database table `<prefix>_nl_subscribers`. To upgrade, both tables need to be dropped and the extension will recreate them (backup first, then reload your subscribers manually).  

V0.2.2:

- Bugfix: Avoid extra content when downloading subscribers file.

V0.2.1:

- Bugfix: Bug #1.

V0.2:

- Added custom extra fields to subscribe form.

V0.1: 

- First functional version.
- Basic funcionality: subscribe/confirm/unsubscribe

