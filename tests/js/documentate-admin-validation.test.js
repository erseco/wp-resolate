const $ = require('jquery');

beforeEach(() => {
	$(document).off('submit', '#post');
	global.jQuery = $;
	document.body.innerHTML = `<div class="wrap"><h1>Documento</h1></div><form id="post">
		<div class="documentate-rich-editor-wrap" data-required="true"><textarea id="rich"></textarea></div>
		<textarea id="row" class="documentate-array-rich" required>Contenido</textarea></form>`;
	document.querySelector('form').reportValidity = jest.fn(() => true);
	delete window.tinyMCE;
	jest.isolateModules(() => require('../../admin/js/documentate-admin.js'));
});

afterEach(() => {
	$(document).off('submit', '#post');
	delete global.jQuery;
	delete window.tinyMCE;
});

function submit() {
	const event = new Event('submit', { bubbles: true, cancelable: true });
	document.querySelector('form').dispatchEvent(event);
	return event;
}

it('validates native constraints before rich fields', () => {
	document.querySelector('form').reportValidity.mockReturnValue(false);
	expect(submit().defaultPrevented).toBe(true);
	expect(document.getElementById('documentate-required-notice')).toBeNull();
});

it('rejects empty classic and repeated rich fields and reuses the notice', () => {
	window.tinyMCE = { triggerSave: jest.fn(), get: jest.fn(() => ({ save: jest.fn() })) };
	expect(submit().defaultPrevented).toBe(true);
	expect(document.querySelector('.documentate-rich-editor-wrap').classList.contains('documentate-rich-required-error')).toBe(true);
	expect(document.getElementById('documentate-required-notice').textContent).toContain('Rellena todos');
	document.getElementById('rich').value = 'Texto';
	document.getElementById('row').value = '<p>&nbsp;</p>';
	expect(submit().defaultPrevented).toBe(true);
	expect(document.querySelectorAll('#documentate-required-notice')).toHaveLength(1);
	expect(window.tinyMCE.triggerSave).toHaveBeenCalledTimes(2);
});

it('accepts populated classic editors without TinyMCE', () => {
	document.getElementById('rich').value = '<p>Texto</p>';
	expect(submit().defaultPrevented).toBe(false);
});

it('rejects a required wrapper missing its textarea', () => {
	document.getElementById('rich').remove();
	expect(submit().defaultPrevented).toBe(true);
});
