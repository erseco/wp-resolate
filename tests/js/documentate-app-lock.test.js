const $ = require('jquery');

beforeEach(() => {
	$(document).off('.documentateLock');
	$(window).off('.documentateLock');
	document.body.innerHTML = `<div id="dcta-edit-lock" data-post-id="42" data-lock="100:7" data-release-nonce="nonce">
		<dialog id="dcta-lock-dialog"><span id="dcta-lock-owner"></span></dialog></div>
		<form class="dcta-editor"><input value="Unsaved text"></form>`;
	global.jQuery = $;
	global.wp = { heartbeat: { interval: jest.fn() } };
	global.documentateAppLock = { ajaxUrl: '/wp-admin/admin-ajax.php' };
	navigator.sendBeacon = jest.fn();
	document.querySelector('dialog').showModal = jest.fn();
});

function boot() {
	jest.isolateModules(() => require('../../public/js/documentate-app-lock.js'));
}

it('uses core Heartbeat to refresh ownership and releases the latest token', () => {
	boot();
	const outgoing = {};
	$(document).trigger('heartbeat-send', [outgoing]);
	expect(outgoing['wp-refresh-post-lock']).toEqual({ post_id: 42, lock: '100:7' });
	expect(wp.heartbeat.interval).toHaveBeenCalledWith(15);
	$(document).trigger('heartbeat-tick', [{}]);
	$(document).trigger('heartbeat-tick', [{'wp-refresh-post-lock': {}}]);
	$(document).trigger('heartbeat-tick', [{'wp-refresh-post-lock': { new_lock: '200:7' }}]);
	const submit = new Event('submit', { cancelable: true });
	document.querySelector('form').dispatchEvent(submit);
	expect(submit.defaultPrevented).toBe(false);
	$(window).trigger('pagehide');
	const [url, data] = navigator.sendBeacon.mock.calls[0];
	expect(url).toBe('/wp-admin/admin-ajax.php');
	expect(Object.fromEntries(data)).toEqual({action: 'wp-remove-post-lock', post_ID: '42', _wpnonce: 'nonce', active_post_lock: '200:7'});
	$(window).trigger($.Event('pageshow', { originalEvent: { persisted: false } }));
});

it('freezes the losing editor without deleting unsaved data or reclaiming the lock', () => {
	boot();
	$(document).trigger('heartbeat-tick', [{'wp-refresh-post-lock': { lock_error: { name: '<Otro editor>' } }}]);
	expect(document.querySelector('form').inert).toBe(true);
	expect(document.querySelector('input').value).toBe('Unsaved text');
	expect(document.getElementById('dcta-lock-owner').textContent).toBe('<Otro editor>');
	expect(document.querySelector('dialog').showModal).toHaveBeenCalledTimes(1);
	const submit = new Event('submit', { cancelable: true });
	document.querySelector('form').dispatchEvent(submit);
	expect(submit.defaultPrevented).toBe(true);
	const cancel = new Event('cancel', { cancelable: true });
	document.querySelector('dialog').dispatchEvent(cancel);
	expect(cancel.defaultPrevented).toBe(true);
	const outgoing = {};
	$(document).trigger('heartbeat-send', [outgoing]);
	expect(outgoing).toEqual({});
	$(document).trigger('heartbeat-tick', [{'wp-refresh-post-lock': { new_lock: '300:7' }}]);
	$(window).trigger('pagehide');
	expect(navigator.sendBeacon).not.toHaveBeenCalled();
});

it('leaves expiration to WordPress when sendBeacon is unavailable', () => {
	delete navigator.sendBeacon;
	boot();
	expect(() => $(window).trigger('pagehide')).not.toThrow();
});

it.each(['', '<div id="dcta-edit-lock" data-lock=""></div>'])('does not renew a lock on a blocked or unrelated view', (html) => {
	document.body.innerHTML = html;
	boot();
	expect(wp.heartbeat.interval).not.toHaveBeenCalled();
});


it('does not recreate a lock after submitting a workflow transition', () => {
	boot();
	document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
	$(window).trigger('pagehide');
	expect(navigator.sendBeacon).not.toHaveBeenCalled();
});

it('still releases on navigation after validation prevents submission', () => {
	boot();
	document.querySelector('form').addEventListener('submit', (event) => event.preventDefault());
	document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
	$(window).trigger('pagehide');
	expect(navigator.sendBeacon).toHaveBeenCalledTimes(1);
});
