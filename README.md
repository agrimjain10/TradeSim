# TradeSim

TradeSim is a stock trading simulator built with PHP and MySQL.

## What it does

- Lets you view stocks and look up current data
- Lets you buy and sell shares
- Shows your portfolio and watchlist
- Keeps everything easy to use and clean

## Files included

- `index.php` — main page
- `dashboard.php` — overview of your account
- `buy.php` / `sell.php` — buy or sell stocks
- `holdings.php` — see what you own
- `watchlist.php` — track stocks you like
- `stock.php` — stock details page
- `login.php` / `logout.php` — account login flow
- `chart_data.php` — stock chart data
- `search_suggestions.php` — search helper
- `style.css` — styling for the site
- `layout.php` — page layout and header/footer

## Setup

1. Put the project inside your PHP web server folder or map it through Apache.
2. Create or import the `tradesim_app` database.
3. Import `tradesim_app_export.sql` if you want the base schema and starter data.
4. Open the project in the browser and log in.

Default login:

- Username: `agrim`
- Password: `123`

## Database configuration

By default the app tries common local setups automatically:

- `localhost:3306`
- `127.0.0.1:3306`
- `localhost:3307`
- `127.0.0.1:3307`
- XAMPP socket: `C:/xampp/mysql/mysql.sock`

You can override that with environment variables:

- `TRADESIM_DB_HOST`
- `TRADESIM_DB_PORT`
- `TRADESIM_DB_NAME`
- `TRADESIM_DB_USER`
- `TRADESIM_DB_PASSWORD`
- `TRADESIM_DB_SOCKET`

## Notes

- Live quotes depend on external market endpoints being reachable.
- The app now shows a detailed database boot error page instead of failing with a generic message.

Enjoy using TradeSim!
