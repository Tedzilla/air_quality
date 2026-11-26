<?php
/** @var array $status */
/** @var string $defaultFrom */
/** @var string $defaultTo */
/** @var string $min */
/** @var string $max */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Air quality ranking</title>
    <style>
        :root {
            --bg: #f4f1ea;
            --ink: #1c1915;
            --muted: #5c564c;
            --card: #fffdf8;
            --line: #d9d1c3;
            --accent: #2f6f4e;
            --warn: #8a3b2a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Iowan Old Style", "Palatino Linotype", Palatino, serif;
            background: var(--bg);
            color: var(--ink);
        }
        main {
            max-width: 720px;
            margin: 0 auto;
            padding: 3rem 1.25rem 4rem;
        }
        h1 { font-size: 2.1rem; font-weight: 600; margin: 0 0 0.4rem; }
        .lede { color: var(--muted); margin: 0 0 2rem; line-height: 1.45; }
        form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 0.75rem;
            align-items: end;
            background: var(--card);
            border: 1px solid var(--line);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        label { display: block; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.3rem; }
        input[type="date"] {
            width: 100%;
            padding: 0.55rem 0.6rem;
            border: 1px solid var(--line);
            background: #fff;
            font: inherit;
        }
        button {
            padding: 0.62rem 1.1rem;
            border: 0;
            background: var(--accent);
            color: #fff;
            font: inherit;
            cursor: pointer;
        }
        button:disabled { opacity: 0.6; cursor: wait; }
        .meta { font-size: 0.9rem; color: var(--muted); margin-bottom: 1.25rem; }
        .error {
            border: 1px solid #e3b6ac;
            background: #fff4f1;
            color: var(--warn);
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
        }
        .winner {
            background: var(--card);
            border: 1px solid var(--line);
            padding: 1.25rem 1.35rem;
            margin-bottom: 1rem;
        }
        .winner h2 { margin: 0 0 0.35rem; font-size: 1.5rem; }
        .winner .value { font-size: 2rem; }
        table { width: 100%; border-collapse: collapse; background: var(--card); }
        th, td { text-align: left; padding: 0.65rem 0.8rem; border-bottom: 1px solid var(--line); }
        th { font-size: 0.8rem; color: var(--muted); font-weight: 600; }
        .muted { color: var(--muted); }
        .small { font-size: 0.85rem; }
        .warn { color: var(--warn); }
        .tag {
            display: inline-block;
            font-size: 0.75rem;
            padding: 0.05rem 0.4rem;
            border: 1px solid var(--line);
            margin-right: 0.25rem;
        }
        .table-wrap { overflow-x: auto; }
        @media (max-width: 640px) {
            form { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<main>
    <h1>Cleanest air, by city</h1>
    <p class="lede">
        Average PM2.5 (µg/m³) for Sofia, Plovdiv, Varna, Burgas and Ruse.
        Data is downloaded from the European Environment Agency and stored locally.
        Where EEA data is incomplete, missing hours are filled from OpenAQ, then from sensor.community.
    </p>

    <form id="query-form">
        <div>
            <label for="from">From</label>
            <input id="from" name="from" type="date" required value="<?= htmlspecialchars($defaultFrom) ?>" min="<?= htmlspecialchars($min) ?>" max="<?= htmlspecialchars($max) ?>">
        </div>
        <div>
            <label for="to">To</label>
            <input id="to" name="to" type="date" required value="<?= htmlspecialchars($defaultTo) ?>" min="<?= htmlspecialchars($min) ?>" max="<?= htmlspecialchars($max) ?>">
        </div>
        <button type="submit" id="submit">Show ranking</button>
    </form>

    <p class="meta" id="status-line">
        <?php if (!empty($status['data_window'])): ?>
            Local history: <?= htmlspecialchars($status['data_window']['from']) ?>
            to <?= htmlspecialchars($status['data_window']['to']) ?>
            (<?= (int) $status['data_window']['samples'] ?> hourly samples).
            <?php if (!empty($status['last_updated'])): ?>
                Last download: <?= htmlspecialchars($status['last_updated']) ?>.
            <?php endif; ?>
        <?php else: ?>
            No local data yet. The first EEA download may still be running.
        <?php endif; ?>
        <?php if (!empty($status['last_ingest_error'])): ?>
            Last download error: <?= htmlspecialchars($status['last_ingest_error']) ?>
        <?php endif; ?>
    </p>

    <div id="result"></div>
</main>
<script>
const form = document.getElementById('query-form');
const result = document.getElementById('result');
const button = document.getElementById('submit');

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    result.innerHTML = '';
    button.disabled = true;
    const from = document.getElementById('from').value;
    const to = document.getElementById('to').value;
    try {
        const response = await fetch(`/api/ranking?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
        const data = await response.json();
        if (!response.ok) {
            result.innerHTML = `<div class="error">${escapeHtml(data.error || 'Request failed')}</div>`;
            return;
        }
        result.innerHTML = render(data);
    } catch (error) {
        result.innerHTML = `<div class="error">${escapeHtml(error.message)}</div>`;
    } finally {
        button.disabled = false;
    }
});

function render(data) {
    const sourceNames = { eea: 'EEA', openaq: 'OpenAQ', sensor_community: 'sensor.community' };
    const rows = data.ranking.map((row, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${escapeHtml(row.city)}</td>
            <td>${row.average_pm25.toFixed(2)}</td>
            <td class="${row.coverage.complete ? '' : 'warn'}">
                ${row.coverage.percent.toFixed(1)}%${row.coverage.complete ? '' : ' ⚠'}
                <div class="muted small">${row.coverage.hours_covered} / ${row.coverage.hours_expected} h</div>
            </td>
            <td>
                ${row.sources.map((s) => `<span class="tag">${escapeHtml(sourceNames[s] || s)}</span>`).join('')}
                <div class="muted small">${row.station_count} station${row.station_count === 1 ? '' : 's'}${Object.entries(row.coverage.hours_by_source).filter(([s]) => s !== 'eea').map(([s, h]) => `, ${h} h from ${escapeHtml(sourceNames[s] || s)}`).join('')}</div>
            </td>
        </tr>
    `).join('');
    const incomplete = data.ranking.filter((row) => !row.coverage.complete).map((row) => row.city);
    const missing = (data.unavailable || []).map((row) =>
        `<li><strong>${escapeHtml(row.city)}</strong> — ${escapeHtml(row.reason)}</li>`
    ).join('');
    return `
        <section class="winner">
            <div class="muted">Cleanest city in this period</div>
            <h2>${escapeHtml(data.cleanest.city)}</h2>
            <div class="value">${data.cleanest.average_pm25.toFixed(2)} <span class="muted">${escapeHtml(data.unit)}</span></div>
        </section>
        ${incomplete.length ? `<div class="error">Incomplete data (below ${data.coverage_threshold_percent}% of hours) for ${incomplete.map(escapeHtml).join(', ')}. Their averages may not be representative.</div>` : ''}
        <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>City</th><th>Average PM2.5</th><th>Coverage</th><th>Source</th></tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
        </div>
        ${missing ? `<p class="muted" style="margin-top:1rem">Not ranked</p><ul>${missing}</ul>` : ''}
    `;
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
}
</script>
</body>
</html>
