<?php
/**
 * dnbx-editor.php - In-Browser Visual Editor & Exporter for dnbx.de config.php
 * Fully in-browser workflow: No local copies of config.php are stored in this repository.
 */

const DEFAULT_PROVIDERS = [
    'nx' => 'Nexserv',
    'as' => 'A.S.',
    'sk' => 'Sk.',
    'cf' => 'Cloudflare',
    'oc' => 'OVH Cloud',
    'hz' => 'Hetzner',
];

// In-memory AJAX parser for pasted initial PHP arrays
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'parse_array') {
        $raw = trim((string)($_POST['php_code'] ?? ''));
        if ($raw === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Input array code is empty.']);
            exit;
        }

        if (!str_contains($raw, '<?php')) {
            $evalCode = "<?php return " . rtrim($raw, ';') . ";";
        } else {
            $evalCode = $raw;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dnbx_eval_');
        file_put_contents($tmp, $evalCode);

        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        if (!str_contains((string)$lint, 'No syntax errors detected')) {
            @unlink($tmp);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "PHP Syntax error:\n" . trim((string)$lint)]);
            exit;
        }

        try {
            $res = include $tmp;
            @unlink($tmp);

            if (!is_array($res) || !isset($res['domains']) || !is_array($res['domains'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Input array must return an array containing a 'domains' list."]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'domains' => $res['domains'],
                'providers' => $res['providers'] ?? DEFAULT_PROVIDERS,
                'stats_metadata' => $res['stats_metadata'] ?? null,
            ]);
            exit;
        } catch (Throwable $e) {
            @unlink($tmp);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>dnbx.de In-Browser Domain Editor</title>
<style>
:root {
    --bg-page: #090d16;
    --bg-surface: #111827;
    --bg-surface-elevated: #1f2937;
    --border: #374151;
    --border-subtle: #1f2937;
    --text-main: #f9fafb;
    --text-muted: #9ca3af;
    --text-faint: #6b7280;
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
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
    background: rgba(59, 130, 246, 0.15);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: #93c5fd;
    font-size: 0.72rem;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    font-family: var(--font-mono);
}
.header-actions {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}

/* BUTTONS */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.45rem 0.85rem;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
    color: var(--text-main);
}
.btn svg { flex-shrink: 0; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); }
.btn-success { background: #059669; color: #fff; }
.btn-success:hover { background: #047857; }
.btn-secondary { background: var(--bg-surface-elevated); border-color: var(--border); }
.btn-secondary:hover { background: #374151; }
.btn-danger { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #fca5a5; }
.btn-danger:hover { background: var(--danger); color: #fff; }
.btn-icon { padding: 0.35rem 0.55rem; }

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
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: all 0.15s ease;
}
.tab-btn.active {
    background: var(--bg-surface);
    color: var(--text-main);
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

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
    font-size: 0.72rem;
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

/* TOOLBAR */
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
    display: flex;
    align-items: center;
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
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
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
    gap: 0.3rem;
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
.expiry-warn { color: var(--warning); font-weight: 600; font-family: var(--font-mono); font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem; }
.expiry-danger { color: var(--danger); font-weight: 700; font-family: var(--font-mono); font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem; }

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

/* CODE PANEL */
.code-panel {
    background: #090d16;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.25rem;
}
.code-textarea {
    width: 100%;
    min-height: 520px;
    background: transparent;
    border: none;
    color: #e2e8f0;
    font-family: var(--font-mono);
    font-size: 0.88rem;
    line-height: 1.6;
    outline: none;
    resize: vertical;
}

/* EMPTY STATE */
.empty-banner {
    background: var(--bg-surface);
    border: 2px dashed var(--border);
    border-radius: 12px;
    padding: 3.5rem 2rem;
    text-align: center;
    max-width: 650px;
    margin: 3rem auto;
}
.empty-banner h3 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.5rem; }
.empty-banner p { color: var(--text-muted); font-size: 0.95rem; margin-bottom: 1.5rem; }

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

<!-- SVG SYMBOL DEFINITIONS (Zero Emojis) -->
<svg style="display:none;">
    <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </symbol>
    <symbol id="icon-star" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1">
        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </symbol>
    <symbol id="icon-shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
    </symbol>
    <symbol id="icon-archive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>
    </symbol>
    <symbol id="icon-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
    </symbol>
    <symbol id="icon-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
    </symbol>
    <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
    </symbol>
    <symbol id="icon-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
    </symbol>
    <symbol id="icon-copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
    </symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </symbol>
    <symbol id="icon-code" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>
    </symbol>
</svg>

<header>
    <div class="header-container">
        <div class="brand-group">
            <span class="brand-title">dnbx.de <span class="brand-badge">In-Browser Editor</span></span>
            <div class="nav-tabs">
                <button class="tab-btn active" id="tabBtnVisual" onclick="switchTab('visual')">
                    Visual Manager
                </button>
                <button class="tab-btn" id="tabBtnCode" onclick="switchTab('code')">
                    <svg width="14" height="14"><use href="#icon-code"/></svg>
                    Initial Array / PHP Code
                </button>
            </div>
        </div>

        <div class="header-actions">
            <button class="btn btn-secondary" onclick="openAddModal()">
                <svg width="14" height="14"><use href="#icon-plus"/></svg>
                Add Domain
            </button>
            <button class="btn btn-primary" onclick="copyPhpCode()">
                <svg width="14" height="14"><use href="#icon-copy"/></svg>
                Copy PHP
            </button>
            <button class="btn btn-success" onclick="downloadConfigFile()">
                <svg width="14" height="14"><use href="#icon-download"/></svg>
                Download config.php
            </button>
            <a href="viewer.php" class="btn btn-secondary btn-icon" title="Return to Infradex Viewer">&larr;</a>
        </div>
    </div>
</header>

<div class="app-container">

    <!-- VIEW 1: VISUAL DOMAIN MANAGER -->
    <div id="viewVisual">

        <!-- EMPTY STATE IF NO ARRAY LOADED -->
        <div id="emptyBanner" class="empty-banner" style="display:none;">
            <h3>No Initial Array Loaded</h3>
            <p>Paste your <code>config.php</code> array from the <strong>dnbx.de</strong> repository to view and edit your domains visually. Everything runs 100% in-browser.</p>
            <button class="btn btn-primary" onclick="switchTab('code')">
                <svg width="14" height="14"><use href="#icon-code"/></svg>
                Paste Initial Array
            </button>
        </div>

        <div id="visualContent">
            <!-- STATS (Active Domains = non-expired AND non-archived) -->
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
                        <span class="search-icon"><svg width="15" height="15"><use href="#icon-search"/></svg></span>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search domain name, TLD, description..." oninput="renderTable()">
                    </div>
                    
                    <div class="pills-group" id="statusFilterPills">
                        <span class="pill active" data-filter="all" onclick="setStatusFilter('all')">All</span>
                        <span class="pill" data-filter="active" onclick="setStatusFilter('active')">Active</span>
                        <span class="pill" data-filter="archived" onclick="setStatusFilter('archived')">
                            <svg width="12" height="12"><use href="#icon-archive"/></svg> Archived
                        </span>
                        <span class="pill" data-filter="premium" onclick="setStatusFilter('premium')">
                            <svg width="12" height="12"><use href="#icon-star"/></svg> Premium
                        </span>
                        <span class="pill" data-filter="phishing" onclick="setStatusFilter('phishing')">
                            <svg width="12" height="12"><use href="#icon-shield"/></svg> Phishing
                        </span>
                        <span class="pill" data-filter="expired" onclick="setStatusFilter('expired')">
                            <svg width="12" height="12"><use href="#icon-alert"/></svg> Expired
                        </span>
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

    </div>

    <!-- VIEW 2: INITIAL ARRAY INPUT / CODE VIEW -->
    <div id="viewCode" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:1rem">
            <div>
                <h2 style="font-size:1.25rem; font-weight:700">Initial Array Input & PHP Generator</h2>
                <p style="color:var(--text-muted); font-size:0.85rem">Paste your <code>config.php</code> array from <code>dnbx.de</code> here, or edit visually and copy the output. No files are saved to infradex.</p>
            </div>
            <div style="display:flex; gap:0.5rem">
                <button class="btn btn-secondary" onclick="copyPhpCode()">
                    <svg width="14" height="14"><use href="#icon-copy"/></svg>
                    Copy PHP Code
                </button>
                <button class="btn btn-success" onclick="downloadConfigFile()">
                    <svg width="14" height="14"><use href="#icon-download"/></svg>
                    Download config.php
                </button>
                <button class="btn btn-primary" onclick="parsePastedArray()">
                    Parse & Load Into Editor
                </button>
            </div>
        </div>

        <div class="code-panel">
            <textarea id="rawPhpTextarea" class="code-textarea" spellcheck="false" placeholder="// Paste your dnbx.de config.php array here...&#10;return [&#10;    'domains' => [ ... ]&#10;];"></textarea>
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
                    <textarea id="modalDescription" class="form-control" placeholder="Notes on project, usage, etc."></textarea>
                </div>
                <div class="form-group full" style="flex-direction:row; gap:1.5rem; align-items:center; padding-top:0.5rem">
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsPremium">
                        <svg width="13" height="13" style="color:#facc15"><use href="#icon-star"/></svg> Premium
                    </label>
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsPhishing">
                        <svg width="13" height="13" style="color:#f87171"><use href="#icon-shield"/></svg> Phishing Prevention
                    </label>
                    <label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer; font-size:0.85rem">
                        <input type="checkbox" id="modalIsArchived">
                        <svg width="13" height="13" style="color:#94a3b8"><use href="#icon-archive"/></svg> Archived
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
// Known Providers (Fallback / Default)
let knownProviders = <?=json_encode(DEFAULT_PROVIDERS)?>;
let statsMetadata = [
    { label: 'Active Domains', note: null, muted: false },
    { label: 'Total Managed', note: null, muted: false },
    { label: 'Unique TLDs', note: null, muted: false },
    { label: 'Sold Domains', note: 'Not a marketplace.', muted: true }
];

// In-Browser State (Stored purely in browser localStorage)
let domains = [];
let currentStatusFilter = 'all';
let currentTldFilter = 'all';
let currentProvFilter = 'all';

// Load stored session from browser localStorage if available
try {
    const saved = localStorage.getItem('dnbx_browser_domains');
    if (saved) {
        domains = JSON.parse(saved);
    }
    const savedProvs = localStorage.getItem('dnbx_browser_providers');
    if (savedProvs) {
        knownProviders = JSON.parse(savedProvs);
    }
} catch (e) {
    console.warn('Storage load error', e);
}

function persistToBrowser() {
    try {
        localStorage.setItem('dnbx_browser_domains', JSON.stringify(domains));
        localStorage.setItem('dnbx_browser_providers', JSON.stringify(knownProviders));
    } catch (e) {}
}

function showToast(msg, type = 'info') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + type;
    setTimeout(() => { t.className = ''; }, 3500);
}

function switchTab(tab) {
    document.getElementById('viewVisual').style.display = tab === 'visual' ? 'block' : 'none';
    document.getElementById('viewCode').style.display = tab === 'code' ? 'block' : 'none';
    document.getElementById('tabBtnVisual').className = 'tab-btn ' + (tab === 'visual' ? 'active' : '');
    document.getElementById('tabBtnCode').className = 'tab-btn ' + (tab === 'code' ? 'active' : '');
    if (tab === 'code' && domains.length > 0) {
        document.getElementById('rawPhpTextarea').value = generatePurePhpCode();
    }
}

function renderFilterPills() {
    const tlds = [...new Set(domains.map(d => d.tld || 'de'))].sort();
    const tldWrap = document.getElementById('tldFilterPills');
    tldWrap.innerHTML = `<span class="pill ${currentTldFilter === 'all' ? 'active' : ''}" onclick="setTldFilter('all')">All</span>` +
        tlds.map(t => `<span class="pill ${currentTldFilter === t ? 'active' : ''}" onclick="setTldFilter('${t}')">.${t}</span>`).join('');

    const provWrap = document.getElementById('provFilterPills');
    provWrap.innerHTML = `<span class="pill ${currentProvFilter === 'all' ? 'active' : ''}" onclick="setProvFilter('all')">All</span>` +
        Object.entries(knownProviders).map(([code, name]) => 
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

// Render Domain Table & Calculate Active Domains (non-expired AND non-archived)
function renderTable() {
    const emptyBanner = document.getElementById('emptyBanner');
    const visualContent = document.getElementById('visualContent');

    if (domains.length === 0) {
        emptyBanner.style.display = 'block';
        visualContent.style.display = 'none';
        return;
    } else {
        emptyBanner.style.display = 'none';
        visualContent.style.display = 'block';
    }

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
        
        // Expiry calculation
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

        // Active Domain Definition: ONLY count non-expired AND non-archived domains
        const isActive = !d.is_archived && !isExpired;

        if (isActive) statActive++;
        if (isExpired || isExpiringSoon) statExpiring++;
        if (d.is_premium) statPrem++;
        if (d.is_phishing_prevention) statPhish++;
        if (d.tld) tldSet.add(d.tld);

        // Filter search
        if (q && !fullName.includes(q) && !desc.includes(q)) return false;

        // Filter status
        if (currentStatusFilter === 'active' && !isActive) return false;
        if (currentStatusFilter === 'archived' && !d.is_archived) return false;
        if (currentStatusFilter === 'premium' && !d.is_premium) return false;
        if (currentStatusFilter === 'phishing' && !d.is_phishing_prevention) return false;
        if (currentStatusFilter === 'expired' && !isExpired && !isExpiringSoon) return false;

        // Filter TLD
        if (currentTldFilter !== 'all' && (d.tld || 'de') !== currentTldFilter) return false;

        // Filter Provider
        if (currentProvFilter !== 'all') {
            const p = d.provider_short;
            const matches = Array.isArray(p) ? p.includes(currentProvFilter) : p === currentProvFilter;
            if (!matches) return false;
        }

        return true;
    });

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

        const provs = Array.isArray(d.provider_short) ? d.provider_short : [d.provider_short || 'nx'];
        const provBadges = provs.map(p => {
            const label = knownProviders[p] || p;
            return `<span class="badge-provider badge-${p}" title="${label}">${p.toUpperCase()}</span>`;
        }).join(' ');

        let expiryHtml = `<span class="expiry-ok">${escapeHtml(d.expiry_date || '–')}</span>`;
        if (d.expiry_date) {
            const exp = new Date(d.expiry_date);
            if (!isNaN(exp)) {
                const diffDays = (exp - now) / (1000 * 60 * 60 * 24);
                if (diffDays < 0) {
                    expiryHtml = `<span class="expiry-danger" title="Expired"><svg width="12" height="12"><use href="#icon-alert"/></svg> ${escapeHtml(d.expiry_date)} (Expired)</span>`;
                } else if (diffDays <= 30) {
                    expiryHtml = `<span class="expiry-warn" title="Expiring soon"><svg width="12" height="12"><use href="#icon-alert"/></svg> ${escapeHtml(d.expiry_date)} (${Math.ceil(diffDays)}d)</span>`;
                }
            }
        }

        // SVG chips without emojis
        const flagsHtml = `
            <span class="chip chip-premium ${d.is_premium ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_premium')">
                <svg width="11" height="11"><use href="#icon-star"/></svg> Premium
            </span>
            <span class="chip chip-phishing ${d.is_phishing_prevention ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_phishing_prevention')">
                <svg width="11" height="11"><use href="#icon-shield"/></svg> Phish
            </span>
            <span class="chip chip-archived ${d.is_archived ? 'active' : 'inactive'}" onclick="toggleFlag(${d._originalIndex}, 'is_archived')">
                <svg width="11" height="11"><use href="#icon-archive"/></svg> Arch
            </span>
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
                <button class="btn btn-secondary btn-icon" onclick="openEditModal(${d._originalIndex})" title="Edit">
                    <svg width="13" height="13"><use href="#icon-edit"/></svg>
                </button>
                <button class="btn btn-danger btn-icon" onclick="deleteDomain(${d._originalIndex})" title="Delete">
                    <svg width="13" height="13"><use href="#icon-trash"/></svg>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function toggleFlag(index, flagName) {
    if (domains[index]) {
        domains[index][flagName] = !domains[index][flagName];
        persistToBrowser();
        renderTable();
        showToast(`Toggled ${flagName} on ${domains[index].name}.${domains[index].tld}`);
    }
}

function deleteDomain(index) {
    const d = domains[index];
    if (!d) return;
    if (confirm(`Delete ${d.name}.${d.tld}?`)) {
        domains.splice(index, 1);
        persistToBrowser();
        renderFilterPills();
        renderTable();
        showToast(`Deleted ${d.name}.${d.tld}`, 'error');
    }
}

// Modal
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
    wrap.innerHTML = Object.entries(knownProviders).map(([code, name]) => {
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

    persistToBrowser();
    closeModal();
    renderFilterPills();
    renderTable();
}

// Generate PURE PHP array return string (NO define(), variable-safe)
function generatePurePhpCode() {
    let out = "<?php\n\nreturn [\n    'providers' => [\n";
    for (const [k, v] of Object.entries(knownProviders)) {
        out += `        '${k}' => '${addslashes(v)}',\n`;
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

    out += "    ],\n";

    if (statsMetadata && Array.isArray(statsMetadata)) {
        out += "\n    'stats_metadata' => [\n";
        statsMetadata.forEach(m => {
            const lbl = `'${addslashes(m.label || '')}'`;
            const note = m.note ? `'${addslashes(m.note)}'` : 'null';
            const muted = m.muted ? 'true' : 'false';
            out += `        ['label' => ${lbl}, 'note' => ${note}, 'muted' => ${muted}],\n`;
        });
        out += "    ],\n";
    }

    out += "];\n";
    return out;
}

function copyPhpCode() {
    const code = generatePurePhpCode();
    navigator.clipboard.writeText(code).then(() => {
        showToast('Pure config.php array copied to clipboard!', 'success');
    }).catch(() => {
        document.getElementById('rawPhpTextarea').value = code;
        document.getElementById('rawPhpTextarea').select();
        document.execCommand('copy');
        showToast('Copied to clipboard!', 'success');
    });
}

function downloadConfigFile() {
    const code = generatePurePhpCode();
    const blob = new Blob([code], { type: 'application/x-php' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'config.php';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Downloaded config.php', 'success');
}

// Parse pasted array from input
async function parsePastedArray() {
    const raw = document.getElementById('rawPhpTextarea').value.trim();
    if (!raw) {
        alert('Please paste a PHP array first.');
        return;
    }
    
    showToast('Validating and parsing array in-memory...', 'info');

    const form = new FormData();
    form.append('action', 'parse_array');
    form.append('php_code', raw);

    try {
        const res = await fetch('dnbx-editor.php?ajax=1', { method: 'POST', body: form });
        const json = await res.json();
        if (!json.success) throw new Error(json.error || 'Parsing failed');

        domains = json.domains || [];
        if (json.providers) knownProviders = json.providers;
        if (json.stats_metadata) statsMetadata = json.stats_metadata;

        persistToBrowser();
        renderFilterPills();
        renderTable();
        switchTab('visual');
        showToast(`Loaded ${domains.length} domains successfully into browser!`, 'success');
    } catch (e) {
        alert('Error: ' + e.message);
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
