<?php
require_once __DIR__ . "/app.php";

if (isset($_SESSION["user_id"])) {
    redirect_to("dashboard.php");
}

redirect_to("login.php");
