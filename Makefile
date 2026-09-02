# Makefile

# Define SED_INPLACE based on the operating system
ifeq ($(shell uname), Darwin)
  SED_INPLACE = sed -i ''
else
  SED_INPLACE = sed -i
endif

# Use npx so CI (no global install) and local dev both work.
# Override with: make up WP_ENV="wp-env"
WP_ENV = npx wp-env

# Ports — single source of truth for the Makefile. Keep in sync with the
# `port`/`testsPort` declared in .wp-env.docker.json.
# Chosen away from the common wp-env defaults (8888/8889) so documentate can
# run alongside other local WordPress stacks without freeing ports.
DOCKER_PORT       ?= 8989
DOCKER_TESTS_PORT ?= 8990

# Docker test config used for all wp-env run commands
DOCKER_CONFIG = --config=.wp-env.docker.json

# WP-CLI inside the Docker dev container (the instance the browser/tests use).
WP_CLI = $(WP_ENV) run cli $(DOCKER_CONFIG) wp

# ─── Port arbitration (local dev, safety net only) ───────────────────────────
# Ports 8989/8990 are documentate-specific and should not collide with other
# stacks. If something else is bound to them, stop those containers only.
# Skipped under CI ($$CI set) and a no-op when Docker is down.
# Usage: $(call free_ports,8989 8990)
define free_ports
	@if [ -z "$$CI" ] && docker version >/dev/null 2>&1; then \
		ids="$$(docker ps -q $(patsubst %,--filter publish=%,$(1)))"; \
		if [ -n "$$ids" ]; then \
			echo "Freeing port(s) '$(1)': stopping conflicting containers..."; \
			docker stop $$ids >/dev/null; \
		fi; \
	fi
endef

# Check if Docker is running
check-docker:
	@docker version  > /dev/null || (echo "" && echo "Error: Docker is not running. Please ensure Docker is installed and running." && echo "" && exit 1)

install-requirements:
	npm -g i @wordpress/env


# ─── Docker (port 8989, requires Docker) ─────────────────────────────────────

# The runtime translation files are generated rather than committed, and the
# wp-env configs run WordPress in Spanish, so they have to exist before the
# environment serves the plugin. Otherwise the site silently falls back to
# English and any test or manual check that reads translated UI is misleading.
start-docker-if-not-running: check-docker package-translations
	@if [ "$$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$(DOCKER_PORT))" = "000" ]; then \
		echo "Docker env is NOT running. Starting..."; \
		$(WP_ENV) start $(DOCKER_CONFIG) --update; \
		$(WP_CLI) plugin activate documentate; \
		echo "Visit http://localhost:$(DOCKER_PORT)/wp-admin/ to access the Docker environment."; \
	else \
		echo "Docker env is already running on port $(DOCKER_PORT), skipping start."; \
	fi

# Bring up Docker containers (this is the default `up`)
up: check-docker
	$(call free_ports,$(DOCKER_PORT) $(DOCKER_TESTS_PORT))
	@$(MAKE) --no-print-directory start-docker-if-not-running
up-docker: up

# Stop Docker containers (this is the default `down`)
down: check-docker
	$(WP_ENV) stop $(DOCKER_CONFIG)
down-docker: down

# ─── Clean / Destroy ─────────────────────────────────────────────────────────

# Clean the Docker environment (wp-env v11 replaced "clean" with "reset")
clean: check-docker
	$(WP_ENV) reset $(DOCKER_CONFIG) development
	$(WP_ENV) reset $(DOCKER_CONFIG) tests
	$(WP_CLI) plugin activate documentate
	$(WP_CLI) language core install es_ES --activate
	$(WP_CLI) site switch-language es_ES

destroy:
	$(WP_ENV) destroy

# ─── PHPUnit tests (Docker, port 8989) ───────────────────────────────────────

tests: test

# The WP test bootstrap reinstalls the tests database: two PHPUnit runs at
# once corrupt each other. Refuse to start while another run is alive.
# Keep the guard on its own recipe line: joined to the command that names
# ./vendor/bin/phpunit it would match its own shell under procps pgrep (Linux).
PHPUNIT_GUARD = if pgrep -f 'vendor/bin/[p]hpunit' >/dev/null 2>&1; then echo 'PHPUnit ya está en ejecución'; exit 1; fi

# Run unit tests with PHPUnit. Use FILE or FILTER (or both).
test: start-docker-if-not-running
	@$(PHPUNIT_GUARD)
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD --colors=always

# Run document generation tests only
test-generation: start-docker-if-not-running
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate ./vendor/bin/phpunit --testsuite=generation --colors=always

# Run unit tests in verbose mode. Honor TEST filter if provided.
test-verbose: start-docker-if-not-running
	@CMD="./vendor/bin/phpunit"; \
	if [ -n "$(TEST)" ]; then CMD="$$CMD --filter $(TEST)"; fi; \
	CMD="$$CMD --debug --verbose"; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD --colors=always

# Run tests with code coverage report.
# IMPORTANT: Requires wp-env started with Xdebug enabled:
#   wp-env start --config=.wp-env.docker.json --xdebug=coverage
# If coverage shows 0%, restart wp-env with the --xdebug=coverage flag.
test-coverage: start-docker-if-not-running
	@$(PHPUNIT_GUARD)
	@mkdir -p artifacts/coverage
	@CMD="env XDEBUG_MODE=coverage ./vendor/bin/phpunit --colors=always --coverage-text=artifacts/coverage/coverage.txt --coverage-html artifacts/coverage/html --coverage-clover artifacts/coverage/clover.xml"; \
	if [ -n "$(FILE)" ]; then CMD="$$CMD $(FILE)"; fi; \
	if [ -n "$(FILTER)" ]; then CMD="$$CMD --filter $(FILTER)"; fi; \
	$(WP_ENV) run tests-cli $(DOCKER_CONFIG) --env-cwd=wp-content/plugins/documentate $$CMD; \
	EXIT_CODE=$$?; \
	echo ""; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "                    COVERAGE SUMMARY                        "; \
	echo "════════════════════════════════════════════════════════════"; \
	grep -E "^\s*(Lines|Functions|Classes|Methods):" artifacts/coverage/coverage.txt 2>/dev/null || echo "Coverage data not available"; \
	echo "════════════════════════════════════════════════════════════"; \
	echo "Full report: artifacts/coverage/html/index.html"; \
	echo ""; \
	exit $$EXIT_CODE

# ─── E2E tests ────────────────────────────────────────────────────────────────

# Ensure dev environment (port 8989) has admin user and plugin active for E2E
setup-e2e-env:
	@echo "Setting up E2E environment..."
	@$(WP_CLI) core install \
		--url=http://localhost:$(DOCKER_PORT) \
		--title="Documentate Tests" \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.com \
		--skip-email 2>/dev/null || true
	@$(WP_CLI) language core install es_ES --activate 2>/dev/null || true
	@$(WP_CLI) site switch-language es_ES 2>/dev/null || true
	@$(WP_CLI) plugin activate documentate 2>/dev/null || true
	@$(WP_CLI) rewrite structure '/%postname%/' --hard 2>/dev/null || true

# Run E2E tests against Docker (port 8989) — the default.
test-e2e: start-docker-if-not-running setup-e2e-env
	WP_BASE_URL=http://localhost:$(DOCKER_PORT) npm run test:e2e -- $(ARGS)

# Alias kept for CI / back-compat.
test-e2e-docker: test-e2e

# Run E2E tests with the visual UI / inspector (Docker).
test-e2e-visual: start-docker-if-not-running setup-e2e-env
	WP_BASE_URL=http://localhost:$(DOCKER_PORT) npm run test:e2e -- --ui $(ARGS)

# ─── Capturas (informe con capturas del ciclo completo) ──────────────────────

# Recorre la aplicación con Playwright y deja capturas/informe.html: verificación
# de que el ciclo funciona de punta a punta y base del manual.
# Admite SOLO=escritorio|movil y DOCUMENTATE_SIN_SEMBRAR=1.
#
# El guion siembra la demo y recorre el ciclo en el sitio de desarrollo
# (puerto $(DOCKER_PORT)), el mismo que usan los E2E: si hay una tanda de
# pruebas viva se para aquí en vez de pisarle los datos a mitad de una prueba.
# Los corchetes del patrón evitan que pgrep encuentre el propio shell de esta
# receta, cuya línea de órdenes contiene el patrón; un servidor MCP de
# Playwright no cuenta, porque no escribe en el sitio.
capturas: start-docker-if-not-running setup-e2e-env
	@if pgrep -f '[t]est-playwright|[p]laywright/cli\.js|[c]apturas\.mjs' > /dev/null 2>&1; then \
		echo ""; \
		echo "Error: hay una tanda de Playwright en marcha (¿make test-e2e?, ¿otras capturas?)."; \
		echo "Las capturas y los E2E escriben en el mismo sitio; espera a que termine."; \
		echo ""; \
		exit 1; \
	fi
	@npx playwright install chromium
	@DOCUMENTATE_URL=http://localhost:$(DOCKER_PORT) node scripts/capturas.mjs
	@echo "Informe: $(CURDIR)/capturas/informe.html"

# ─── WP-CLI helpers (Docker) ─────────────────────────────────────────────────

flush-permalinks:
	$(WP_CLI) rewrite structure '/%postname%/'

# Function to create a user only if it does not exist
create-user:
	@if [ -z "$(USER)" ] || [ -z "$(EMAIL)" ] || [ -z "$(ROLE)" ]; then \
		echo "Error: Please, specify USER, EMAIL, ROLE and PASSWORD. Usage: make create-user USER=test1 EMAIL=test1@example.org ROLE=editor PASSWORD=password"; \
		exit 1; \
	fi
	$(WP_ENV) run cli $(DOCKER_CONFIG) sh -c 'wp user list --field=user_login | grep -q "^$(USER)$$" || wp user create $(USER) $(EMAIL) --role=$(ROLE) --user_pass=$(PASSWORD)'

logs:
	$(WP_ENV) logs $(DOCKER_CONFIG)

logs-test:
	$(WP_ENV) logs $(DOCKER_CONFIG) --environment=tests

# Finds the CLI container used by wp-env (Docker)
cli-container:
	@docker ps --format "{{.Names}}" \
	| grep "\-cli\-" \
	| grep -v "tests-cli" \
	|| ( \
		echo "No main CLI container found. Please run 'make up-docker' first." ; \
		exit 1 \
	)

# ─── Plugin check (Docker) ───────────────────────────────────────────────────

# Pass the wp plugin-check with proper error handling
check-plugin: check-docker start-docker-if-not-running
	# Install plugin-check if needed (don't fail if already active)
	@$(WP_CLI) plugin install plugin-check --activate --color || true

	# Run plugin check; wp-env run always exits 0, so we grep the output for ERRORs.
	@echo "Running WordPress Plugin Check..."
	@TMPFILE=$$(mktemp); \
	$(WP_CLI) plugin check documentate \
		--exclude-directories=tests \
		--exclude-checks=file_type,image_functions \
		--ignore-warnings \
		--color 2>&1 | tee $$TMPFILE; \
	ERRORS=$$(sed 's/\x1B\[[0-9;]*[mK]//g' $$TMPFILE | grep -cE '\bERROR\b' || true); \
	rm -f $$TMPFILE; \
	echo ""; \
	if [ "$$ERRORS" -gt 0 ]; then \
		echo "Plugin Check: ✗ $$ERRORS error(s) found."; \
		exit 1; \
	else \
		echo "Plugin Check: ✓ No errors found."; \
	fi

# ─── Linting & Code Quality ──────────────────────────────────────────────────

# Combined check for lint, tests, untranslated, and more.
# Verification targets do not modify source files.
check: lint check-plugin test check-untranslated mo

check-all: check

# Ensure PHP_CodeSniffer is available. Does not install dependencies automatically.
require-phpcs:
	@if [ ! -x "./vendor/bin/phpcs" ]; then \
		echo "Error: PHP_CodeSniffer is not installed."; \
		echo "Run: composer install --prefer-dist"; \
		exit 1; \
	fi

# Check WordPress Coding Standards. This is the default PHP lint target.
# Canonical tool: PHPCS + WPCS (.phpcs.xml.dist). Does not modify files.
lint: require-phpcs
	composer phpcs

# Automatically fix WordPress Coding Standards violations with PHPCBF.
fix: require-phpcs
	composer phpcbf

# Non-interactive aliases for git hooks.
fix-no-tty: fix
lint-no-tty: lint

# Optional secondary Mago tooling. Not part of default checks or CI.
# May be removed if it does not provide enough additional value.
require-mago:
	@if [ ! -x "./vendor/bin/mago" ]; then \
		echo "Error: Mago is not installed."; \
		echo "Run: composer install --prefer-dist"; \
		exit 1; \
	fi

mago-lint: require-mago
	composer mago:lint

mago-format: require-mago
	composer mago:format
# Run PHP Mess Detector ignoring vendor and node_modules
phpmd:
	phpmd . text cleancode,codesize,controversial,design,naming,unusedcode --exclude vendor,node_modules,tests

# ─── Composer / Translations / Packaging ─────────────────────────────────────

# Update Composer dependencies
update: check-docker
	composer update --no-cache --with-all-dependencies
	npm update

# Generate a .pot file for translations
pot:
	composer make-pot

# Update .po files from .pot file
po:
	composer update-po

# Generate .mo files from .po files
mo:
	composer make-mo

# Generate .l10n.php files from .po files.
# WordPress 6.5+ loads these instead of the .mo when both are present; the .mo
# stays as the fallback for the 6.1-6.4 range declared in readme.txt.
l10n-php:
	composer make-php

# Build and validate the runtime translation files that ship in the package.
# They are generated from the committed .po sources and deliberately kept out
# of the repository, so packaging must never assume they are already present.
package-translations: mo l10n-php
	@set -e; \
	found=0; \
	for po in languages/documentate-*.po; do \
		if [ ! -e "$$po" ]; then continue; fi; \
		found=1; \
		mo="$${po%.po}.mo"; \
		l10n="$${po%.po}.l10n.php"; \
		for f in "$$mo" "$$l10n"; do \
			if [ ! -s "$$f" ]; then \
				echo "Error: Missing or empty generated translation file: $$f" >&2; \
				exit 1; \
			fi; \
		done; \
	done; \
	if [ "$$found" -eq 0 ]; then \
		echo "Error: No translation source files found under languages/." >&2; \
		exit 1; \
	fi

# Check the untranslated strings
check-untranslated:
	composer check-untranslated

# Generate the LibreOffice WASM glue that the plugin ships but does not track.
# `wp dist-archive` packages the working tree and ignores .gitignore, so these
# files only need to exist on disk at packaging time.
package-assets:
	@if [ -d node_modules/@matbee/libreoffice-converter ]; then \
		npm run --silent copy:libreoffice-converter; \
	else \
		echo "node_modules missing, installing to generate the WASM glue..."; \
		npm ci --no-audit --no-fund --silent; \
	fi
	@test -f admin/vendor/libreoffice-converter/dist/browser.js \
		|| { echo "Error: LibreOffice WASM glue was not generated."; exit 1; }

# Generate the documentate-X.X.X.zip package
package:
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: No se ha especificado una versión. Usa 'make package VERSION=1.2.3'"; \
		exit 1; \
	fi
	$(MAKE) package-assets
	$(MAKE) package-translations

	# Update the version in documentate.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           $(VERSION)/" documentate.php
	$(SED_INPLACE) "s/define( *'DOCUMENTATE_VERSION', '[^']*'/define( 'DOCUMENTATE_VERSION', '$(VERSION)'/" documentate.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: $(VERSION)/" readme.txt

	@# Create the ZIP package with proper folder structure.
	@# `wp dist-archive` reads .distignore straight from the working tree, so the
	@# generated files that .gitignore keeps out of the repository (the WASM glue,
	@# the runtime translations) are packaged like any other file.
	@# --plugin-dirname is what makes the archive extract as documentate/. Without
	@# it WordPress names the plugin folder after the ZIP file and every release
	@# lands in a new directory.
	@# `--force` does not empty an existing archive: dist-archive 3.1 shells out
	@# to the `zip` binary, which ADDS to one. Without this removal a file that a
	@# new .distignore rule excludes would survive from an earlier build.
	rm -f "$(CURDIR)/documentate-$(VERSION).zip"
	./vendor/bin/wp dist-archive . "$(CURDIR)/documentate-$(VERSION).zip" \
		--plugin-dirname=documentate --force

	# Restore the version in documentate.php & readme.txt
	$(SED_INPLACE) "s/^ \* Version:.*/ * Version:           0.0.0/" documentate.php
	$(SED_INPLACE) "s/define( *'DOCUMENTATE_VERSION', '[^']*'/define( 'DOCUMENTATE_VERSION', '0.0.0'/" documentate.php
	$(SED_INPLACE) "s/^Stable tag:.*/Stable tag: 0.0.0/" readme.txt

# ─── Help ─────────────────────────────────────────────────────────────────────

# Show help with available commands
help:
	@echo "Available commands:"
	@echo ""
	@echo "Environments:"
	@echo "  up / up-docker     - Start the Docker environment on port $(DOCKER_PORT)"
	@echo "  down / down-docker - Stop the Docker environment"
	@echo "  logs               - Show Docker container logs"
	@echo "  logs-test          - Show Docker test environment logs"
	@echo "  clean              - Reset Docker environment"
	@echo "  destroy            - Destroy all wp-env environments"
	@echo "  flush-permalinks   - Flush permalinks (Docker)"
	@echo "  create-user        - Create a WordPress user (Docker)"
	@echo "                       Usage: make create-user USER=<username> EMAIL=<email> ROLE=<role> PASSWORD=<password>"
	@echo ""
	@echo "Linting & Code Quality:"
	@echo "  fix                - Fix PHP with PHPCBF and WordPress Coding Standards"
	@echo "  lint               - Check PHP with PHPCS and WordPress Coding Standards"
	@echo "  fix-no-tty         - Alias for fix (for git hooks)"
	@echo "  lint-no-tty        - Alias for lint (for git hooks)"
	@echo "  mago-lint          - Optional secondary Mago lint"
	@echo "  mago-format        - Optional secondary Mago formatter"
	@echo "  check-plugin       - Run WordPress plugin-check (Docker)"
	@echo "  check-untranslated - Check for untranslated strings"
	@echo "  check              - Run lint, plugin-check, tests, untranslated, and mo"
	@echo "  check-all          - Alias for check"
	@echo ""
	@echo "Testing:"
	@echo "  test               - Run PHPUnit tests (Docker, port $(DOCKER_PORT))"
	@echo "                       FILTER=<pattern> (run tests matching the pattern)"
	@echo "                       FILE=<path>      (run tests in specific file)"
	@echo "  test-generation    - Run document generation tests only (Docker)"
	@echo "  test-coverage      - Run PHPUnit with coverage (Docker, requires --xdebug=coverage)"
	@echo ""
	@echo "  test-e2e           - Run E2E tests against Docker (port $(DOCKER_PORT))"
	@echo "  test-e2e-visual    - Run E2E tests with visual UI (Docker)"
	@echo "  capturas           - Walk the whole cycle and write capturas/informe.html"
	@echo "                       SOLO=escritorio|movil (one screen size only)"
	@echo ""
	@echo "Translations:"
	@echo "  pot                - Generate a .pot file for translations"
	@echo "  po                 - Update .po files from .pot file"
	@echo "  mo                 - Generate .mo files from .po files"
	@echo ""
	@echo "Packaging & Updates:"
	@echo "  update             - Update Composer dependencies"
	@echo "  package            - Create ZIP package. Usage: make package VERSION=x.y.z"
	@echo ""
	@echo "  help               - Show this help message"

# Set help as the default target if no target is specified
.DEFAULT_GOAL := help
