<?php
require_once __DIR__ . "/app.php";

if (!isset($GLOBALS["layout_shutdown_registered"])) {
    $GLOBALS["layout_shutdown_registered"] = true;
    register_shutdown_function(function () {
        if (empty($GLOBALS["layout_footer_rendered"])) {
            return;
        }
        ?>
    <script>
    (function () {
        window.TradeSimUI = {
            escapeHtml(value) {
                return String(value ?? "").replace(/[&<>"']/g, function (character) {
                    return ({
                        "&": "&amp;",
                        "<": "&lt;",
                        ">": "&gt;",
                        "\"": "&quot;",
                        "'": "&#39;"
                    })[character];
                });
            },
            formatCurrency(value) {
                const amount = Number(value || 0);
                return "Rs. " + amount.toLocaleString("en-IN", {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            },
            formatSignedPercent(value) {
                const number = Number(value || 0);
                return (number >= 0 ? "+" : "") + number.toFixed(2) + "%";
            },
            applyDirection(node, value) {
                if (!node) return;
                node.classList.remove("profit", "loss", "neutral");
                if (Number(value) > 0) {
                    node.classList.add("profit");
                } else if (Number(value) < 0) {
                    node.classList.add("loss");
                } else {
                    node.classList.add("neutral");
                }
            },
            hydrateHeader(payload) {
                if (!payload) return;

                const balanceNode = document.querySelector("[data-live-balance]");
                if (balanceNode && typeof payload.balance !== "undefined") {
                    balanceNode.textContent = window.TradeSimUI.formatCurrency(payload.balance);
                }

                if (Array.isArray(payload.marketStrip)) {
                    payload.marketStrip.forEach(item => {
                        const chip = document.querySelector('[data-market-symbol="' + item.symbol.replace(/"/g, '\\"') + '"]');
                        if (!chip) return;

                        const lastNode = chip.querySelector("[data-market-last]");
                        const changeNode = chip.querySelector("[data-market-change]");

                        if (lastNode) {
                            lastNode.textContent = item.last > 0 ? Number(item.last).toLocaleString("en-IN", {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }) : "NA";
                        }

                        if (changeNode) {
                            changeNode.textContent = item.last > 0 ? window.TradeSimUI.formatSignedPercent(item.percentChange) : "NA";
                            window.TradeSimUI.applyDirection(changeNode, item.percentChange);
                        }
                    });
                }
            }
        };

        const input = document.getElementById("top-search-input");
        const box = document.getElementById("top-search-suggest");
        if (!input || !box) return;

        let timer = null;
        input.addEventListener("input", function () {
            const q = input.value.trim();
            if (q.length < 1) {
                box.style.display = "none";
                box.innerHTML = "";
                return;
            }

            clearTimeout(timer);
            timer = setTimeout(function () {
                fetch("search_suggestions.php?q=" + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(items => {
                        if (!Array.isArray(items) || items.length === 0) {
                            box.style.display = "none";
                            box.innerHTML = "";
                            return;
                        }

                        box.innerHTML = "";
                        items.slice(0, 8).forEach(item => {
                            const row = document.createElement("a");
                            row.className = "suggest-item";
                            row.href = "stock.php?symbol=" + encodeURIComponent(item.symbol);
                            row.innerHTML = "<strong>" + item.symbol + "</strong><span>" + item.name + "</span>";
                            box.appendChild(row);
                        });
                        box.style.display = "block";
                    })
                    .catch(() => {
                        box.style.display = "none";
                        box.innerHTML = "";
                    });
            }, 180);
        });

        document.addEventListener("click", function (e) {
            if (!box.contains(e.target) && e.target !== input) {
                box.style.display = "none";
            }
        });
    })();
    </script>
    </body>
    </html>
        <?php
    });
}

function render_header($title, $active = "")
{
    global $conn;
    $message = isset($GLOBALS["preloaded_flash"]) ? $GLOBALS["preloaded_flash"] : get_flash();
    $user = isset($_SESSION["user_id"]) ? current_user($conn) : null;
    $marketStrip = isset($_SESSION["user_id"]) ? get_market_strip_snapshot(true) : [];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo escape($title); ?></title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <?php if (isset($_SESSION["user_id"])) : ?>
        <div class="layout">
            <div class="shell-backdrop"></div>
            <div class="header-shell">
                <div class="header-bar">
                    <div class="brand-block">
                        <a class="brand-mark" href="dashboard.php" aria-label="TradeSim home">
                            <span class="brand-mark-core"></span>
                        </a>
                        <div class="logo-box">
                            <h1>TradeSim</h1>
                            <span>Paper trading workspace</span>
                        </div>
                    </div>
                    <div class="top-search-wrap">
                        <form method="get" action="stock.php" class="top-search-form" autocomplete="off">
                            <div class="search-icon" aria-hidden="true"></div>
                            <input type="text" id="top-search-input" name="symbol" placeholder="Search stocks, indices, or symbols" />
                            <button type="submit">Open</button>
                            <div id="top-search-suggest" class="suggest-box"></div>
                        </form>
                    </div>
                    <div class="header-actions">
                        <div class="balance-pill">
                            <span>Available</span>
                            <strong data-live-balance>Rs. <?php echo number_format((float) $user["balance"], 2); ?></strong>
                        </div>
                        <div class="profile-pill">
                            <div class="profile-avatar"><?php echo strtoupper(substr($user["username"], 0, 1)); ?></div>
                            <div>
                                <strong><?php echo escape($user["username"]); ?></strong>
                                <span>Investor mode</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="header-subbar">
                    <div class="nav-links">
                        <a class="<?php echo $active === "dashboard" ? "active" : ""; ?>" href="dashboard.php">Overview</a>
                        <a class="<?php echo $active === "holdings" ? "active" : ""; ?>" href="holdings.php">Holdings</a>
                        <a class="<?php echo $active === "watchlist" ? "active" : ""; ?>" href="watchlist.php">Watchlist</a>
                        <a href="logout.php">Logout</a>
                    </div>
                    <div class="market-strip">
                        <?php
                        $marketStripTemplate = [
                            ["label" => "NIFTY 50", "symbol" => "NIFTY 50"],
                            ["label" => "SENSEX", "symbol" => "SENSEX"],
                            ["label" => "BANK NIFTY", "symbol" => "NIFTY BANK"],
                            ["label" => "NIFTY NEXT 50", "symbol" => "NIFTY NEXT 50"]
                        ];
                        $stripMap = [];
                        foreach ($marketStrip as $item) {
                            $stripMap[$item["symbol"]] = $item;
                        }
                        foreach ($marketStripTemplate as $item) :
                            $row = isset($stripMap[$item["symbol"]]) ? $stripMap[$item["symbol"]] : null;
                            $last = $row && isset($row["last"]) ? (float) $row["last"] : 0;
                            $change = $row && isset($row["percentChange"]) ? (float) $row["percentChange"] : 0;
                            ?>
                            <a class="ticker-chip" data-market-symbol="<?php echo escape($item["symbol"]); ?>" href="stock.php?symbol=<?php echo urlencode($item["symbol"]); ?>">
                                <span><?php echo escape($item["label"]); ?></span>
                                <strong data-market-last><?php echo $last > 0 ? number_format($last, 2) : "NA"; ?></strong>
                                <em data-market-change class="<?php echo $change >= 0 ? "profit" : "loss"; ?>">
                                    <?php echo $last > 0 ? (($change >= 0 ? "+" : "") . number_format($change, 2) . "%") : "NA"; ?>
                                </em>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="page-body">
    <?php else : ?>
        <div class="login-area">
    <?php endif; ?>

    <?php if ($message) : ?>
        <div class="flash <?php echo escape($message["type"]); ?>">
            <?php echo escape($message["message"]); ?>
        </div>
    <?php endif; ?>
    <?php
}

function render_footer()
{
    echo "</div>";

    if (isset($_SESSION["user_id"])) {
        echo "</div>";
    }

    $GLOBALS["layout_footer_rendered"] = true;
}
