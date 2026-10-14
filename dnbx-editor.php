<?php
// dnbx-editor.php - Visual editor for dnbx.de PHP array domain list.
// Uses token authentication (INFRADEx_EDIT_TOKEN or .edit-token file).
require_once __DIR__ . '/lib/infradex.php';

const DNBX_PATH = __DIR__ . '/inventory/domains/dnbx.php';
const TOKEN_FILE = __DIR__ . '/.edit-token';
const KNOWN_PROVIDERS = [
    'nx' => 'NexServ',
    'as' => 'A.S.',
    'sk' => 'Sk.',
    'cf' => 'Cloudflare',
    'oc' => 'OVH Cloud',
    'hz' => 'Hetzner',
];

function dnbx_edit_token(): string {
    $env = getenv('INFRADEx_EDIT_TOKEN');
    if (is_string($env) && $env !== '') return trim($env);
    if (is_file(TOKEN_FILE)) return trim((string)file_get_contents(TOKEN_FILE));
    return '';
}

function dnbx_authorized(): bool {
    $expected = dnbx_edit_token();
    if ($expected === '') return false;
    $given = $_POST['token'] ?? '';
    if (!is_string($given) || $given === '') return false;
    return hash_equals($expected, $given);
}

function load_dnbx_domains(): array {
    if (!is_file(DNBX_PATH)) return [];
    $data = require DNBX_PATH;
    return is_array($data) && isset($data['domains']) && is_array($data['domains']) ? $data['domains'] : [];
}

function save_dnbx_domains(array $domains): void {
    if (is_file(DNBX_PATH)) {
        $bak = __DIR__ . '/_archive/dnbx.php.' . date('Ymd-His') . '.bak';
        if (!is_dir(__DIR__ . '/_archive')) mkdir(__DIR__ . '/_archive', 0755, true);
        @copy(DNBX_PATH, $bak);
    }
    
    $out = "<?php\n// dnbx.de domain portfolio (ternisdomains / ternis.net)\nreturn [\n    'domains' => [\n";
    foreach ($domains as $d) {
        $out .= "        [\n";
        $out .= "            'owner_id' => " . (int)($d['owner_id'] ?? 1) . ",\n";
        $out .= "            'name' => " . var_export((string)($d['name'] ?? ''), true) . ",\n";
        $out .= "            'tld' => " . var_export((string)($d['tld'] ?? 'de'), true) . ",\n";
        $out .= "            'registration_date' => " . var_export((string)($d['registration_date'] ?? ''), true) . ",\n";
        $out .= "            'expiry_date' => " . var_export((string)($d['expiry_date'] ?? ''), true) . ",\n";

        $prov = $d['provider_short'] ?? 'nx';
        if (is_array($prov)) {
            $formattedProv = '[' . implode(', ', array_map(fn($x) => var_export((string)$x, true), $prov)) . ']';
        } else {
            $formattedProv = var_export((string)$prov, true);
        }
        $out .= "            'provider_short' => " . $formattedProv . ",\n";

        $desc = $d['description'] ?? null;
        $out .= "            'description' => " . ($desc === null || $desc === '' ? "null" : var_export((string)$desc, true)) . ",\n";
        $out .= "            'is_premium' => " . (!empty($d['is_premium']) ? 'true' : 'false') . ",\n";
        $out .= "            'is_phishing_prevention' => " . (!empty($d['is_phishing_prevention']) ? 'true' : 'false') . ",\n";
        $out .= "            'is_archived' => " . (!empty($d['is_archived']) ? 'true' : 'false') . ",\n";
        $out .= "        ],\n";
    }
    $out .= "    ],\n";

    if (is_file(DNBX_PATH)) {
        $existingData = require DNBX_PATH;
        if (isset($existingData['stats_metadata']) && is_array($existingData['stats_metadata'])) {
            $out .= "\n    'stats_metadata' => [\n";
            foreach ($existingData['stats_metadata'] as $m) {
                $lbl = var_export((string)($m['label'] ?? ''), true);
                $note = isset($m['note']) && $m['note'] !== null ? var_export((string)$m['note'], true) : 'null';
                $muted = !empty($m['muted']) ? 'true' : 'false';
                $out .= "        ['label' => {$lbl}, 'note' => {$note}, 'muted' => {$muted}],\n";
            }
            $out .= "    ],\n";
        }
    }
    $out .= "];\n";

    $fh = fopen(DNBX_PATH, 'c');
    if (!$fh) throw new RuntimeException('Cannot open ' . DNBX_PATH);
    if (!flock($fh, LOCK_EX)) { fclose($fh); throw new RuntimeException('Cannot lock ' . DNBX_PATH); }
    ftruncate($fh, 0);
    fwrite($fh, $out);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

$message = '';
$messageClass = '';
$tokenConfigured = dnbx_edit_token() !== '';

$domains = load_dnbx_domains();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!dnbx_authorized()) {
        http_response_code(403);
        $message = 'Invalid or missing authentication token.';
        $messageClass = 'err';
    } else {
        try {
            if ($action === 'save' || $action === 'add') {
                $idx = isset($_POST['index']) && $_POST['index'] !== '' ? (int)$_POST['index'] : null;
                $name = strtolower(trim((string)($_POST['name'] ?? '')));
                $tld = strtolower(trim((string)($_POST['tld'] ?? 'de')));
                $tld = ltrim($tld, '.');
                if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $name)) {
                    throw new RuntimeException('Invalid domain label (letters, numbers, dashes only).');
                }
                if ($tld === '' || !preg_match('/^[a-z0-9]+$/', $tld)) {
                    throw new RuntimeException('Invalid TLD.');
                }
                
                $provRaw = trim((string)($_POST['provider_short'] ?? 'nx'));
                if (str_contains($provRaw, ',')) {
                    $providerShort = array_values(array_filter(array_map('trim', explode(',', $provRaw))));
                } else {
                    $providerShort = $provRaw;
                }

                $desc = trim((string)($_POST['description'] ?? ''));

                $entry = [
                    'owner_id' => (int)($_POST['owner_id'] ?? 1),
                    'name' => $name,
                    'tld' => $tld,
                    'registration_date' => trim((string)($_POST['registration_date'] ?? '')),
                    'expiry_date' => trim((string)($_POST['expiry_date'] ?? '')),
                    'provider_short' => $providerShort,
                    'description' => $desc === '' ? null : $desc,
                    'is_premium' => !empty($_POST['is_premium']),
                    'is_phishing_prevention' => !empty($_POST['is_phishing_prevention']),
                    'is_archived' => !empty($_POST['is_archived']),
                ];

                if ($action === 'save' && $idx !== null && isset($domains[$idx])) {
                    $domains[$idx] = $entry;
                    $message = "Saved {$name}.{$tld}";
                } else {
                    // Check duplicate
                    foreach ($domains as $existing) {
                        if ($existing['name'] === $name && $existing['tld'] === $tld) {
                            throw new RuntimeException("Domain {$name}.{$tld} already exists!");
                        }
                    }
                    $domains[] = $entry;
                    $message = "Added {$name}.{$tld}";
                }

                save_dnbx_domains($domains);
                $messageClass = 'ok';
            } elseif ($action === 'delete') {
                $idx = (int)($_POST['index'] ?? -1);
                if (isset($domains[$idx])) {
                    $deletedName = $domains[$idx]['name'] . '.' . $domains[$idx]['tld'];
                    array_splice($domains, $idx, 1);
                    save_dnbx_domains($domains);
                    $message = "Deleted {$deletedName}";
                    $messageClass = 'ok';
                }
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageClass = 'err';
        }
    }
}

// Filter parameters
$search = strtolower(trim($_GET['q'] ?? ''));
$filterTld = trim($_GET['tld'] ?? '');
$filterProv = trim($_GET['prov'] ?? '');
$filterArchived = $_GET['archived'] ?? '';
$filterFlag = $_GET['flag'] ?? '';

$tlds = array_values(array_unique(array_column($domains, 'tld')));
sort($tlds);

$filtered = [];
foreach ($domains as $idx => $d) {
    $fullName = ($d['name'] ?? '') . '.' . ($d['tld'] ?? '');
    if ($search !== '' && !str_contains($fullName, $search) && !str_contains((string)($d['description'] ?? ''), $search)) {
        continue;
    }
    if ($filterTld !== '' && ($d['tld'] ?? '') !== $filterTld) {
        continue;
    }
    if ($filterProv !== '') {
        $p = $d['provider_short'] ?? '';
        $matches = is_array($p) ? in_array($filterProv, $p, true) : ($p === $filterProv);
        if (!$matches) continue;
    }
    if ($filterArchived === 'no' && !empty($d['is_archived'])) continue;
    if ($filterArchived === 'yes' && empty($d['is_archived'])) continue;
    if ($filterFlag === 'premium' && empty($d['is_premium'])) continue;
    if ($filterFlag === 'phishing' && empty($d['is_phishing_prevention'])) continue;

    $filtered[$idx] = $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>dnbx.de domain editor (PHP Array)</title>
<style>
:root {
    --bg: #f8fafc;
    --card: #ffffff;
    --border: #e2e8f0;
    --text: #0f172a;
    --muted: #64748b;
    --accent: #2563eb;
    --accent-hover: #1d4ed8;
    --danger: #dc2626;
    --badge-bg: #f1f5f9;
}
* { box-sizing: border-box; }
body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    margin: 0;
    padding: 2rem 1.5rem;
    max-width: 1300px;
    margin-inline: auto;
}
header { margin-bottom: 2rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; }
h1 { margin: 0 0 0.5rem 0; font-size: 1.8rem; display: flex; align-items: center; gap: .75rem; }
.subtitle { color: var(--muted); font-size: 0.95rem; margin: 0; }
nav { margin-top: 0.75rem; font-size: 0.9rem; }
nav a { color: var(--accent); text-decoration: none; margin-right: 1.25rem; }
nav a:hover { text-decoration: underline; }
.alert { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-size: 0.95rem; }
.alert.ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.alert.err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.alert.info { background: #eff6ff; color: #1e40af; border: 1px solid #dbeafe; }

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    background: var(--card);
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid var(--border);
    margin-bottom: 1.5rem;
    align-items: center;
}
.toolbar input, .toolbar select {
    padding: 0.45rem 0.65rem;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.9rem;
}
.btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.btn-primary { background: var(--accent); color: white; }
.btn-primary:hover { background: var(--accent-hover); }
.btn-danger { background: var(--danger); color: white; font-size: 0.8rem; padding: 0.35rem 0.65rem; }
.btn-secondary { background: var(--badge-bg); color: var(--text); border: 1px solid var(--border); }

table {
    width: 100%;
    border-collapse: collapse;
    background: var(--card);
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
th, td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 0.9rem;
    vertical-align: middle;
}
th { background: #f8fafc; font-weight: 600; color: var(--muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; }
tr:hover { background: #f1f5f9; }
tr.archived td { opacity: 0.6; }
.domain-title { font-weight: 600; font-size: 1rem; }
.domain-tld { color: var(--muted); }
.badge {
    display: inline-block;
    padding: 0.15rem 0.45rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-right: 0.25rem;
}
.badge-nx { background: #e0f2fe; color: #0369a1; }
.badge-as { background: #fef3c7; color: #b45309; }
.badge-sk { background: #ede9fe; color: #6d28d9; }
.badge-cf { background: #ffedd5; color: #c2410c; }
.badge-premium { background: #fef08a; color: #854d0e; }
.badge-phishing { background: #fee2e2; color: #991b1b; }
.badge-archived { background: #e2e8f0; color: #475569; }

.modal-details {
    background: #fafafa;
    border-top: 1px dashed var(--border);
    padding: 1rem;
}
details summary {
    cursor: pointer;
    color: var(--accent);
    font-size: 0.85rem;
    font-weight: 500;
    user-select: none;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-top: 0.75rem;
}
.form-group label {
    display: block;
    font-size: 0.8rem;
    font-weight: 500;
    color: var(--muted);
    margin-bottom: 0.25rem;
}
.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 0.45rem;
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 0.85rem;
}
.checkboxes {
    display: flex;
    gap: 1rem;
    align-items: center;
    margin-top: 0.75rem;
    grid-column: 1 / -1;
}
.checkboxes label { font-size: 0.85rem; display: flex; align-items: center; gap: 0.35rem; cursor: pointer; }
.form-actions { margin-top: 1rem; display: flex; gap: 0.5rem; justify-content: flex-end; }
</style>
</head>
<body>

<header>
    <h1>dnbx.de domain editor</h1>
    <p class="subtitle">Direct visual management for <code>inventory/domains/dnbx.php</code> (native PHP arrays). Part of ternisdomains / ternis.net.</p>
    <nav>
        <a href="viewer.php">&larr; viewer.php (infradex)</a>
        <a href="editor.php">editor.php (infra.yaml)</a>
        <a href="?archived=no">Active Domains</a>
        <a href="?archived=yes">Archived</a>
        <a href="?flag=premium">Premium</a>
        <a href="?flag=phishing">Phishing Protection</a>
    </nav>
</header>

<?php if ($message !== ''): ?>
    <div class="alert <?=h($messageClass)?>"><?=h($message)?></div>
<?php endif ?>

<?php if (!$tokenConfigured): ?>
    <div class="alert info">
        <strong>Notice:</strong> <code>INFRADEx_EDIT_TOKEN</code> environment variable or <code>.edit-token</code> file is not configured.
        You can view the domains below. To enable saving, provide a token or add <code>.edit-token</code>.
    </div>
<?php endif ?>

<!-- ADD NEW DOMAIN -->
<details style="margin-bottom: 1.5rem; background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 1rem;">
    <summary style="font-size: 1.05rem; font-weight: 600;">+ Add New Domain to dnbx.de portfolio</summary>
    <form method="post" style="margin-top: 1rem;">
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <div class="form-group">
                <label>Auth Token</label>
                <input type="password" name="token" placeholder="token from .edit-token" required>
            </div>
            <div class="form-group">
                <label>Domain Name (without TLD)</label>
                <input type="text" name="name" placeholder="my-service" required>
            </div>
            <div class="form-group">
                <label>TLD</label>
                <input type="text" name="tld" value="de" required>
            </div>
            <div class="form-group">
                <label>Owner ID</label>
                <input type="number" name="owner_id" value="1" required>
            </div>
            <div class="form-group">
                <label>Provider Short (nx, as, sk, cf, oc, hz - or comma-separated)</label>
                <input type="text" name="provider_short" value="nx">
            </div>
            <div class="form-group">
                <label>Registration Date</label>
                <input type="text" name="registration_date" placeholder="YYYY-MM-DD">
            </div>
            <div class="form-group">
                <label>Expiry Date</label>
                <input type="text" name="expiry_date" placeholder="YYYY-MM-DD">
            </div>
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Description / Notes</label>
                <textarea name="description" rows="2" placeholder="Optional notes..."></textarea>
            </div>
            <div class="checkboxes">
                <label><input type="checkbox" name="is_premium" value="1"> Premium</label>
                <label><input type="checkbox" name="is_phishing_prevention" value="1"> Phishing Prevention</label>
                <label><input type="checkbox" name="is_archived" value="1"> Archived</label>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">+ Add Domain</button>
        </div>
    </form>
</details>

<!-- TOOLBAR / FILTER -->
<form class="toolbar" method="get">
    <input type="text" name="q" value="<?=h($search)?>" placeholder="Search name or description...">
    <select name="tld">
        <option value="">All TLDs</option>
        <?php foreach ($tlds as $t): ?>
            <option value="<?=h($t)?>" <?= $filterTld === $t ? 'selected' : '' ?>>.<?=h($t)?></option>
        <?php endforeach ?>
    </select>
    <select name="prov">
        <option value="">All Providers</option>
        <?php foreach (KNOWN_PROVIDERS as $code => $label): ?>
            <option value="<?=h($code)?>" <?= $filterProv === $code ? 'selected' : '' ?>><?=h($code)?> (<?=h($label)?>)</option>
        <?php endforeach ?>
    </select>
    <select name="archived">
        <option value="">Archived: All</option>
        <option value="no" <?= $filterArchived === 'no' ? 'selected' : '' ?>>Active Only</option>
        <option value="yes" <?= $filterArchived === 'yes' ? 'selected' : '' ?>>Archived Only</option>
    </select>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <a href="dnbx-editor.php" class="btn btn-secondary" style="font-size:0.85rem">Reset</a>
    <span style="margin-left:auto; font-size: 0.85rem; color: var(--muted);">Showing <?=count($filtered)?> of <?=count($domains)?> domains</span>
</form>

<!-- DOMAINS TABLE -->
<table>
    <thead>
        <tr>
            <th>Domain</th>
            <th>Owner</th>
            <th>Provider</th>
            <th>Registered</th>
            <th>Expires</th>
            <th>Flags & Description</th>
            <th style="text-align:right">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($filtered)): ?>
            <tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--muted)">No domains matched your filters.</td></tr>
        <?php endif ?>
        <?php foreach ($filtered as $idx => $d):
            $isArchived = !empty($d['is_archived']);
            $prov = $d['provider_short'] ?? '';
            $provList = is_array($prov) ? $prov : [$prov];
        ?>
        <tr class="<?= $isArchived ? 'archived' : '' ?>">
            <td>
                <span class="domain-title"><?=h($d['name'] ?? '')?></span><span class="domain-tld">.<?=h($d['tld'] ?? '')?></span>
            </td>
            <td>Founder-<?=h($d['owner_id'] ?? 1)?></td>
            <td>
                <?php foreach ($provList as $p): ?>
                    <span class="badge badge-<?=h($p)?>" title="<?=h(KNOWN_PROVIDERS[$p] ?? $p)?>"><?=h($p)?></span>
                <?php endforeach ?>
            </td>
            <td><small><?=h($d['registration_date'] ?? '–')?></small></td>
            <td><small><?=h($d['expiry_date'] ?? '–')?></small></td>
            <td>
                <?php if (!empty($d['is_premium'])): ?><span class="badge badge-premium">Premium</span><?php endif ?>
                <?php if (!empty($d['is_phishing_prevention'])): ?><span class="badge badge-phishing">Phishing Protection</span><?php endif ?>
                <?php if (!empty($d['is_archived'])): ?><span class="badge badge-archived">Archived</span><?php endif ?>
                <?php if (!empty($d['description'])): ?>
                    <div style="font-size:0.8rem; color:var(--muted); margin-top:0.25rem"><?=h($d['description'])?></div>
                <?php endif ?>
            </td>
            <td style="text-align:right">
                <details>
                    <summary>Edit</summary>
                    <div class="modal-details" style="text-align:left">
                        <form method="post">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="index" value="<?=$idx?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Auth Token</label>
                                    <input type="password" name="token" required>
                                </div>
                                <div class="form-group">
                                    <label>Name</label>
                                    <input type="text" name="name" value="<?=h($d['name'] ?? '')?>" required>
                                </div>
                                <div class="form-group">
                                    <label>TLD</label>
                                    <input type="text" name="tld" value="<?=h($d['tld'] ?? '')?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Owner ID</label>
                                    <input type="number" name="owner_id" value="<?=h($d['owner_id'] ?? 1)?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Provider</label>
                                    <input type="text" name="provider_short" value="<?=h(is_array($prov) ? implode(', ', $prov) : $prov)?>">
                                </div>
                                <div class="form-group">
                                    <label>Registration Date</label>
                                    <input type="text" name="registration_date" value="<?=h($d['registration_date'] ?? '')?>">
                                </div>
                                <div class="form-group">
                                    <label>Expiry Date</label>
                                    <input type="text" name="expiry_date" value="<?=h($d['expiry_date'] ?? '')?>">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Description</label>
                                    <textarea name="description" rows="2"><?=h($d['description'] ?? '')?></textarea>
                                </div>
                                <div class="checkboxes">
                                    <label><input type="checkbox" name="is_premium" value="1" <?=!empty($d['is_premium']) ? 'checked' : ''?>> Premium</label>
                                    <label><input type="checkbox" name="is_phishing_prevention" value="1" <?=!empty($d['is_phishing_prevention']) ? 'checked' : ''?>> Phishing Prevention</label>
                                    <label><input type="checkbox" name="is_archived" value="1" <?=!empty($d['is_archived']) ? 'checked' : ''?>> Archived</label>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete <?=h($d['name'].'.'.$d['tld'])?>?')" style="margin-top:0.5rem">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="index" value="<?=$idx?>">
                            <input type="password" name="token" placeholder="token to delete" style="padding:0.3rem; font-size:0.8rem; width:140px" required>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </details>
            </td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>

</body>
</html>
