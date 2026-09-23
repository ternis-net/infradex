<?php
// Shared helpers for viewer.php and editor.php. No extensions required.
// Parses only the constrained YAML subset used in inventory/*.yaml:
// top-level "<key>:" list of maps with scalar values and inline [a, b] arrays.

function infradex_parse_value(string $raw) {
    $raw = trim($raw);
    if ($raw === '' || $raw === '~' || strtolower($raw) === 'null') return '';
    if (strlen($raw) >= 2 && $raw[0] === '[' && $raw[strlen($raw) - 1] === ']') {
        $inner = trim(substr($raw, 1, -1));
        if ($inner === '') return [];
        return array_values(array_map(function ($p) {
            $p = trim($p);
            if (strlen($p) >= 2 && (($p[0] === '"' && $p[strlen($p)-1] === '"') || ($p[0] === "'" && $p[strlen($p)-1] === "'"))) {
                $p = substr($p, 1, -1);
            }
            return $p;
        }, explode(',', $inner)));
    }
    if (strlen($raw) >= 2 && (($raw[0] === '"' && $raw[strlen($raw)-1] === '"') || ($raw[0] === "'" && $raw[strlen($raw)-1] === "'"))) {
        return substr($raw, 1, -1);
    }
    return $raw;
}

function infradex_format_value($v): string {
    if (is_array($v)) {
        return '[' . implode(', ', $v) . ']';
    }
    return (string)$v;
}

function infradex_load_list(string $path, string $listKey): array {
    // Returns [headerLines, items]. Header = all comment/blank lines + version line before "<key>:".
    if (!is_file($path)) return [[], []];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $header = [];
    $items = [];
    $current = null;
    $inList = false;
    foreach ($lines as $line) {
        if (!$inList) {
            if (preg_match('/^\s*' . preg_quote($listKey, '/') . '\s*:\s*$/', $line)) {
                $inList = true;
            } else {
                $header[] = $line;
            }
            continue;
        }
        $trim = trim($line);
        if ($trim === '' || str_starts_with($trim, '#')) continue;
        if (preg_match('/^\s*-\s+(\w+)\s*:\s*(.*)$/', $line, $m)) {
            if ($current !== null) $items[] = $current;
            $current = [$m[1] => infradex_parse_value($m[2])];
        } elseif ($current !== null && preg_match('/^\s+(\w+)\s*:\s*(.*)$/', $line, $m)) {
            $current[$m[1]] = infradex_parse_value($m[2]);
        }
    }
    if ($current !== null) $items[] = $current;
    return [$header, $items];
}

function infradex_save_list(string $path, string $listKey, array $header, array $items, string $fileHeaderComment = ''): void {
    $out = [];
    $hasVersion = false;
    foreach ($header as $h) {
        $out[] = $h;
        if (preg_match('/^\s*version\s*:/', $h)) $hasVersion = true;
    }
    if (!$hasVersion) array_unshift($out, 'version: 1');
    if ($fileHeaderComment !== '') {
        // Replace any existing non-version comment block lines after version with the canonical comment.
        $filtered = [];
        $seenListKey = false;
        foreach ($out as $h) {
            $t = trim($h);
            if (!$seenListKey && ($t === '' || str_starts_with($t, '#') || preg_match('/^\s*version\s*:/', $h))) {
                if (preg_match('/^\s*version\s*:/', $h) || $t === '') $filtered[] = $h;
                continue; // drop old comments, re-add canonical below
            }
            $filtered[] = $h;
            $seenListKey = true;
        }
        $out = $filtered;
        $pos = 0;
        foreach ($out as $i => $h) { if (preg_match('/^\s*version\s*:/', $h)) { $pos = $i + 1; break; } }
        array_splice($out, $pos, 0, explode("\n", $fileHeaderComment));
    }
    $out[] = $listKey . ':';
    if (empty($items)) {
        $out[] = '  []';
    } else {
        foreach ($items as $it) {
            $first = true;
            foreach ($it as $k => $v) {
                if ($first) {
                    $out[] = '  - ' . $k . ': ' . infradex_format_value($v);
                    $first = false;
                } else {
                    $out[] = '    ' . $k . ': ' . infradex_format_value($v);
                }
            }
            $out[] = '';
        }
    }
    $data = implode("\n", $out);
    if (substr($data, -1) !== "\n") $data .= "\n";
    $fh = fopen($path, 'c');
    if (!$fh) throw new RuntimeException('cannot open ' . $path);
    if (!flock($fh, LOCK_EX)) { fclose($fh); throw new RuntimeException('cannot lock ' . $path); }
    ftruncate($fh, 0);
    fwrite($fh, $data);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

function h($s): string {
    if (is_array($s)) $s = implode(', ', $s);
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
