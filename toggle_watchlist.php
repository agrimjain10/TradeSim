<?php
require_once __DIR__ . "/app.php";

require_login();

$userId = (int) $_SESSION["user_id"];
$symbol = isset($_POST["symbol"]) ? strtoupper(trim($_POST["symbol"])) : "";
$displayName = isset($_POST["display_name"]) ? trim($_POST["display_name"]) : ("NSE:" . $symbol);
$returnTo = isset($_POST["return_to"]) ? trim($_POST["return_to"]) : "dashboard.php";

if ($symbol === "") {
    set_flash("error", "Invalid symbol");
    redirect_to("dashboard.php");
}

$safeSymbol = mysqli_real_escape_string($conn, $symbol);
$safeName = mysqli_real_escape_string($conn, $displayName);

if (is_in_watchlist($conn, $userId, $symbol)) {
    mysqli_query($conn, "DELETE FROM watchlist WHERE user_id = $userId AND symbol = '$safeSymbol'");
    set_flash("success", $symbol . " removed from watchlist");
} else {
    mysqli_query($conn, "INSERT INTO watchlist (user_id, symbol, display_name) VALUES ($userId, '$safeSymbol', '$safeName')");
    set_flash("success", $symbol . " added to watchlist");
}

redirect_to($returnTo);
