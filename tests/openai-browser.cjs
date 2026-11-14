/* Optional OpenAI image search: synthetic provider, no keys or external API calls. */
const { chromium } = require("playwright");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const net = require("node:net");
const { spawn } = require("node:child_process");
const assert = require("node:assert/strict");
const root = path.resolve(__dirname, "..");
const axe = fs.readFileSync(
  process.env.AXE_PATH || require.resolve("axe-core/axe.min.js"),
  "utf8",
);
(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "mywish-openai-browser-"));
  const web = path.join(temp, "public");
  fs.mkdirSync(web);
  const port = await new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => resolve(p));
    });
  });
  for (const dir of [web, path.join(web, "mywish")]) {
    fs.cpSync(root, dir, {
      recursive: true,
      filter: (source) =>
        !/\/(\.git|node_modules|\.artifacts|__pycache__)(\/|$)/.test(source) &&
        !["config.php", "state.php", "layout.php", "lock.php"].includes(
          path.basename(source),
        ),
    });
    fs.writeFileSync(
      path.join(dir, "config.php"),
      "<?php return ['openai'=>['api_key'=>'sk-test-server-key','model'=>'test-model']];",
    );
  }
  const server = spawn(
    process.env.PHP_BIN || "php",
    [
      "-d",
      "auto_prepend_file=" +
        path.join(root, "tests/fixtures/openai-transport.php"),
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      web,
    ],
    { stdio: "ignore", detached: true },
  );
  let browser;
  let audits = 0;
  async function audit(page) {
    for (const width of [320, 360, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
        "No image-search overflow",
      );
      await page.evaluate(axe);
      const violations = await page.evaluate(async () =>
        (
          await axe.run(document, {
            runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21aa"] },
          })
        ).violations.map((v) => ({
          id: v.id,
          nodes: v.nodes.map((n) => ({
            target: n.target,
            message: n.failureSummary,
          })),
        })),
      );
      assert.deepEqual(violations, []);
      audits++;
    }
  }
  try {
    for (let i = 0; i < 100; i++) {
      try {
        await fetch(`http://127.0.0.1:${port}/`);
        break;
      } catch {
        await new Promise((r) => setTimeout(r, 50));
      }
    }
    browser = await chromium.launch({
      headless: true,
      ...(process.env.CHROMIUM_PATH
        ? { executablePath: process.env.CHROMIUM_PATH }
        : {}),
      args: ["--no-sandbox"],
    });
    for (const prefix of ["/", "/mywish/"]) {
      const base = `http://127.0.0.1:${port}${prefix}`;
      const context = await browser.newContext({ serviceWorkers: "block" });
      const page = await context.newPage();
      let searches = 0;
      page.on("request", (r) => {
        if (r.url().includes("action=searchImage")) searches++;
        assert.ok(
          r.url().startsWith(`http://127.0.0.1:${port}/`),
          "Images and scripts stay local",
        );
      });
      let csrf = (
        await (
          await context.request.get(base + "api/index.php?action=session")
        ).json()
      ).csrf;
      const post = async (action, data) => {
        const r = await context.request.post(
          base + "api/index.php?action=" + action,
          { data: { ...data, csrf } },
        );
        const body = await r.json();
        assert.equal(body.ok, true);
        csrf = body.csrf;
        return body.data;
      };
      await post("setup", {
        name: "Owner",
        username: "owner",
        password: "abcdefgh",
        siteName: "Images",
        registration: "open",
      });
      const list = (
        await post("saveList", { name: "Wishes", occasion: "Anderes" })
      ).id;
      const wishUrl = base + "index.php?page=wish&listId=" + list;
      await page.goto(wishUrl);
      assert.equal(searches, 0, "No paid search on opening form");
      await page.locator('[name="title"]').fill("source-only");
      await page
        .locator("#productUrl")
        .fill(
          "https://www.amazon.de/Cuisinart-Toaster/dp/B0G1586BZG/?tracking=secret",
        );
      await page.getByRole("button", { name: "Daten übernehmen" }).click();
      await page
        .locator("#imported-image-box .image-preview")
        .waitFor({ state: "visible" });
      assert.equal(
        searches,
        1,
        "One automatic search after missing normal image",
      );
      assert.equal(
        await page.locator("[name=useImportedImage]").inputValue(),
        "yes",
      );
      assert.equal(
        await page.locator('[name="title"]').inputValue(),
        "source-only",
      );
      assert.equal(
        await page.locator("#imported-image-box a").getAttribute("href"),
        "https://shop.example/with-image",
      );
      assert.equal(await page.locator("#google-image-search").count(), 0);
      assert.ok(!(await page.content()).includes("sk-test-server-key"));
      await audit(page);
      await page.getByRole("button", { name: "Wunsch speichern" }).click();
      await page.waitForURL(/page=list/);
      assert.equal(
        await page
          .getByRole("link", { name: "Bildquelle" })
          .getAttribute("href"),
        "https://shop.example/with-image",
      );
      await audit(page);
      await page.goto(wishUrl);
      await page.locator('[name="title"]').fill("provider-error");
      await page
        .getByRole("button", { name: "Bild mit OpenAI suchen" })
        .click();
      await page.waitForFunction(() =>
        document
          .querySelector("#openai-image-status")
          .textContent.includes("HTTP 401"),
      );
      assert.equal(await page.locator("[name=useImportedImage]").count(), 0);
      assert.equal(
        await page.locator("#openai-image-search").isEnabled(),
        true,
      );
      assert.equal(
        await page.locator('[name="title"]').inputValue(),
        "provider-error",
      );
      await audit(page);
      // A completed server search may arrive after the user changed the product.
      await page.locator('[name="title"]').fill("Product before edit");
      let release, rejectIntercept;
      const intercepted = new Promise((resolve, reject) => {
        release = resolve;
        rejectIntercept = reject;
      });
      const interceptTimeout = setTimeout(
        () => rejectIntercept(new Error("Search request was not intercepted")),
        10000,
      );
      await page.route("**/api/index.php?action=searchImage", async (route) => {
        clearTimeout(interceptTimeout);
        let response;
        try {
          response = await route.fetch({ timeout: 5000 });
        } catch (error) {
          rejectIntercept(error);
          return;
        }
        release();
        await new Promise((r) => setTimeout(r, 200));
        try {
          await route.fulfill({ response });
        } catch {}
      });
      await page
        .getByRole("button", { name: "Bild mit OpenAI suchen" })
        .click();
      await intercepted;
      await page.locator('[name="title"]').fill("Changed product");
      await page.waitForFunction(
        () => document.querySelector("#openai-image-search").disabled === false,
      );
      assert.equal(
        await page.locator("[name=useImportedImage]").count(),
        0,
        "Cancelled result never selects an image for a changed product",
      );
      await page.unrouteAll({ behavior: "ignoreErrors" });
      await page
        .getByRole("button", { name: "Bild mit OpenAI suchen" })
        .click();
      await page
        .locator("#imported-image-box .image-preview")
        .waitFor({ state: "visible" });
      await page.getByLabel("Dieses Bild übernehmen").uncheck();
      await page.getByRole("button", { name: "Wunsch speichern" }).click();
      await page.waitForURL(/page=list/);
      const stored = (
        await (
          await context.request.get(base + "api/index.php?action=state")
        ).json()
      ).data.wishes;
      assert.equal(
        stored[stored.length - 1].image,
        false,
        "Rejected AI proposal is not stored",
      );
      assert.equal(stored[stored.length - 1].imageSourceUrl, undefined);
      await page.unrouteAll({ behavior: "ignoreErrors" });
      await context.close();
    }
    console.log(
      `OK: OpenAI automatic fallback, source persistence, provider errors, cancellation and ${audits} axe/overflow audits`,
    );
  } finally {
    if (browser) await browser.close();
    process.kill(-server.pid, "SIGTERM");
    await new Promise((r) => server.once("exit", r));
    fs.rmSync(temp, { recursive: true, force: true });
  }
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
