# Contributing

## Development Setup
1. Clone the repository
2. Run `composer install`
3. Copy `.env.example` to `.env` and configure
4. Run tests: `composer test`

## Testing
- Unit tests: `vendor/bin/phpunit --testsuite Unit`
- Integration tests: `vendor/bin/phpunit --testsuite Integration`
- Static analysis: `vendor/bin/phpstan analyze`

## Pull Request Process
1. Ensure tests pass
2. Update documentation
3. Follow PSR-12 coding standards
