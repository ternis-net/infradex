<?php
// editor.php - edit inventory/domains/infra.yaml via web form. Token required.
// Setup: export INFRADEx_EDIT_TOKEN="long-random"  OR  echo "long-random" > .edit-token (gitignored, chmod 600).
// Hosts/networks are edited via git only, so hosts.yaml grouping comments are never rewritten.
require __DIR__ . '/lib/infradex.php';

const INFRA_PATH = __DIR__ . '/inventory/domains/infra.yaml';
const TOKEN_FILE = __DIR__ . '/.edit-token';
const DNS_PROVIDERS = ['cloudflare', 'self-hosted', 'nameserver-eu', 'mixed', 'other'];
const PURPOSES = ['production', 'example', 'parked', 'redirect', 'infra-test'];
const STATUSES = ['active', 'deprecated'];
const FILE_HEADER = '# Only infra-relevant domains. Full 100+ portfolio lives in index.yaml (generated).'
    . "\n" . '# hosting_ref = where the A target is hosted (infra_ref or host name in hosts.yaml). NOT rDNS.'
    . "\n" . '# dns_provider: cloudflare | self-hosted (one.ns/two.ns.ternis.net, example-dns.net/org) | nameserver-eu | mixed | other';

function edit_token(): string {
    $env = getenv('INFRADEx_EDIT_TOKEN');
    if (is_string($env) && $env !== '') return trim($env);
    if (is_file(TOKEN_FILE)) return trim((string)file_get_contents(TOKEN_FILE));
    return '';
}

function authorized(): bool {
    $expected = edit_token();
    if ($expected === '') return false;
    $given = $_POST['token'] ?? '';
    if (!is_string($given) || $given === '') return false;
    return hash_equals($expected, $given);
}

function valid_hostname(string $s): bool {
    return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $s);
}

function parse_ns_input(string $s): array {
    $parts = preg_split('/[;,\n]+/', $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
}

$message = '';
$messageClass = '';

if (!edit_token()) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><h1>editor not configured</h1>'
        . '<p>Set <code>INFRADEx_EDIT_TOKEN</code> env or create <code>.edit-token</code> (gitignored, chmod 600), then reload.</p></body></html>';
    exit;
}

[$header, $domains] = infradex_load_list(INFRA_PATH, 'domains');
$byName = [];
foreach ($domains as $d) { if (isset($d['name'])) $byName[$d['name']] = $d; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!authorized()) {
        http_response_code(403);
        $message = 'invalid token';
        $messageClass = 'err';
    } else {
        try {
            if ($action === 'save') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (!isset($byName[$name])) throw new RuntimeException('unknown domain: ' . $name);
                $targetIp = trim((string)($_POST['target_ip'] ?? ''));
                $hostingRef = trim((string)($_POST['hosting_ref'] ?? ''));
                $ns = parse_ns_input((string)($_POST['ns_set'] ?? ''));
                $provider = trim((string)($_POST['dns_provider'] ?? ''));
                $purpose = trim((string)($_POST['purpose'] ?? ''));
                $status = trim((string)($_POST['status'] ?? ''));
                $note = trim((string)($_POST['note'] ?? ''));
                if (!filter_var($targetIp, FILTER_VALIDATE_IP)) throw new RuntimeException('invalid target_ip');
                if ($hostingRef === '') throw new RuntimeException('hosting_ref required');
                if (empty($ns)) throw new RuntimeException('ns_set required');
                if (!in_array($provider, DNS_PROVIDERS, true)) throw new RuntimeException('invalid dns_provider');
                if (!in_array($purpose, PURPOSES, true)) throw new RuntimeException('invalid purpose');
                if (!in_array($status, STATUSES, true)) throw new RuntimeException('invalid status');
                $entry = [
                    'name' => $name,
                    'target_ip' => $targetIp,
                    'hosting_ref' => $hostingRef,
                    'ns_set' => $ns,
                    'dns_provider' => $provider,
                    'purpose' => $purpose,
                ];
                if ($note !== '') $entry['note'] = $note;
                $entry['status'] = $status;
                foreach ($domains as $i => $d) {
                    if (($d['name'] ?? '') === $name) { $domains[$i] = $entry; break; }
                }
                backup_and_save($header, $domains);
                $message = 'saved ' . $name;
                $messageClass = 'ok';
                [$header, $domains] = infradex_load_list(INFRA_PATH, 'domains');
                $byName = [];
                foreach ($domains as $d) { if (isset($d['name'])) $byName[$d['name']] = $d; }
            } elseif ($action === 'add') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (!valid_hostname($name)) throw new RuntimeException('invalid domain name');
                if (isset($byName[$name])) throw new RuntimeException('domain already exists: ' . $name);
                $targetIp = trim((string)($_POST['target_ip'] ?? ''));
                if (!filter_var($targetIp, FILTER_VALIDATE_IP)) throw new RuntimeException('invalid target_ip');
                $ns = parse_ns_input((string)($_POST['ns_set'] ?? ''));
                if (empty($ns)) throw new RuntimeException('ns_set required');
                $domains[] = [
                    'name' => $name,
                    'target_ip' => $targetIp,
                    'hosting_ref' => trim((string)($_POST['hosting_ref'] ?? '')) ?: 'TODO',
                    'ns_set' => $ns,
                    'dns_provider' => in_array($_POST['dns_provider'] ?? '', DNS_PROVIDERS, true) ? $_POST['dns_provider'] : 'other',
                    'purpose' => in_array($_POST['purpose'] ?? '', PURPOSES, true) ? $_POST['purpose'] : 'production',
                    'status' => 'active',
                ];
                usort($domains, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
                backup_and_save($header, $domains);
                $message = 'added ' . $name;
                $messageClass = 'ok';
                [$header, $domains] = infradex_load_list(INFRA_PATH, 'domains');
                $byName = [];
                foreach ($domains as $d) { if (isset($d['name'])) $byName[$d['name']] = $d; }
            } elseif ($action === 'delete') {
                $name = trim((string)($_POST['name'] ?? ''));
                if (!isset($byName[$name])) throw new RuntimeException('unknown domain: ' . $name);
                $domains = array_values(array_filter($domains, fn($d) => ($d['name'] ?? '') !== $name));
                backup_and_save($header, $domains);
                $message = 'deleted ' . $name;
                $messageClass = 'ok';
                [$header, $domains] = infradex_load_list(INFRA_PATH, 'domains');
                $byName = [];
                foreach ($domains as $d) { if (isset($d['name'])) $byName[$d['name']] = $d; }
            } else {
                throw new RuntimeException('unknown action');
            }
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $messageClass = 'err';
        }
    }
}

function backup_and_save(array $header, array $domains): void {
    if (is_file(INFRA_PATH)) {
        $bak = __DIR__ . '/_archive/infra.yaml.' . date('Ymd-His') . '.bak';
        if (!@copy(INFRA_PATH, $bak)) throw new RuntimeException('backup failed');
    }
    infradex_save_list(INFRA_PATH, 'domains', $header, $domains, FILE_HEADER);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>infradex editor</title>
<style>
body{font-family:system-ui,sans-serif;margin:2rem;max-width:1000px}
fieldset{margin-bottom:1.5rem}
label{display:block;margin:.4rem 0}
input[type=text]{width:100%;max-width:600px}
.err{color:#a00}.ok{color:#060}
nav{margin-bottom:1rem}
small{color:#555}
</style>
</head>
<body>
<h1>infradex editor</h1>
<nav><a href="viewer.php">viewer.php</a> (read-only) | edits <code>inventory/domains/infra.yaml</code> only; hosts go via git.</nav>
<?php if ($message !== ''): ?><p class="<?=h($messageClass)?>"><?=h($message)?></p><?php endif ?>

<h2>add domain</h2>
<form method="post">
<input type="hidden" name="action" value="add">
<label>token <input type="password" name="token" required></label>
<label>name <input type="text" name="name" placeholder="new.example.de" required></label>
<label>target_ip <input type="text" name="target_ip" placeholder="77.90.60.110" required></label>
<label>hosting_ref <input type="text" name="hosting_ref" placeholder="kvm10.eyl / vweb01"></label>
<label>ns_set (comma/semicolon separated) <input type="text" name="ns_set" placeholder="one.ns.ternis.net, two.ns.ternis.net" required></label>
<label>dns_provider <select name="dns_provider"><?php foreach (DNS_PROVIDERS as $p): ?><option><?=h($p)?></option><?php endforeach ?></select></label>
<label>purpose <select name="purpose"><?php foreach (PURPOSES as $p): ?><option><?=h($p)?></option><?php endforeach ?></select></label>
<button type="submit">add</button>
</form>

<h2>edit (<?=count($domains)?>)</h2>
<?php foreach ($domains as $d): $n = $d['name'] ?? ''; ?>
<fieldset>
<legend><strong><?=h($n)?></strong> (<?=h($d['status'] ?? '')?>)</legend>
<form method="post">
<input type="hidden" name="action" value="save">
<input type="hidden" name="name" value="<?=h($n)?>">
<label>token <input type="password" name="token" required></label>
<label>target_ip <input type="text" name="target_ip" value="<?=h($d['target_ip'] ?? '')?>" required></label>
<label>hosting_ref <input type="text" name="hosting_ref" value="<?=h($d['hosting_ref'] ?? '')?>" required></label>
<label>ns_set <input type="text" name="ns_set" value="<?=h(implode(', ', (array)($d['ns_set'] ?? [])))?>" required></label>
<label>dns_provider <select name="dns_provider"><?php foreach (DNS_PROVIDERS as $p): ?><option value="<?=h($p)?>" <?=($d['dns_provider'] ?? '')===$p?'selected':''?>><?=h($p)?></option><?php endforeach ?></select></label>
<label>purpose <select name="purpose"><?php foreach (PURPOSES as $p): ?><option value="<?=h($p)?>" <?=($d['purpose'] ?? '')===$p?'selected':''?>><?=h($p)?></option><?php endforeach ?></select></label>
<label>status <select name="status"><?php foreach (STATUSES as $s): ?><option value="<?=h($s)?>" <?=($d['status'] ?? '')===$s?'selected':''?>><?=h($s)?></option><?php endforeach ?></select></label>
<label>note <input type="text" name="note" value="<?=h($d['note'] ?? '')?>"></label>
<button type="submit">save</button>
</form>
<form method="post" onsubmit="return confirm('delete <?=h($n)?>?')" style="margin-top:.5rem">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="name" value="<?=h($n)?>">
<label>token <input type="password" name="token" required></label>
<button type="submit">delete</button>
</form>
</fieldset>
<?php endforeach ?>
<p><small>Each save backs up to <code>_archive/infra.yaml.&lt;timestamp&gt;.bak</code>. Commit via git afterwards.</small></p>
</body>
</html>
