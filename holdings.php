<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
$userId = (int) $user["id"];
$GLOBALS["preloaded_flash"] = get_flash();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$totalInvested = 0;
$currentValue = 0;
$holdingResult = mysqli_query($conn, "SELECT * FROM holdings WHERE user_id = $userId ORDER BY id DESC");

$holdingList = [];
$winningCount = 0;
$losingCount = 0;
$largestHolding = null;
while ($holdingResult && ($holdingRow = mysqli_fetch_assoc($holdingResult))) {
    $displayName = $holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"];
    $livePrice = resolve_holding_display_price($holdingRow["instrument_key"], $displayName, $holdingRow["buy_price"]);

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
    $totalInvested += $holdingRow["quantity"] * $holdingRow["buy_price"];
    $currentValue += $holdingRow["quantity"] * $livePrice;
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
            <strong id="holdings-invested">Rs. <?php echo number_format($totalInvested, 2); ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Current Value</span>
            <strong id="holdings-current">Rs. <?php echo number_format($currentValue, 2); ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Total Profit / Loss</span>
            <strong id="holdings-profit" class="<?php echo $totalProfit >= 0 ? 'profit' : 'loss'; ?>">
                <?php echo ($totalProfit >= 0 ? "+" : "") . "Rs. " . number_format($totalProfit, 2); ?>
            </strong>
        </div>
        <div class="summary-card feature-card">
            <span>Total Return</span>
            <strong id="holdings-return" class="<?php echo $totalReturn >= 0 ? 'profit' : 'loss'; ?>">
                <?php echo ($totalReturn >= 0 ? "+" : "") . number_format($totalReturn, 2); ?>%
            </strong>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="section-box">
        <div class="table-box elevated-table">
            <table class="stock-table responsive-table">
                <thead>
                    <tr>
                        <th>Stock</th>
                        <th>Qty</th>
                        <th>Buy Price</th>
                        <th>Current Price</th>
                        <th>Return</th>
                        <th>Current Value</th>
                        <th>Sell</th>
                    </tr>
                </thead>
                <tbody id="holdings-table-body">
                    <?php if (count($holdingList) == 0) : ?>
                        <tr><td colspan="7">No holdings found.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($holdingList as $holdingRow) : ?>
                        <?php
                        $profit = $holdingRow["profit_value"];
                        $profitClass = $profit >= 0 ? "profit" : "loss";
                        $symbolOnly = strtoupper(str_replace(["NSE:", "BSE:"], "", ($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"])));
                        ?>
                        <tr>
                            <td data-label="Stock">
                                <div class="table-primary-cell">
                                    <a class="stock-link" href="stock.php?symbol=<?php echo urlencode($symbolOnly); ?>">
                                        <?php echo escape($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"]); ?>
                                    </a>
                                    <span><?php echo escape($symbolOnly); ?></span>
                                </div>
                            </td>
                            <td data-label="Qty"><?php echo (int) $holdingRow["quantity"]; ?></td>
                            <td data-label="Buy Price">Rs. <?php echo number_format((float) $holdingRow["buy_price"], 2); ?></td>
                            <td data-label="Current Price">Rs. <?php echo number_format((float) $holdingRow["current_price"], 2); ?></td>
                            <td data-label="Return" class="<?php echo $profitClass; ?>">
                                <div><?php echo ($holdingRow["profit_percent"] >= 0 ? "+" : "") . number_format((float) $holdingRow["profit_percent"], 2); ?>%</div>
                                <small><?php echo ($profit >= 0 ? "+" : "") . "Rs. " . number_format((float) $profit, 2); ?></small>
                            </td>
                            <td data-label="Current Value">Rs. <?php echo number_format((float) $holdingRow["holding_value"], 2); ?></td>
                            <td data-label="Sell">
                                <form method="post" action="sell.php" class="stock-form">
                                    <input type="hidden" name="instrument_key" value="<?php echo escape($holdingRow["instrument_key"]); ?>">
                                    <input type="hidden" name="display_name" value="<?php echo escape($holdingRow["display_name"] ? $holdingRow["display_name"] : $holdingRow["stock_name"]); ?>">
                                    <input type="hidden" name="return_to" value="holdings.php">
                                    <input type="number" name="qty" min="1" max="<?php echo (int) $holdingRow["quantity"]; ?>" required>
                                    <button type="submit" class="sell-button">Sell</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="section-box sidebar-stack">
        <div class="summary-card insight-panel">
            <span>Portfolio breadth</span>
            <h4>How the book is distributed</h4>
            <div class="metric-pair">
                <div><strong id="breadth-open"><?php echo count($holdingList); ?></strong><span>Open positions</span></div>
                <div><strong id="breadth-winning"><?php echo $winningCount; ?></strong><span>In profit</span></div>
                <div><strong id="breadth-losing"><?php echo $losingCount; ?></strong><span>In loss</span></div>
            </div>
        </div>
        <div class="summary-card insight-panel">
            <span>Largest allocation</span>
            <h4 id="largest-name"><?php echo $largestHolding ? escape($largestHolding["display_name"] ? $largestHolding["display_name"] : $largestHolding["stock_name"]) : "No holdings"; ?></h4>
            <p class="help-text" id="largest-text">
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

<script>
(function () {
    const ui = window.TradeSimUI;
    if (!ui) return;

    const body = document.getElementById("holdings-table-body");

    function renderRows(rows) {
        if (!body) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            body.innerHTML = "<tr><td colspan='7'>No holdings found.</td></tr>";
            return;
        }

        body.innerHTML = rows.map(row => {
            const profitClass = Number(row.profitValue) >= 0 ? "profit" : "loss";
            const profitPercent = (Number(row.profitPercent) >= 0 ? "+" : "") + Number(row.profitPercent).toFixed(2) + "%";
            const profitValue = (Number(row.profitValue) >= 0 ? "+" : "") + ui.formatCurrency(row.profitValue);
            const displayName = ui.escapeHtml(row.displayName);
            const symbol = ui.escapeHtml(row.symbol);
            return `
                <tr>
                    <td data-label="Stock">
                        <div class="table-primary-cell">
                            <a class="stock-link" href="stock.php?symbol=${encodeURIComponent(row.symbol)}">${displayName}</a>
                            <span>${symbol}</span>
                        </div>
                    </td>
                    <td data-label="Qty">${row.quantity}</td>
                    <td data-label="Buy Price">${ui.formatCurrency(row.buyPrice)}</td>
                    <td data-label="Current Price">${ui.formatCurrency(row.currentPrice)}</td>
                    <td data-label="Return" class="${profitClass}">
                        <div>${profitPercent}</div>
                        <small>${profitValue}</small>
                    </td>
                    <td data-label="Current Value">${ui.formatCurrency(row.holdingValue)}</td>
                    <td data-label="Sell">
                        <form method="post" action="sell.php" class="stock-form">
                            <input type="hidden" name="instrument_key" value="${row.instrumentKey || row.symbol}">
                            <input type="hidden" name="display_name" value="${displayName}">
                            <input type="hidden" name="return_to" value="holdings.php">
                            <input type="number" name="qty" min="1" max="${row.quantity}" required>
                            <button type="submit" class="sell-button">Sell</button>
                        </form>
                    </td>
                </tr>
            `;
        }).join("");
    }

    function applyHoldings(payload) {
        ui.hydrateHeader(payload);

        if (payload.summary) {
            document.getElementById("holdings-invested").textContent = ui.formatCurrency(payload.summary.invested);
            document.getElementById("holdings-current").textContent = ui.formatCurrency(payload.summary.current);

            const profitNode = document.getElementById("holdings-profit");
            const returnNode = document.getElementById("holdings-return");

            profitNode.textContent = (Number(payload.summary.profit) >= 0 ? "+" : "") + ui.formatCurrency(payload.summary.profit);
            returnNode.textContent = ui.formatSignedPercent(payload.summary.return);

            ui.applyDirection(profitNode, payload.summary.profit);
            ui.applyDirection(returnNode, payload.summary.return);
        }

        if (payload.breadth) {
            document.getElementById("breadth-open").textContent = payload.breadth.openPositions;
            document.getElementById("breadth-winning").textContent = payload.breadth.winning;
            document.getElementById("breadth-losing").textContent = payload.breadth.losing;
        }

        if (payload.largest) {
            document.getElementById("largest-name").textContent = payload.largest.name;
            document.getElementById("largest-text").textContent = "Current exposure is " + ui.formatCurrency(payload.largest.holdingValue) + " across " + payload.largest.quantity + " shares.";
        } else {
            document.getElementById("largest-name").textContent = "No holdings";
            document.getElementById("largest-text").textContent = "Add positions to start building the portfolio.";
        }

        renderRows(payload.rows || []);
    }

    async function refreshHoldings() {
        try {
            const response = await fetch("live_data.php?view=holdings", { cache: "no-store" });
            const payload = await response.json();
            if (!payload.ok) return;
            applyHoldings(payload);
        } catch (error) {
        }
    }

    refreshHoldings();
    window.setInterval(refreshHoldings, 20000);
})();
</script>
