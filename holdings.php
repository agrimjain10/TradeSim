<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
$userId = (int) $user["id"];

$totalInvested = 0;
$currentValue = 0;
$holdingResult = mysqli_query($conn, "SELECT * FROM holdings WHERE user_id = $userId ORDER BY id DESC");

$holdingList = [];
$winningCount = 0;
$losingCount = 0;
$largestHolding = null;
while ($holdingRow = mysqli_fetch_assoc($holdingResult)) {
    $livePrice = (float) $holdingRow["buy_price"];

    if (isset($holdingRow["instrument_key"]) && $holdingRow["instrument_key"] !== "") {
        $quote = get_live_quote($holdingRow["instrument_key"]);
        if ($quote && isset($quote["last_price"])) {
            $livePrice = (float) $quote["last_price"];
        }
    }

    $holdingRow["current_price"] = $livePrice;
    $holdingValue = (float) $holdingRow["quantity"] * $livePrice;
    $holdingProfit = ($livePrice - (float) $holdingRow["buy_price"]) * (int) $holdingRow["quantity"];
    $holdingRow["holding_value"] = $holdingValue;
    $holdingRow["profit_value"] = $holdingProfit;
    $holdingRow["profit_percent"] = (float) $holdingRow["buy_price"] > 0 ? (($livePrice - (float) $holdingRow["buy_price"]) / (float) $holdingRow["buy_price"]) * 100 : 0;
    if ($holdingProfit >= 0) {
        $winningCount++;
    } else {
        $losingCount++;
    }
    if ($largestHolding === null || $holdingValue > $largestHolding["holding_value"]) {
        $largestHolding = $holdingRow;
    }
    $holdingList[] = $holdingRow;
    $totalInvested = $totalInvested + ($holdingRow["quantity"] * $holdingRow["buy_price"]);
    $currentValue = $currentValue + ($holdingRow["quantity"] * $livePrice);
}

$totalProfit = $currentValue - $totalInvested;
$totalReturn = $totalInvested > 0 ? ($totalProfit / $totalInvested) * 100 : 0;

render_header("Your Holdings", "holdings");
?>

<div class="portfolio-hero">
    <div class="section-head">
        <div>
            <p class="small-title">Portfolio book</p>
            <h2>Your Holdings</h2>
            <p class="help-text">See invested capital, live mark-to-market value, and how each position is behaving right now.</p>
        </div>
    </div>

    <div class="portfolio-metrics-grid">
        <div class="summary-card feature-card">
            <span>Total Invested</span>
            <strong>Rs. <?php echo number_format($totalInvested, 2); ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Current Value</span>
            <strong>Rs. <?php echo number_format($currentValue, 2); ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Total Profit / Loss</span>
            <strong class="<?php echo $totalProfit >= 0 ? 'profit' : 'loss'; ?>">
                <?php echo ($totalProfit >= 0 ? "+" : "") . "Rs. " . number_format($totalProfit, 2); ?>
            </strong>
        </div>
        <div class="summary-card feature-card">
            <span>Total Return</span>
            <strong class="<?php echo $totalReturn >= 0 ? 'profit' : 'loss'; ?>">
                <?php echo ($totalReturn >= 0 ? "+" : "") . number_format($totalReturn, 2); ?>%
            </strong>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="section-box">
        <div class="table-box elevated-table">
    <table class="stock-table">
        <tr>
            <th>Stock</th>
            <th>Qty</th>
            <th>Buy Price</th>
            <th>Current Price</th>
            <th>Return</th>
            <th>Current Value</th>
            <th>Sell Qty</th>
            <th>Action</th>
        </tr>

        <?php
        if (count($holdingList) == 0) {
            echo '<tr><td colspan="8">No holdings found.</td></tr>';
        }

        foreach ($holdingList as $holdingRow) {
            $profit = $holdingRow["profit_value"];
            $profitClass = $profit >= 0 ? "profit" : "loss";
            ?>
            <tr>
                <td>
                    <?php
                    $symbolOnly = strtoupper(str_replace(["NSE:", "BSE:"], "", ($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"])));
                    ?>
                    <div class="table-primary-cell">
                        <a class="stock-link" href="stock.php?symbol=<?php echo urlencode($symbolOnly); ?>">
                            <?php echo escape($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"]); ?>
                        </a>
                        <span><?php echo escape($symbolOnly); ?></span>
                    </div>
                </td>
                <td><?php echo (int) $holdingRow["quantity"]; ?></td>
                <td>Rs. <?php echo number_format((float) $holdingRow["buy_price"], 2); ?></td>
                <td>Rs. <?php echo number_format((float) $holdingRow["current_price"], 2); ?></td>
                <td class="<?php echo $profitClass; ?>">
                    <div><?php echo ($holdingRow["profit_percent"] >= 0 ? "+" : "") . number_format((float) $holdingRow["profit_percent"], 2); ?>%</div>
                    <small><?php echo ($profit >= 0 ? "+" : "") . "Rs. " . number_format((float) $profit, 2); ?></small>
                </td>
                <td>Rs. <?php echo number_format((float) $holdingRow["holding_value"], 2); ?></td>
                <td>
                    <form method="post" action="sell.php" class="stock-form">
                        <input type="hidden" name="instrument_key" value="<?php echo escape($holdingRow["instrument_key"]); ?>">
                        <input type="hidden" name="display_name" value="<?php echo escape($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"]); ?>">
                        <input type="hidden" name="return_to" value="holdings.php">
                        <input type="number" name="qty" min="1" max="<?php echo (int) $holdingRow["quantity"]; ?>" required>
                </td>
                <td>
                        <button type="submit" class="sell-button">Sell</button>
                    </form>
                </td>
            </tr>
        <?php } ?>
    </table>
        </div>
    </div>
    <div class="section-box sidebar-stack">
        <div class="summary-card insight-panel">
            <span>Portfolio breadth</span>
            <h4>How the book is distributed</h4>
            <div class="metric-pair">
                <div><strong><?php echo count($holdingList); ?></strong><span>Open positions</span></div>
                <div><strong><?php echo $winningCount; ?></strong><span>In profit</span></div>
                <div><strong><?php echo $losingCount; ?></strong><span>In loss</span></div>
            </div>
        </div>
        <div class="summary-card insight-panel">
            <span>Largest allocation</span>
            <h4><?php echo $largestHolding ? escape($largestHolding["display_name"] ? $largestHolding["display_name"] : $largestHolding["stock_name"]) : "No holdings"; ?></h4>
            <p class="help-text">
                <?php if ($largestHolding) : ?>
                    Current exposure is Rs. <?php echo number_format((float) $largestHolding["holding_value"], 2); ?> across <?php echo (int) $largestHolding["quantity"]; ?> shares.
                <?php else : ?>
                    Add positions to start building the portfolio.
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<?php render_footer(); ?>
