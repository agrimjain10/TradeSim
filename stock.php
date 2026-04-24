<?php
require_once __DIR__ . "/layout.php";

require_login();
$user = current_user($conn);
$userId = (int) $user["id"];
$GLOBALS["preloaded_flash"] = get_flash();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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
$summary = summarize_quote($quote, $symbol);
$tradeSignal = get_trade_signal($quote);
record_price_history($symbol, $summary["lastPrice"]);

$holdingQty = 0;
$holdingAvg = 0;
if (!$isIndex) {
    $safeSymbol = mysqli_real_escape_string($conn, $symbol);
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

<div class="section-box stock-hero-panel">
    <div class="stock-header">
        <div class="stock-header-main">
            <p class="small-title">Stock detail</p>
            <h2><?php echo escape($symbol); ?></h2>
            <p class="help-text" id="stock-name"><?php echo escape($summary["name"] ? $summary["name"] : $displayName); ?></p>
        </div>
        <div class="stock-header-action">
            <form method="post" action="toggle_watchlist.php" class="stock-form">
                <input type="hidden" name="symbol" value="<?php echo escape($symbol); ?>">
                <input type="hidden" name="display_name" value="<?php echo escape($displayName); ?>">
                <input type="hidden" name="return_to" value="<?php echo escape("stock.php?symbol=" . $symbol); ?>">
                <button type="submit" id="watchlist-button"><?php echo $inWatchlist ? "Remove Watchlist" : "Add Watchlist"; ?></button>
            </form>
        </div>
    </div>

    <div class="stock-hero-meta">
        <div class="summary-card quote-highlight-card price-pop">
            <span>Live Price</span>
            <strong id="quote-last">Rs. <?php echo number_format($summary["lastPrice"], 2); ?></strong>
        </div>
        <div class="summary-card">
            <span>Change %</span>
            <strong id="quote-change" class="<?php echo $summary["changePercent"] >= 0 ? "profit" : "loss"; ?>">
                <?php echo ($summary["changePercent"] >= 0 ? "+" : "") . number_format($summary["changePercent"], 2); ?>%
            </strong>
        </div>
        <div class="summary-card">
            <span>Volume</span>
            <strong id="quote-volume"><?php echo $summary["volume"] > 0 ? number_format($summary["volume"], 0) : "NA"; ?></strong>
        </div>
    </div>
</div>

<div class="dashboard-grid stock-live-grid">
    <div class="section-box stock-chart-panel">
        <div class="section-head">
            <h3>Price Chart</h3>
            <p class="help-text">Recent trend for <?php echo escape($symbol); ?> with auto-refreshing live history.</p>
        </div>
        <div class="table-box chart-panel-box chart-stage">
            <div class="chart-grid-glow"></div>
            <canvas id="stock-chart" height="120"></canvas>
        </div>
    </div>
    <div class="section-box sidebar-stack">
        <div class="summary-card signal-card signal-<?php echo escape($tradeSignal["tone"]); ?>" id="trade-signal-card">
            <span>Paper trade cue</span>
            <h4 id="trade-signal-title"><?php echo escape($tradeSignal["title"]); ?></h4>
            <p class="help-text" id="trade-signal-text"><?php echo escape($tradeSignal["text"]); ?></p>
        </div>
        <div class="summary-card insight-panel">
            <span>Session pulse</span>
            <h4>Day range position</h4>
            <p class="help-text" id="range-position-text">
                <?php
                $range = max($summary["high"] - $summary["low"], 0.01);
                $rangePosition = (($summary["lastPrice"] - $summary["low"]) / $range) * 100;
                echo "Price is trading around " . number_format($rangePosition, 0) . "% of today's range.";
                ?>
            </p>
        </div>
    </div>
</div>

<div class="section-box">
    <div class="section-head">
        <h3>Market Details</h3>
        <p class="help-text">Day range, previous close, and current bid / ask.</p>
    </div>
    <div class="detail-grid">
        <div class="info-box">
            <span>Open</span>
            <strong id="quote-open">Rs. <?php echo number_format($summary["open"], 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Day High</span>
            <strong id="quote-high">Rs. <?php echo number_format($summary["high"], 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Day Low</span>
            <strong id="quote-low">Rs. <?php echo number_format($summary["low"], 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Prev Close</span>
            <strong id="quote-close">Rs. <?php echo number_format($summary["close"], 2); ?></strong>
        </div>
        <div class="info-box">
            <span>Best Bid</span>
            <strong id="quote-bid"><?php echo $summary["bestBid"] > 0 ? "Rs. " . number_format($summary["bestBid"], 2) : "NA"; ?></strong>
        </div>
        <div class="info-box">
            <span>Best Ask</span>
            <strong id="quote-ask"><?php echo $summary["bestAsk"] > 0 ? "Rs. " . number_format($summary["bestAsk"], 2) : "NA"; ?></strong>
        </div>
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
            <strong id="holding-qty"><?php echo $holdingQty; ?></strong>
            <div id="holding-avg">Avg Buy: Rs. <?php echo number_format($holdingAvg, 2); ?></div>
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
                <input type="number" id="sell-qty-input" name="qty" min="1" max="<?php echo $holdingQty > 0 ? $holdingQty : 1; ?>" required>
                <button type="submit" class="sell-button">Sell</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const ui = window.TradeSimUI;
    if (!ui) return;

    const selectedSymbol = <?php echo json_encode($symbol); ?>;
    const isIndex = <?php echo $isIndex ? "true" : "false"; ?>;
    const chartCanvas = document.getElementById("stock-chart");
    const signalCard = document.getElementById("trade-signal-card");
    const sellQtyInput = document.getElementById("sell-qty-input");
    let chart = null;

    function formatPlainNumber(value, digits = 2) {
        return Number(value || 0).toLocaleString("en-IN", {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    }

    function formatMaybeCurrency(value) {
        return Number(value) > 0 ? ui.formatCurrency(value) : "NA";
    }

    function updateRangePosition(quote) {
        const node = document.getElementById("range-position-text");
        if (!node) return;
        const high = Number(quote.high || 0);
        const low = Number(quote.low || 0);
        const last = Number(quote.lastPrice || 0);
        const range = Math.max(high - low, 0.01);
        const position = ((last - low) / range) * 100;
        node.textContent = "Price is trading around " + position.toFixed(0) + "% of today's range.";
    }

    function buildGradient(ctx, chartArea, positive) {
        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        if (positive) {
            gradient.addColorStop(0, "rgba(28, 130, 92, 0.32)");
            gradient.addColorStop(1, "rgba(28, 130, 92, 0.02)");
        } else {
            gradient.addColorStop(0, "rgba(198, 92, 56, 0.28)");
            gradient.addColorStop(1, "rgba(198, 92, 56, 0.02)");
        }
        return gradient;
    }

    function renderChart(labels, prices) {
        if (!chartCanvas || !Array.isArray(prices) || prices.length === 0) return;

        const positive = prices[prices.length - 1] >= prices[0];
        const strokeColor = positive ? "#12825c" : "#c65c38";

        if (!chart) {
            chart = new Chart(chartCanvas, {
                type: "line",
                data: {
                    labels: labels,
                    datasets: [{
                        data: prices,
                        borderColor: strokeColor,
                        borderWidth: 2.5,
                        backgroundColor: function (context) {
                            const chartRef = context.chart;
                            const area = chartRef.chartArea;
                            if (!area) {
                                return positive ? "rgba(28, 130, 92, 0.18)" : "rgba(198, 92, 56, 0.18)";
                            }
                            return buildGradient(chartRef.ctx, area, positive);
                        },
                        fill: true,
                        tension: 0.34,
                        pointRadius: function (context) {
                            return context.dataIndex === context.dataset.data.length - 1 ? 3.5 : 0;
                        },
                        pointHoverRadius: 5,
                        pointBackgroundColor: strokeColor
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: "index"
                    },
                    animation: {
                        duration: 650,
                        easing: "easeOutQuart"
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: "rgba(27, 24, 20, 0.92)",
                            displayColors: false,
                            callbacks: {
                                label: function (context) {
                                    return "Price " + ui.formatCurrency(context.parsed.y);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: "rgba(118, 105, 91, 0.08)" },
                            ticks: { color: "#7a6b5d", maxTicksLimit: 8 }
                        },
                        y: {
                            grid: { color: "rgba(118, 105, 91, 0.08)" },
                            ticks: {
                                color: "#7a6b5d",
                                callback: function (value) {
                                    return "Rs. " + Number(value).toLocaleString("en-IN", { maximumFractionDigits: 2 });
                                }
                            }
                        }
                    }
                }
            });
            return;
        }

        chart.data.labels = labels;
        chart.data.datasets[0].data = prices;
        chart.data.datasets[0].borderColor = strokeColor;
        chart.data.datasets[0].pointBackgroundColor = strokeColor;
        chart.data.datasets[0].backgroundColor = function (context) {
            const chartRef = context.chart;
            const area = chartRef.chartArea;
            if (!area) {
                return positive ? "rgba(28, 130, 92, 0.18)" : "rgba(198, 92, 56, 0.18)";
            }
            return buildGradient(chartRef.ctx, area, positive);
        };
        chart.update();
    }

    function applyStockPayload(payload) {
        ui.hydrateHeader(payload);

        if (!payload.quote) return;

        const quote = payload.quote;
        const changeNode = document.getElementById("quote-change");
        document.getElementById("stock-name").textContent = quote.name || selectedSymbol;
        document.getElementById("quote-last").textContent = ui.formatCurrency(quote.lastPrice);
        document.getElementById("quote-volume").textContent = Number(quote.volume) > 0 ? Number(quote.volume).toLocaleString("en-IN", { maximumFractionDigits: 0 }) : "NA";
        document.getElementById("quote-open").textContent = formatMaybeCurrency(quote.open);
        document.getElementById("quote-high").textContent = formatMaybeCurrency(quote.high);
        document.getElementById("quote-low").textContent = formatMaybeCurrency(quote.low);
        document.getElementById("quote-close").textContent = formatMaybeCurrency(quote.close);
        document.getElementById("quote-bid").textContent = formatMaybeCurrency(quote.bestBid);
        document.getElementById("quote-ask").textContent = formatMaybeCurrency(quote.bestAsk);

        if (changeNode) {
            changeNode.textContent = ui.formatSignedPercent(quote.changePercent);
            ui.applyDirection(changeNode, quote.changePercent);
        }

        if (payload.tradeSignal) {
            document.getElementById("trade-signal-title").textContent = payload.tradeSignal.title;
            document.getElementById("trade-signal-text").textContent = payload.tradeSignal.text;
            if (signalCard) {
                signalCard.classList.remove("signal-bullish", "signal-bearish", "signal-neutral");
                signalCard.classList.add("signal-" + payload.tradeSignal.tone);
            }
        }

        if (!isIndex && payload.holding) {
            document.getElementById("holding-qty").textContent = payload.holding.quantity;
            document.getElementById("holding-avg").textContent = "Avg Buy: " + ui.formatCurrency(payload.holding.averagePrice);
            if (sellQtyInput) {
                sellQtyInput.max = Math.max(1, Number(payload.holding.quantity || 1));
            }
        }

        updateRangePosition(quote);

        if (payload.chart) {
            renderChart(payload.chart.labels || [], payload.chart.prices || []);
        }
    }

    async function refreshStock() {
        try {
            const response = await fetch("live_data.php?view=stock&symbol=" + encodeURIComponent(selectedSymbol), { cache: "no-store" });
            const payload = await response.json();
            if (!payload.ok) return;
            applyStockPayload(payload);
        } catch (error) {
        }
    }

    refreshStock();
    window.setInterval(refreshStock, 18000);
})();
</script>
