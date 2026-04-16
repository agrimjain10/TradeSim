<?php
require_once __DIR__ . "/app.php";

require_login();

$symbol = isset($_GET["symbol"]) ? strtoupper(trim($_GET["symbol"])) : "";
$symbol = str_replace(["NSE:", "BSE:", ".NS", ".BO"], "", $symbol);

if ($symbol === "") {
    header("Content-Type: application/json");
    echo json_encode(["labels" => [], "prices" => []]);
    exit;
}

$safeSymbol = mysqli_real_escape_string($conn, $symbol);

$live = get_live_quote($symbol);
if ($live && isset($live["last_price"])) {
    $livePrice = (float) $live["last_price"];
    if ($livePrice > 0) {
        mysqli_query($conn, "INSERT INTO price_history (stock_name, price) VALUES ('$safeSymbol', $livePrice)");
    }
}

$result = mysqli_query($conn, "
    SELECT created_at, price
    FROM (
        SELECT created_at, price
        FROM price_history
        WHERE stock_name = '$safeSymbol'
        ORDER BY id DESC
        LIMIT 120
    ) x
    ORDER BY created_at ASC
");

$labels = [];
$prices = [];

while ($row = mysqli_fetch_assoc($result)) {
    $labels[] = date("H:i", strtotime($row["created_at"]));
    $prices[] = (float) $row["price"];
}

if (count($prices) < 2) {
    $quote = $live ? $live : get_live_quote($symbol);
    if ($quote && isset($quote["last_price"])) {
        $last = (float) $quote["last_price"];
        $close = isset($quote["ohlc"]["close"]) ? (float) $quote["ohlc"]["close"] : $last;
        $labels = ["Prev", "Now"];
        $prices = [$close, $last];
    }
}

header("Content-Type: application/json");
echo json_encode(["labels" => $labels, "prices" => $prices]);
exit;
