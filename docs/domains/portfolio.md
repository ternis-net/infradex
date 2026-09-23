# Domain portfolio (100+ domains)

Bulk list lives in `inventory/domains/index.yaml` (generated).
Infra-relevant only in `inventory/domains/infra.yaml`.

Workflow:
1. Export CSV from registrar(s).
2. Run `python3 scripts/sync-domains.py registrar-export.csv`.
3. CI should fail on expiry <30d or NS mismatch vs one.ns/two.ns.
