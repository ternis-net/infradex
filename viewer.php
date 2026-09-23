<?php
// viewer.php - read-only HTML view of inventory/*.yaml. No dependencies, no writes.
require __DIR__ . '/lib/infradex.php';

$base = __DIR__ . '/inventory';
[$hHeader, $hosts] = infradex_load_list($base . '/hosts.yaml', 'hosts');
[$nHeader, $networks] = infradex_load_list($base . '/networks.yaml', 'networks');
[$dHeader, $domains] = infradex_load_list($base . '/domains/infra.yaml', 'domains');

$statusFilter = $_GET['status'] ?? '';
$providerFilter = $_GET['dns_provider'] ?? '';
$roleFilter = $_GET['role'] ?? '';

$hostsView = array_filter($hosts, function ($x) use ($statusFilter, $roleFilter) {
    if ($statusFilter !== '' && ($x['status'] ?? '') !== $statusFilter) return false;
    if ($roleFilter !== '' && ($x['role'] ?? '') !== $roleFilter) return false;
    return true;
});
$domainsView = array_filter($domains, function ($x) use ($statusFilter, $providerFilter) {
    if ($statusFilter !== '' && ($x['status'] ?? '') !== $statusFilter) return false;
    if ($providerFilter !== '' && ($x['dns_provider'] ?? '') !== $providerFilter) return false;
    return true;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>infradex viewer</title>
<style>
body{font-family:system-ui,sans-serif;margin:2rem;max-width:1200px}
table{border-collapse:collapse;width:100%;margin-bottom:2rem}
th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left;vertical-align:top;font-size:.9rem}
th{background:#f4f4f4}
.deprecated{color:#888}
form.filters{margin-bottom:1rem}
form.filters label{margin-right:1rem}
nav{margin-bottom:1rem}
code{background:#f4f4f4;padding:0 .3rem}
</style>
</head>
<body>
<h1>infradex viewer</h1>
<nav><a href="editor.php">editor.php</a> (writes <code>inventory/domains/infra.yaml</code>, token required)</nav>

<form class="filters" method="get">
<label>status <select name="status"><option value="">all</option>
<?php foreach (['active','deprecated'] as $s): ?>
<option value="<?=h($s)?>" <?= $statusFilter===$s?'selected':'' ?>><?=h($s)?></option>
<?php endforeach ?></select></label>
<label>host role <select name="role"><option value="">all</option>
<?php foreach (['dns','web','vpn','misc','lab'] as $r): ?>
<option value="<?=h($r)?>" <?= $roleFilter===$r?'selected':'' ?>><?=h($r)?></option>
<?php endforeach ?></select></label>
<label>dns provider <select name="dns_provider"><option value="">all</option>
<?php foreach (['cloudflare','self-hosted','nameserver-eu','mixed','other'] as $p): ?>
<option value="<?=h($p)?>" <?= $providerFilter===$p?'selected':'' ?>><?=h($p)?></option>
<?php endforeach ?></select></label>
<button type="submit">filter</button>
</form>

<h2>domains (<?=count($domainsView)?>/<?=count($domains)?>)</h2>
<table>
<tr><th>name</th><th>target_ip</th><th>hosting_ref</th><th>ns_set</th><th>dns_provider</th><th>purpose</th><th>status</th><th>note</th></tr>
<?php foreach ($domainsView as $d): ?>
<tr class="<?=h($d['status'] ?? '')?>">
<td><?=h($d['name'] ?? '')?></td>
<td><code><?=h($d['target_ip'] ?? '')?></code></td>
<td><?=h($d['hosting_ref'] ?? '')?></td>
<td><?=h($d['ns_set'] ?? [])?></td>
<td><?=h($d['dns_provider'] ?? '')?></td>
<td><?=h($d['purpose'] ?? '')?></td>
<td><?=h($d['status'] ?? '')?></td>
<td><?=h($d['note'] ?? '')?></td>
</tr>
<?php endforeach ?>
</table>

<h2>hosts (<?=count($hostsView)?>/<?=count($hosts)?>)</h2>
<table>
<tr><th>name</th><th>fqdn</th><th>addresses</th><th>infra_ref</th><th>role</th><th>env</th><th>hosting</th><th>status</th><th>note</th></tr>
<?php foreach ($hostsView as $x): ?>
<tr class="<?=h($x['status'] ?? '')?>">
<td><?=h($x['name'] ?? '')?></td>
<td><?=h($x['fqdn'] ?? '')?></td>
<td><code><?=h($x['addresses'] ?? [])?></code></td>
<td><?=h($x['infra_ref'] ?? '')?></td>
<td><?=h($x['role'] ?? '')?></td>
<td><?=h($x['env'] ?? '')?></td>
<td><?=h($x['hosting'] ?? '')?></td>
<td><?=h($x['status'] ?? '')?></td>
<td><?=h($x['note'] ?? '')?></td>
</tr>
<?php endforeach ?>
</table>

<h2>networks (<?=count($networks)?>)</h2>
<table>
<tr><th>name</th><th>prefixes</th><th>env</th><th>note</th></tr>
<?php foreach ($networks as $n): ?>
<tr><td><?=h($n['name'] ?? '')?></td><td><code><?=h($n['prefixes'] ?? [])?></code></td><td><?=h($n['env'] ?? '')?></td><td><?=h($n['note'] ?? '')?></td></tr>
<?php endforeach ?>
</table>

<p><small>Source: <code>inventory/hosts.yaml</code>, <code>inventory/networks.yaml</code>, <code>inventory/domains/infra.yaml</code>. Read-only.</small></p>
</body>
</html>
