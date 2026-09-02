<?php
/**
 * Document converter template for Collabora in WordPress Playground.
 *
 * This template is loaded in a popup window and uses JavaScript fetch() to communicate
 * with the Collabora proxy, bypassing PHP's wp_remote_post which doesn't work well
 * with multipart/form-data in Playground's networking layer.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

// This template is included by Documentate_Admin_Helper::render_collabora_playground_page()
// which handles headers, permission checks, and nonce validation.

// Get conversion parameters from the validated request.
$documentate_document_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
$documentate_target_format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'pdf';
$documentate_source_format = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : 'odt';
$documentate_output_action = isset( $_GET['output'] ) ? sanitize_key( $_GET['output'] ) : 'preview';
$documentate_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$documentate_use_channel = isset( $_GET['use_channel'] ) && '1' === $_GET['use_channel'];

// Get Collabora URL from settings.
$documentate_options = get_option( 'documentate_settings', array() );
$documentate_collabora_url = isset( $documentate_options['collabora_base_url'] )
	? esc_url( $documentate_options['collabora_base_url'] )
	: '';

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
		// Conversion parameters from URL (validated by PHP).
		const conversionConfig = {
			postId: <?php echo (int) $documentate_document_id; ?>,
			targetFormat: <?php echo wp_json_encode( $documentate_target_format ); ?>,
			sourceFormat: <?php echo wp_json_encode( $documentate_source_format ); ?>,
			outputAction: <?php echo wp_json_encode( $documentate_output_action ); ?>,
			nonce: <?php echo wp_json_encode( $documentate_nonce ); ?>,
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			collaboraUrl: <?php echo wp_json_encode( $documentate_collabora_url ); ?>,
			useChannel: <?php echo $documentate_use_channel ? 'true' : 'false'; ?>
		};

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
		console.log('Documentate Collabora Playground: Config =', conversionConfig);

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

		// Initialize and start conversion.
		async function init() {
			try {
				if (!conversionConfig.collaboraUrl) {
					throw new Error(<?php echo wp_json_encode( 'La URL de Collabora no está configurada.' ); ?>);
				}

				// Step 1: Generate source document via AJAX.
				updateStatus(
					<?php echo wp_json_encode( 'Generando documento...' ); ?>,
					<?php echo wp_json_encode( 'Procesando plantilla en el servidor.' ); ?>
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
					throw new Error(ajaxData.data?.message ||
					<?php
					echo wp_json_encode( 'Error al generar el documento de origen.' );
					?>
					);
				}

				// Step 2: Fetch the source document.
				updateStatus(
					<?php echo wp_json_encode( 'Descargando documento...' ); ?>,
					<?php echo wp_json_encode( 'Obteniendo documento fuente.' ); ?>
				);

				const sourceResponse = await fetch(ajaxData.data.url, { credentials: 'same-origin' });
				if (!sourceResponse.ok) {
					throw new Error(`<?php echo esc_js( 'Error al obtener el documento de origen:' ); ?> ${sourceResponse.status}`);
				}
				const sourceBlob = await sourceResponse.blob();

				// Step 3: Send to Collabora proxy via JavaScript fetch().
				updateStatus(
					<?php echo wp_json_encode( 'Convirtiendo a PDF...' ); ?>,
					<?php echo wp_json_encode( 'Enviando al servidor de Collabora.' ); ?>
				);

				// Build multipart form data for Collabora.
				const collaboraFormData = new FormData();
				const filename = `document.${conversionConfig.sourceFormat}`;
				collaboraFormData.append('data', sourceBlob, filename);

				// Build Collabora endpoint URL.
				const collaboraEndpoint = `${conversionConfig.collaboraUrl.replace(/\/$/, '')}/cool/convert-to/${conversionConfig.targetFormat}`;

				console.log('Documentate: Sending to Collabora:', collaboraEndpoint);

				const collaboraResponse = await fetch(collaboraEndpoint, {
					method: 'POST',
					body: collaboraFormData
				});

				if (!collaboraResponse.ok) {
					const errorText = await collaboraResponse.text();
					throw new Error(`Collabora error ${collaboraResponse.status}: ${errorText || 'Unknown error'}`);
				}

				const resultBlob = await collaboraResponse.blob();

				if (resultBlob.size === 0) {
					throw new Error(<?php echo wp_json_encode( 'Collabora devolvió una respuesta vacía.' ); ?>);
				}

				console.log('Documentate: Conversion successful, size:', resultBlob.size);

				// Step 4: Handle result.
				const mimeTypes = {
					pdf: 'application/pdf',
					docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					odt: 'application/vnd.oasis.opendocument.text'
				};
				const finalBlob = new Blob([resultBlob], { type: mimeTypes[conversionConfig.targetFormat] || 'application/octet-stream' });
				const blobUrl = URL.createObjectURL(finalBlob);

				if (conversionConfig.useChannel) {
					if (conversionConfig.outputAction === 'preview' && conversionConfig.targetFormat === 'pdf') {
						// For preview: reuse this popup window to show the PDF.
						sendToChannel('preview_ready', { message: 'PDF ready in popup' });

						// Resize and reposition window.
						const width = Math.min(900, screen.availWidth - 100);
						const height = Math.min(700, screen.availHeight - 100);
						const left = Math.round((screen.availWidth - width) / 2);
						const top = Math.round((screen.availHeight - height) / 2);

						window.resizeTo(width, height);
						window.moveTo(left, top);
						window.focus();

						// Navigate to PDF blob URL.
						window.location.href = blobUrl;
					} else {
						// For download: send result via BroadcastChannel.
						const arrayBuffer = await finalBlob.arrayBuffer();
						sendToChannel('success', {
							outputData: arrayBuffer,
							outputFormat: conversionConfig.targetFormat
						});

						updateStatus(
							<?php echo wp_json_encode( '¡Completado!' ); ?>,
							<?php echo wp_json_encode( 'Documento convertido.' ); ?>,
							false,
							true
						);

						setTimeout(() => window.close(), 1000);
					}
				} else {
					// Legacy mode: handle directly in popup.
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
							<?php echo wp_json_encode( '¡Completado!' ); ?>,
							<?php echo wp_json_encode( 'El documento se ha descargado.' ); ?>,
							false,
							true
						);

						setTimeout(() => window.close(), 2000);
					}
				}

			} catch (error) {
				console.error('Documentate conversion error:', error);

				if (conversionConfig.useChannel) {
					sendToChannel('error', null, error.message || <?php echo wp_json_encode( 'Error en la conversión.' ); ?>);
				}

				updateStatus(
					<?php echo wp_json_encode( 'Error' ); ?>,
					error.message || <?php echo wp_json_encode( 'Error en la conversión.' ); ?>,
					true
				);
			}
		}

		// Start immediately.
		init();
	</script>
</body>
</html>
