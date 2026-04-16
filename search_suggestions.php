<?php
require_once __DIR__ . "/app.php";

require_login();

$q = isset($_GET["q"]) ? trim($_GET["q"]) : "";
if ($q === "" || strlen($q) < 1) {
    header("Content-Type: application/json");
    echo json_encode([]);
    exit;
}

$list = search_market_instruments($q);
$out = [];

foreach ($list as $item) {
    $symbol = isset($item["trading_symbol"]) ? strtoupper($item["trading_symbol"]) : "";
    if ($symbol === "") {
        continue;
    }

    $out[] = [
        "symbol" => $symbol,
        "name" => isset($item["name"]) ? $item["name"] : $symbol,
        "exchange" => isset($item["exchange"]) ? $item["exchange"] : "NSE"
    ];
}

header("Content-Type: application/json");
echo json_encode($out);
exit;
