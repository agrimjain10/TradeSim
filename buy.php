<?php
require_once __DIR__ . "/app.php";

require_login();

$userId = $_SESSION["user_id"];
$instrumentKey = mysqli_real_escape_string($conn, $_POST["instrument_key"]);
$displayName = mysqli_real_escape_string($conn, $_POST["display_name"]);
$quantity = (int) $_POST["qty"];
$returnTo = isset($_POST["return_to"]) ? trim($_POST["return_to"]) : "dashboard.php";

if ($instrumentKey === "" || $displayName === "" || $quantity < 1) {
    set_flash("error", "Enter a valid stock and quantity");
    redirect_to($returnTo);
}

$quote = get_live_quote($instrumentKey);
if (!$quote || !isset($quote["last_price"])) {
    set_flash("error", "Stock not found");
    redirect_to($returnTo);
}

$price = (float) $quote["last_price"];
$user = current_user($conn);
$balance = (float) $user["balance"];
$totalCost = $price * $quantity;

if ($totalCost > $balance) {
    set_flash("error", "Not enough balance");
    redirect_to($returnTo);
}

mysqli_query($conn, "UPDATE users SET balance = balance - $totalCost WHERE id = $userId");

$holdingResult = mysqli_query($conn, "SELECT * FROM holdings WHERE user_id = $userId AND instrument_key = '$instrumentKey'");

if (mysqli_num_rows($holdingResult) > 0) {
    $holding = mysqli_fetch_assoc($holdingResult);
    $newQuantity = $holding["quantity"] + $quantity;
    $oldTotal = $holding["buy_price"] * $holding["quantity"];
    $newTotal = $price * $quantity;
    $averagePrice = ($oldTotal + $newTotal) / $newQuantity;

    mysqli_query($conn, "UPDATE holdings SET quantity = $newQuantity, buy_price = $averagePrice WHERE user_id = $userId AND instrument_key = '$instrumentKey'");
} else {
    mysqli_query($conn, "INSERT INTO holdings (user_id, stock_name, quantity, buy_price, instrument_key, display_name) VALUES ($userId, '$displayName', $quantity, $price, '$instrumentKey', '$displayName')");
}

mysqli_query($conn, "INSERT INTO trades (user_id, instrument_key, display_name, side, quantity, price) VALUES ($userId, '$instrumentKey', '$displayName', 'BUY', $quantity, $price)");

set_flash("success", "Stock bought successfully");
redirect_to($returnTo);
