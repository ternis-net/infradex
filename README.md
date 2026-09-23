# infradex

Infrastructure index for ternis.net. Source of truth for hosts, networks, and domains.

## Layout

```
inventory/
  hosts.yaml       # all servers, machine-readable (migrated from initial.md)
  networks.yaml    # prefixes in use: public, lab, vpn
  domains/
    index.yaml     # generated list of all 100+ domains (registrar export)
    infra.yaml     # only infra-relevant domains with NS/web mapping
docs/
  ternis.net/dns.md
  ternis.net/web.md
  ternis.net/vpn.md
  lab/internal.md
  domains/portfolio.md
scripts/
  sync-domains.py  # refresh inventory/domains/index.yaml from registrar
  start-viewer.sh  # ./scripts/start-viewer.sh [port] -> viewer.php, no token
  start-editor.sh  # ./scripts/start-editor.sh [port] -> editor.php, uses .edit-token
viewer.php       # read-only HTML view of inventory (no deps)
editor.php       # web edit of inventory/domains/infra.yaml, needs INFRADEx_EDIT_TOKEN or .edit-token
lib/infradex.php # shared YAML-subset parser for viewer/editor
_archive/initial.md # original flat list, do not update
```

## Rules

1. IPs live only in `inventory/hosts.yaml`. Docs link there, don't duplicate.
2. `inventory/domains/index.yaml` is generated, not hand-edited.
3. No secrets: no `*.key`, `.env`, `*.pem`, WireGuard configs, `terraform.tfstate`. See `.gitignore`.
4. Group by `env > role`, not provider: `prod/dns`, `prod/web/self-operated`, `prod/web/external`, `lab/internal`.
5. Deprecated hosts stay with `status: deprecated`, don't delete immediately.

## Update workflow

1. Add/change host in `inventory/hosts.yaml`.
2. If domain affected, update `inventory/domains/infra.yaml`.
3. Run registrar sync for bulk portfolio: `python3 scripts/sync-domains.py`.
