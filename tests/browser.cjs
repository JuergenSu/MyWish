/* Development-only browser checks. No browser libraries are needed by the app. */
const { chromium } = require("playwright");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn } = require("node:child_process");
const assert = require("node:assert/strict");
const net = require("node:net");
const root = path.resolve(__dirname, "..");
const artifacts = path.join(__dirname, ".artifacts");
const axePath = process.env.AXE_PATH || require.resolve("axe-core/axe.min.js");
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "mywish-browser-"));
  const doc = path.join(temp, "mywish");
  fs.cpSync(root, doc, {
    recursive: true,
    filter: (source) =>
      !/\/(\.git|node_modules|\.artifacts|__pycache__)(\/|$)/.test(source) &&
      !["state.php", "lock.php", "config.php"].includes(path.basename(source)),
  });
  const port = await new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => resolve(p));
    });
  });
  const log = fs.openSync(path.join(artifacts, "server.log"), "w");
  const server = spawn(
    process.env.PHP_BIN || "php",
    [
      "-d",
      "upload_max_filesize=2M",
      "-d",
      "post_max_size=4M",
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      temp,
    ],
    { stdio: ["ignore", log, log], detached: true },
  );
  const base = `http://127.0.0.1:${port}/mywish/index.php`;
  let audits = 0;
  async function audit(sourcePage, name) {
    const auditContext = await browser.newContext({
      storageState: await sourcePage.context().storageState(),
    });
    const page = await auditContext.newPage();
    await page.goto(sourcePage.url());
    for (const width of [320, 360, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
        `Overflow ${name} ${width}`,
      );
      await page.screenshot({
        path: path.join(artifacts, `${name}-${width}.png`),
        fullPage: true,
      });
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
      assert.deepEqual(
        violations,
        [],
        `axe ${name} ${width}: ${JSON.stringify(violations)}`,
      );
      audits++;
    }
    await auditContext.close();
  }
  let browser;
  try {
    for (let i = 0; i < 100; i++) {
      try {
        await fetch(base);
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
    const owner = await browser.newContext({
      javaScriptEnabled: false,
      viewport: { width: 1440, height: 1000 },
    });
    const page = await owner.newPage();
    const external = [];
    page.on("request", (r) => {
      if (!r.url().startsWith(`http://127.0.0.1:${port}`))
        external.push(r.url());
    });
    await page.goto(base);
    await audit(page, "setup");
    await page.getByLabel("Dein Anzeigename", { exact: true }).fill("Antonia");
    await page
      .getByLabel("Benutzername oder E-Mail", { exact: true })
      .fill("antonia");
    const passwordInput = page.getByLabel("Passwort", { exact: true });
    for (const invalid of ["abcdef!", "abcdefg", "äöüßéa!"]) {
      await passwordInput.fill(invalid);
      assert.equal(
        await passwordInput.evaluate((input) => input.validity.patternMismatch),
        true,
      );
    }
    for (const valid of ["abcdefgh", "12345678", "äöüßéabc", "abcdefg!"]) {
      await passwordInput.fill(valid);
      assert.equal(
        await passwordInput.evaluate((input) => input.checkValidity()),
        true,
      );
    }
    await passwordInput.fill("abcdefgh");
    assert.equal(
      await passwordInput.evaluate((input) => input.checkValidity()),
      true,
    );
    await page
      .getByRole("button", { name: "MyWish einrichten", exact: true })
      .click();
    await page
      .getByRole("link", { name: "Liste erstellen", exact: true })
      .click();
    await page
      .getByLabel("Name der Liste", { exact: true })
      .fill("Ein Geburtstag voller Vorfreude");
    await page
      .getByLabel("Datum (optional)", { exact: true })
      .fill("2026-12-12");
    await page
      .getByLabel("Ein paar Worte zur Liste (optional)", { exact: true })
      .fill(
        "Ein paar Ideen, die mir Freude machen. Am schönsten ist gemeinsame Zeit.",
      );
    await page
      .getByRole("button", { name: "Liste speichern", exact: true })
      .click();
    const listUrl = page.url();
    await page
      .getByRole("link", { name: "Wunsch hinzufügen", exact: true })
      .click();
    await page
      .getByLabel("Dein Wunsch", { exact: true })
      .fill("Ein Buch für gemütliche Winterabende");
    await page
      .getByLabel("Beschreibung (optional)", { exact: true })
      .fill(
        "Geschichten zum Eintauchen. Gerne auch aus dem Buchladen um die Ecke.",
      );
    await page
      .getByLabel("Preis in EUR (optional)", { exact: true })
      .fill("24,99");
    const png = Buffer.from(
      "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6C6sAAAAASUVORK5CYII=",
      "base64",
    );
    // Real multipart upload works without JS and without GD.
    await page
      .getByLabel("Eigenes Bild (optional)", { exact: true })
      .setInputFiles({ name: "tiny.png", mimeType: "image/png", buffer: png });
    await page
      .getByRole("button", { name: "Wunsch speichern", exact: true })
      .click();
    assert.equal(await page.locator(".wish-card").count(), 1);
    assert.equal(await page.locator(".availability").count(), 0);
    assert.equal(
      (await page.locator(".wish-image-main").getAttribute("src")).includes(
        "media.php",
      ),
      true,
    );
    await page
      .getByRole("link", { name: "Wunsch bearbeiten", exact: true })
      .click();
    await audit(page, "wish-form");
    await page.getByRole("link", { name: "Abbrechen", exact: true }).click();
    // Create second card with no image for visual variation.
    await page
      .getByRole("link", { name: "Wunsch hinzufügen", exact: true })
      .click();
    await page
      .getByLabel("Dein Wunsch", { exact: true })
      .fill("Zeit zusammen: ein Frühstück am Sonntag");
    await page
      .getByRole("button", { name: "Wunsch speichern", exact: true })
      .click();
    const imageOwner = await browser.newContext({
      storageState: await owner.storageState(),
    });
    const imagePage = await imageOwner.newPage();
    const unexpectedImageRequests = [];
    imagePage.on("request", (request) => {
      if (!request.url().startsWith(`http://127.0.0.1:${port}`))
        unexpectedImageRequests.push(request.url());
    });
    await imagePage.goto(
      base + "?page=wish&listId=" + new URL(listUrl).searchParams.get("id"),
    );
    await imagePage
      .getByLabel("Dein Wunsch", { exact: true })
      .fill("Orzly Lenkräder");
    await imagePage
      .locator("#productUrl")
      .fill("https://www.amazon.de/dp/B0FLM3M1JJ/");
    const search = imagePage.locator("#google-image-search");
    const target = new URL(await search.getAttribute("href"));
    assert.equal(target.origin, "https://www.google.com");
    assert.equal(target.searchParams.get("q"), "Orzly Lenkräder B0FLM3M1JJ");
    assert.equal(target.searchParams.get("tbm"), "isch");
    assert.equal(await search.getAttribute("target"), "_blank");
    assert.match(await search.getAttribute("rel"), /noreferrer/);
    assert.deepEqual(
      unexpectedImageRequests,
      [],
      "No automatic Google requests while typing",
    );
    await audit(imagePage, "manual-image-search");
    await imageOwner.close();
    await page.goto(base);
    const giver = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    });
    const gift = await giver.newPage();
    await gift.goto(base);
    await audit(gift, "login");
    await gift
      .getByRole("link", { name: "Jetzt registrieren", exact: true })
      .click();
    await gift.getByLabel("Dein Anzeigename", { exact: true }).fill("Jürgen");
    await gift
      .getByLabel("Benutzername oder E-Mail", { exact: true })
      .fill("juergen");
    await gift.getByLabel("Passwort", { exact: true }).fill("12345678");
    await gift
      .getByRole("button", { name: "Konto erstellen", exact: true })
      .click();
    const screens = [
      ["dashboard", base],
      ["list", listUrl],
      ["reservations", base + "?page=reservations"],
      ["account", base + "?page=account"],
    ];
    for (const width of [320, 360, 768, 1440]) {
      await gift.setViewportSize({ width, height: 1000 });
      for (const [name, url] of screens) {
        await gift.goto(url);
        const overflow = await gift.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        );
        assert.equal(overflow, false, `Horizontal overflow: ${name} ${width}`);
        await gift.screenshot({
          path: path.join(artifacts, `${name}-${width}.png`),
          fullPage: true,
        });
        await gift.evaluate(fs.readFileSync(axePath, "utf8"));
        const results = await gift.evaluate(async () =>
          axe.run(document, {
            runOnly: { type: "tag", values: ["wcag2a", "wcag2aa", "wcag21aa"] },
          }),
        );
        const violations = results.violations.map((v) => ({
          id: v.id,
          impact: v.impact,
          nodes: v.nodes.map((n) => n.target),
        }));
        assert.deepEqual(
          violations,
          [],
          `axe ${name} ${width}: ${JSON.stringify(violations)}`,
        );
        audits++;
      }
    }
    await gift.goto(listUrl);
    await gift
      .getByRole("button", { name: "Für mich reservieren", exact: true })
      .first()
      .click();
    await gift
      .getByText("Eine Einheit für dich reserviert", { exact: true })
      .waitFor();
    await gift
      .getByRole("link", { name: "Meine Reservierungen", exact: true })
      .click();
    assert.equal(await gift.locator(".wish-card").count(), 1);
    await audit(gift, "reservation");
    await gift
      .getByRole("button", { name: "Reservierung freigeben", exact: true })
      .click();
    await gift.locator(".wish-card").waitFor({ state: "detached" });
    assert.equal(await gift.locator(".wish-card").count(), 0);
    await page.goto(listUrl);
    assert.equal(await page.locator(".availability").count(), 0);
    assert.deepEqual(external, [], "No external app requests");
    // Native controls, skip link and logical keyboard order.
    await gift.goto(base);
    await gift.keyboard.press("Tab");
    assert.equal(
      await gift.evaluate(() => document.activeElement.textContent.trim()),
      "Zum Inhalt",
    );
    await gift.keyboard.press("Enter");
    assert.equal(await gift.evaluate(() => document.activeElement.id), "main");
    console.log(
      `OK: no-JS setup/CRUD/upload, reservation flow, ${audits} axe/overflow audits, keyboard skip link, local resources`,
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
