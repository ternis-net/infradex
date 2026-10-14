<?php
/**
 * dnbx-editor.php - Visual UI/UX Domain Manager for dnbx.de (config.php / dnbx.php).
 * Allows loading an "initial array" via file or direct paste, visual editing, and saving back to config.php.
 */

const CONFIG_PATH = __DIR__ . '/inventory/domains/dnbx-domains.php';
const TOKEN_FILE = __DIR__ . '/.edit-token';

$defaultProviders = [
    'nx' => 'Nexserv',
    'as' => 'A.S.',
    'sk' => 'Sk.',
    'cf' => 'Cloudflare',
    'oc' => 'OVH Cloud',
    'hz' => 'Hetzner',
];

$fileData = is_file(CONFIG_PATH) ? (@include CONFIG_PATH) : [];
$knownProviders = is_array($fileData) && isset($fileData['providers']) && is_array($fileData['providers'])
    ? $fileData['providers']
    : $defaultProviders;

function get_auth_token(): string {
    $env = getenv('INFRADEx_EDIT_TOKEN');
    if (is_string($env) && $env !== '') return trim($env);
    if (is_file(TOKEN_FILE)) return trim((string)file_get_contents(TOKEN_FILE));
    return '';
}

function is_authorized(string $providedToken): bool {
    $expected = get_auth_token();
    if ($expected === '') return true; // If no token is set in system, allow local edits
    return hash_equals($expected, trim($providedToken));
}

function parse_php_array_code(string $code): array {
    $code = trim($code);
    if ($code === '') throw new InvalidArgumentException('Input code is empty.');
    
    // Ensure code has php tags or return statement
    if (!str_contains($code, '<?php')) {
        $evalCode = "<?php return " . rtrim($code, ';') . ";";
    } else {
        $evalCode = $code;
    }
    
    // Test syntax via temporary file with php -l or isolated evaluation
    $tmp = tempnam(sys_get_temp_dir(), 'dnbx_parse_');
    file_put_contents($tmp, $evalCode);
    
    $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
    if (!str_contains((string)$lint, 'No syntax errors detected')) {
        @unlink($tmp);
        throw new RuntimeException("PHP Syntax error in initial array:\n" . trim((string)$lint));
    }
    
    $res = include $tmp;
    @unlink($tmp);
    
    if (!is_array($res) || !isset($res['domains']) || !is_array($res['domains'])) {
        throw new RuntimeException("Parsed array must return an associative array containing a 'domains' list.");
    }
    
    return $res;
}

function serialize_to_config_php(array $domains, array $providers = [], ?array $statsMeta = null): string {
    global $knownProviders;
    $provMap = !empty($providers) ? $providers : $knownProviders;
    
    $out = "<?php\n\nreturn [\n    'providers' => [\n";
    foreach ($provMap as $k => $v) {
        $out .= "        " . var_export((string)$k, true) . " => " . var_export((string)$v, true) . ",\n";
    }
    $out .= "    ],\n    'domains' => [\n";
    
    $currentOwner = null;
    foreach ($domains as $d) {
        $owner = (int)($d['owner_id'] ?? 1);
        if ($currentOwner === null || $currentOwner !== $owner) {
            $out .= "\n        // Owned by Founder-{$owner}\n";
            $currentOwner = $owner;
        }
        
        $name = var_export((string)($d['name'] ?? ''), true);
        $tld = var_export((string)($d['tld'] ?? 'de'), true);
        $regDate = var_export((string)($d['registration_date'] ?? ''), true);
        $expDate = var_export((string)($d['expiry_date'] ?? ''), true);
        
        $prov = $d['provider_short'] ?? 'nx';
        if (is_array($prov)) {
            $provStr = '[' . implode(', ', array_map(fn($x) => var_export((string)$x, true), $prov)) . ']';
        } else {
            $provStr = var_export((string)$prov, true);
        }
        
        $desc = $d['description'] ?? null;
        $descStr = ($desc === null || $desc === '') ? 'null' : var_export((string)$desc, true);
        
        $prem = !empty($d['is_premium']) ? 'true' : 'false';
        $phish = !empty($d['is_phishing_prevention']) ? 'true' : 'false';
        $arch = !empty($d['is_archived']) ? 'true' : 'false';
        
        $out .= "        [\n";
        $out .= "            'owner_id' => {$owner},\n";
        $out .= "            'name' => {$name},\n";
        $out .= "            'tld' => {$tld},\n";
        $out .= "            'registration_date' => {$regDate},\n";
        $out .= "            'expiry_date' => {$expDate},\n";
        $out .= "            'provider_short' => {$provStr},\n";
        $out .= "            'description' => {$descStr},\n";
        $out .= "            'is_premium' => {$prem},\n";
        $out .= "            'is_phishing_prevention' => {$phish},\n";
        if (!empty($d['is_archived'])) {
            $out .= "            'is_archived' => true,\n";
        }
        $out .= "        ],\n";
    }
    
    $out .= "    ],\n";
    
    if (!empty($statsMeta)) {
        $out .= "\n    'stats_metadata' => [\n";
        foreach ($statsMeta as $m) {
            $lbl = var_export((string)($m['label'] ?? ''), true);
            $note = isset($m['note']) && $m['note'] !== null ? var_export((string)$m['note'], true) : 'null';
            $muted = !empty($m['muted']) ? 'true' : 'false';
            $out .= "        ['label' => {$lbl}, 'note' => {$note}, 'muted' => {$muted}],\n";
        }
        $out .= "    ],\n";
    }
    
    $out .= "];\n";
    return $out;
}

// Handle AJAX actions
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $token = (string)($_POST['token'] ?? '');
    
    if (!is_authorized($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid authentication token. Check .edit-token file.']);
        exit;
    }
    
    try {
        if ($action === 'parse_initial_array') {
            $raw = (string)($_POST['php_code'] ?? '');
            $parsed = parse_php_array_code($raw);
            echo json_encode(['success' => true, 'data' => $parsed]);
            exit;
        }
        
        if ($action === 'save_all') {
            $domains = json_decode((string)($_POST['domains'] ?? '[]'), true);
            if (!is_array($domains)) throw new RuntimeException('Invalid domains data.');
            
            // Backup before write
            if (is_file(CONFIG_PATH)) {
                $bakDir = __DIR__ . '/_archive';
                if (!is_dir($bakDir)) @mkdir($bakDir, 0755, true);
                @copy(CONFIG_PATH, $bakDir . '/config.php.' . date('Ymd-His') . '.bak');
            }
            
            // Retain stats metadata from current config if available
            $currentData = is_file(CONFIG_PATH) ? (include CONFIG_PATH) : [];
            $stats = $currentData['stats_metadata'] ?? [
                ['label' => 'Active Domains',  'note' => null,                 'muted' => false],
                ['label' => 'Total Managed',   'note' => null,                 'muted' => false],
                ['label' => 'Unique TLDs',     'note' => null,                 'muted' => false],
                ['label' => 'Sold Domains',    'note' => 'Not a marketplace.', 'muted' => true],
            ];
            
            $code = serialize_to_config_php($domains, $knownProviders, $stats);
            file_put_contents(CONFIG_PATH, $code);
            
            echo json_encode(['success' => true, 'count' => count($domains), 'timestamp' => date('H:i:s')]);
            exit;
        }
        
        throw new RuntimeException('Unknown AJAX action: ' . $action);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Initial Data Load
$initialData = is_file(CONFIG_PATH) ? (include CONFIG_PATH) : ['domains' => []];
$initialDomains = $initialData['domains'] ?? [];
$initialCode = is_file(CONFIG_PATH) ? file_get_contents(CONFIG_PATH) : '';
$hasTokenConfigured = get_auth_token() !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>dnbx.de Config & Domain Editor</title>
<style>
:root {
    --bg-page: #0f172a;
    --bg-surface: #1e293b;
    --bg-surface-elevated: #334155;
    --border: #475569;
    --border-subtle: #334155;
    --text-main: #f8fafc;
    --text-muted: #94a3b8;
    --text-faint: #64748b;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --accent-purple: #8b5cf6;
    --accent-cyan: #06b6d4;
    --font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
    --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: var(--bg-page);
    color: var(--text-main);
    font-family: var(--font);
    line-height: 1.5;
    min-height: 100vh;
    padding-bottom: 5rem;
}

/* TOP NAVBAR */
header {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-subtle);
    padding: 0.85rem 1.75rem;
    position: sticky;
    top: 0;
    z-index: 40;
    backdrop-filter: blur(8px);
}
.header-container {
    max-width: 1500px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
}
.brand-group {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.brand-title {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.brand-badge {
    background: #1e3a8a;
    color: #93c5fd;
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    font-family: var(--font-mono);
}
.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

/* BUTTONS */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.9rem;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    color: var(--text-main);
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-success { background: #059669; color: #fff; }
.btn-success:hover { background: #047857; }
.btn-secondary { background: var(--bg-surface-elevated); border-color: var(--border); }
.btn-secondary:hover { background: #475569; }
.btn-danger { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #fca5a5; }
.btn-danger:hover { background: var(--danger); color: #fff; }
.btn-icon { padding: 0.35rem 0.65rem; }

/* TABS */
.nav-tabs {
    display: flex;
    gap: 0.25rem;
    background: var(--bg-page);
    padding: 0.25rem;
    border-radius: 8px;
    border: 1px solid var(--border-subtle);
}
.tab-btn {
    background: transparent;
    border: none;
    color: var(--text-muted);
    padding: 0.4rem 0.85rem;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.tab-btn.active {
    background: var(--bg-surface);
    color: var(--text-main);
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

/* CONTAINER */
.app-container {
    max-width: 1500px;
    margin: 1.5rem auto;
    padding: 0 1.5rem;
}

/* STATS ROW */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.stat-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    padding: 1rem;
    display: flex;
    flex-direction: column;
}
.stat-label {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.05em;
    margin-bottom: 0.35rem;
}
.stat-value {
    font-size: 1.65rem;
    font-weight: 700;
    font-family: var(--font-mono);
}

/* FILTER & SEARCH TOOLBAR */
.toolbar-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.toolbar-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.search-input-wrap {
    flex: 1;
    min-width: 260px;
    position: relative;
}
.search-input {
    width: 100%;
    background: var(--bg-page);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.55rem 0.85rem 0.55rem 2.2rem;
    color: var(--text-main);
    font-size: 0.9rem;
    outline: none;
}
.search-input:focus { border-color: var(--primary); }
.search-icon {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-faint);
    pointer-events: none;
}

/* PILLS */
.pills-group {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.pill {
    background: var(--bg-surface-elevated);
    border: 1px solid transparent;
    color: var(--text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.25rem 0.65rem;
    border-radius: 9999px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.pill:hover { color: var(--text-main); border-color: var(--border); }
.pill.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* PROVIDER BADGES */
.badge-provider {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    font-family: var(--font-mono);
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
.badge-nx { background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); }
.badge-as { background: rgba(251, 191, 36, 0.15); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.3); }
.badge-sk { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
.badge-cf { background: rgba(249, 115, 22, 0.15); color: #fb923c; border: 1px solid rgba(249, 115, 22, 0.3); }
.badge-oc { background: rgba(236, 72, 153, 0.15); color: #f472b6; border: 1px solid rgba(236, 72, 153, 0.3); }
.badge-hz { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }

/* STATUS FLAGS CHIPS */
.chip {
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    cursor: pointer;
    user-select: none;
    transition: opacity 0.15s ease;
}
.chip.active { opacity: 1; }
.chip.inactive { opacity: 0.3; filter: grayscale(1); }
.chip-premium { background: rgba(234, 179, 8, 0.2); color: #facc15; border: 1px solid rgba(234, 179, 8, 0.4); }
.chip-phishing { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
.chip-archived { background: rgba(148, 163, 184, 0.2); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.4); }

/* TABLE */
.table-panel {
    background: var(--bg-surface);
    border: 1px solid var(--border-subtle);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);
}
table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}
th {
    background: #192338;
    padding: 0.75rem 1rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-subtle);
}
td {
    padding: 0.8rem 1rem;
    font-size: 0.875rem;
    border-bottom: 1px solid rgba(71, 85, 105, 0.3);
    vertical-align: middle;
}
tr:hover td { background: rgba(51, 65, 85, 0.4); }
tr.is-archived td { opacity: 0.55; }
.domain-cell {
    font-weight: 600;
    font-size: 0.95rem;
    letter-spacing: -0.01em;
}
.domain-cell .tld { color: var(--primary); font-weight: 700; }
.desc-text {
    font-size: 0.75rem;
    color: var(--text-muted);
    max-width: 350px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-top: 0.2rem;
}

/* EXPIRY STATUS */
.expiry-ok { color: var(--text-muted); font-family: var(--font-mono); font-size: 0.8rem; }
.expiry-warn { color: var(--warning); font-weight: 600; font-family: var(--font-mono); font-size: 0.8rem; }
.expiry-danger { color: var(--danger); font-weight: 700; font-family: var(--font-mono); font-size: 0.8rem; }

/* MODALS */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    backdrop-filter: blur(4px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal-card {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    width: 100%;
    max-width: 650px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
    overflow: hidden;
}
.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-subtle);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-header h3 { font-size: 1.15rem; font-weight: 700; }
.modal-body { padding: 1.5rem; max-height: 75vh; overflow-y: auto; }
.modal-footer {
    padding: 1rem 1.5rem;
    background: rgba(15, 23, 42, 0.4);
    border-top: 1px solid var(--border-subtle);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* FORM ELEMENTS */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}
.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.form-group.full { grid-column: 1 / -1; }
.form-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-muted);
}
.form-control {
    background: var(--bg-page);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 0.55rem 0.75rem;
    color: var(--text-main);
    font-size: 0.9rem;
    outline: none;
}
.form-control:focus { border-color: var(--primary); }
textarea.form-control { resize: vertical; min-height: 80px; font-family: inherit; }

/* CODE EDITOR VIEW */
.code-panel {
    background: #090d16;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.25rem;
    font-family: var(--font-mono);
}
.code-textarea {
    width: 100%;
    min-height: 480px;
    background: transparent;
    border: none;
    color: #e2e8f0;
    font-family: var(--font-mono);
    font-size: 0.88rem;
    line-height: 1.6;
    outline: none;
    resize: vertical;
}

/* TOAST */
#toast {
    position: fixed;
    bottom: 2rem;
    right: 2rem;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border);
    color: #fff;
    padding: 0.75rem 1.25rem;
    border-radius: 8px;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    z-index: 100;
    transform: translateY(100px);
    opacity: 0;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
#toast.show { transform: translateY(0); opacity: 1; }
#toast.success { background: #065f46; border-color: #059669; }
#toast.error { background: #991b1b; border-color: #dc2626; }
</style>
</head>
<body>

<header>
    <div class="header-container">
        <div class="brand-group">
            <span class="brand-title">dnbx.de <span class="brand-badge">config.php</span></span>
            <div class="nav-tabs">
                <button class="tab-btn active" id="tabBtnVisual" onclick="switchTab('visual')">Visual Editor</button>
                <button class="tab-btn" id="tabBtnCode" onclick="switchTab('code')">Initial Array / Raw PHP</button>
            </div>
        </div>

        <div class="header-actions">
            <!-- Global Auth Token -->
            <input type="password" id="authToken" class="form-control" style="width: 150px; font-size: 0.8rem; padding: 0.35rem 0.6rem;" placeholder="Auth token...">
            <button class="btn btn-primary" onclick="openAddModal()">+ Add Domain</button>
            <button class="btn btn-success" onclick="saveAllChanges()">Save to config.php</button>
            <a href="viewer.php" class="btn btn-secondary btn-icon" title="Infradex Viewer">&larr; Viewer</a>
        </div>
    </div>
</header>

<div class="app-container">

    <!-- VIEW 1: VISUAL DOMAIN MANAGER -->
    <div id="viewVisual">
        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Managed</span>
                <span class="stat-value" id="statTotal">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Domains</span>
                <span class="stat-value" style="color:var(--success)" id="statActive">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Expiring Soon / Expired</span>
                <span class="stat-value" style="color:var(--warning)" id="statExpiring">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Premium</span>
                <span class="stat-value" style="color:#facc15" id="statPremium">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Phishing Prevention</span>
                <span class="stat-value" style="color:#f87171" id="statPhishing">0</span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Unique TLDs</span>
                <span class="stat-value" id="statTlds">0</span>
            </div>
        </div>

        <!-- SEARCH & FILTER TOOLBAR -->
        <div class="toolbar-panel">
            <div class="toolbar-row">
                <div class="search-input-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search domain name, TLD, description..." oninput="renderTable()">
                </div>
                
                <div class="pills-group" id="statusFilterPills">
                    <span class="pill active" data-filter="all" onclick="setStatusFilter('all')">All</span>
                    <span class="pill" data-filter="active" onclick="setStatusFilter('active')">Active</span>
                    <span class="pill" data-filter="archived" onclick="setStatusFilter('archived')">Archived</span>
                    <span class="pill" data-filter="premium" onclick="setStatusFilter('premium')">Premium ⭐</span>
                    <span class="pill" data-filter="phishing" onclick="setStatusFilter('phishing')">Phishing 🛡️</span>
                    <span class="pill" data-filter="expired" onclick="setStatusFilter('expired')">Expired ⚠️</span>
                </div>
            </div>

            <div class="toolbar-row" style="font-size:0.8rem; justify-content:space-between">
                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap">
                    <span style="color:var(--text-faint); font-weight:600">TLD:</span>
                    <div id="tldFilterPills" class="pills-group"></div>
                </div>

                <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap">
                    <span style="color:var(--text-faint); font-weight:600">Provider:</span>
                    <div id="provFilterPills" class="pills-group"></div>
                </div>
            </div>
        </div>

        <!-- DOMAINS TABLE -->
        <div class="table-panel">
            <table>
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Owner</th>
                        <th>Provider(s)</th>
                        <th>Expires</th>
                        <th>Flags</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody id="domainsTableBody"></tbody>
            </table>
        </div>
    </div>

    <!-- VIEW 2: INITIAL ARRAY / CODE VIEW -->
    <div id="viewCode" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem">
            <div>
                <h2 style="font-size:1.25rem; font-weight:700">Initial Array Input & PHP Generator</h2>
                <p style="color:var(--text-muted); font-size:0.85rem">Paste your initial <code>config.php</code> array below and click "Parse & Load", or view and copy the generated PHP code.</p>
            </div>
            <div style="display:flex; gap:0.5rem">
                <button class="btn btn-secondary" onclick="copyPhpCode()">Copy Code</button>
                <button class="btn btn-primary" onclick="parseInitialArrayFromInput()">Parse & Load Into Editor</button>
            </div>
        </div>

        <div class="code-panel">
            <textarea id="rawPhpTextarea" class="code-textarea" spellcheck="false"><?=htmlspecialchars($initialCode)?></textarea>
        </div>
    </div>

</div>

<!-- EDIT / ADD MODAL -->
<div class="modal-overlay" id="editModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3 id="modalTitle">Edit Domain</h3>
            <button class="btn btn-secondary btn-icon" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editDomainIndex" value="-1">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">Domain Name (SLD)</label>
                    <input type="text" id="modalDomainName" class="form-control" placeholder="example">
                </div>
                <div class="form-group">
                    <label class="form-label">TLD</label>
                    <input type="text" id="modalDomainTld" class="form-control" placeholder="de">
                </div>
                <div class="form-group">
                    <label class="form-label">Owner ID</label>
                    <select id="modalOwnerId" class="form-control">
                        <option value="1">Founder-1</option>
                        <option value="2">Founder-2</option>
                        <option value="3">Founder-3</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Provider(s)</label>
                    <div id="modalProviderCheckboxes" style="display:flex; flex-wrap:wrap; gap:0.4rem; padding-top:0.35rem"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Registration Date</label>
                    <input type="text" id="modalRegDate" class="form-control" placeholder="YYYY-MM-DD">
                </div>
                <div class="form-group">
                    <label class="form-label">Expiry Date</label>
                    <input type="text" id="modalExpDate" class="form-control" placeholder="YYYY-MM-DD">
                </div>
                <div class="form-group full">
                    <label class="form-label">Description / Internal Notes</label>
                    <textarea id="modalDescription" class="form-control" placeholder="Notes on usage, project, etc."></textarea>
                </div>
                <div class="form-group full" style="flex-direction:row; gap:1.5rem; align-items:center; padding-top:0.5rem">
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsPremium"> Premium
                    </label>
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsPhishing"> Phishing Prevention
                    </label>
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsArchived"> Archived
                    </label>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-primary" onclick="saveModalDomain()">Apply Changes</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// Known Providers from PHP
const KNOWN_PROVIDERS = <?=json_encode($knownProviders)?>;

// Client State
let domains = <?=json_encode($initialDomains)?>;
let currentStatusFilter = 'all';
let currentTldFilter = 'all';
let currentProvFilter = 'all';

// Load saved auth token from localStorage
const tokenInput = document.getElementById('authToken');
if (localStorage.getItem('dnbx_edit_token')) {
    tokenInput.value = localStorage.getItem('dnbx_edit_token');
}
tokenInput.addEventListener('input', () => {
    localStorage.setItem('dnbx_edit_token', tokenInput.value);
});

// Toast notification
function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => { t.className = ''; }, 3500);
}

// Switch between Visual & Code views
function switchTab(tab) {
    document.getElementById('viewVisual').style.display = tab === 'visual' ? 'block' : 'none';
    document.getElementById('viewCode').style.display = tab === 'code' ? 'block' : 'none';
    document.getElementById('tabBtnVisual').className = 'tab-btn ' + (tab === 'visual' ? 'active' : '');
    document.getElementById('tabBtnCode').className = 'tab-btn ' + (tab === 'code' ? 'active' : '');
    if (tab === 'code') {
        updateRawPhpPreview();
    }
}

// Render dynamic filter pills
function renderFilterPills() {
    // TLDs
    const tlds = [...new Set(domains.map(d => d.tld || 'de'))].sort();
    const tldWrap = document.getElementById('tldFilterPills');
    tldWrap.innerHTML = `<span class="pill ${currentTldFilter === 'all' ? 'active' : ''}" onclick="setTldFilter('all')">All</span>` +
        tlds.map(t => `<span class="pill ${currentTldFilter === t ? 'active' : ''}" onclick="setTldFilter('${t}')">.${t}</span>`).join('');

    // Providers
    const provWrap = document.getElementById('provFilterPills');
    provWrap.innerHTML = `<span class="pill ${currentProvFilter === 'all' ? 'active' : ''}" onclick="setProvFilter('all')">All</span>` +
        Object.entries(KNOWN_PROVIDERS).map(([code, name]) => 
            `<span class="pill ${currentProvFilter === code ? 'active' : ''}" onclick="setProvFilter('${code}')">${code} (${name})</span>`
        ).join('');
}

function setStatusFilter(f) {
    currentStatusFilter = f;
    document.querySelectorAll('#statusFilterPills .pill').forEach(p => {
        p.className = 'pill ' + (p.dataset.filter === f ? 'active' : '');
    });
    renderTable();
}
function setTldFilter(t) { currentTldFilter = t; renderFilterPills(); renderTable(); }
function setProvFilter(p) { currentProvFilter = p; renderFilterPills(); renderTable(); }

// Render Domain Table
function renderTable() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const now = new Date();
    const tbody = document.getElementById('domainsTableBody');
    tbody.innerHTML = '';

    let statActive = 0, statExpiring = 0, statPrem = 0, statPhish = 0;
    const tldSet = new Set();

    const filtered = domains.filter((d, idx) => {
        d._originalIndex = idx;
        const fullName = `${d.name}.${d.tld}`.toLowerCase();
        const desc = (d.description || '').toLowerCase();
        
        // Expiry check
        let isExpired = false;
        let isExpiringSoon = false;
        if (d.expiry_date) {
            const exp = new Date(d.expiry_date);
            if (!isNaN(exp)) {
                const diffDays = (exp - now) / (1000 * 60 * 60 * 24);
                if (diffDays < 0) isExpired = true;
                else if (diffDays <= 30) isExpiringSoon = true;
            }
        }

        // Stats calculation
        if (!d.is_archived) statActive++;
        if (isExpired || isExpiringSoon) statExpiring++;
        if (d.is_premium) statPrem++;
        if (d.is_phishing_prevention) statPhish++;
        if (d.tld) tldSet.add(d.tld);

        // Filter: Search query
        if (q && !fullName.includes(q) && !desc.includes(q)) return false;

        // Filter: Status
        if (currentStatusFilter === 'active' && d.is_archived) return false;
        if (currentStatusFilter === 'archived' && !d.is_archived) return false;
        if (currentStatusFilter === 'premium' && !d.is_premium) return false;
        if (currentStatusFilter === 'phishing' && !d.is_phishing_prevention) return false;
        if (currentStatusFilter === 'expired' && !isExpired && !isExpiringSoon) return false;

        // Filter: TLD
        if (currentTldFilter !== 'all' && (d.tld || 'de') !== currentTldFilter) return false;

        // Filter: Provider
        if (currentProvFilter !== 'all') {
            const p = d.provider_short;
            const matches = Array.isArray(p) ? p.includes(currentProvFilter) : p === currentProvFilter;
            if (!matches) return false;
        }

        return true;
    });

    // Update Stats Card Numbers
    document.getElementById('statTotal').textContent = domains.length;
    document.getElementById('statActive').textContent = statActive;
    document.getElementById('statExpiring').textContent = statExpiring;
    document.getElementById('statPremium').textContent = statPrem;
    document.getElementById('statPhishing').textContent = statPhish;
    document.getElementById('statTlds').textContent = tldSet.size;

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-faint)">No domains match the selected filters.</td></tr>`;
        return;
    }

    filtered.forEach(d => {
        const tr = document.createElement('tr');
        if (d.is_archived) tr.className = 'is-archived';

        // Providers
        const provs = Array.isArray(d.provider_short) ? d.provider_short : [d.provider_short || 'nx'];
        const provBadges = provs.map(p => {
            const label = KNOWN_PROVIDERS[p] || p;
            return `<span class="badge-provider badge-${p}" title="${label}">${p.toUpperCase()}</span>`;
        }).join(' ');

        // Expiry formatting
        let expiryHtml = `<span class="expiry-ok">${d.expiry_date || '–'}</span>`;
        if (d.expiry_date) {
            const exp = new Date(d.expiry_date);
            if (!isNaN(exp)) {
                const diffDays = (exp - now) / (1000 * 60 * 60 * 24);
                if (diffDays < 0) expiryHtml = `<span class="expiry-danger" title="Expired">${d.expiry_date} (Expired)</span>`;
                else if (diffDays <= 30) expiryHtml = `<span class="expiry-warn" title="Expiring soon">${d.expiry_date} (${Math.ceil(diffDays)}d)</span>`;
            }
        }

        // Flags HTML with one-click toggles
        const flagsHtml = `
            <span class="chip chip-premium ${d.is_premium ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_premium')">⭐ Premium</span>
            <span class="chip chip-phishing ${d.is_phishing_prevention ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_phishing_prevention')">🛡️ Phish</span>
            <span class="chip chip-archived ${d.is_archived ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_archived')">📦 Arch</span>
        `;

        tr.innerHTML = `
            <td>
                <div class="domain-cell">${escapeHtml(d.name)}<span class="tld">.${escapeHtml(d.tld || 'de')}</span></div>
                ${d.description ? `<div class="desc-text">${escapeHtml(d.description)}</div>` : ''}
            </td>
            <td><span style="font-size:0.8rem; color:var(--text-muted)">Founder-${d.owner_id || 1}</span></td>
            <td>${provBadges}</td>
            <td>${expiryHtml}</td>
            <td>${flagsHtml}</td>
            <td style="text-align:right">
                <button class="btn btn-secondary btn-icon" onclick="openEditModal(${d._originalIndex})">✏️</button>
                <button class="btn btn-danger btn-icon" onclick="deleteDomain(${d._originalIndex})">🗑️</button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// Quick toggle flags directly on the row
function toggleFlag(index, flagName) {
    if (domains[index]) {
        domains[index][flagName] = !domains[index][flagName];
        renderTable();
        showToast(`Toggled ${flagName} on ${domains[index].name}.${domains[index].tld}`);
    }
}

// Delete domain
function deleteDomain(index) {
    const d = domains[index];
    if (!d) return;
    if (confirm(`Are you sure you want to delete ${d.name}.${d.tld}?`)) {
        domains.splice(index, 1);
        renderFilterPills();
        renderTable();
        showToast(`Deleted ${d.name}.${d.tld}`, 'error');
    }
}

// Modal handling
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New Domain';
    document.getElementById('editDomainIndex').value = '-1';
    document.getElementById('modalDomainName').value = '';
    document.getElementById('modalDomainTld').value = 'de';
    document.getElementById('modalOwnerId').value = '1';
    document.getElementById('modalRegDate').value = '';
    document.getElementById('modalExpDate').value = '';
    document.getElementById('modalDescription').value = '';
    document.getElementById('modalIsPremium').checked = false;
    document.getElementById('modalIsPhishing').checked = false;
    document.getElementById('modalIsArchived').checked = false;
    renderModalProviderBoxes(['nx']);
    document.getElementById('editModal').classList.add('open');
}

function openEditModal(index) {
    const d = domains[index];
    if (!d) return;
    document.getElementById('modalTitle').textContent = `Edit ${d.name}.${d.tld}`;
    document.getElementById('editDomainIndex').value = index;
    document.getElementById('modalDomainName').value = d.name || '';
    document.getElementById('modalDomainTld').value = d.tld || 'de';
    document.getElementById('modalOwnerId').value = String(d.owner_id || 1);
    document.getElementById('modalRegDate').value = d.registration_date || '';
    document.getElementById('modalExpDate').value = d.expiry_date || '';
    document.getElementById('modalDescription').value = d.description || '';
    document.getElementById('modalIsPremium').checked = !!d.is_premium;
    document.getElementById('modalIsPhishing').checked = !!d.is_phishing_prevention;
    document.getElementById('modalIsArchived').checked = !!d.is_archived;
    
    const provs = Array.isArray(d.provider_short) ? d.provider_short : [d.provider_short || 'nx'];
    renderModalProviderBoxes(provs);
    document.getElementById('editModal').classList.add('open');
}

function closeModal() {
    document.getElementById('editModal').classList.remove('open');
}

function renderModalProviderBoxes(selectedList) {
    const wrap = document.getElementById('modalProviderCheckboxes');
    wrap.innerHTML = Object.entries(KNOWN_PROVIDERS).map(([code, name]) => {
        const isChecked = selectedList.includes(code);
        return `
            <label style="background:var(--bg-page); border:1px solid var(--border); padding:0.25rem 0.5rem; border-radius:6px; font-size:0.8rem; display:flex; align-items:center; gap:0.35rem; cursor:pointer">
                <input type="checkbox" name="modal_provider_cb" value="${code}" ${isChecked ? 'checked' : ''}>
                <strong>${code.toUpperCase()}</strong> (${name})
            </label>
        `;
    }).join('');
}

function saveModalDomain() {
    const idx = parseInt(document.getElementById('editDomainIndex').value, 10);
    const name = document.getElementById('modalDomainName').value.trim().toLowerCase();
    const tld = document.getElementById('modalDomainTld').value.trim().toLowerCase().replace(/^\./, '');
    
    if (!name) { alert('Domain name is required.'); return; }
    if (!tld) { alert('TLD is required.'); return; }

    const checkedProvs = Array.from(document.querySelectorAll('input[name="modal_provider_cb"]:checked')).map(cb => cb.value);
    const providerVal = checkedProvs.length > 1 ? checkedProvs : (checkedProvs[0] || 'nx');

    const entry = {
        owner_id: parseInt(document.getElementById('modalOwnerId').value, 10),
        name: name,
        tld: tld,
        registration_date: document.getElementById('modalRegDate').value.trim(),
        expiry_date: document.getElementById('modalExpDate').value.trim(),
        provider_short: providerVal,
        description: document.getElementById('modalDescription').value.trim() || null,
        is_premium: document.getElementById('modalIsPremium').checked,
        is_phishing_prevention: document.getElementById('modalIsPhishing').checked,
        is_archived: document.getElementById('modalIsArchived').checked,
    };

    if (idx >= 0 && domains[idx]) {
        domains[idx] = entry;
        showToast(`Updated ${name}.${tld}`, 'success');
    } else {
        domains.unshift(entry);
        showToast(`Added ${name}.${tld}`, 'success');
    }

    closeModal();
    renderFilterPills();
    renderTable();
}

// Generate Raw PHP Code
function generatePhpCode() {
    let out = "<?php\n\nreturn [\n    'providers' => [\n";
    for (const [k, v] of Object.entries(KNOWN_PROVIDERS)) {
        out += `        '${k}' => '${v}',\n`;
    }
    out += "    ],\n    'domains' => [\n";

    let currentOwner = null;
    domains.forEach(d => {
        const owner = d.owner_id || 1;
        if (currentOwner !== owner) {
            out += `\n        // Owned by Founder-${owner}\n`;
            currentOwner = owner;
        }

        const name = `'${addslashes(d.name)}'`;
        const tld = `'${addslashes(d.tld || 'de')}'`;
        const reg = `'${addslashes(d.registration_date || '')}'`;
        const exp = `'${addslashes(d.expiry_date || '')}'`;
        
        let prov;
        if (Array.isArray(d.provider_short)) {
            prov = '[' + d.provider_short.map(x => `'${addslashes(x)}'`).join(', ') + ']';
        } else {
            prov = `'${addslashes(d.provider_short || 'nx')}'`;
        }

        const desc = d.description ? `'${addslashes(d.description)}'` : 'null';
        const prem = d.is_premium ? 'true' : 'false';
        const phish = d.is_phishing_prevention ? 'true' : 'false';

        out += "        [\n";
        out += `            'owner_id' => ${owner},\n`;
        out += `            'name' => ${name},\n`;
        out += `            'tld' => ${tld},\n`;
        out += `            'registration_date' => ${reg},\n`;
        out += `            'expiry_date' => ${exp},\n`;
        out += `            'provider_short' => ${prov},\n`;
        out += `            'description' => ${desc},\n`;
        out += `            'is_premium' => ${prem},\n`;
        out += `            'is_phishing_prevention' => ${phish},\n`;
        if (d.is_archived) {
            out += `            'is_archived' => true,\n`;
        }
        out += "        ],\n";
    });

    out += "    ],\n];\n";
    return out;
}

function updateRawPhpPreview() {
    document.getElementById('rawPhpTextarea').value = generatePhpCode();
}

function copyPhpCode() {
    const txt = document.getElementById('rawPhpTextarea');
    txt.select();
    navigator.clipboard.writeText(txt.value).then(() => {
        showToast('PHP array code copied to clipboard!', 'success');
    });
}

// Parse Initial Array from direct input textarea
async function parseInitialArrayFromInput() {
    const raw = document.getElementById('rawPhpTextarea').value;
    const token = document.getElementById('authToken').value;
    
    showToast('Parsing initial PHP array...', 'info');

    const form = new FormData();
    form.append('action', 'parse_initial_array');
    form.append('php_code', raw);
    form.append('token', token);

    try {
        const res = await fetch('dnbx-editor.php?ajax=1', { method: 'POST', body: form });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'Parse failed');
        
        domains = json.data.domains || [];
        showToast(`Successfully loaded ${domains.length} domains into editor!`, 'success');
        renderFilterPills();
        renderTable();
        switchTab('visual');
    } catch (e) {
        alert('Failed to parse initial array: ' + e.message);
        showToast(e.message, 'error');
    }
}

// Save all changes back to config.php
async function saveAllChanges() {
    const token = document.getElementById('authToken').value;
    showToast('Saving to config.php...', 'info');

    const form = new FormData();
    form.append('action', 'save_all');
    form.append('token', token);
    form.append('domains', JSON.stringify(domains));

    try {
        const res = await fetch('dnbx-editor.php?ajax=1', { method: 'POST', body: form });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'Save failed');
        
        showToast(`Saved ${json.count} domains to config.php at ${json.timestamp}`, 'success');
    } catch (e) {
        alert('Save error: ' + e.message);
        showToast(e.message, 'error');
    }
}

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function addslashes(str) {
    return (str + '').replace(/[\\"']/g, '\\$&').replace(/\u0000/g, '\\0');
}

// Initial boot
renderFilterPills();
renderTable();
</script>
</body>
</html>
