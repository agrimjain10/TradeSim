<?php
require_once __DIR__ . "/app.php";

require_login();

header("Content-Type: application/json");

$user = current_user($conn);
if (!$user) {
    http_response_code(401);
    echo json_encode(["ok" => false, "message" => "Unauthorized"]);
    exit;
}

$view = isset($_GET["view"]) ? trim($_GET["view"]) : "";
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$response = [
    "ok" => true,
    "balance" => (float) $user["balance"],
    "marketStrip" => get_market_strip_snapshot(),
    "timestamp" => date(DATE_ATOM)
];

if ($view === "dashboard") {
    $userId = (int) $user["id"];
    $portfolioRows = mysqli_query($conn, "SELECT quantity, buy_price, instrument_key, display_name, stock_name FROM holdings WHERE user_id = $userId");
    $investedValue = 0;
    $liveValue = 0;

    while ($portfolioRows && ($holding = mysqli_fetch_assoc($portfolioRows))) {
        $displayName = $holding["display_name"] ? $holding["display_name"] : $holding["stock_name"];
        $livePrice = resolve_holding_live_price($holding["instrument_key"], $displayName, $holding["buy_price"], true);
        $investedValue += (float) $holding["quantity"] * (float) $holding["buy_price"];
        $liveValue += (float) $holding["quantity"] * $livePrice;
    }

    $portfolioPnL = $liveValue - $investedValue;
    $portfolioReturn = $investedValue > 0 ? ($portfolioPnL / $investedValue) * 100 : 0;

    $response["marketMood"] = get_market_mood();
    $response["hero"] = [
        "portfolioValue" => $liveValue,
        "portfolioPnL" => $portfolioPnL,
        "portfolioReturn" => $portfolioReturn
    ];
    $response["topActive"] = nse_get_top_active_stocks(4);

    echo json_encode($response);
    exit;
}

if ($view === "watchlist") {
    $userId = (int) $user["id"];
    $watchlist = get_user_watchlist($conn, $userId);
    $rows = [];
    $positiveMoves = 0;

    foreach ($watchlist as $row) {
        $symbol = strtoupper($row["symbol"]);
        $quote = get_live_quote($symbol, true);
        $summary = summarize_quote($quote, $symbol);

        if ($summary["changePercent"] >= 0) {
            $positiveMoves++;
        }

        $rows[] = [
            "symbol" => $symbol,
            "displayName" => $row["display_name"],
            "price" => $summary["lastPrice"],
            "changePct" => $summary["changePercent"]
        ];
    }

    $response["stats"] = [
        "tracked" => count($rows),
        "positive" => $positiveMoves,
        "negative" => count($rows) - $positiveMoves
    ];
    $response["rows"] = $rows;

    echo json_encode($response);
    exit;
}

if ($view === "holdings") {
    $userId = (int) $user["id"];
    $holdingResult = mysqli_query($conn, "SELECT * FROM holdings WHERE user_id = $userId ORDER BY id DESC");
    $rows = [];
    $invested = 0;
    $current = 0;
    $winning = 0;
    $losing = 0;
    $largest = null;

    while ($holdingResult && ($holding = mysqli_fetch_assoc($holdingResult))) {
        $displayName = $holding["display_name"] ? $holding["display_name"] : $holding["stock_name"];
        $symbolOnly = strtoupper(str_replace(["NSE:", "BSE:"], "", $displayName));
        $livePrice = resolve_holding_live_price($holding["instrument_key"], $displayName, $holding["buy_price"], true);
        $holdingValue = (float) $holding["quantity"] * $livePrice;
        $profitValue = ($livePrice - (float) $holding["buy_price"]) * (int) $holding["quantity"];
        $profitPercent = (float) $holding["buy_price"] > 0 ? (($livePrice - (float) $holding["buy_price"]) / (float) $holding["buy_price"]) * 100 : 0;

        if ($profitValue >= 0) {
            $winning++;
        } else {
            $losing++;
        }

        if ($largest === null || $holdingValue > $largest["holdingValue"]) {
            $largest = [
                "name" => $displayName,
                "holdingValue" => $holdingValue,
                "quantity" => (int) $holding["quantity"]
            ];
        }

        $invested += (float) $holding["quantity"] * (float) $holding["buy_price"];
        $current += $holdingValue;

        $rows[] = [
            "instrumentKey" => $holding["instrument_key"],
            "symbol" => $symbolOnly,
            "displayName" => $displayName,
            "quantity" => (int) $holding["quantity"],
            "buyPrice" => (float) $holding["buy_price"],
            "currentPrice" => $livePrice,
            "profitValue" => $profitValue,
            "profitPercent" => $profitPercent,
            "holdingValue" => $holdingValue
        ];
    }

    $profit = $current - $invested;
    $response["summary"] = [
        "invested" => $invested,
        "current" => $current,
        "profit" => $profit,
        "return" => $invested > 0 ? ($profit / $invested) * 100 : 0
    ];
    $response["breadth"] = [
        "openPositions" => count($rows),
        "winning" => $winning,
        "losing" => $losing
    ];
    $response["largest"] = $largest;
    $response["rows"] = $rows;

    echo json_encode($response);
    exit;
}

if ($view === "stock") {
    $symbol = isset($_GET["symbol"]) ? strtoupper(trim($_GET["symbol"])) : "";
    $symbol = str_replace(["NSE:", "BSE:", ".NS", ".BO"], "", $symbol);

    if ($symbol === "") {
        http_response_code(422);
        echo json_encode(["ok" => false, "message" => "Missing symbol"]);
        exit;
    }

    $quote = get_live_quote($symbol, true);
    if (!$quote || !isset($quote["last_price"])) {
        http_response_code(404);
        echo json_encode(["ok" => false, "message" => "Live data unavailable"]);
        exit;
    }

    record_price_history($symbol, (float) $quote["last_price"]);

    $summary = summarize_quote($quote, $symbol);
    $isIndex = nse_get_index_quote($symbol) ? true : false;
    $holdingQty = 0;
    $holdingAvg = 0;

    if (!$isIndex) {
        $safeSymbol = mysqli_real_escape_string($conn, $symbol);
        $holdingResult = mysqli_query($conn, "SELECT quantity, buy_price FROM holdings WHERE user_id = " . (int) $user["id"] . " AND instrument_key = '$safeSymbol' LIMIT 1");
        if ($holdingResult && mysqli_num_rows($holdingResult) > 0) {
            $holding = mysqli_fetch_assoc($holdingResult);
            $holdingQty = (int) $holding["quantity"];
            $holdingAvg = (float) $holding["buy_price"];
        }
    }

    $response["quote"] = $summary;
    $response["chart"] = get_intraday_chart_series($symbol, $quote);
    $response["tradeSignal"] = get_trade_signal($quote);
    $response["holding"] = [
        "quantity" => $holdingQty,
        "averagePrice" => $holdingAvg
    ];
    $response["isIndex"] = $isIndex;
    $response["isInWatchlist"] = is_in_watchlist($conn, (int) $user["id"], $symbol);

    echo json_encode($response);
    exit;
}

http_response_code(400);
echo json_encode(["ok" => false, "message" => "Unsupported view"]);
exit;
