<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
$userId = (int) $user["id"];

$symbol = isset($_GET["symbol"]) ? strtoupper(trim($_GET["symbol"])) : "";
$symbol = str_replace(["NSE:", "BSE:", ".NS", ".BO"], "", $symbol);

if ($symbol === "") {
    set_flash("error", "Select a stock first");
    redirect_to("dashboard.php");
}

$quote = get_live_quote($symbol);
if (!$quote || !isset($quote["last_price"])) {
    set_flash("error", "Live data not available for this symbol right now");
    redirect_to("dashboard.php");
}

$isIndex = nse_get_index_quote($symbol) ? true : false;
$displayName = "NSE:" . $symbol;

$lastPrice = (float) $quote["last_price"];
$open = isset($quote["ohlc"]["open"]) ? (float) $quote["ohlc"]["open"] : 0;
$high = isset($quote["ohlc"]["high"]) ? (float) $quote["ohlc"]["high"] : 0;
$low = isset($quote["ohlc"]["low"]) ? (float) $quote["ohlc"]["low"] : 0;
$close = isset($quote["ohlc"]["close"]) ? (float) $quote["ohlc"]["close"] : 0;
$changePercent = isset($quote["change_percent"]) ? (float) $quote["change_percent"] : 0;
$volume = isset($quote["volume"]) ? (float) $quote["volume"] : 0;
$bestBid = isset($quote["depth"]["buy"][0]["price"]) ? (float) $quote["depth"]["buy"][0]["price"] : 0;
$bestAsk = isset($quote["depth"]["sell"][0]["price"]) ? (float) $quote["depth"]["sell"][0]["price"] : 0;

$safeSymbol = mysqli_real_escape_string($conn, $symbol);
mysqli_query($conn, "INSERT INTO price_history (stock_name, price) VALUES ('$safeSymbol', $lastPrice)");

$holdingQty = 0;
$holdingAvg = 0;
if (!$isIndex) {
    $holdingResult = mysqli_query($conn, "SELECT quantity, buy_price FROM holdings WHERE user_id = $userId AND instrument_key = '$safeSymbol' LIMIT 1");
    if ($holdingResult && mysqli_num_rows($holdingResult) > 0) {
        $holding = mysqli_fetch_assoc($holdingResult);
        $holdingQty = (int) $holding["quantity"];
        $holdingAvg = (float) $holding["buy_price"];
    }
}

$inWatchlist = is_in_watchlist($conn, $userId, $symbol);

render_header($symbol . " Details", "dashboard");
?>

<div class="section-box">
    <div class="stock-header">
        <div class="stock-header-main">
            <h2><?php echo escape($symbol); ?></h2>
            <p class="help-text"><?php echo escape(isset($quote["name"]) ? $quote["name"] : $displayName); ?></p>
        </div>
        <div class="stock-header-action">
            <form method="post" action="toggle_watchlist.php" class="stock-form">
                <input type="hidden" name="symbol" value="<?php echo escape($symbol); ?>">
                <input type="hidden" name="display_name" value="<?php echo escape($displayName); ?>">
                <input type="hidden" name="return_to" value="<?php echo escape("stock.php?symbol=" . $symbol); ?>">
                <button type="submit"><?php echo $inWatchlist ? "Remove Watchlist" : "Add Watchlist"; ?></button>
            </form>
        </div>
    </div>

    <div class="detail-grid">
        <div class="info-box">
            <span>Live Price</span>
            <strong>Rs. <?php echo number_format($lastPrice, 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Change %</span>
            <strong class="<?php echo $changePercent >= 0 ? "profit" : "loss"; ?>">
                <?php echo ($changePercent >= 0 ? "+" : "") . number_format($changePercent, 2); ?>%
            </strong>
        </div>
        <div class="info-box">
            <span>Volume</span>
            <strong><?php echo $volume > 0 ? number_format($volume, 0) : "NA"; ?></strong>
        </div>
        <div class="info-box">
            <span>Open</span>
            <strong>Rs. <?php echo number_format($open, 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Day High</span>
            <strong>Rs. <?php echo number_format($high, 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Day Low</span>
            <strong>Rs. <?php echo number_format($low, 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Prev Close</span>
            <strong>Rs. <?php echo number_format($close, 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Best Bid</span>
            <strong><?php echo $bestBid > 0 ? "Rs. " . number_format($bestBid, 2) : "NA"; ?></strong>
        </div>
        <div class="info-box">
            <span>Best Ask</span>
            <strong><?php echo $bestAsk > 0 ? "Rs. " . number_format($bestAsk, 2) : "NA"; ?></strong>
        </div>
    </div>
</div>

<div class="section-box">
    <div class="section-head">
        <h3>Price Chart</h3>
        <p class="help-text">Recent trend for <?php echo escape($symbol); ?></p>
    </div>
    <div class="table-box" style="padding: 0.75rem;">
        <canvas id="stock-chart" height="120"></canvas>
    </div>
</div>

<?php if (!$isIndex) : ?>
<div class="section-box">
    <div class="section-head">
        <h3>Trade Actions</h3>
        <p class="help-text">Paper trade with your virtual balance.</p>
    </div>
    <div class="summary-row">
        <div class="summary-card">
            <span>Your Holding Qty</span>
            <strong><?php echo $holdingQty; ?></strong>
            <div>Avg Buy: Rs. <?php echo number_format($holdingAvg, 2); ?></div>
        </div>
        <div class="summary-card">
            <span>Quick Buy</span>
            <form method="post" action="buy.php" class="stock-form" style="margin-top: 8px;">
                <input type="hidden" name="instrument_key" value="<?php echo escape($symbol); ?>">
                <input type="hidden" name="display_name" value="<?php echo escape($displayName); ?>">
                <input type="hidden" name="return_to" value="<?php echo escape("stock.php?symbol=" . $symbol); ?>">
                <input type="number" name="qty" min="1" required>
                <button type="submit">Buy</button>
            </form>
        </div>
        <div class="summary-card">
            <span>Quick Sell</span>
            <form method="post" action="sell.php" class="stock-form" style="margin-top: 8px;">
                <input type="hidden" name="instrument_key" value="<?php echo escape($symbol); ?>">
                <input type="hidden" name="display_name" value="<?php echo escape($displayName); ?>">
                <input type="hidden" name="return_to" value="<?php echo escape("stock.php?symbol=" . $symbol); ?>">
                <input type="number" name="qty" min="1" max="<?php echo $holdingQty > 0 ? $holdingQty : 1; ?>" required>
                <button type="submit" class="sell-button">Sell</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const selectedSymbol = "<?php echo escape($symbol); ?>";
fetch("chart_data.php?symbol=" + encodeURIComponent(selectedSymbol))
    .then(response => response.json())
    .then(data => {
        const labels = Array.isArray(data.labels) ? data.labels : [];
        const prices = Array.isArray(data.prices) ? data.prices : [];
        const ctx = document.getElementById("stock-chart");
        if (!ctx) return;

        if (prices.length === 0) {
            ctx.outerHTML = "<p class='help-text'>No chart data yet.</p>";
            return;
        }

        const up = prices[prices.length - 1] >= prices[0];
        const color = up ? "#2d7a46" : "#b23a3a";

        new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [{
                    data: prices,
                    borderColor: color,
                    backgroundColor: up ? "rgba(45,122,70,0.10)" : "rgba(178,58,58,0.10)",
                    fill: true,
                    tension: 0.25,
                    pointRadius: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return "Rs. " + value;
                            }
                        }
                    }
                }
            }
        });
    });
</script>
