# Versioning

apidcms follows **Semantic Versioning** (SemVer): `MAJOR.MINOR.PATCH`

## Scheme

| Bump | When |
|------|------|
| **MAJOR** | Breaking changes, incompatible API, new architecture |
| **MINOR** | New features, new page types, new subsystems (backward compatible) |
| **PATCH** | Bug fixes, UI polish, performance, security (backward compatible) |

## Sources of truth

1. **Changelog DB** (`changelog` table on apidcms.dezajno.ru) — canonical version history
2. **Git tags** — mirror the same versions on the repository
3. **VERSION file** — single-line file with current version, updated on each release
4. **install.php** — `INSTALLER_VERSION` constant, updated on each release

## Release checklist

- [ ] Add entry to `changelog` table on apidcms.dezajno.ru
- [ ] Update `VERSION` file
- [ ] Update `INSTALLER_VERSION` in `install.php`
- [ ] Update `CHANGELOG.md` (both `www/` and `www/core_lib/`)
- [ ] Git commit + tag (e.g. `v1.3.0`)
- [ ] `sync-core all` to distribute to projects

## History

| Version | Date | Highlights |
|---------|------|------------|
| v1.3.1 | 13 Jul 2026 | Installer fixes, core_lib recovery |
| v1.3.0 | 12 Jul 2026 | Auto-installer, WAL mode, sync-core protection |
| v1.2.0 | 10 Jul 2026 | Dynamic type, docs section, new landing |
| v1.1.0 | 18 Jun 2026 | Plugin system, AI security, visit stats |
| v1.0.1 | 15 Jun 2026 | File manager, Markdown filter |
| v1.0.0 | 14 Jun 2026 | First stable release |
