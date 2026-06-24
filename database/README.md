# MariaDB deployment

## 1. Import the database

Import `mariadb_import.sql` from phpMyAdmin. The file creates the tables and
replaces their contents with the data exported from `cbt_exam.db`.

Delete `mariadb_import.sql` from the public server after importing it. The
included Apache `.htaccess` blocks web access, but removing migration files
after use is still recommended.

To regenerate it after the SQLite data changes:

```bash
php scripts/export_mariadb_sql.php
```

## 2. Configure the connection

Copy the example file:

```bash
cp config/database.local.php.example config/database.local.php
```

Edit `config/database.local.php` with the MariaDB host, database name, user,
and password supplied by the hosting provider. Do not publish this file.

## 3. Required PHP extensions

- `pdo_mysql`
- `mbstring`
- `iconv`
- `zip`
- `fileinfo`

The server must allow PHP to write to `data/sessions`, `data/backups`, and
`images/exams`.

Exam subject definitions are stored in `exam_subjects`. New images uploaded
from the admin page are separated by subject under:

```text
images/exams/{level}/{exam}/{round}/subjects/{subject}/
```
