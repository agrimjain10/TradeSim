<?php
session_start();

$conn = mysqli_connect("localhost", "root", "", "tradesim_app");
$UPSTOX_TOKEN = getenv("UPSTOX_ACCESS_TOKEN");

if (!$conn) {
    die("Database connection failed");
}

initialize_database($conn);

function redirect_to($page)
{
    header("Location: " . $page);
    exit;
}

function require_login()
{
    if (!isset($_SESSION["user_id"])) {
        redirect_to("login.php");
    }
}

function current_user($conn)
{
    $id = (int) $_SESSION["user_id"];
    $result = mysqli_query($conn, "SELECT * FROM users WHERE id = $id");
    return mysqli_fetch_assoc($result);
}

function set_flash($type, $message)
{
    $_SESSION["flash_type"] = $type;
    $_SESSION["flash_message"] = $message;
}

function get_flash()
{
    if (!isset($_SESSION["flash_message"])) {
        return null;
    }

    $flash = [
        "type" => $_SESSION["flash_type"],
        "message" => $_SESSION["flash_message"]
    ];

    unset($_SESSION["flash_type"]);
    unset($_SESSION["flash_message"]);

    return $flash;
}

function escape($text)
{
    return htmlspecialchars($text, ENT_QUOTES, "UTF-8");
}

function api_get_json($url, $headers = [], $cookieFile = null)
{
    if (!function_exists("curl_init")) {
        return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 18);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if (count($headers) > 0) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return null;
    }

    return $json;
}

function upstox_api_get($url)
{
    global $UPSTOX_TOKEN;

    if (!$UPSTOX_TOKEN) {
        return null;
    }

    $headers = [
        "Accept: application/json",
        "Content-Type: application/json",
        "Authorization: Bearer " . $UPSTOX_TOKEN
    ];

    return api_get_json($url, $headers);
}

function nse_cookie_file()
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "tradesim_nse_cookie.txt";
}

function nse_headers($referer = "https://www.nseindia.com/")
{
    return [
        "Accept: application/json,text/plain,*/*",
        "Accept-Language: en-US,en;q=0.9",
        "Connection: keep-alive",
        "Referer: " . $referer,
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
    ];
}

function nse_prime_cookie()
{
    $cookieFile = nse_cookie_file();
    if (!file_exists($cookieFile)) {
        touch($cookieFile);
    }

    $ageSeconds = time() - filemtime($cookieFile);
    if ($ageSeconds < 600) {
        return;
    }

    api_get_json("https://www.nseindia.com", nse_headers("https://www.nseindia.com/"), $cookieFile);
}

function nse_api_get($path, $query = [], $referer = "https://www.nseindia.com/")
{
    nse_prime_cookie();

    $cookieFile = nse_cookie_file();
    $url = "https://www.nseindia.com" . $path;
    if (count($query) > 0) {
        $url .= "?" . http_build_query($query);
    }

    $json = api_get_json($url, nse_headers($referer), $cookieFile);
    if ($json) {
        return $json;
    }

    nse_prime_cookie();
    return api_get_json($url, nse_headers($referer), $cookieFile);
}

function normalize_symbol($value)
{
    if (!$value) {
        return "";
    }

    $symbol = strtoupper(trim($value));
    $symbol = str_replace(["NSE:", "BSE:", ".NS", ".BO"], "", $symbol);

    if ($symbol === "^NSEI") {
        return "NIFTY 50";
    }
    if ($symbol === "^NSEBANK") {
        return "NIFTY BANK";
    }
    if ($symbol === "^BSESN" || $symbol === "SENSEX") {
        return "SENSEX";
    }

    return $symbol;
}

function session_cache_get($key, $ttlSeconds)
{
    if (!isset($_SESSION["app_cache"]) || !is_array($_SESSION["app_cache"])) {
        $_SESSION["app_cache"] = [];
    }

    if (!isset($_SESSION["app_cache"][$key])) {
        return null;
    }

    $entry = $_SESSION["app_cache"][$key];
    if (
        !is_array($entry) ||
        !isset($entry["stored_at"], $entry["payload"]) ||
        (time() - (int) $entry["stored_at"]) > (int) $ttlSeconds
    ) {
        unset($_SESSION["app_cache"][$key]);
        return null;
    }

    return $entry["payload"];
}

function session_cache_put($key, $payload)
{
    if (!isset($_SESSION["app_cache"]) || !is_array($_SESSION["app_cache"])) {
        $_SESSION["app_cache"] = [];
    }

    $_SESSION["app_cache"][$key] = [
        "stored_at" => time(),
        "payload" => $payload
    ];
}

function nse_search_stocks($query)
{
    $json = nse_api_get("/api/search/autocomplete", ["q" => $query], "https://www.nseindia.com/get-quotes/equity?symbol=TCS");
    if (!$json || !isset($json["symbols"]) || !is_array($json["symbols"])) {
        return [];
    }

    $list = [];
    foreach ($json["symbols"] as $item) {
        if (!isset($item["result_type"]) || $item["result_type"] !== "symbol") {
            continue;
        }

        if (!isset($item["result_sub_type"]) || $item["result_sub_type"] !== "equity") {
            continue;
        }

        if (!isset($item["symbol"])) {
            continue;
        }

        $symbol = strtoupper($item["symbol"]);
        $name = isset($item["symbol_info"]) ? $item["symbol_info"] : $symbol;

        $list[] = [
            "instrument_key" => $symbol,
            "trading_symbol" => $symbol,
            "exchange" => "NSE",
            "name" => $name
        ];

        if (count($list) >= 12) {
            break;
        }
    }

    return $list;
}

function nse_get_indices($forceRefresh = false)
{
    if (!$forceRefresh) {
        $cached = session_cache_get("nse_indices", 20);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $json = nse_api_get("/api/allIndices", [], "https://www.nseindia.com/market-data/live-equity-market");
    if (!$json || !isset($json["data"]) || !is_array($json["data"])) {
        return [];
    }

    $indexMap = [];
    foreach ($json["data"] as $row) {
        if (!isset($row["index"])) {
            continue;
        }
        $name = strtoupper(trim($row["index"]));
        $indexMap[$name] = $row;
    }

    session_cache_put("nse_indices", $indexMap);
    return $indexMap;
}

function nse_get_index_stocks($indexName = "NIFTY 50", $forceRefresh = false)
{
    $cacheKey = "nse_index_stocks_" . strtoupper(trim($indexName));
    if (!$forceRefresh) {
        $cached = session_cache_get($cacheKey, 20);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $json = nse_api_get(
        "/api/equity-stockIndices",
        ["index" => $indexName],
        "https://www.nseindia.com/market-data/live-equity-market"
    );

    if (!$json || !isset($json["data"]) || !is_array($json["data"])) {
        return [];
    }

    $rows = [];
    foreach ($json["data"] as $row) {
        if (!isset($row["symbol"])) {
            continue;
        }
        $symbol = strtoupper(trim($row["symbol"]));
        if ($symbol === strtoupper($indexName)) {
            continue;
        }
        $rows[] = $row;
    }

    session_cache_put($cacheKey, $rows);
    return $rows;
}

function nse_get_equity_snapshot_map($indexName)
{
    static $snapshotCache = [];

    $cacheKey = strtoupper(trim($indexName));
    if (isset($snapshotCache[$cacheKey])) {
        return $snapshotCache[$cacheKey];
    }

    $rows = nse_get_index_stocks($indexName);
    $map = [];
    foreach ($rows as $row) {
        if (!isset($row["symbol"])) {
            continue;
        }

        $symbol = strtoupper(trim($row["symbol"]));
        $map[$symbol] = [
            "symbol" => $symbol,
            "name" => isset($row["meta"]["companyName"]) ? $row["meta"]["companyName"] : $symbol,
            "exchange" => "NSE",
            "last_price" => isset($row["lastPrice"]) ? (float) $row["lastPrice"] : 0,
            "ohlc" => [
                "open" => isset($row["open"]) ? (float) $row["open"] : 0,
                "high" => isset($row["dayHigh"]) ? (float) $row["dayHigh"] : 0,
                "low" => isset($row["dayLow"]) ? (float) $row["dayLow"] : 0,
                "close" => isset($row["previousClose"]) ? (float) $row["previousClose"] : 0
            ],
            "depth" => [
                "buy" => [["price" => 0]],
                "sell" => [["price" => 0]]
            ],
            "volume" => isset($row["totalTradedVolume"]) ? (float) $row["totalTradedVolume"] : 0,
            "change_percent" => isset($row["pChange"]) ? (float) $row["pChange"] : 0
        ];
    }

    $snapshotCache[$cacheKey] = $map;
    return $map;
}

function nse_get_equity_quote_from_snapshots($symbol)
{
    $cleanSymbol = normalize_symbol($symbol);
    if ($cleanSymbol === "") {
        return null;
    }

    $indicesToCheck = ["NIFTY 50", "NIFTY NEXT 50", "NIFTY BANK"];
    foreach ($indicesToCheck as $indexName) {
        $map = nse_get_equity_snapshot_map($indexName);
        if (isset($map[$cleanSymbol]) && isset($map[$cleanSymbol]["last_price"]) && (float) $map[$cleanSymbol]["last_price"] > 0) {
            return $map[$cleanSymbol];
        }
    }

    return null;
}

function nse_get_top_active_stocks($limit = 4, $forceRefresh = false)
{
    $rows = nse_get_index_stocks("NIFTY 50", $forceRefresh);
    if (count($rows) === 0) {
        return [];
    }

    usort($rows, function ($a, $b) {
        $volA = isset($a["totalTradedVolume"]) ? (float) $a["totalTradedVolume"] : 0;
        $volB = isset($b["totalTradedVolume"]) ? (float) $b["totalTradedVolume"] : 0;
        if ($volA === $volB) {
            return 0;
        }
        return ($volA < $volB) ? 1 : -1;
    });

    $top = [];
    foreach ($rows as $row) {
        $symbol = isset($row["symbol"]) ? strtoupper($row["symbol"]) : "";
        if ($symbol === "") {
            continue;
        }

        $top[] = [
            "symbol" => $symbol,
            "name" => isset($row["meta"]["companyName"]) ? $row["meta"]["companyName"] : $symbol,
            "price" => isset($row["lastPrice"]) ? (float) $row["lastPrice"] : 0,
            "change_percent" => isset($row["pChange"]) ? (float) $row["pChange"] : 0,
            "volume" => isset($row["totalTradedVolume"]) ? (float) $row["totalTradedVolume"] : 0
        ];

        if (count($top) >= $limit) {
            break;
        }
    }

    return $top;
}

function nse_get_equity_quote($symbol)
{
    $cleanSymbol = normalize_symbol($symbol);
    if ($cleanSymbol === "" || $cleanSymbol === "NIFTY 50" || $cleanSymbol === "NIFTY BANK" || $cleanSymbol === "SENSEX") {
        return null;
    }

    $main = nse_api_get("/api/quote-equity", ["symbol" => $cleanSymbol], "https://www.nseindia.com/get-quotes/equity?symbol=" . urlencode($cleanSymbol));
    if (!$main) {
        return nse_get_equity_quote_from_snapshots($cleanSymbol);
    }

    $trade = nse_api_get("/api/quote-equity", ["symbol" => $cleanSymbol, "section" => "trade_info"], "https://www.nseindia.com/get-quotes/equity?symbol=" . urlencode($cleanSymbol));

    $lastPrice = isset($main["priceInfo"]["lastPrice"]) ? (float) $main["priceInfo"]["lastPrice"] : 0;
    $previousClose = isset($main["priceInfo"]["previousClose"]) ? (float) $main["priceInfo"]["previousClose"] : 0;
    $open = isset($main["priceInfo"]["open"]) ? (float) $main["priceInfo"]["open"] : 0;
    $dayHigh = isset($main["priceInfo"]["intraDayHighLow"]["max"]) ? (float) $main["priceInfo"]["intraDayHighLow"]["max"] : 0;
    $dayLow = isset($main["priceInfo"]["intraDayHighLow"]["min"]) ? (float) $main["priceInfo"]["intraDayHighLow"]["min"] : 0;
    $volume = isset($trade["marketDeptOrderBook"]["tradeInfo"]["totalTradedVolume"]) ? (float) $trade["marketDeptOrderBook"]["tradeInfo"]["totalTradedVolume"] : 0;
    $bestBid = isset($trade["marketDeptOrderBook"]["bid"][0]["price"]) ? (float) $trade["marketDeptOrderBook"]["bid"][0]["price"] : 0;
    $bestAsk = isset($trade["marketDeptOrderBook"]["ask"][0]["price"]) ? (float) $trade["marketDeptOrderBook"]["ask"][0]["price"] : 0;
    $name = isset($main["info"]["companyName"]) ? $main["info"]["companyName"] : $cleanSymbol;

    return [
        "symbol" => $cleanSymbol,
        "name" => $name,
        "exchange" => "NSE",
        "last_price" => $lastPrice,
        "ohlc" => [
            "open" => $open,
            "high" => $dayHigh,
            "low" => $dayLow,
            "close" => $previousClose
        ],
        "depth" => [
            "buy" => [["price" => $bestBid]],
            "sell" => [["price" => $bestAsk]]
        ],
        "volume" => $volume,
        "change_percent" => isset($main["priceInfo"]["pChange"]) ? (float) $main["priceInfo"]["pChange"] : 0
    ];
}

function nse_get_index_quote($indexName, $forceRefresh = false)
{
    $indices = nse_get_indices($forceRefresh);
    if (count($indices) === 0) {
        return null;
    }

    $name = strtoupper(normalize_symbol($indexName));
    if ($name === "NIFTY") {
        $name = "NIFTY 50";
    }
    if ($name === "BANKNIFTY") {
        $name = "NIFTY BANK";
    }

    if (!isset($indices[$name])) {
        return null;
    }

    $row = $indices[$name];
    $last = isset($row["last"]) ? (float) $row["last"] : 0;
    $close = isset($row["previousClose"]) ? (float) $row["previousClose"] : 0;

    return [
        "symbol" => $name,
        "name" => $name,
        "exchange" => "NSE",
        "last_price" => $last,
        "ohlc" => [
            "open" => isset($row["open"]) ? (float) $row["open"] : 0,
            "high" => isset($row["high"]) ? (float) $row["high"] : 0,
            "low" => isset($row["low"]) ? (float) $row["low"] : 0,
            "close" => $close
        ],
        "depth" => [
            "buy" => [["price" => 0]],
            "sell" => [["price" => 0]]
        ],
        "volume" => 0,
        "change_percent" => isset($row["percentChange"]) ? (float) $row["percentChange"] : 0
    ];
}

function search_market_instruments($query)
{
    if (!$query) {
        return [];
    }

    global $UPSTOX_TOKEN;

    if ($UPSTOX_TOKEN) {
        $url = "https://api.upstox.com/v1/instruments/search?query=" . urlencode($query) . "&segments=EQ&exchanges=NSE,BSE&records=10";
        $json = upstox_api_get($url);

        if ($json && isset($json["data"]) && is_array($json["data"])) {
            return $json["data"];
        }
    }

    return nse_search_stocks($query);
}

function get_live_quote($instrumentKey, $forceRefresh = false)
{
    global $UPSTOX_TOKEN;

    static $requestCache = [];

    if (!$instrumentKey) {
        return null;
    }

    $cacheKey = strtoupper(trim((string) $instrumentKey));
    if (!$forceRefresh && array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    if (!isset($_SESSION["quote_cache"])) {
        $_SESSION["quote_cache"] = [];
    }

    if (!$forceRefresh && isset($_SESSION["quote_cache"][$cacheKey])) {
        $cached = $_SESSION["quote_cache"][$cacheKey];
        if (
            is_array($cached) &&
            isset($cached["fetched_at"], $cached["quote"]) &&
            (time() - (int) $cached["fetched_at"]) < 20
        ) {
            $requestCache[$cacheKey] = $cached["quote"];
            return $cached["quote"];
        }
    }

    if ($UPSTOX_TOKEN) {
        $url = "https://api.upstox.com/v2/market-quote/quotes?instrument_key=" . urlencode($instrumentKey);
        $json = upstox_api_get($url);
        if ($json && isset($json["data"]) && is_array($json["data"]) && isset($json["data"][$instrumentKey])) {
            $requestCache[$cacheKey] = $json["data"][$instrumentKey];
            $_SESSION["quote_cache"][$cacheKey] = [
                "fetched_at" => time(),
                "quote" => $json["data"][$instrumentKey]
            ];
            return $json["data"][$instrumentKey];
        }
    }

    $symbol = normalize_symbol($instrumentKey);
    $indexQuote = nse_get_index_quote($symbol);
    if ($indexQuote) {
        $requestCache[$cacheKey] = $indexQuote;
        $_SESSION["quote_cache"][$cacheKey] = [
            "fetched_at" => time(),
            "quote" => $indexQuote
        ];
        return $indexQuote;
    }

    $quote = nse_get_equity_quote($symbol);
    if ($quote) {
        $requestCache[$cacheKey] = $quote;
        $_SESSION["quote_cache"][$cacheKey] = [
            "fetched_at" => time(),
            "quote" => $quote
        ];
        return $quote;
    }

    $requestCache[$cacheKey] = null;
    $_SESSION["quote_cache"][$cacheKey] = [
        "fetched_at" => time(),
        "quote" => null
    ];

    return null;
}

function get_recent_cached_price($symbol)
{
    global $conn;

    $safeSymbol = mysqli_real_escape_string($conn, normalize_symbol($symbol));
    if ($safeSymbol === "") {
        return 0;
    }

    $result = mysqli_query($conn, "SELECT price, created_at FROM price_history WHERE stock_name = '$safeSymbol' ORDER BY id DESC LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    if (!isset($row["created_at"]) || (time() - strtotime($row["created_at"])) > 900) {
        return 0;
    }

    return isset($row["price"]) ? (float) $row["price"] : 0;
}

function record_price_history($symbol, $price)
{
    global $conn;

    $normalized = normalize_symbol($symbol);
    $price = (float) $price;

    if ($normalized === "" || $price <= 0) {
        return;
    }

    $safeSymbol = mysqli_real_escape_string($conn, $normalized);
    $result = mysqli_query($conn, "SELECT price, created_at FROM price_history WHERE stock_name = '$safeSymbol' ORDER BY id DESC LIMIT 1");

    if ($result && mysqli_num_rows($result) > 0) {
        $latest = mysqli_fetch_assoc($result);
        $lastPrice = isset($latest["price"]) ? (float) $latest["price"] : 0;
        $lastTime = isset($latest["created_at"]) ? strtotime($latest["created_at"]) : 0;

        if ($lastTime > 0 && (time() - $lastTime) < 45 && abs($lastPrice - $price) < 0.01) {
            return;
        }
    }

    mysqli_query($conn, "INSERT INTO price_history (stock_name, price) VALUES ('$safeSymbol', $price)");
}

function get_price_history_series($symbol, $limit = 120, $liveQuote = null)
{
    global $conn;

    $normalized = normalize_symbol($symbol);
    $safeSymbol = mysqli_real_escape_string($conn, $normalized);
    $limit = max(2, (int) $limit);

    $result = mysqli_query($conn, "
        SELECT created_at, price
        FROM (
            SELECT created_at, price
            FROM price_history
            WHERE stock_name = '$safeSymbol'
            ORDER BY id DESC
            LIMIT $limit
        ) history_rows
        ORDER BY created_at ASC
    ");

    $labels = [];
    $prices = [];

    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $labels[] = date("H:i", strtotime($row["created_at"]));
        $prices[] = (float) $row["price"];
    }

    if (count($prices) < 2) {
        $quote = $liveQuote ? $liveQuote : get_live_quote($symbol);
        if ($quote && isset($quote["last_price"])) {
            $last = (float) $quote["last_price"];
            $close = isset($quote["ohlc"]["close"]) ? (float) $quote["ohlc"]["close"] : $last;
            $labels = ["Prev", "Now"];
            $prices = [$close, $last];
        }
    }

    return [
        "labels" => $labels,
        "prices" => $prices
    ];
}

function normalize_company_key($value)
{
    $value = strtoupper((string) $value);
    return preg_replace('/[^A-Z0-9]+/', '', $value);
}

function get_local_stock_reference_price($name)
{
    global $conn;

    static $stockPriceMap = null;

    if ($stockPriceMap === null) {
        $stockPriceMap = [];
        $result = mysqli_query($conn, "SELECT name, price FROM stocks");
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $key = normalize_company_key($row["name"]);
            $stockPriceMap[$key] = (float) $row["price"];
        }
    }

    $key = normalize_company_key($name);
    return isset($stockPriceMap[$key]) ? (float) $stockPriceMap[$key] : 0;
}

function get_session_quote_price($instrumentKey, $displayName)
{
    if (!isset($_SESSION["quote_cache"]) || !is_array($_SESSION["quote_cache"])) {
        return 0;
    }

    $keys = [];
    if ($instrumentKey) {
        $keys[] = strtoupper(trim((string) $instrumentKey));
    }
    if ($displayName) {
        $keys[] = strtoupper(trim((string) $displayName));
        $keys[] = strtoupper(trim(normalize_symbol($displayName)));
    }

    foreach (array_unique($keys) as $key) {
        if (!isset($_SESSION["quote_cache"][$key])) {
            continue;
        }

        $cached = $_SESSION["quote_cache"][$key];
        if (
            !is_array($cached) ||
            !isset($cached["fetched_at"], $cached["quote"]["last_price"]) ||
            (time() - (int) $cached["fetched_at"]) > 300
        ) {
            continue;
        }

        $price = (float) $cached["quote"]["last_price"];
        if ($price > 0) {
            return $price;
        }
    }

    return 0;
}

function resolve_holding_display_price($instrumentKey, $displayName, $buyPrice)
{
    $buyPrice = (float) $buyPrice;

    $localPrice = get_local_stock_reference_price($displayName);
    $sessionPrice = get_session_quote_price($instrumentKey, $displayName);

    if ($sessionPrice > 0 && $localPrice > 0) {
        $deviation = abs($sessionPrice - $localPrice) / max($localPrice, 1);
        if ($deviation < 0.15) {
            return $sessionPrice;
        }
    }

    if ($localPrice > 0) {
        return $localPrice;
    }

    if ($sessionPrice > 0) {
        return $sessionPrice;
    }

    $recentPrice = get_recent_cached_price($displayName ? $displayName : $instrumentKey);
    if ($recentPrice > 0 && ($buyPrice <= 0 || abs($recentPrice - $buyPrice) / max($buyPrice, 1) < 0.12)) {
        return $recentPrice;
    }

    return $buyPrice;
}

function resolve_holding_live_price($instrumentKey, $displayName, $buyPrice, $forceRefresh = false)
{
    $buyPrice = (float) $buyPrice;
    $quoteKey = $instrumentKey ? $instrumentKey : $displayName;
    $livePrice = $buyPrice;

    if ($quoteKey) {
        $quote = get_live_quote($quoteKey, $forceRefresh);
        if ($quote && isset($quote["last_price"]) && (float) $quote["last_price"] > 0) {
            $livePrice = (float) $quote["last_price"];
        }
    }

    $fallbackSymbol = normalize_symbol($displayName);
    if (($livePrice <= 0 || abs($livePrice - $buyPrice) < 0.0001) && $fallbackSymbol !== "") {
        $fallbackQuote = get_live_quote($fallbackSymbol, $forceRefresh);
        if ($fallbackQuote && isset($fallbackQuote["last_price"]) && (float) $fallbackQuote["last_price"] > 0) {
            $livePrice = (float) $fallbackQuote["last_price"];
        }
    }

    if ($livePrice <= 0 || abs($livePrice - $buyPrice) < 0.0001) {
        $recentPrice = get_recent_cached_price($displayName ? $displayName : $instrumentKey);
        if ($recentPrice > 0) {
            $livePrice = $recentPrice;
        }
    }

    return $livePrice > 0 ? $livePrice : $buyPrice;
}

function get_live_quote_fast($instrumentKey)
{
    $cacheKey = strtoupper(trim((string) $instrumentKey));

    if (
        isset($_SESSION["quote_cache"][$cacheKey]) &&
        is_array($_SESSION["quote_cache"][$cacheKey]) &&
        isset($_SESSION["quote_cache"][$cacheKey]["fetched_at"], $_SESSION["quote_cache"][$cacheKey]["quote"]) &&
        (time() - (int) $_SESSION["quote_cache"][$cacheKey]["fetched_at"]) < 300
    ) {
        return $_SESSION["quote_cache"][$cacheKey]["quote"];
    }

    $normalized = normalize_symbol($instrumentKey);
    if ($normalized === "") {
        return null;
    }

    $indexQuote = nse_get_index_quote($normalized);
    if ($indexQuote) {
        return $indexQuote;
    }

    $snapshotQuote = nse_get_equity_quote_from_snapshots($normalized);
    if ($snapshotQuote) {
        return $snapshotQuote;
    }

    $recentPrice = get_recent_cached_price($normalized);
    if ($recentPrice > 0) {
        return [
            "symbol" => $normalized,
            "name" => $normalized,
            "exchange" => "NSE",
            "last_price" => $recentPrice,
            "ohlc" => [
                "open" => 0,
                "high" => 0,
                "low" => 0,
                "close" => $recentPrice
            ],
            "depth" => [
                "buy" => [["price" => 0]],
                "sell" => [["price" => 0]]
            ],
            "volume" => 0,
            "change_percent" => 0
        ];
    }

    return null;
}

function summarize_quote($quote, $fallbackSymbol = "")
{
    $lastPrice = $quote && isset($quote["last_price"]) ? (float) $quote["last_price"] : 0;
    $changePercent = $quote && isset($quote["change_percent"]) ? (float) $quote["change_percent"] : 0;
    $open = $quote && isset($quote["ohlc"]["open"]) ? (float) $quote["ohlc"]["open"] : 0;
    $high = $quote && isset($quote["ohlc"]["high"]) ? (float) $quote["ohlc"]["high"] : 0;
    $low = $quote && isset($quote["ohlc"]["low"]) ? (float) $quote["ohlc"]["low"] : 0;
    $close = $quote && isset($quote["ohlc"]["close"]) ? (float) $quote["ohlc"]["close"] : 0;
    $volume = $quote && isset($quote["volume"]) ? (float) $quote["volume"] : 0;
    $bestBid = $quote && isset($quote["depth"]["buy"][0]["price"]) ? (float) $quote["depth"]["buy"][0]["price"] : 0;
    $bestAsk = $quote && isset($quote["depth"]["sell"][0]["price"]) ? (float) $quote["depth"]["sell"][0]["price"] : 0;

    return [
        "symbol" => $fallbackSymbol !== "" ? $fallbackSymbol : ($quote && isset($quote["symbol"]) ? $quote["symbol"] : ""),
        "name" => $quote && isset($quote["name"]) ? $quote["name"] : $fallbackSymbol,
        "lastPrice" => $lastPrice,
        "changePercent" => $changePercent,
        "open" => $open,
        "high" => $high,
        "low" => $low,
        "close" => $close,
        "volume" => $volume,
        "bestBid" => $bestBid,
        "bestAsk" => $bestAsk
    ];
}

function get_trade_signal($quote)
{
    $summary = summarize_quote($quote);
    $price = $summary["lastPrice"];
    $open = $summary["open"];
    $high = $summary["high"];
    $low = $summary["low"];
    $changePercent = $summary["changePercent"];

    if ($price <= 0) {
        return [
            "tone" => "neutral",
            "title" => "Wait for data",
            "text" => "Quote depth is missing right now, so wait for a cleaner read before paper trading."
        ];
    }

    $range = max($high - $low, 0.01);
    $positionInRange = ($price - $low) / $range;

    if ($changePercent >= 1 && $price >= $open && $positionInRange > 0.72) {
        return [
            "tone" => "bullish",
            "title" => "Breakout pressure",
            "text" => "Price is trading near the session high with buyers in control. Watch for continuation entries on pullbacks."
        ];
    }

    if ($changePercent <= -1 && $price <= $open && $positionInRange < 0.3) {
        return [
            "tone" => "bearish",
            "title" => "Supply in control",
            "text" => "Price is pinned near the lower end of the day range. Short-term traders usually wait for strength before buying."
        ];
    }

    if ($positionInRange >= 0.45 && $positionInRange <= 0.6) {
        return [
            "tone" => "neutral",
            "title" => "Range-bound action",
            "text" => "The stock is trading around the middle of its range. A breakout above high or breakdown below low will be more informative."
        ];
    }

    if ($price > $open) {
        return [
            "tone" => "bullish",
            "title" => "Buyers defending dips",
            "text" => "Price is holding above the open, which often signals dip demand. Paper traders can wait for support confirmation."
        ];
    }

    return [
        "tone" => "bearish",
        "title" => "Sellers still active",
        "text" => "Price remains below the open, so patience may be better than forcing a trade until momentum improves."
    ];
}

function get_market_strip_snapshot($preferCached = false)
{
    $indices = $preferCached
        ? (session_cache_get("nse_indices", 300) ?: [])
        : nse_get_indices();
    $marketStrip = [
        ["label" => "NIFTY 50", "symbol" => "NIFTY 50"],
        ["label" => "SENSEX", "symbol" => "SENSEX"],
        ["label" => "BANK NIFTY", "symbol" => "NIFTY BANK"],
        ["label" => "NIFTY NEXT 50", "symbol" => "NIFTY NEXT 50"]
    ];

    $rows = [];
    foreach ($marketStrip as $item) {
        $lookup = strtoupper($item["symbol"]);
        $row = isset($indices[$lookup]) ? $indices[$lookup] : null;
        $rows[] = [
            "label" => $item["label"],
            "symbol" => $item["symbol"],
            "last" => $row && isset($row["last"]) ? (float) $row["last"] : 0,
            "percentChange" => $row && isset($row["percentChange"]) ? (float) $row["percentChange"] : 0
        ];
    }

    return $rows;
}

function get_market_mood($preferCached = false)
{
    $niftyQuote = $preferCached ? get_live_quote_fast("NIFTY 50") : get_live_quote("NIFTY 50");

    if (!$niftyQuote || !isset($niftyQuote["last_price"]) || !isset($niftyQuote["ohlc"]["close"])) {
        return ["title" => "Normal", "text" => "Live market mood unavailable. Use careful position sizing."];
    }

    $last = (float) $niftyQuote["last_price"];
    $close = (float) $niftyQuote["ohlc"]["close"];
    $diffPercent = 0;

    if ($close > 0) {
        $diffPercent = (($last - $close) / $close) * 100;
    }

    if ($diffPercent > 0.7) {
        return ["title" => "Bullish", "text" => "Market is strong. Avoid overbuying; look for quality entries."];
    }

    if ($diffPercent > 0.15) {
        return ["title" => "Positive", "text" => "Market is mildly positive. Buy only on planned setups."];
    }

    if ($diffPercent < -0.7) {
        return ["title" => "Bearish", "text" => "Market is weak. Prefer defensive positions or wait."];
    }

    if ($diffPercent < -0.15) {
        return ["title" => "Weak", "text" => "Market is slightly weak. Keep position size small."];
    }

    return ["title" => "Sideways", "text" => "Market is flat. Trade only clear opportunities."];
}

function get_tv_symbol($exchange, $tradingSymbol)
{
    $symbol = strtoupper(trim($tradingSymbol));
    if ($symbol === "NIFTY 50" || $symbol === "NIFTY") {
        return "NSE:NIFTY50";
    }
    if ($symbol === "NIFTY BANK" || $symbol === "BANKNIFTY") {
        return "NSE:BANKNIFTY";
    }
    if ($symbol === "SENSEX") {
        return "BSE:SENSEX";
    }

    if ($exchange === "BSE") {
        return "BSE:" . $symbol;
    }
    return "NSE:" . $symbol;
}

function get_user_watchlist($conn, $userId)
{
    $userId = (int) $userId;
    $result = mysqli_query($conn, "SELECT symbol, display_name FROM watchlist WHERE user_id = $userId ORDER BY id DESC");
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

function is_in_watchlist($conn, $userId, $symbol)
{
    $userId = (int) $userId;
    $safeSymbol = mysqli_real_escape_string($conn, strtoupper(trim($symbol)));
    $result = mysqli_query($conn, "SELECT id FROM watchlist WHERE user_id = $userId AND symbol = '$safeSymbol' LIMIT 1");
    return mysqli_num_rows($result) > 0;
}

function initialize_database($conn)
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, password VARCHAR(50) NOT NULL, balance DECIMAL(12,2) NOT NULL DEFAULT 0)");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stocks (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, price DECIMAL(12,2) NOT NULL)");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS holdings (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, stock_name VARCHAR(100) NOT NULL, quantity INT NOT NULL DEFAULT 0, buy_price DECIMAL(12,2) NOT NULL, instrument_key VARCHAR(120) NULL, display_name VARCHAR(120) NULL, UNIQUE KEY unique_holding (user_id, stock_name))");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS price_history (id INT AUTO_INCREMENT PRIMARY KEY, stock_name VARCHAR(100) NOT NULL, price DECIMAL(12,2) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS trades (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, instrument_key VARCHAR(120) NOT NULL, display_name VARCHAR(120) NOT NULL, side VARCHAR(10) NOT NULL, quantity INT NOT NULL, price DECIMAL(12,2) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS watchlist (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, symbol VARCHAR(40) NOT NULL, display_name VARCHAR(120) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY user_symbol_unique (user_id, symbol))");

    $users = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM users"));
    if ($users["total"] == 0) {
        mysqli_query($conn, "INSERT INTO users (username, password, balance) VALUES ('agrim', '123', 100000.00)");
    }

    $stocks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM stocks"));
    if ($stocks["total"] == 0) {
        mysqli_query($conn, "INSERT INTO stocks (name, price) VALUES ('TCS', 3650.00), ('Infosys', 1480.00), ('Reliance', 2860.00), ('HDFC Bank', 1710.00), ('ICICI Bank', 1185.00), ('SBI', 845.00), ('Wipro', 535.00), ('ITC', 438.00), ('Bharti Airtel', 1365.00), ('Tata Motors', 982.00)");
    }

    $history = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM price_history"));
    if ($history["total"] == 0) {
        mysqli_query($conn, "INSERT INTO price_history (stock_name, price) SELECT name, price FROM stocks");
    }

    $keyColumn = mysqli_query($conn, "SHOW COLUMNS FROM holdings LIKE 'instrument_key'");
    if (mysqli_num_rows($keyColumn) == 0) {
        mysqli_query($conn, "ALTER TABLE holdings ADD COLUMN instrument_key VARCHAR(120) NULL");
    }

    $nameColumn = mysqli_query($conn, "SHOW COLUMNS FROM holdings LIKE 'display_name'");
    if (mysqli_num_rows($nameColumn) == 0) {
        mysqli_query($conn, "ALTER TABLE holdings ADD COLUMN display_name VARCHAR(120) NULL");
    }
}
