<?php
require_once __DIR__ . "/layout.php";

if (isset($_SESSION["user_id"])) {
    redirect_to("dashboard.php");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = mysqli_real_escape_string($conn, trim($_POST["username"]));
    $password = mysqli_real_escape_string($conn, trim($_POST["password"]));
    $result = mysqli_query($conn, "SELECT * FROM users WHERE username = '$username' AND password = '$password'");

    if (mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $_SESSION["user_id"] = $user["id"];
        redirect_to("dashboard.php");
    } else {
        set_flash("error", "Invalid login");
        redirect_to("login.php");
    }
}

render_header("Login");
?>

<div class="login-card">
    <p class="small-title">Welcome</p>
    <h2>Login</h2>
    <p class="help-text">Login to enter TradeSim.</p>

    <form method="post" class="login-box-form">
        <input type="text" name="username" placeholder="Enter username" required>
        <input type="password" name="password" placeholder="Enter password" required>
        <button type="submit">Login</button>
    </form>
</div>

<?php render_footer(); ?>
