-- Run once as a MariaDB admin (e.g. `sudo mariadb < database/create-user.sql`).
-- Development-only credentials: change the password and mirror it in .env.
CREATE USER IF NOT EXISTS 'foodwell'@'localhost' IDENTIFIED BY 'change_me';
CREATE USER IF NOT EXISTS 'foodwell'@'127.0.0.1' IDENTIFIED BY 'change_me';

-- `foodwell` is the real database; `foodwell_empty` is the isolated
-- empty-list fixture used by the Build 1 checks.
GRANT ALL PRIVILEGES ON foodwell.*       TO 'foodwell'@'localhost';
GRANT ALL PRIVILEGES ON foodwell_empty.* TO 'foodwell'@'localhost';
GRANT ALL PRIVILEGES ON foodwell.*       TO 'foodwell'@'127.0.0.1';
GRANT ALL PRIVILEGES ON foodwell_empty.* TO 'foodwell'@'127.0.0.1';
FLUSH PRIVILEGES;
