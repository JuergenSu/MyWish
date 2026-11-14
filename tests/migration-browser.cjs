/* Uses the disposable Apache fixture in web-migration.py. No production data. */
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const artifacts = path.join(__dirname, ".artifacts");
const axePath = process.env.AXE_PATH || require.resolve("axe-core/axe.min.js");
(async () => {
  fs.mkdirSync(artifacts, { recursive: true });
  const browser = await chromium.launch({
    headless: true,
    ...(process.env.CHROMIUM_PATH
      ? { executablePath: process.env.CHROMIUM_PATH }
      : {}),
  });
  try {
    const page = await browser.newPage();
    await page.goto(process.argv[2] + "migrate-storage.php");
    async function audit(name) {
      for (const width of [320, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.equal(
          await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
        );
        await page.evaluate(fs.readFileSync(axePath, "utf8"));
        const result = await page.evaluate(async () =>
          axe.run(document, {
            runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21aa"] },
          }),
        );
        assert.deepEqual(
          result.violations.map((v) => v.id),
          [],
        );
        await page.screenshot({
          path: path.join(artifacts, `migration-${name}-${width}.png`),
          fullPage: true,
        });
      }
    }
    await audit("start");
    await Promise.all([
      page.waitForNavigation(),
      page.getByRole("button", { name: "Migration starten" }).click(),
    ]);
    assert.match(
      await page.locator("h1").innerText(),
      /Migration abgeschlossen/,
    );
    assert.match(
      await page.locator("main").innerText(),
      /Migrationsskript wurde gelöscht/,
    );
    await audit("complete");
    assert.equal(
      (
        await page.request.get(process.argv[2] + "migrate-storage.php")
      ).status(),
      404,
    );
    await page.getByRole("link", { name: "MyWish öffnen" }).click();
    assert.ok(page.url().endsWith("/visual/index.php"));
    console.log(
      "OK: migration browser flow, self removal, 6 axe/overflow audits",
    );
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
