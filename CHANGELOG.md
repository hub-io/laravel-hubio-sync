# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/hub-io/laravel-hubio-sync/compare/v0.1.0...HEAD)

## [v0.1.0](https://github.com/hub-io/laravel-hubio-sync/releases/tag/v0.1.0) - 2026-11-01

### Added

* Anonymous device registration with a stable identity and the personal backup household.
* Family / work households with invite codes.
* Two-way sync of syncable models, photos and deletions; the newest change wins.
* Background sync (`SyncTask`) and push-triggered sync (`SyncRequested`).
* Shared records with codes and 1–5 star ratings (`Shares`).
