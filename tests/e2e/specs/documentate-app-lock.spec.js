/** Native editing ownership across two app sessions and wp-admin. */
const { test, expect } = require('../fixtures');
const { createFixture, removeFixture, loginAs } = require('../fixtures/site');

const RUN = `locks${Date.now()}`;
let fixture;

test.beforeAll(() => {
	test.setTimeout(300_000);
	fixture = createFixture({
		users: { second: { login: `${RUN}second`, role: 'administrator' } },
		documents: {
			app: { title: `App lock ${RUN}` },
			admin: { title: `Admin lock ${RUN}` },
		},
	});
});

test.afterAll(() => {
	test.setTimeout(300_000);
	if (fixture) removeFixture(fixture.cleanup);
});

test('a takeover freezes the first editor and rejects its stale save', async ({ page, browser, baseURL }) => {
	const other = await loginAs(browser, baseURL, `${RUN}second`);
	const id = fixture.documents.app;
	const url = `/documentate/?vista=editar&doc=${id}`;
	try {
		await page.goto(url);
		await expect(page.locator('form.dcta-editor')).toBeVisible();
		await page.locator('#documentate-app-titulo').fill('Cambios sin guardar');
		const nonce = await page.locator('form.dcta-editor [name="documentate_app_nonce"]').inputValue();
		await other.page.goto(url);
		await expect(other.page.getByRole('heading', { name: 'Documento en edición' })).toBeVisible();
		await expect(other.page.locator('form.dcta-editor')).toHaveCount(0);
		const notice = await other.page.locator('#dcta-lock-dialog').boundingBox();
		const footer = await other.page.locator('footer.dcta-pie').boundingBox();
		expect(footer.y).toBeGreaterThanOrEqual(notice.y + notice.height);
		await other.page.getByRole('button', { name: 'Tomar posesión' }).click();
		await expect(other.page.locator('form.dcta-editor')).toBeVisible();

		// A direct stale POST must fail even before the next Heartbeat tick.
		const rejected = await page.request.post(url, { form: {
			documentate_app_accion: 'guardar_documento',
			documentate_app_doc: id,
			documentate_app_nonce: nonce,
			documentate_app_titulo: 'Sobrescrito',
		} });
		expect(rejected.status()).toBe(409);
		expect(await rejected.text()).toContain('Otra persona está editando');

		await page.evaluate(() => wp.heartbeat.connectNow());
		await expect(page.locator('#dcta-lock-dialog')).toBeVisible({ timeout: 25_000 });
		await expect(page.locator('form.dcta-editor')).toHaveJSProperty('inert', true);
		await expect(page.locator('#documentate-app-titulo')).toHaveValue('Cambios sin guardar');
		await page.keyboard.press('Escape');
		await expect(page.locator('#dcta-lock-dialog')).toBeVisible();
		await other.page.reload();
		await expect(other.page.locator('#documentate-app-titulo')).toHaveValue(`App lock ${RUN}`);

		await page.getByRole('button', { name: 'Tomar posesión' }).click();
		await expect(page.locator('form.dcta-editor')).toBeVisible();
		await other.page.evaluate(() => wp.heartbeat.connectNow());
		await expect(other.page.locator('#dcta-lock-dialog')).toBeVisible({ timeout: 25_000 });
		await expect(other.page.locator('form.dcta-editor')).toHaveJSProperty('inert', true);
	} finally {
		await other.context.close();
	}
});

test('the app respects a wp-admin lock and wp-admin detects an app takeover', async ({ page, browser, baseURL }) => {
	const other = await loginAs(browser, baseURL, `${RUN}second`);
	const id = fixture.documents.admin;
	try {
		await page.goto(`/wp-admin/post.php?post=${id}&action=edit`);
		await expect(page.locator('#post')).toBeVisible();
		await other.page.goto(`/documentate/?vista=editar&doc=${id}`);
		await expect(other.page.getByRole('button', { name: 'Tomar posesión' })).toBeVisible();
		await expect(other.page.locator('form.dcta-editor')).toHaveCount(0);
		await other.page.getByRole('button', { name: 'Tomar posesión' }).click();
		await expect(other.page.locator('form.dcta-editor')).toBeVisible();
		await page.evaluate(() => wp.heartbeat.connectNow());
		await expect(page.locator('#post-lock-dialog .notification-dialog')).toBeVisible({ timeout: 25_000 });
	} finally {
		await other.context.close();
	}
});
