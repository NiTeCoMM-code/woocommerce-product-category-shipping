# Contributing

## Reporting Bugs

Open an issue with:
- WordPress version
- WooCommerce version
- PHP version
- Steps to reproduce
- Expected vs actual behaviour

## Pull Requests

1. Fork the repository and create a feature branch from `main`.
2. Run `composer cs` before committing to catch style issues.
3. Ensure all PHP files pass `php -l` syntax check.
4. Submit a pull request targeting `main`.

## Code Style

[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) (WPCS) are enforced via `composer cs` and `composer cs-fix`.

## PHP Version Support

The plugin targets PHP 7.4 as the minimum. Run `composer compatibility` to check for compatibility issues.
