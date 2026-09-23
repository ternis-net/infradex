# DNS - ternis.net

Self-hosted set (for ternis.net):
Primary: `one.ns.ternis.net` (77.90.60.110, kvm10.eyl)
Secondary: `two.ns.ternis.net` (94.249.188.145, kvm11.eyl)
Deprecated: `three.ns` (77.90.15.49) - see `inventory/hosts.yaml`.

Exception: `example-dns.com/net/org` are PRODUCTION but delegated to
Cloudflare (`margot.ns.cloudflare.com`, `martin.ns.cloudflare.com`),
verified 2026-09-23. A targets still point at kvm10/kvm11.
`hosting_ref` in `infra.yaml` is hosting location, NOT rDNS.

Source of truth for hosts: `inventory/hosts.yaml`.
Source of truth for infra domains: `inventory/domains/infra.yaml`.
