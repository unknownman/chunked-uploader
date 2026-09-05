# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-05

### Added

- Framework-agnostic PHP 8.2 chunk upload orchestration.
- O(1)-memory stream assembly with local chunk storage.
- Redis-backed upload metadata and atomic progress tracking.
- HMAC upload token generation and verification.
- Strict path sanitization and magic-byte MIME inspection.
- Redis rate limiting and injectable ClamAV scanning.
- Validation pipeline for MIME, extension, chunk-size, and total-size rules.
- Laravel, Symfony, and vanilla JavaScript integration examples.
- PHPUnit 10-compatible unit and feature test suite.
