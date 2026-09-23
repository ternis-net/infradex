# DNS

Self-hosted set (`one.ns.ternis.net` 77.90.60.110 kvm10.eyl,
`two.ns.ternis.net` 94.249.188.145 kvm11.eyl, `three.ns` deprecated):
serves `tstatus.de`, `ternisdomains.de`.

Cloudflare (`margot/martin.ns.cloudflare.com`):
`ternis.net` -> 77.90.15.49, `ternis.org` -> 77.90.15.3,
`example-dns.com/net` -> 77.90.60.110, `example-dns.org` -> 94.249.188.145.

Mixed (Cloudflare + self-hosted): `ternis.dev` -> 77.90.15.2.

nameserver-eu (`nameserver01-06.eu`, SOA `nameserver01.eu` / `mail.threatoff.eu`):
`thosted.de` -> 77.90.15.2, `ternis.link` -> 77.90.61.239, `dnbx.de` -> 77.90.15.4.

`hosting_ref` in `infra.yaml` is hosting location, NOT rDNS.
Verified 2026-09-23 via dig, see `inventory/domains/infra.yaml`.

Source of truth for hosts: `inventory/hosts.yaml`.
Source of truth for infra domains: `inventory/domains/infra.yaml`.
