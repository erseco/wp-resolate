<?php
/**
 * Document converter template for LibreOffice WASM (browser) mode.
 *
 * This template is loaded in a popup window via admin-post.php, which sends the
 * required COOP/COEP headers so SharedArrayBuffer is available. All conversion
 * parameters are passed via the URL query string. The WASM runtime is loaded from
 * plugin-local assets copied from @matbee/libreoffice-converter.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// This template is included by Documentate_Admin_Helper::render_converter_page()
// which handles headers, permission checks, and nonce validation.

require_once plugin_dir_path( __FILE__ ) . '../includes/class-documentate-libreoffice-wasm-converter.php';

// Get conversion parameters from the validated request.
$documentate_document_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
$documentate_target_format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'pdf';
$documentate_source_format = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : 'odt';
$documentate_output_action = isset( $_GET['output'] ) ? sanitize_key( $_GET['output'] ) : 'preview';
$documentate_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$documentate_use_channel = isset( $_GET['use_channel'] ) && '1' === $_GET['use_channel'];

// Plugin-local browser runtime assets and the converter wrapper module.
$documentate_wasm_config = Documentate_Libreoffice_Wasm_Converter::get_browser_config();
$documentate_wrapper_url = plugins_url( 'admin/js/documentate-libreoffice-wasm.js', DOCUMENTATE_PLUGIN_FILE );

$documentate_converter_config = array_merge(
	$documentate_wasm_config,
	array(
		'postId' => $documentate_document_id,
		'targetFormat' => $documentate_target_format,
		'sourceFormat' => $documentate_source_format,
		'outputAction' => $documentate_output_action,
		'nonce' => $documentate_nonce,
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'wrapperUrl' => $documentate_wrapper_url,
		'useChannel' => $documentate_use_channel,
	)
);

?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( 'Conversor de Documentate' ); ?></title>
	<style>
		body {
			margin: 0;
			padding: 20px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			min-height: calc(100vh - 40px);
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.status {
			padding: 30px 40px;
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			text-align: center;
			max-width: 400px;
		}
		.status h2 {
			margin: 0 0 10px;
			color: #1d2327;
			font-size: 1.3em;
		}
		.status p {
			margin: 0;
			color: #50575e;
		}
		.spinner {
			width: 50px;
			height: 50px;
			margin: 0 auto 20px;
			border: 4px solid #f3f3f3;
			border-top: 4px solid #2271b1;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		.error { color: #d63638; }
		.error h2 { color: #d63638; }
		.success { color: #00a32a; }
		.success h2 { color: #00a32a; }
	</style>
</head>
<body>
	<div class="status" id="status">
		<div class="spinner" id="spinner"></div>
		<h2 id="status-title"><?php echo esc_html( 'Iniciando...' ); ?></h2>
		<p id="status-message"><?php echo esc_html( 'Preparando el conversor de documentos.' ); ?></p>
	</div>

	<script type="module">
		// Conversion parameters and asset URLs from PHP (validated / localized).
		const conversionConfig = <?php echo wp_json_encode( $documentate_converter_config ); ?>;
		const strings = conversionConfig.strings || {};

		// BroadcastChannel for sending results to opener (when useChannel is true).
		const channel = conversionConfig.useChannel ? new BroadcastChannel('documentate_converter') : null;

		// Helper to send progress/results via channel.
		function sendToChannel(status, data, error) {
			if (channel) {
				channel.postMessage({
					type: 'conversion_result',
					status,
					data,
					error
				});
			}
		}

		// Debug info.
		console.log('Documentate: crossOriginIsolated =', window.crossOriginIsolated);
		console.log('Documentate: SharedArrayBuffer =', typeof SharedArrayBuffer !== 'undefined');
		console.log('Documentate: Config =', conversionConfig);

		const statusTitle = document.getElementById('status-title');
		const statusMessage = document.getElementById('status-message');
		const spinner = document.getElementById('spinner');
		const statusDiv = document.getElementById('status');

		function updateStatus(title, message, isError = false, isSuccess = false) {
			statusTitle.textContent = title;
			statusMessage.textContent = message;
			statusDiv.classList.remove('error', 'success');
			if (isError) {
				statusDiv.classList.add('error');
				spinner.style.display = 'none';
			}
			if (isSuccess) {
				statusDiv.classList.add('success');
				spinner.style.display = 'none';
			}

			// Send progress to opener via channel.
			if (!isError && !isSuccess) {
				sendToChannel('progress', { title, message });
			}
		}

		const mimeTypes = {
			pdf: 'application/pdf',
			docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			odt: 'application/vnd.oasis.opendocument.text'
		};

		// LibreOffice WASM converter instance (created after asset/header checks).
		let wasmConverter = null;

		async function init() {
			try {
				// Guard: the WASM runtime assets must be installed.
				if (!conversionConfig.assetsAvailable) {
					throw new Error(strings.missingAssets || 'The LibreOffice WASM assets are not installed.');
				}

				// Guard: SharedArrayBuffer requires a cross-origin isolated context (COOP/COEP).
				if (typeof SharedArrayBuffer === 'undefined' || !window.crossOriginIsolated) {
					throw new Error(strings.sharedArrayBufferError || 'SharedArrayBuffer is not available.');
				}

				// Step 1: Load the LibreOffice WASM runtime and the wrapper.
				updateStatus(
					strings.loading || 'Loading LibreOffice...',
					strings.loadingDetail || 'Downloading LibreOffice WASM components.'
				);

				const { WorkerBrowserConverter, createWasmPaths } = await import(conversionConfig.moduleUrl);
				const { createLibreOfficeWasmConverter } = await import(conversionConfig.wrapperUrl);

				wasmConverter = createLibreOfficeWasmConverter({
					WorkerBrowserConverter,
					createWasmPaths,
					wasmBaseUrl: conversionConfig.wasmBaseUrl,
					workerUrl: conversionConfig.workerUrl,
					sofficeWasm: conversionConfig.sofficeWasmUrl,
					sofficeData: conversionConfig.sofficeDataUrl,
					outputFormat: conversionConfig.targetFormat,
					strings,
					onProgress: (info) => {
						if (info && info.message) {
							updateStatus(strings.converting || 'Converting to PDF...', info.message);
						}
					}
				});

				await wasmConverter.init();

				// Step 2: Generate the source document via AJAX.
				updateStatus(
					strings.generating || 'Generating document...',
					strings.generatingDetail || 'Processing template on server.'
				);

				const formData = new FormData();
				formData.append('action', 'documentate_generate_document');
				formData.append('post_id', conversionConfig.postId);
				formData.append('format', conversionConfig.sourceFormat);
				formData.append('output', 'download');
				formData.append('_wpnonce', conversionConfig.nonce);

				const ajaxResponse = await fetch(conversionConfig.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});
				const ajaxData = await ajaxResponse.json();

				if (!ajaxData.success || !ajaxData.data?.url) {
					throw new Error(ajaxData.data?.message || (strings.errorGeneric || 'Conversion error.'));
				}

				// Step 3: Fetch the source document.
				updateStatus(
					strings.downloading || 'Downloading document...',
					strings.downloadingDetail || 'Fetching source document.'
				);

				const sourceResponse = await fetch(ajaxData.data.url, { credentials: 'same-origin' });
				if (!sourceResponse.ok) {
					throw new Error(`Failed to fetch source: ${sourceResponse.status}`);
				}
				const sourceBuffer = await sourceResponse.arrayBuffer();

				// Step 4: Convert using the LibreOffice WASM worker.
				updateStatus(
					strings.converting || 'Converting to PDF...',
					strings.convertingDetail || 'Processing with LibreOffice WASM.'
				);

				const sourceFilename = 'documento.' + conversionConfig.sourceFormat;
				const result = await wasmConverter.convert(new Uint8Array(sourceBuffer), sourceFilename);
				const outputData = result.data.buffer;

				// Step 5: Handle the result.
				const blob = new Blob([outputData], { type: result.mimeType || mimeTypes[conversionConfig.targetFormat] || 'application/octet-stream' });
				const blobUrl = URL.createObjectURL(blob);

				if (conversionConfig.useChannel) {
					if (conversionConfig.outputAction === 'preview' && conversionConfig.targetFormat === 'pdf') {
						// For preview: reuse this popup window to show the PDF.
						sendToChannel('preview_ready', { message: 'PDF ready in popup' });

						const width = Math.min(900, screen.availWidth - 100);
						const height = Math.min(700, screen.availHeight - 100);
						const left = Math.round((screen.availWidth - width) / 2);
						const top = Math.round((screen.availHeight - height) / 2);

						window.resizeTo(width, height);
						window.moveTo(left, top);
						window.focus();

						window.location.href = blobUrl;
					} else {
						// For download: send result via BroadcastChannel.
						sendToChannel('success', {
							outputData: outputData,
							outputFormat: conversionConfig.targetFormat
						});

						updateStatus(
							strings.completed || 'Completed!',
							strings.completedDetail || 'Document converted.',
							false,
							true
						);

						setTimeout(() => window.close(), 1000);
					}
				} else {
					// Legacy mode: handle directly in the popup.
					if (conversionConfig.outputAction === 'preview') {
						window.location.href = blobUrl;
					} else {
						const a = document.createElement('a');
						a.href = blobUrl;
						a.download = `documento.${conversionConfig.targetFormat}`;
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);

						updateStatus(
							strings.completed || 'Completed!',
							strings.completedDetail || 'Document converted.',
							false,
							true
						);

						setTimeout(() => window.close(), 2000);
					}
				}

			} catch (error) {
				console.error('Documentate conversion error:', error);

				if (conversionConfig.useChannel) {
					sendToChannel('error', null, error.message || (strings.errorGeneric || 'Conversion error.'));
				}

				updateStatus(
					strings.error || 'Error',
					error.message || (strings.errorGeneric || 'Conversion error.'),
					true
				);
			}
		}

		// Start immediately.
		init();
	</script>
</body>
</html>
