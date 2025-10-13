# Resolate

![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![Language](https://img.shields.io/badge/Language-PHP-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/ateeducacion/wp-resolate/total)
![Last Commit](https://img.shields.io/github/last-commit/ateeducacion/wp-resolate)
![Open Issues](https://img.shields.io/github/issues/ateeducacion/wp-resolate)

**Resolate** es un plugin de WordPress para la generación de resoluciones oficiales con estructura de secciones y exportación a DOCX.

## Demo

Try Resolate instantly in your browser using WordPress Playground! The demo includes sample data to help you explore the features. Note that all changes will be lost when you close the browser window, as everything runs locally in your browser.

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-resolate/refs/heads/main/blueprint.json)


### Key Features

- **Customization**: Adjustable settings available in the WordPress admin panel.
- **Multisite Support**: Fully compatible with WordPress multisite installations.
- **WordPress Coding Standards Compliance**: Adheres to [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) for quality and security.
- **Continuous Integration Pipeline**: Set up for automated code verification and release generation on GitLab.
- **Individual Calendar Feeds**: Subscribe to event-type specific calendar URLs (Meeting, Absence, Warning and Alert).

## Installation

1. **Download the latest release** from the [GitHub Releases page](https://github.com/ateeducacion/wp-resolate/releases).
2. Upload the downloaded ZIP file to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin under 'Settings' by providing the necessary Nextcloud API details.

## Development

For development, you can bring up a local WordPress environment with the plugin pre-installed by using the following command:

```bash
make up
```

This command will start a Dockerized WordPress instance accessible at [http://localhost:8888](http://localhost:8080) with the default admin username `admin` and password `password`. 
