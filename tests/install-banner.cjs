/* Installationsverhalten ohne echten OS-Dialog: Chromium + simuliertes Browser-Event. */
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const net = require("node:net");
const { spawn } = require("node:child_process");
const root = path.resolve(__dirname, "..");
const artifacts = path.join(__dirname, ".artifacts");
const axePath = process.env.AXE_PATH || require.resolve("axe-core/axe.min.js");
const android =
  "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36";
const iphone =
  "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1";
(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "mywish-install-"));
  const filter = (source) =>
    !/\/(\.git|node_modules|\.artifacts|__pycache__)(\/|$)/.test(source) &&
    !["state.php", "lock.php", "config.php"].includes(path.basename(source));
  fs.cpSync(root, temp, { recursive: true, filter });
  fs.cpSync(root, path.join(temp, "mywish"), { recursive: true, filter });
  fs.mkdirSync(artifacts, { recursive: true });
  const log = fs.openSync(path.join(artifacts, "install-server.log"), "w");
  const port = await new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => resolve(p));
    });
  });
  const server = spawn(
    process.env.PHP_BIN || "php",
    ["-S", `127.0.0.1:${port}`, "-t", temp],
    { stdio: ["ignore", log, log], detached: true },
  );
  let browser;
  try {
    for (let i = 0; i < 100; i++) {
      try {
        await fetch(`http://127.0.0.1:${port}/index.php`);
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
    for (const folder of ["/", "/mywish/"]) {
      const base = `http://127.0.0.1:${port}${folder}`;
      const context = await browser.newContext({
        userAgent: android,
        viewport: { width: 360, height: 900 },
      });
      const session = await (
        await context.request.get(`${base}api/index.php?action=session`)
      ).json();
      const setup = await context.request.post(
        `${base}api/index.php?action=setup`,
        {
          data: {
            csrf: session.csrf,
            siteName: "MyWish",
            username: "first",
            name: "First",
            password: "Setup!12",
            registration: "open",
          },
        },
      );
      assert.equal(setup.status(), 201);
      const page = await context.newPage();
      const errors = [];
      page.on("pageerror", (error) => errors.push(error.message));
      await page.goto(`${base}index.php`);
      await page.locator("#install-banner").waitFor({ state: "visible" });
      assert.equal(await page.locator("#install-help").isVisible(), false);
      const registration = await page.evaluate(async () => {
        const ready = await navigator.serviceWorker.ready;
        return { scope: ready.scope, script: ready.active.scriptURL };
      });
      assert.equal(registration.scope, base);
      assert.equal(registration.script, `${base}sw.js`);
      const manifestResponse = await context.request.get(
        `${base}manifest.webmanifest`,
      );
      assert.equal(manifestResponse.status(), 200);
      const manifest = await manifestResponse.json();
      assert.equal(
        new URL(manifest.start_url, `${base}manifest.webmanifest`).href,
        `${base}index.php`,
      );
      assert.equal(
        new URL(manifest.scope, `${base}manifest.webmanifest`).href,
        base,
      );
      for (const icon of manifest.icons) {
        const response = await context.request.get(
          new URL(icon.src, base).href,
        );
        assert.equal(response.status(), 200);
        const bytes = await response.body();
        assert.equal(bytes.readUInt32BE(16), parseInt(icon.sizes));
        assert.equal(bytes.readUInt32BE(20), parseInt(icon.sizes));
      }
      // Safari verwendet das Apple-Touch-Icon statt der Android-Manifest-Icons.
      const appleIcon = page.locator('link[rel="apple-touch-icon"]');
      assert.equal(await appleIcon.getAttribute("sizes"), "180x180");
      const appleUrl = new URL(
        await appleIcon.getAttribute("href"),
        page.url(),
      );
      assert.equal(appleUrl.href, `${base}assets/icons/apple-touch-icon.png`);
      const appleResponse = await context.request.get(appleUrl.href);
      assert.equal(appleResponse.status(), 200);
      assert.match(appleResponse.headers()["content-type"], /image\/png/);
      const appleBytes = await appleResponse.body();
      assert.equal(appleBytes.readUInt32BE(16), 180);
      assert.equal(appleBytes.readUInt32BE(20), 180);
      // Fallback bleibt bei fehlendem beforeinstallprompt nutzbar.
      await page
        .getByRole("button", { name: "So geht’s", exact: true })
        .click();
      assert.match(
        await page.locator("#install-help").textContent(),
        /Browsermenü/,
      );
      for (const width of [320, 360, 768]) {
        await page.setViewportSize({ width, height: 900 });
        assert.equal(
          await page.evaluate(
            () => document.documentElement.scrollWidth > innerWidth,
          ),
          false,
          `Banner overflow: ${folder} ${width}`,
        );
        await page.screenshot({
          path: path.join(
            artifacts,
            `install-android-${folder === "/" ? "root" : "subdir"}-${width}.png`,
          ),
          fullPage: true,
        });
      }
      await page.evaluate(fs.readFileSync(axePath, "utf8"));
      const violations = await page.evaluate(async () =>
        (
          await axe.run(document, {
            runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21aa"] },
          })
        ).violations.map((v) => ({
          id: v.id,
          nodes: v.nodes.map((n) => n.target),
        })),
      );
      assert.deepEqual(violations, []);
      // Kein Prompt vor Nutzerklick; Browser-Bestätigung wird genau einmal verwendet.
      await page.evaluate(() => {
        window.promptCalls = 0;
        const event = new Event("beforeinstallprompt", { cancelable: true });
        event.prompt = async () => {
          window.promptCalls++;
        };
        event.userChoice = Promise.resolve({ outcome: "dismissed" });
        window.dispatchEvent(event);
        window.prevented = event.defaultPrevented;
      });
      assert.equal(await page.evaluate(() => window.promptCalls), 0);
      assert.equal(await page.evaluate(() => window.prevented), true);
      await page
        .getByRole("button", { name: "Hinzufügen", exact: true })
        .click();
      await page
        .getByRole("button", { name: "So geht’s", exact: true })
        .waitFor();
      assert.equal(await page.evaluate(() => window.promptCalls), 1);
      await page.evaluate(() => {
        const event = new Event("beforeinstallprompt", { cancelable: true });
        event.prompt = async () => {
          window.promptCalls++;
        };
        event.userChoice = Promise.resolve({ outcome: "accepted" });
        window.dispatchEvent(event);
      });
      await page
        .getByRole("button", { name: "Hinzufügen", exact: true })
        .click();
      await page.locator("#install-banner").waitFor({ state: "hidden" });
      await page.reload();
      assert.equal(
        await page.locator("#install-banner").isVisible(),
        false,
        "Accepted install stays hidden",
      );
      await page.evaluate(() => localStorage.clear());
      await page.reload();
      await page.locator("#install-banner").waitFor({ state: "visible" });
      await page
        .getByRole("button", { name: "Installationshinweis schließen" })
        .click();
      assert.equal(
        await page.evaluate(() =>
          document.activeElement.classList.contains("brand"),
        ),
        true,
      );
      await page.reload();
      assert.equal(
        await page.locator("#install-banner").isVisible(),
        false,
        "Dismissal persists across pages",
      );
      await page.evaluate(() => {
        for (const key of Object.keys(localStorage))
          localStorage.setItem(key, String(Date.now() - 1));
      });
      await page.reload();
      await page.locator("#install-banner").waitFor({ state: "visible" });
      await page.evaluate(() =>
        window.dispatchEvent(new Event("appinstalled")),
      );
      await page.locator("#install-banner").waitFor({ state: "hidden" });
      // Das Netzwerk-Verhalten legt keine privaten Antwortkopien in Cache Storage ab.
      await page.reload();
      assert.deepEqual(await page.evaluate(() => caches.keys()), []);
      assert.deepEqual(errors, []);
      await context.close();
      console.log(
        "PASS manifest, scoped worker, icons, native/fallback banner, privacy:",
        folder,
      );
    }
    const base = `http://127.0.0.1:${port}/mywish/`;
    const ios = await browser.newContext({
      userAgent: iphone,
      viewport: { width: 360, height: 900 },
    });
    const iosPage = await ios.newPage();
    await iosPage.goto(`${base}index.php`);
    await iosPage
      .getByRole("button", { name: "So geht’s", exact: true })
      .click();
    assert.match(
      await iosPage.locator("#install-help").textContent(),
      /Teilen.*Zum Home-Bildschirm/,
    );
    await iosPage.screenshot({
      path: path.join(artifacts, "install-iphone-360.png"),
      fullPage: true,
    });
    await ios.close();
    for (const variant of [
      "ios-standalone",
      "android-standalone",
      "desktop",
      "blocked-storage",
      "insecure",
      "no-js",
    ]) {
      const context = await browser.newContext({
        userAgent: variant === "desktop" ? undefined : iphone,
        javaScriptEnabled: variant !== "no-js",
        viewport: { width: 360, height: 900 },
      });
      if (variant === "ios-standalone")
        await context.addInitScript(() =>
          Object.defineProperty(navigator, "standalone", { value: true }),
        );
      if (variant === "android-standalone")
        await context.addInitScript(() => {
          const original = window.matchMedia.bind(window);
          window.matchMedia = (query) =>
            query === "(display-mode: standalone)"
              ? { matches: true, addEventListener() {} }
              : original(query);
        });
      if (variant === "blocked-storage")
        await context.addInitScript(() =>
          Object.defineProperty(window, "localStorage", {
            get() {
              throw new Error("Storage blocked");
            },
          }),
        );
      if (variant === "insecure")
        await context.addInitScript(() =>
          Object.defineProperty(window, "isSecureContext", { value: false }),
        );
      const page = await context.newPage();
      await page.goto(`${base}index.php`);
      if (variant === "desktop") {
        assert.equal(
          await page.evaluate(() => {
            const event = new Event("beforeinstallprompt", {
              cancelable: true,
            });
            window.dispatchEvent(event);
            return event.defaultPrevented;
          }),
          false,
          "Desktop browser keeps its native installation behavior",
        );
      }
      if (variant === "blocked-storage") {
        await page
          .getByRole("button", { name: "So geht’s", exact: true })
          .click();
        await page
          .getByRole("button", { name: "Installationshinweis schließen" })
          .click();
      }
      assert.equal(
        await page.locator("#install-banner").isVisible(),
        false,
        variant,
      );
      await context.close();
    }
    console.log(
      "OK: Android/iPhone instructions, installed/desktop/no-JS suppression, blocked storage and root/subdirectory installation",
    );
  } finally {
    if (browser) await browser.close();
    try {
      process.kill(-server.pid, "SIGTERM");
    } catch {}
    fs.closeSync(log);
    fs.rmSync(temp, { recursive: true, force: true });
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
