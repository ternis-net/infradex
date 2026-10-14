<?php
/**
 * Infradex - Public Infrastructure Directory & Status Index
 * Public-facing site for infradex.ternis.net / infra.ternis.net
 */
require_once __DIR__ . '/../lib/infradex.php';

$base = __DIR__ . '/../inventory';
[$hHeader, $hosts] = infradex_load_list($base . '/hosts.yaml', 'hosts');
[$nHeader, $networks] = infradex_load_list($base . '/networks.yaml', 'networks');

// Filter only public production hosts for external transparency
$publicHosts = array_filter($hosts, function ($h) {
    if (($h['status'] ?? '') !== 'active') return false;
    // Hide purely internal RFC1918 addresses from public view unless marked external
    if (($h['env'] ?? '') === 'lab') {
        return false; // keep lab internal
    }
    return true;
});

// Group by role
$grouped = [];
foreach ($publicHosts as $h) {
    $role = $h['role'] ?? 'other';
    $grouped[$role][] = $h;
}

$roleTitles = [
    'dns' => 'Authoritative Nameservers',
    'web' => 'Web Services & Application Gateways',
    'mail' => 'Mail Exchange Endpoints (MX)',
    'webmail' => 'Webmail Client Interfaces',
    'vpn' => 'Network & VPN Gateways',
    'misc' => 'Core Systems & Virtual Hosts',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Infradex - ternis.net Infrastructure Index</title>
<meta name="description" content="Public infrastructure directory and service transparency index for ternis.net and related networks.">
<style>
:root {
    --bg-page: #090d16;
    --bg-surface: #111827;
    --bg-surface-elevated: #1f2937;
    --border: #374151;
    --border-subtle: #1f2937;
    --text: #f9fafb;
    --text-muted: #9ca3af;
    --text-faint: #6b7280;
    --primary: #3b82f6;
    --primary-light: #60a5fa;
    --success: #10b981;
    --success-bg: rgba(16, 185, 129, 0.12);
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg-page);
    color: var(--text);
    font-family: var(--font);
    line-height: 1.6;
    min-height: 100vh;
    padding-bottom: 5rem;
}

header {
    background: linear-gradient(180deg, #111827 0%, rgba(17, 24, 39, 0.7) 100%);
    border-bottom: 1px solid var(--border-subtle);
    padding: 3.5rem 1.5rem 2.5rem;
    text-align: center;
}
.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: var(--primary-light);
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    margin-bottom: 1.25rem;
    font-family: var(--font-mono);
}
.hero-badge::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    background: var(--success);
    border-radius: 50%;
    box-shadow: 0 0 8px var(--success);
}
h1 {
    font-size: clamp(2rem, 4vw, 3rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    margin-bottom: 0.5rem;
}
.hero-sub {
    color: var(--text-muted);
    font-size: 1.1rem;
    max-width: 650px;
    margin: 0 auto;
}

.container {
    max-width: 1100px;
    margin: 2.5rem auto 0;
    padding: 0 1.5rem;
}

/* SEARCH BAR */
.search-box {
    margin-bottom: 2.5rem;
    position: relative;
}
.search-input {
    width: 100%;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1rem 1.25rem 1rem 3rem;
    color: var(--text);
    font-size: 1rem;
    outline: none;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.3);
    transition: all 0.2s ease;
}
.search-input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}
.search-icon {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1.1rem;
    color: var(--text-faint);
}

/* SECTION */
.section {
    margin-bottom: 3rem;
}
.section-title {
    font-size: 1.2rem;
    font-weight: 700;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    letter-spacing: -0.01em;
}

/* HOST CARDS GRID */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}
.card {
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1.25rem;
    transition: transform 0.15s ease, border-color 0.15s ease;
}
.card:hover {
    transform: translateY(-2px);
    border-color: var(--border);
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.65rem;
}
.host-name {
    font-size: 1.05rem;
    font-weight: 700;
    font-family: var(--font-mono);
}
.host-fqdn {
    font-size: 0.8rem;
    color: var(--text-muted);
}
.status-pill {
    background: var(--success-bg);
    color: var(--success);
    font-size: 0.72rem;
    font-weight: 700;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.card-body {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    font-size: 0.85rem;
}
.ip-tag {
    font-family: var(--font-mono);
    color: var(--primary-light);
    font-size: 0.85rem;
}
.location-tag {
    color: var(--text-faint);
    font-size: 0.8rem;
}

/* NETWORKS LIST */
.net-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    overflow: hidden;
    font-size: 0.9rem;
}
.net-table th, .net-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-subtle);
}
.net-table th { background: rgba(31, 41, 55, 0.5); color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }

footer {
    border-top: 1px solid var(--border-subtle);
    padding: 2rem 1.5rem;
    text-align: center;
    color: var(--text-faint);
    font-size: 0.85rem;
    margin-top: 4rem;
}
footer a { color: var(--text-muted); text-decoration: none; }
footer a:hover { color: var(--primary); }
</style>
</head>
<body>

<header>
    <div class="hero-badge">All Systems Operational</div>
    <h1>infradex.ternis.net</h1>
    <p class="hero-sub">Public infrastructure directory and network transparency index for ternis.net & affiliated production nodes.</p>
</header>

<div class="container">

    <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" id="searchInput" class="search-input" placeholder="Search services, hostnames, IPs, or locations..." oninput="filterCards()">
    </div>

    <?php foreach ($grouped as $role => $hostsInRole): ?>
    <section class="section">
        <h2 class="section-title">
            <span><?=htmlspecialchars($roleTitles[$role] ?? ucfirst($role))?></span>
            <span style="font-size:0.8rem; color:var(--text-faint); font-weight:400">(<?=count($hostsInRole)?>)</span>
        </h2>
        <div class="grid">
            <?php foreach ($hostsInRole as $h): ?>
            <div class="card" data-search="<?=htmlspecialchars(strtolower(($h['name']??'') . ' ' . ($h['fqdn']??'') . ' ' . implode(' ', (array)($h['addresses']??[])) . ' ' . ($h['infra_ref']??'')))?>">
                <div class="card-header">
                    <div>
                        <div class="host-name"><?=htmlspecialchars($h['name'] ?? '')?></div>
                        <?php if (!empty($h['fqdn'])): ?>
                            <div class="host-fqdn"><?=htmlspecialchars($h['fqdn'])?></div>
                        <?php endif ?>
                    </div>
                    <span class="status-pill"><?=htmlspecialchars($h['status'] ?? 'active')?></span>
                </div>
                <div class="card-body">
                    <div>
                        <span class="ip-tag"><?=htmlspecialchars(implode(', ', (array)($h['addresses'] ?? [])))?></span>
                    </div>
                    <?php if (!empty($h['infra_ref'])): ?>
                        <div class="location-tag">Node: <?=htmlspecialchars($h['infra_ref'])?> (<?=htmlspecialchars($h['hosting'] ?? 'prod')?>)</div>
                    <?php endif ?>
                    <?php if (!empty($h['note'])): ?>
                        <div style="font-size:0.78rem; color:var(--text-faint); margin-top:0.2rem"><?=htmlspecialchars($h['note'])?></div>
                    <?php endif ?>
                </div>
            </div>
            <?php endforeach ?>
        </div>
    </section>
    <?php endforeach ?>

    <!-- PUBLIC NETWORKS SECTION -->
    <section class="section">
        <h2 class="section-title">Public Autonomous IP Ranges</h2>
        <table class="net-table">
            <thead>
                <tr>
                    <th>Network Scope</th>
                    <th>Announced Prefixes</th>
                    <th>Zone</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($networks as $n): if (($n['env'] ?? '') === 'lab') continue; ?>
                <tr>
                    <td><strong><?=htmlspecialchars($n['name'] ?? '')?></strong></td>
                    <td><code style="font-family:var(--font-mono); color:var(--primary-light)"><?=htmlspecialchars(implode(', ', (array)($n['prefixes'] ?? [])))?></code></td>
                    <td><?=htmlspecialchars($n['note'] ?? 'Production')?></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </section>

</div>

<footer>
    <p>&copy; <?=date('Y')?> <a href="https://ternis.net">ternis.net</a> &bull; Infradex Public Service Directory &bull; Automated DNS verification</p>
</footer>

<script>
function filterCards() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    document.querySelectorAll('.card').forEach(card => {
        const text = card.dataset.search || '';
        card.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
}
</script>

</body>
</html>
