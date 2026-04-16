<?php
require_once __DIR__ . "/app.php";

session_destroy();
redirect_to("login.php");
