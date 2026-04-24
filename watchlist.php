<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
$userId = (int) $user["id"];
$GLOBALS["preloaded_flash"] = get_flash();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$watchlist = get_user_watchlist($conn, $userId);
$watchlistStats = [];
$positiveMoves = 0;

foreach ($watchlist as $row) {
    $symbol = strtoupper($row["symbol"]);
    $quote = get_live_quote_fast($symbol);
    $price = $quote && isset($quote["last_price"]) ? (float) $quote["last_price"] : 0;
    $changePct = $quote && isset($quote["change_percent"]) ? (float) $quote["change_percent"] : 0;
    if ($changePct >= 0) {
        $positiveMoves++;
    }
    $watchlistStats[] = [
        "symbol" => $symbol,
        "display_name" => $row["display_name"],
        "price" => $price,
        "changePct" => $changePct
    ];
}

render_header("Watchlist", "watchlist");
?>

<div class="portfolio-hero">
    <div class="section-head">
        <div>
            <p class="small-title">Market radar</p>
            <h2>Your Watchlist</h2>
            <p class="help-text">Keep a clean shortlist of names you want to monitor before taking a paper trade.</p>
        </div>
    </div>
    <div class="portfolio-metrics-grid">
        <div class="summary-card feature-card">
            <span>Tracked names</span>
            <strong id="watchlist-tracked"><?php echo count($watchlistStats); ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Positive today</span>
            <strong id="watchlist-positive"><?php echo $positiveMoves; ?></strong>
        </div>
        <div class="summary-card feature-card">
            <span>Under pressure</span>
            <strong id="watchlist-negative"><?php echo count($watchlistStats) - $positiveMoves; ?></strong>
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="section-box">
        <div class="table-box elevated-table">
            <table class="stock-table responsive-table">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Name</th>
                        <th>Live Price</th>
                        <th>Change %</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="watchlist-table-body">
                    <?php if (count($watchlistStats) === 0) : ?>
                        <tr><td colspan="5">No watchlist stocks yet.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($watchlistStats as $row) : ?>
                        <tr>
                            <td data-label="Symbol"><a class="stock-link" href="stock.php?symbol=<?php echo urlencode($row["symbol"]); ?>"><?php echo escape($row["symbol"]); ?></a></td>
                            <td data-label="Name"><?php echo escape($row["display_name"]); ?></td>
                            <td data-label="Live Price"><?php echo $row["price"] > 0 ? "Rs. " . number_format($row["price"], 2) : "NA"; ?></td>
                            <td data-label="Change %" class="<?php echo $row["changePct"] >= 0 ? "profit" : "loss"; ?>"><?php echo ($row["changePct"] >= 0 ? "+" : "") . number_format($row["changePct"], 2); ?>%</td>
                            <td data-label="Action">
                                <form method="post" action="toggle_watchlist.php">
                                    <input type="hidden" name="symbol" value="<?php echo escape($row["symbol"]); ?>">
                                    <input type="hidden" name="display_name" value="<?php echo escape($row["display_name"]); ?>">
                                    <input type="hidden" name="return_to" value="watchlist.php">
                                    <button type="submit" class="sell-button">Remove</button>
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
            <span>Watchlist note</span>
            <h4>Use this like a pre-trade shortlist</h4>
            <p class="help-text">Keep only names you actively track. Once a setup is ready, open the stock page and place the paper trade from there.</p>
        </div>
        <div class="summary-card insight-panel">
            <span>Quick idea</span>
            <h4>Balance the board</h4>
            <p class="help-text">Try mixing leaders, laggards, and one or two high-volume breakout candidates so your radar is not too one-sided.</p>
        </div>
    </div>
</div>

<?php render_footer(); ?>

<script>
(function () {
    const ui = window.TradeSimUI;
    if (!ui) return;

    const body = document.getElementById("watchlist-table-body");

    function renderRows(rows) {
        if (!body) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            body.innerHTML = "<tr><td colspan='5'>No watchlist stocks yet.</td></tr>";
            return;
        }

        body.innerHTML = rows.map(row => {
            const changeClass = Number(row.changePct) >= 0 ? "profit" : "loss";
            const change = (Number(row.changePct) >= 0 ? "+" : "") + Number(row.changePct).toFixed(2) + "%";
            const price = Number(row.price) > 0 ? ui.formatCurrency(row.price) : "NA";
            const symbol = ui.escapeHtml(row.symbol);
            const displayName = ui.escapeHtml(row.displayName);
            return `
                <tr>
                    <td data-label="Symbol"><a class="stock-link" href="stock.php?symbol=${encodeURIComponent(row.symbol)}">${symbol}</a></td>
                    <td data-label="Name">${displayName}</td>
                    <td data-label="Live Price">${price}</td>
                    <td data-label="Change %" class="${changeClass}">${change}</td>
                    <td data-label="Action">
                        <form method="post" action="toggle_watchlist.php">
                            <input type="hidden" name="symbol" value="${symbol}">
                            <input type="hidden" name="display_name" value="${displayName}">
                            <input type="hidden" name="return_to" value="watchlist.php">
                            <button type="submit" class="sell-button">Remove</button>
                        </form>
                    </td>
                </tr>
            `;
        }).join("");
    }

    function applyWatchlist(payload) {
        ui.hydrateHeader(payload);

        if (payload.stats) {
            document.getElementById("watchlist-tracked").textContent = payload.stats.tracked;
            document.getElementById("watchlist-positive").textContent = payload.stats.positive;
            document.getElementById("watchlist-negative").textContent = payload.stats.negative;
        }

        renderRows(payload.rows || []);
    }

    async function refreshWatchlist() {
        try {
            const response = await fetch("live_data.php?view=watchlist", { cache: "no-store" });
            const payload = await response.json();
            if (!payload.ok) return;
            applyWatchlist(payload);
        } catch (error) {
        }
    }

    refreshWatchlist();
    window.setInterval(refreshWatchlist, 20000);
})();
</script>
