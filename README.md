# Common Ground classifieds

A responsive PHP and MySQL neighborhood classifieds site. Categories, areas, locations, and classifieds are loaded from MySQL; the catalog is server-rendered in batches of 20 and requests the next batch as visitors scroll. Registration, login, publishing, and OAuth require MySQL.

## Requirements

- PHP 8.1 or newer with PDO and `pdo_mysql`
- MySQL 8.0 or newer
- A web server configured to route requests to `index.php`

## Local setup

1. Create the schema by importing `database/schema.sql` into MySQL. For example: `mysql -u root -p < database/schema.sql`.
2. Set the database connection environment variables before starting PHP: `DB_HOST`, `DB_NAME=common_ground`, `DB_USER`, and `DB_PASSWORD`.
3. Create the first administrator from PowerShell:

   ```powershell
   $env:DB_HOST = '127.0.0.1'
   $env:DB_NAME = 'common_ground'
   $env:DB_USER = 'root'
   $env:DB_PASSWORD = 'your-local-database-password'
   $env:ADMIN_EMAIL = 'you@example.com'
   $env:ADMIN_PASSWORD = 'choose-a-unique-password-of-12-or-more-characters'
   php database/seed_admin.php
   ```

4. Start PHP's local server from this directory with `php -S 127.0.0.1:8000 router.php` and open `http://127.0.0.1:8000`.

Administrators create menu categories, areas, and locations from **Market desk**. Create an area first, then add its locations. The marketplace defaults to the earliest-created location; visitors can search and select another area/location from the header menu. Classifieds created by administrators or members are assigned to a location, and the marketplace feed is scoped to that location. Registered members can publish under administrator-created categories and review their own posts. Administrators can also publish classifieds, manage categories and locations, and review all posts. Public pages show only records stored in MySQL. Passwords are stored with PHP's password hashing API. Forms use session-bound CSRF tokens, SQL uses prepared statements, and output is HTML-escaped.

## Social sign-in

Google, Facebook, and LinkedIn buttons use OAuth 2.0 authorization-code sign-in with state verification. Register the callback URL `http://localhost:8000/?action=oauth_callback&provider=PROVIDER` with each provider and set `OAUTH_REDIRECT_URI` to the corresponding provider-specific callback when deploying. Set each provider's `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`, `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET`, and `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` environment variables. Use HTTPS for deployed callback URLs. Social sign-in requires the provider to return a verified email address.

## Deployment notes

Set environment variables in the PHP-FPM/Apache service configuration, not in a publicly served file. Route the web root to this project's directory and send application requests to `index.php`; do not expose database credentials. Use HTTPS in production and configure each provider's OAuth callback URL to match the site. The sample catalog images are served by Unsplash; replace these image URLs with your own assets as appropriate.

## How to Run this APP?

To open the site in your browser on Windows:
Open a VS Code terminal in classifis.
Start the PHP server:
php -S 127.0.0.1:8000 router.php
Leave the terminal running and visit http://127.0.0.1:8000.

## How to deployee?

Deploy on GoDaddy cPanel

1. Check your hosting
Confirm your plan includes cPanel hosting, PHP 8.1 or newer, and MySQL. In cPanel, use MultiPHP Manager to select the PHP version for your domain. Make sure pdo_mysql is enabled; ask GoDaddy support how to enable it if it’s not listed. This app cannot connect to MySQL without that PHP extension.

2. Upload the app
In cPanel, open File Manager and find your domain’s document root. It’s usually public_html for the primary domain, but an addon domain may use a different folder. Upload the project files and folders there, preserving the structure:

public_html/
├── index.php
├── config.php
├── router.php
├── assets/
└── database/

Use Upload to upload a ZIP, then Extract it in the document root. Make sure index.php is directly in that folder, not buried inside an extra project folder. On cPanel, Apache serves the PHP pages and assets directly; router.php is only needed for the local PHP development server.

3. Create the MySQL database and user
In cPanel, open MySQL Database Wizard (or MySQL Databases):

Create a database. cPanel will usually add your account prefix to its name.

Create a database user with a strong, unique password. Its name will also usually have your account prefix.

Add the user to the database and grant All Privileges.

Note the full database name, full username, and database host. The host is often localhost; use the value shown by cPanel or provided by GoDaddy.

4. Import the app’s tables
Open phpMyAdmin, select the database you just created, and choose Import. Before importing schema.sql, remove its first two lines:

--
CREATE DATABASE IF NOT EXISTS common_ground CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE common_ground;
--

cPanel creates a prefixed database for you, so the import should create the tables inside the database you selected rather than trying to create or switch to common_ground. Import the remaining SQL; it creates the account, category, and classified tables, along with starter categories.

5. Set the database connection
The app reads DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD from PHP’s environment. You can try adding these to public_html/.htaccess:

--
SetEnv DB_HOST localhost
SetEnv DB_NAME yourcpanelprefix_common_ground
SetEnv DB_USER yourcpanelprefix_dbuser
SetEnv DB_PASSWORD your-database-password
--

Replace the example values with the exact names and host shown in cPanel. Keep the file private; do not publish your database credentials or send them to anyone. Some GoDaddy PHP configurations may not pass .htaccess variables through to PHP’s getenv(). After setting these, check that the site no longer shows its “Preview mode” banner. If it still does, ask GoDaddy how to set environment variables for your PHP handler, or I can adjust the app to read a private settings file stored outside public_html.

6. Create the administrator account
Use cPanel Terminal or SSH, if your plan provides it. From the project folder, set the same database values and run the seeder:

--
cd ~/public_html
DB_HOST=localhost \
DB_NAME=yourcpanelprefix_common_ground \
DB_USER=yourcpanelprefix_dbuser \
DB_PASSWORD='your-database-password' \
ADMIN_EMAIL='you@example.com' \
ADMIN_PASSWORD='your-unique-password-of-at-least-12-characters' \
php database/seed_admin.php
--
Use your real cPanel-prefixed values and choose a unique admin password. The seeder runs from the command line, not from a browser. Once it reports that the admin account was created, log in with that email and password; the admin dashboard lets you add categories and publish classifieds. If Terminal or SSH isn’t available, ask GoDaddy whether it’s included in your plan before attempting to run the seeder.

7. Enable HTTPS and test
Enable SSL for the domain in GoDaddy/cPanel, then visit https://yourdomain.com. Test browsing, category filtering, search, and loading more ads while scrolling. Then test admin login and add a sample category and listing. New registrations and listings should appear in the MySQL tables via phpMyAdmin.

Social login is optional. Google, Facebook, and LinkedIn require OAuth app credentials registered with each provider. The current app also needs a provider-specific HTTPS callback URI configured for each provider before those buttons will work; the local development callback is not suitable for production.

For an existing installation created before areas and locations were added, select its database and run the migration once: `mysql -u root -p common_ground < database/migrate_locations.sql`. The migration preserves existing free-text listing locations under an **Existing listings** area. Fresh installations should import `database/schema.sql` instead and must not run the migration.

The full setup notes are i