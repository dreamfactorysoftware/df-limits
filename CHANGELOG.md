# Change Log
All notable changes to this project will be documented in this file.
This project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]
## [0.7.0] - 2017-12-28
### Added
- Added package discovery
### Changed
- DF-1150 Update copyright and support email
- Used the new df-system repo
- Used beefed up ServiceManager methods
### Fixed
- Fixed for updated Laravel RateLimiter
- Fixed return as INT on DELETE, both single and bulk resource
- Fixed resolve limit period on 429 errors

## [0.6.0] - 2017-11-03
- Add subscription requirements to service provider
- Upgrade Swagger to OpenAPI 3.0 specification

## [0.5.2] - 2017-09-18
### Added
- Adding redisprefix to limitcache instantiation

## [0.5.1] - 2017-08-17
### Fixed
- Evaluate limits bug fix

## [0.5.0] - 2017-08-17
### Changed
- Reworked API doc usage and generation
### Fixed
- Fix swagger def to pass validation

## [0.4.0] - 2017-07-27
### Added
- Trigger service modified event to clear event cache when limit is created or deleted
- Added listener and basic event functionality for exceeded limits
- Added system limit events to fire when limits are exceeded
- Added enrichment for limit periods to event firing
### Changed
- Changed Limit events to id, not key_text
- Added more API Doc info to limit_cache
- Bailing at earliest opportunity for admin and script requested calls on limit checks

## [0.3.2] - 2017-06-26
### Added
- Added functionality for basic-auth requests and each_user limits

## [0.3.1] - 2017-06-14
### Added
- Added casting to fix sqlite issue on active / inactive limits

## [0.3.0] - 2017-06-05
### Added
- Added related limit_cache_by_limit_id functionality to GET, POST, and PATCH
### Changed
- Cleanup - removal of php-utils dependency
- Remove unnecessary dropIndex() calls after dropForeign(), breaking migrations on sqlsrv
- Separate limit cache config into its own file
- Fixing limit_cache relations with single array element
- Allowed endpoints to be saved as null for evaluation as top level resource

## [0.2.0] - 2017-04-21
### Added
- Added endpoint support to available limit types

## [0.1.0] - 2017-03-03
First official release of this library.

[Unreleased]: https://github.com/dreamfactorysoftware/df-limits/compare/0.7.0...HEAD
[0.7.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.5.2...0.6.0
[0.5.2]: https://github.com/dreamfactorysoftware/df-limits/compare/0.5.1...0.5.2
[0.5.1]: https://github.com/dreamfactorysoftware/df-limits/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.3.2...0.4.0
[0.3.2]: https://github.com/dreamfactorysoftware/df-limits/compare/0.3.1...0.3.2
[0.3.1]: https://github.com/dreamfactorysoftware/df-limits/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/dreamfactorysoftware/df-limits/compare/0.1.0...0.2.0
