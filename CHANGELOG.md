# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.5.1] - 2026-04-11

### Added
- **Statistics Tab**: New administration tab exposing cache and database metrics
  (`CacheStatisticsService`, `DatabaseStatisticsService`, `StatisticsController`).
- **Queue Manager**: Show Redis and AMQP queue counts in the `QueueChecker`,
  in addition to the existing Doctrine transport.
- **Elasticsearch Tools**: Dry-run and orphaned-index cleanup actions in the
  admin UI, backed by new `getUnusedIndices`, `getOrphanedIndices` and
  `deleteOrphanedIndices` methods on `ElasticsearchManager`.
- `CLAUDE.md` documenting the database change policy for contributors.

### Changed
- `ElasticsearchManager::deleteUnusedIndices()` now returns a structured
  result (`array{deleted, errors}`) instead of `void`; the
  `DeleteUnusedIndicesCommand` reports deleted indices and surfaces errors.
- `QueueChecker` distinguishes in-flight state and restores the Doctrine
  message age display.
- Queue `recommended` label is localized per `QueueChecker` path.

### Fixed
- `QueueChecker` count is reconciled with the increment gateway (#393).
- `QueueChecker` ignores stale `messenger_messages` rows (#393).
- Defensive guards for non-Doctrine messenger transports (#393).
- Empty context menu is hidden in the table-sizes grid.
- `queries/sec` formatting corrected and stat-card font size reduced.
- Snippets are now registered explicitly in the `frosh-tools` module.

[3.5.1]: https://github.com/FriendsOfShopware/FroshTools/releases/tag/3.5.1
