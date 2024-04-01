# Release Notes for Craft Cloud  ⛅️

## 2024-04-01

- Greatly improved the DNS settings and management UX. 
- MySQL database users now have `CREATE_VIEW` and `SHOW_VIEW` permissions by default.
- You no longer have to do a deployment on a fresh project before you can back up your database.

## 2024-03-31

- MySQL database backups now pass in the `--single-transaction` flag to help prevent table locking during a backup. 