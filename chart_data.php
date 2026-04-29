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

$live = get_live_quote($symbol, true);
if ($live && isset($live["last_price"])) {
    record_price_history($symbol, (float) $live["last_price"]);
}

$series = get_intraday_chart_series($symbol, $live);

header("Content-Type: application/json");
echo json_encode($series);
exit;
