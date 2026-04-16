<?php
require_once __DIR__ . "/app.php";

require_login();

$userId = $_SESSION["user_id"];
$instrumentKey = mysqli_real_escape_string($conn, $_POST["instrument_key"]);
$displayName = mysqli_real_escape_string($conn, $_POST["display_name"]);
$quantity = (int) $_POST["qty"];
$returnTo = isset($_POST["return_to"]) ? trim($_POST["return_to"]) : "holdings.php";

if ($instrumentKey === "" || $quantity < 1) {
    set_flash("error", "Enter a valid stock and quantity");
    redirect_to($returnTo);
}

$holdingResult = mysqli_query($conn, "SELECT * FROM holdings WHERE user_id = $userId AND instrument_key = '$instrumentKey'");
$holding = mysqli_fetch_assoc($holdingResult);

if (!$holding) {
    set_flash("error", "Holding not found");
    redirect_to($returnTo);
}

$currentQuantity = $holding["quantity"];

if ($quantity > $currentQuantity) {
    set_flash("error", "You cannot sell more than your holding quantity");
    redirect_to($returnTo);
}

$quote = get_live_quote($instrumentKey);
if (!$quote || !isset($quote["last_price"])) {
    set_flash("error", "Stock not found");
    redirect_to($returnTo);
}

$price = (float) $quote["last_price"];
$newQuantity = $currentQuantity - $quantity;

if ($newQuantity > 0) {
    mysqli_query($conn, "UPDATE holdings SET quantity = $newQuantity WHERE user_id = $userId AND instrument_key = '$instrumentKey'");
} else {
    mysqli_query($conn, "DELETE FROM holdings WHERE user_id = $userId AND instrument_key = '$instrumentKey'");
}

$totalAmount = $price * $quantity;
mysqli_query($conn, "UPDATE users SET balance = balance + $totalAmount WHERE id = $userId");
mysqli_query($conn, "INSERT INTO trades (user_id, instrument_key, display_name, side, quantity, price) VALUES ($userId, '$instrumentKey', '$displayName', 'SELL', $quantity, $price)");

set_flash("success", "Sold " . $displayName . " successfully");
redirect_to($returnTo);
