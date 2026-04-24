<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
if (!$user) {
    redirect_to("login.php");
}
$GLOBALS["preloaded_flash"] = get_flash();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$marketMood = get_market_mood(true);
$query = isset($_GET["q"]) ? trim($_GET["q"]) : "";
$searchResults = $query !== "" ? search_market_instruments($query) : [];

$indexMap = session_cache_get("nse_indices", 300);
$indexMap = is_array($indexMap) ? $indexMap : [];
$niftyRow = isset($indexMap["NIFTY 50"]) ? $indexMap["NIFTY 50"] : null;
$bankNiftyRow = isset($indexMap["NIFTY BANK"]) ? $indexMap["NIFTY BANK"] : null;
$next50Row = isset($indexMap["NIFTY NEXT 50"]) ? $indexMap["NIFTY NEXT 50"] : null;
$sensexRow = isset($indexMap["SENSEX"]) ? $indexMap["SENSEX"] : null;

$topActive = session_cache_get("nse_index_stocks_NIFTY 50", 300);
$topActive = is_array($topActive) ? nse_get_top_active_stocks(4) : [];
$watchlistCount = count(get_user_watchlist($conn, (int) $user["id"]));
$portfolioRows = mysqli_query($conn, "SELECT quantity, buy_price, instrument_key, display_name, stock_name FROM holdings WHERE user_id = " . (int) $user["id"]);
$investedValue = 0;
$liveValue = 0;
while ($portfolioRows && $holding = mysqli_fetch_assoc($portfolioRows)) {
    $investedValue += (float) $holding["quantity"] * (float) $holding["buy_price"];
    $displayName = $holding["display_name"] ? $holding["display_name"] : $holding["stock_name"];
    $livePrice = resolve_holding_display_price($holding["instrument_key"], $displayName, $holding["buy_price"]);
    $liveValue += (float) $holding["quantity"] * $livePrice;
}
$portfolioPnL = $liveValue - $investedValue;
$portfolioReturn = $investedValue > 0 ? ($portfolioPnL / $investedValue) * 100 : 0;

$topBought = mysqli_query($conn, "SELECT display_name, SUM(quantity) AS total_qty FROM trades WHERE side = 'BUY' GROUP BY display_name ORDER BY total_qty DESC LIMIT 4");
$topSold = mysqli_query($conn, "SELECT display_name, SUM(quantity) AS total_qty FROM trades WHERE side = 'SELL' GROUP BY display_name ORDER BY total_qty DESC LIMIT 4");

render_header("Dashboard", "dashboard");
?>

<div class="hero-panel premium-hero">
    <div class="welcome-left">
        <p class="small-title">Market overview</p>
        <h2>Welcome back, <?php echo escape($user["username"]); ?></h2>
        <p class="help-text">A calmer trading workspace with fast market context, live movers, and your paper portfolio in one glance.</p>
        <div class="hero-cta-row">
            <a class="primary-link-button" href="watchlist.php">Open watchlist</a>
            <a class="secondary-link-button" href="holdings.php">Review holdings</a>
        </div>
        <div class="hero-stat-grid">
            <div class="hero-stat-card">
                <span>Virtual balance</span>
                <strong>Rs. <?php echo number_format((float) $user["balance"], 2); ?></strong>
            </div>
            <div class="hero-stat-card">
                <span>Portfolio value</span>
                <strong id="portfolio-value">Rs. <?php echo number_format($liveValue, 2); ?></strong>
            </div>
            <div class="hero-stat-card">
                <span>Watchlist names</span>
                <strong><?php echo $watchlistCount; ?></strong>
            </div>
        </div>
    </div>
    <div class="welcome-right hero-aside">
        <div class="mood-card spotlight-card">
            <span>Market Mood</span>
            <strong id="market-mood-title"><?php echo escape($marketMood["title"]); ?></strong>
            <p id="market-mood-text"><?php echo escape($marketMood["text"]); ?></p>
        </div>
        <div class="mini-insight-list">
            <div>
                <span>Portfolio P&amp;L</span>
                <strong id="portfolio-pnl" class="<?php echo $portfolioPnL >= 0 ? "profit" : "loss"; ?>">
                    <?php echo ($portfolioPnL >= 0 ? "+" : "") . "Rs. " . number_format($portfolioPnL, 2); ?>
                </strong>
            </div>
            <div>
                <span>Total return</span>
                <strong id="portfolio-return" class="<?php echo $portfolioReturn >= 0 ? "profit" : "loss"; ?>">
                    <?php echo ($portfolioReturn >= 0 ? "+" : "") . number_format($portfolioReturn, 2); ?>%
                </strong>
            </div>
        </div>
    </div>
</div>

<div class="section-box">
    <div class="section-head">
        <h3>Market pulse</h3>
        <p class="help-text">Four headline benchmarks with quick access into the full quote page.</p>
    </div>
    <div class="market-overview-grid">
        <a class="summary-card feature-card" data-index-card="NIFTY 50" href="stock.php?symbol=NIFTY%2050">
            <span>NIFTY 50</span>
            <strong data-index-last><?php echo $niftyRow ? number_format((float) $niftyRow["last"], 2) : "NA"; ?></strong>
            <div data-index-change class="<?php echo ($niftyRow && isset($niftyRow["percentChange"]) && (float) $niftyRow["percentChange"] >= 0) ? "profit" : "loss"; ?>">
                <?php echo $niftyRow ? ((float) $niftyRow["percentChange"] >= 0 ? "+" : "") . number_format((float) $niftyRow["percentChange"], 2) . "%" : "NA"; ?>
            </div>
            <p class="card-note">Benchmark strength for the broader market.</p>
        </a>
        <a class="summary-card feature-card" data-index-card="SENSEX" href="stock.php?symbol=SENSEX">
            <span>SENSEX</span>
            <strong data-index-last><?php echo $sensexRow ? number_format((float) $sensexRow["last"], 2) : "NA"; ?></strong>
            <div data-index-change class="<?php echo ($sensexRow && isset($sensexRow["percentChange"]) && (float) $sensexRow["percentChange"] >= 0) ? "profit" : "loss"; ?>">
                <?php echo $sensexRow ? ((float) $sensexRow["percentChange"] >= 0 ? "+" : "") . number_format((float) $sensexRow["percentChange"], 2) . "%" : "NA"; ?>
            </div>
            <p class="card-note">Large-cap sentiment snapshot.</p>
        </a>
        <a class="summary-card feature-card" data-index-card="NIFTY BANK" href="stock.php?symbol=NIFTY%20BANK">
            <span>BANK NIFTY</span>
            <strong data-index-last><?php echo $bankNiftyRow ? number_format((float) $bankNiftyRow["last"], 2) : "NA"; ?></strong>
            <div data-index-change class="<?php echo ($bankNiftyRow && isset($bankNiftyRow["percentChange"]) && (float) $bankNiftyRow["percentChange"] >= 0) ? "profit" : "loss"; ?>">
                <?php echo $bankNiftyRow ? ((float) $bankNiftyRow["percentChange"] >= 0 ? "+" : "") . number_format((float) $bankNiftyRow["percentChange"], 2) . "%" : "NA"; ?>
            </div>
            <p class="card-note">Financial sector leadership and volatility.</p>
        </a>
        <a class="summary-card feature-card" data-index-card="NIFTY NEXT 50" href="stock.php?symbol=NIFTY%20NEXT%2050">
            <span>NIFTY NEXT 50</span>
            <strong data-index-last><?php echo $next50Row ? number_format((float) $next50Row["last"], 2) : "NA"; ?></strong>
            <div data-index-change class="<?php echo ($next50Row && isset($next50Row["percentChange"]) && (float) $next50Row["percentChange"] >= 0) ? "profit" : "loss"; ?>">
                <?php echo $next50Row ? ((float) $next50Row["percentChange"] >= 0 ? "+" : "") . number_format((float) $next50Row["percentChange"], 2) . "%" : "NA"; ?>
            </div>
            <p class="card-note">Emerging momentum just beyond the top fifty.</p>
        </a>
    </div>
</div>

<div class="dashboard-grid">
    <div class="section-box">
        <div class="section-head">
            <h3>Most active on NSE</h3>
            <p class="help-text">Top traded names right now, laid out as discovery cards.</p>
        </div>
        <div class="active-stock-grid" id="active-stock-grid">
            <?php if (count($topActive) === 0) : ?>
                <div class="summary-card feature-card"><div>Live active stocks unavailable right now.</div></div>
            <?php endif; ?>

            <?php foreach ($topActive as $row) : ?>
                <a class="summary-card active-stock-card" href="stock.php?symbol=<?php echo urlencode($row["symbol"]); ?>">
                    <div class="card-symbol-row">
                        <span><?php echo escape($row["symbol"]); ?></span>
                        <div class="mini-trend <?php echo (float) $row["change_percent"] >= 0 ? "up" : "down"; ?>"></div>
                    </div>
                    <strong>Rs. <?php echo number_format((float) $row["price"], 2); ?></strong>
                    <div class="<?php echo (float) $row["change_percent"] >= 0 ? "profit" : "loss"; ?>">
                        <?php echo ((float) $row["change_percent"] >= 0 ? "+" : "") . number_format((float) $row["change_percent"], 2); ?>%
                    </div>
                    <p class="card-note">Volume <?php echo number_format((float) $row["volume"], 0); ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="section-box sidebar-stack">
        <div class="summary-card insight-panel">
            <span>Paper trading trends</span>
            <h4>Where users are rotating capital</h4>
            <div class="insight-split">
                <div>
                    <p class="muted-label">Most bought</p>
                    <?php
                    $hasBought = false;
                    while ($row = mysqli_fetch_assoc($topBought)) {
                        $hasBought = true;
                        $symbol = strtoupper(str_replace(["NSE:", "BSE:"], "", $row["display_name"]));
                        echo "<div class='trend-line'><a class='stock-link' href='stock.php?symbol=" . urlencode($symbol) . "'>" . escape($row["display_name"]) . "</a><strong>" . (int) $row["total_qty"] . "</strong></div>";
                    }
                    if (!$hasBought) {
                        echo "<div class='empty-inline'>No buy trades yet.</div>";
                    }
                    ?>
                </div>
                <div>
                    <p class="muted-label">Most sold</p>
                    <?php
                    $hasSold = false;
                    while ($row = mysqli_fetch_assoc($topSold)) {
                        $hasSold = true;
                        $symbol = strtoupper(str_replace(["NSE:", "BSE:"], "", $row["display_name"]));
                        echo "<div class='trend-line'><a class='stock-link' href='stock.php?symbol=" . urlencode($symbol) . "'>" . escape($row["display_name"]) . "</a><strong>" . (int) $row["total_qty"] . "</strong></div>";
                    }
                    if (!$hasSold) {
                        echo "<div class='empty-inline'>No sell trades yet.</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
        <div class="summary-card quick-access-panel">
            <span>Quick access</span>
            <h4>Move between your core screens</h4>
            <a class="quick-link-row" href="watchlist.php"><strong>Watchlist</strong><em>Scan favorites and remove names fast</em></a>
            <a class="quick-link-row" href="holdings.php"><strong>Holdings</strong><em>Review current book and realize paper P&amp;L</em></a>
        </div>
    </div>
</div>

<?php if ($query !== "") : ?>
<div class="section-box">
    <div class="section-head">
        <h3>Search Results</h3>
    </div>
    <div class="table-box">
    <table class="stock-table">
        <tr>
            <th>Symbol</th>
            <th>Name</th>
            <th>Price</th>
            <th>Change</th>
            <th>Action</th>
        </tr>
        <?php if (count($searchResults) === 0) : ?>
            <tr><td colspan="5">No stock found.</td></tr>
        <?php endif; ?>

        <?php foreach ($searchResults as $item) : ?>
            <?php
            $symbol = isset($item["trading_symbol"]) ? strtoupper($item["trading_symbol"]) : "";
            $name = isset($item["name"]) ? $item["name"] : $symbol;
            $quote = get_live_quote($symbol);
            $price = $quote && isset($quote["last_price"]) ? (float) $quote["last_price"] : 0;
            $change = $quote && isset($quote["change_percent"]) ? (float) $quote["change_percent"] : 0;
            ?>
            <tr>
                <td><a class="stock-link" href="stock.php?symbol=<?php echo urlencode($symbol); ?>"><?php echo escape($symbol); ?></a></td>
                <td><?php echo escape($name); ?></td>
                <td><?php echo $price > 0 ? "Rs. " . number_format($price, 2) : "NA"; ?></td>
                <td class="<?php echo $change >= 0 ? "profit" : "loss"; ?>"><?php echo ($change >= 0 ? "+" : "") . number_format($change, 2); ?>%</td>
                <td><a class="stock-link" href="stock.php?symbol=<?php echo urlencode($symbol); ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>

<script>
(function () {
    const ui = window.TradeSimUI;
    if (!ui) return;

    const portfolioValueNode = document.getElementById("portfolio-value");
    const pnlNode = document.getElementById("portfolio-pnl");
    const returnNode = document.getElementById("portfolio-return");
    const moodTitleNode = document.getElementById("market-mood-title");
    const moodTextNode = document.getElementById("market-mood-text");
    const activeGrid = document.getElementById("active-stock-grid");

    function renderActiveStocks(rows) {
        if (!activeGrid || !Array.isArray(rows)) return;

        if (rows.length === 0) {
            activeGrid.innerHTML = "<div class='summary-card feature-card'><div>Live active stocks unavailable right now.</div></div>";
            return;
        }

        activeGrid.innerHTML = rows.map(row => {
            const direction = Number(row.change_percent) >= 0 ? "profit" : "loss";
            const trend = Number(row.change_percent) >= 0 ? "up" : "down";
            const price = Number(row.price || 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const volume = Number(row.volume || 0).toLocaleString("en-IN", { maximumFractionDigits: 0 });
            const change = (Number(row.change_percent) >= 0 ? "+" : "") + Number(row.change_percent || 0).toFixed(2) + "%";
            const symbol = ui.escapeHtml(row.symbol);
            return `
                <a class="summary-card active-stock-card fade-rise" href="stock.php?symbol=${encodeURIComponent(row.symbol)}">
                    <div class="card-symbol-row">
                        <span>${symbol}</span>
                        <div class="mini-trend ${trend}"></div>
                    </div>
                    <strong>Rs. ${price}</strong>
                    <div class="${direction}">${change}</div>
                    <p class="card-note">Volume ${volume}</p>
                </a>
            `;
        }).join("");
    }

    function applyDashboard(payload) {
        ui.hydrateHeader(payload);

        if (payload.hero) {
            if (portfolioValueNode) {
                portfolioValueNode.textContent = ui.formatCurrency(payload.hero.portfolioValue);
            }
            if (pnlNode) {
                pnlNode.textContent = (Number(payload.hero.portfolioPnL) >= 0 ? "+" : "") + ui.formatCurrency(payload.hero.portfolioPnL);
                ui.applyDirection(pnlNode, payload.hero.portfolioPnL);
            }
            if (returnNode) {
                returnNode.textContent = ui.formatSignedPercent(payload.hero.portfolioReturn);
                ui.applyDirection(returnNode, payload.hero.portfolioReturn);
            }
        }

        if (payload.marketMood) {
            if (moodTitleNode) moodTitleNode.textContent = payload.marketMood.title;
            if (moodTextNode) moodTextNode.textContent = payload.marketMood.text;
        }

        if (Array.isArray(payload.marketStrip)) {
            payload.marketStrip.forEach(item => {
                const card = document.querySelector('[data-index-card="' + item.symbol.replace(/"/g, '\\"') + '"]');
                if (!card) return;
                const lastNode = card.querySelector("[data-index-last]");
                const changeNode = card.querySelector("[data-index-change]");

                if (lastNode) {
                    lastNode.textContent = item.last > 0 ? Number(item.last).toLocaleString("en-IN", {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) : "NA";
                }

                if (changeNode) {
                    changeNode.textContent = item.last > 0 ? ui.formatSignedPercent(item.percentChange) : "NA";
                    ui.applyDirection(changeNode, item.percentChange);
                }
            });
        }

        renderActiveStocks(payload.topActive || []);
    }

    async function refreshDashboard() {
        try {
            const response = await fetch("live_data.php?view=dashboard", { cache: "no-store" });
            const payload = await response.json();
            if (!payload.ok) return;
            applyDashboard(payload);
        } catch (error) {
        }
    }

    refreshDashboard();
    window.setInterval(refreshDashboard, 20000);
})();
</script>
