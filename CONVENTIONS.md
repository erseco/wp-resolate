# Development Conventions for Documentate Plugin

## Coding Style Guidelines
- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP, HTML, CSS, and JavaScript.
- Use spaces for indentation, with four spaces per level.
- Limit lines to 80 characters where possible.
- Use `snake_case` for functions, methods, and variables; `CamelCase` for classes.
- Name files with lowercase letters and hyphens (e.g., `class-documentate-admin.php`).
- Include a file header in all PHP files with a brief description and author information.

## Code Comments
- Write comments in clear and concise English.
- Use PHPDoc blocks for all functions, methods, classes, and files.
- Use inline comments sparingly to explain non-obvious parts of the code.

## Code Clarity
- Write self-documenting code with meaningful names.
- Avoid magic numbers; use constants or descriptive variables instead.

## Test-Driven Development (TDD)
- Use a Test-Driven Development (TDD) approach where possible.
- Write unit tests with PHPUnit for PHP and Jest for JavaScript.
- Integrate testing into the development workflow, running tests automatically before merging.

### Version Control
- Use Git with feature branches for version control.
- Write clear, concise commit messages in English, using the imperative mood.
- Submit pull requests for all changes, ensuring review before merging.

## Directory Structure
- The main plugin file should be named `documentate.php`.
- Place each class in its own file, named `class-pluginname-component.php` (e.g., `class-documentate-admin.php`).
- Place admin-specific functionality in the `admin` directory, including classes, assets, and templates.
- Place shared functions, utilities, and custom post types in the `includes` directory.
- Place public-facing functionality in the `public` directory.
- Place tests in the `tests` directory.

## Constants and Configuration
- Define plugin-specific constants in the main plugin file (`documentate.php`).
- Use the WordPress options API for configuration, following a consistent naming scheme.

## Security Best Practices
- Validate all user inputs and sanitize outputs using WordPress functions like `sanitize_text_field()` and `esc_html()`.
- Use WordPress nonces for form submissions and AJAX requests to prevent CSRF attacks.

## Interface language

- La interfaz está en español directamente en el código; no hay i18n ni ficheros de traducción.
- Escape the literals on output (`esc_html()`, `esc_attr()`, `esc_url()`); code, comments and docblocks stay in English.

## Code Documentation
- Ensure that all classes, methods, and functions are well-documented with PHPDoc.
- The `README.txt` should provide a detailed overview, including installation instructions, usage, and a changelog.

