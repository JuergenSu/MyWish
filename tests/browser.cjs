/* Development-only browser checks. No browser libraries are needed by the app. */
const { chromium } = require("playwright");
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { spawn, execFileSync } = require("node:child_process");
const assert = require("node:assert/strict");
const net = require("node:net");
const root = path.resolve(__dirname, "..");
const artifacts = path.join(__dirname, ".artifacts");
const axePath = process.env.AXE_PATH || require.resolve("axe-core/axe.min.js");
fs.mkdirSync(artifacts, { recursive: true });
(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "mywish-browser-"));
  const web = path.join(temp, "public");
  fs.mkdirSync(web);
  const doc = path.join(web, "mywish");
  fs.cpSync(root, doc, {
    recursive: true,
    filter: (source) =>
      !/\/(\.git|node_modules|\.artifacts|__pycache__)(\/|$)/.test(source) &&
      !["state.php", "layout.php", "lock.php", "config.php"].includes(
        path.basename(source),
      ),
  });
  fs.writeFileSync(
    path.join(doc, "config.php"),
    `<?php return ['data'=>${JSON.stringify(path.join(temp, "data"))}];`,
  );
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
      "upload_max_filesize=8M",
      "-d",
      "post_max_size=10M",
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      web,
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
    // Freeze the actual rendered report: another homepage GET consumes the cursor.
    if (name === "recent-activity") {
      const html = await sourcePage.content();
      await page.route(sourcePage.url(), (route) =>
        route.fulfill({
          status: 200,
          contentType: "text/html; charset=utf-8",
          body: html,
        }),
      );
    }
    await page.goto(sourcePage.url());
    if (name.includes("share-open"))
      await page.locator(".share-control > summary").first().click();
    for (const width of [320, 360, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.equal(
        await page.evaluate(
          () => document.documentElement.scrollWidth > innerWidth,
        ),
        false,
        `Overflow ${name} ${width}`,
      );
      const clipped = await page
        .locator(".share-control > summary")
        .evaluateAll((items) =>
          items.some((item) => {
            const r = item.getBoundingClientRect();
            return r.width < 44 || r.left < 0 || r.right > innerWidth + 1;
          }),
        );
      assert.equal(clipped, false, `Share button clipped: ${name} ${width}`);
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
        await fetch(new URL("anleitung.php", base));
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
    const guideUrl = new URL("anleitung.php", base).href;
    const guideResponse = await page.goto(guideUrl);
    assert.equal(guideResponse.status(), 200);
    assert.equal(guideResponse.headers()["set-cookie"], undefined);
    assert.equal(await page.locator("form, input").count(), 0);
    assert.equal(fs.existsSync(path.join(doc, "storage/layout.php")), false);
    for (const link of await page.locator(".guide-contents a").all()) {
      const target = await link.getAttribute("href");
      assert.equal(await page.locator(target).count(), 1);
    }
    await audit(page, "public-guide");
    await page
      .getByRole("link", { name: "Ein Geschenk reservieren", exact: true })
      .click();
    assert.equal(new URL(page.url()).hash, "#schenken");
    await page
      .getByRole("link", { name: "Jetzt MyWish öffnen", exact: true })
      .click();
    assert.equal(page.url(), base);
    assert.equal(
      await page.locator('footer a[href$="/anleitung.php"]').count(),
      1,
    );
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
    assert.equal(
      await page.title(),
      "myWish - Ein Geburtstag voller Vorfreude von Antonia",
    );
    await page
      .getByRole("link", { name: "Wunsch hinzufügen", exact: true })
      .click();
    // These native field hints must remain understandable without JavaScript.
    for (const id of ["productUrl", "imageUrl"]) {
      const link = page.locator("#" + id);
      assert.equal(
        await link.getAttribute("placeholder"),
        "Gib einen Link ein …",
      );
      await link.fill("kein Link");
      assert.equal(
        await link.evaluate((input) => input.validity.patternMismatch),
        true,
      );
      assert.doesNotMatch(
        await link.evaluate((input) => input.validationMessage),
        /URL/i,
      );
      for (const value of [
        "https://shop.example/item",
        "HTTPS://shop.example/item",
        " http://shop.example/item ",
        "",
      ]) {
        await link.fill(value);
        assert.equal(
          await link.evaluate((input) => input.validity.valid),
          true,
        );
      }
    }
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
    const painter = await browser.newPage();
    const screenshot = await painter.evaluate(() => {
      const canvas = document.createElement("canvas");
      canvas.width = 1080;
      canvas.height = 2400;
      const c = canvas.getContext("2d");
      c.fillStyle = "#eeebfc";
      c.fillRect(0, 0, 1080, 2400);
      c.fillStyle = "#635bdb";
      c.fillRect(0, 0, 1080, 340);
      c.fillRect(0, 2250, 1080, 150);
      c.fillStyle = "white";
      c.font = "bold 76px sans-serif";
      c.fillText("Mein Screenshot", 100, 205);
      c.fillStyle = "#25223b";
      c.font = "56px sans-serif";
      c.fillText("Ein Buch für gemütliche", 80, 660);
      c.fillText("Winterabende", 80, 750);
      c.fillStyle = "#b5a7ea";
      c.fillRect(170, 1000, 740, 950);
      return canvas.toDataURL("image/png").split(",")[1];
    });
    await painter.close();
    const png = Buffer.alloc(8 * 1024 * 1024);
    Buffer.from(screenshot, "base64").copy(png);
    // Real 8 MB phone screenshot upload works without JavaScript.
    await page
      .getByLabel("Eigenes Bild (optional)", { exact: true })
      .setInputFiles({
        name: "Handy-Screenshot.png",
        mimeType: "image/png",
        buffer: png,
      });
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
    const media = await owner.request.get(
      new URL(await page.locator(".wish-image-main").getAttribute("src"), base)
        .href,
    );
    const imageBytes = await media.body();
    assert.equal(imageBytes.readUInt32BE(16), 540);
    assert.equal(imageBytes.readUInt32BE(20), 1200);
    await page.locator(".share-control > summary").click();
    const shareUrl = await page.locator(".share-url").inputValue();
    assert.equal(new URL(shareUrl).origin, new URL(base).origin);
    assert.equal(new URL(shareUrl).pathname, new URL(base).pathname);
    assert.equal(new URL(shareUrl).searchParams.get("page"), "share");
    await page.locator(".share-control > summary").click();
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
    assert.equal(await imagePage.locator("#google-image-search").count(), 0);
    assert.deepEqual(
      unexpectedImageRequests,
      [],
      "No Google image search requests while typing",
    );
    await audit(imagePage, "image-form");
    await imagePage.goto(listUrl);
    await imagePage.locator(".share-control > summary").click();
    await imagePage.evaluate(() => {
      Object.defineProperty(navigator, "share", {
        configurable: true,
        value: undefined,
      });
      Object.defineProperty(navigator, "clipboard", {
        configurable: true,
        value: {
          writeText: async (text) => {
            window.copiedShare = text;
          },
        },
      });
    });
    await imagePage.locator("[data-share-button]").click();
    await imagePage
      .getByText("Link kopiert. Du kannst ihn jetzt verschicken.")
      .waitFor();
    assert.equal(await imagePage.evaluate(() => window.copiedShare), shareUrl);
    await imagePage.evaluate(() =>
      Object.defineProperty(navigator, "share", {
        configurable: true,
        value: async (data) => {
          window.nativeShare = data;
        },
      }),
    );
    await imagePage.locator("[data-share-button]").click();
    assert.equal(
      await imagePage.evaluate(() => window.nativeShare.url),
      shareUrl,
    );
    await audit(imagePage, "list-share-open");
    await imagePage.goto(base);
    await audit(imagePage, "dashboard-share-open");
    const noJsGuest = await browser.newContext({ javaScriptEnabled: false });
    const publicNoJs = await noJsGuest.newPage();
    await publicNoJs.goto(shareUrl);
    assert.equal(
      await publicNoJs.title(),
      "myWish - Ein Geburtstag voller Vorfreude von Antonia",
    );
    assert.equal(await publicNoJs.locator(".wish-card").count(), 2);
    await publicNoJs
      .locator(".wish-image-main")
      .evaluate((img) =>
        img.complete
          ? null
          : new Promise((r) => img.addEventListener("load", r, { once: true })),
      );
    assert.ok(
      await publicNoJs
        .locator(".wish-image-main")
        .evaluate((img) => img.naturalWidth > 0),
    );
    await publicNoJs
      .getByRole("link", { name: "Für mich reservieren", exact: true })
      .first()
      .click();
    assert.equal(new URL(publicNoJs.url()).searchParams.get("page"), "login");
    await noJsGuest.close();
    await imageOwner.close();
    await page.goto(base);
    const giver = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    });
    const gift = await giver.newPage();
    await gift.goto(shareUrl);
    await audit(gift, "shared-list");
    await gift
      .getByRole("link", { name: "Für mich reservieren", exact: true })
      .first()
      .click();
    assert.equal(
      new URL(gift.url()).searchParams.get("returnList"),
      new URL(listUrl).searchParams.get("id"),
    );
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
    assert.equal(
      gift.url(),
      listUrl,
      "Registration returns to the shared list",
    );
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
    const estimatedBudget = await gift.locator(".budget-total").innerText();
    await gift.getByLabel("Gekauft für (€)", { exact: true }).fill("12,34");
    await gift
      .getByRole("button", { name: "Kaufpreis speichern", exact: true })
      .click();
    await gift.getByText("Kaufpreis gespeichert.", { exact: true }).waitFor();
    assert.match(
      await gift.locator(".budget-total").innerText(),
      /12,34 € verplant/,
    );
    await gift
      .getByRole("checkbox", { name: "Schon gekauft", exact: true })
      .check();
    await gift.getByText("Als gekauft markiert.", { exact: true }).waitFor();
    assert.equal(await gift.locator(".wish-card.is-purchased").count(), 1);
    assert.equal(
      await gift.locator(".purchase-progress").innerText(),
      "1 von 1 schon gekauft",
    );
    assert.equal(
      await gift
        .getByRole("checkbox", { name: "Schon gekauft", exact: true })
        .isChecked(),
      true,
    );
    await audit(gift, "reservation");
    await gift
      .getByRole("checkbox", { name: "Schon gekauft", exact: true })
      .uncheck();
    await gift.getByText("Kaufmarkierung entfernt.", { exact: true }).waitFor();
    assert.equal(await gift.locator(".wish-card.is-purchased").count(), 0);
    assert.equal(
      await gift.locator(".purchase-progress").innerText(),
      "0 von 1 schon gekauft",
    );
    assert.equal(
      await gift.getByLabel("Gekauft für (€)", { exact: true }).inputValue(),
      "12,34",
    );
    await gift.getByLabel("Gekauft für (€)", { exact: true }).fill("");
    await gift
      .getByRole("button", { name: "Kaufpreis speichern", exact: true })
      .click();
    await gift.getByText("Kaufpreis gespeichert.", { exact: true }).waitFor();
    assert.equal(
      await gift.locator(".budget-total").innerText(),
      estimatedBudget,
    );
    await gift
      .getByRole("button", { name: "Reservierung freigeben", exact: true })
      .click();
    await gift.locator(".wish-card").waitFor({ state: "detached" });
    assert.equal(await gift.locator(".wish-card").count(), 0);
    assert.equal(await gift.locator(".budget-person").count(), 0);
    await page.goto(listUrl);
    assert.equal(await page.locator(".availability").count(), 0);
    assert.deepEqual(external, [], "No external app requests");
    await page.goto(base + "?page=admin");
    assert.equal(await page.locator(".admin-statistics").count(), 0);
    function adminRole(flag) {
      execFileSync(process.env.PHP_BIN || "php", [
        path.join(doc, "tools/set-admin.php"),
        "--web-root=" + web,
        "--username=antonia",
        flag,
      ]);
    }
    adminRole("--enable");
    await page.goto(base + "?page=account");
    await page
      .locator("main")
      .getByRole("link", { name: "Admin-Statistik", exact: true })
      .click();
    assert.equal(await page.locator(".admin-statistics dt").count(), 12);
    assert.equal(await page.locator("main form").count(), 0);
    await audit(page, "admin-statistics");
    adminRole("--disable");
    assert.equal((await page.goto(base + "?page=admin")).status(), 403);
    // Native controls, skip link and logical keyboard order.
    await gift.goto(base);
    await gift.keyboard.press("Tab");
    assert.equal(
      await gift.evaluate(() => document.activeElement.textContent.trim()),
      "Zum Inhalt",
    );
    await gift.keyboard.press("Enter");
    assert.equal(await gift.evaluate(() => document.activeElement.id), "main");
    // Group multiple lists by recipient, while keeping other recipients separate.
    const apiUrl = new URL("api/index.php", base).href;
    async function api(context, action, data) {
      const session = await (
        await context.request.get(apiUrl + "?action=session")
      ).json();
      const response =
        data === undefined
          ? await context.request.get(apiUrl + "?action=" + action)
          : await context.request.post(apiUrl + "?action=" + action, {
              data,
              headers: { "X-CSRF-Token": session.csrf },
            });
      const result = await response.json();
      assert.ok(result.ok, JSON.stringify(result));
      return result.data;
    }
    await page.goto(base); // Baseline for the returning user.
    const recipient = await browser.newContext();
    await api(recipient, "register", {
      name: "Bea",
      username: "recipient-bea",
      password: "abcdefgh",
    });
    const firstList = await api(owner, "saveList", {
      name: "Antonia eins",
      occasion: "Anderes",
    });
    const secondList = await api(owner, "saveList", {
      name: "Antonia zwei",
      occasion: "Anderes",
    });
    const beaList = await api(recipient, "saveList", {
      name: "Beas Liste",
      occasion: "Anderes",
    });
    for (const [context, list, price] of [
      [owner, firstList, "20"],
      [owner, secondList, "30"],
      [recipient, beaList, "15"],
    ]) {
      const wish = await api(context, "saveWish", {
        listId: list.id,
        title: "Geschenk " + price,
        price,
        quantity: "1",
      });
      await api(giver, "reserve", { id: wish.id });
    }
    await gift.goto(base + "?page=reservations");
    assert.equal(await gift.locator(".recipient-reservations").count(), 2);
    const antoniaGroup = gift.locator(".recipient-reservations").filter({
      has: gift.getByRole("heading", { name: "Für Antonia", exact: true }),
    });
    const beaGroup = gift.locator(".recipient-reservations").filter({
      has: gift.getByRole("heading", { name: "Für Bea", exact: true }),
    });
    assert.equal(await antoniaGroup.locator(".wish-card").count(), 2);
    assert.equal(await beaGroup.locator(".wish-card").count(), 1);
    const budgetTexts = await gift.locator(".budget-total").allTextContents();
    assert.ok(
      budgetTexts.some((text) => text.includes("50,00 €")) &&
        budgetTexts.some((text) => text.includes("15,00 €")),
    );
    await antoniaGroup
      .locator(".wish-card")
      .first()
      .getByRole("checkbox", { name: "Schon gekauft", exact: true })
      .check();
    await gift.getByText("Als gekauft markiert.", { exact: true }).waitFor();
    assert.equal(await gift.locator(".wish-card.is-purchased").count(), 1);
    assert.deepEqual(
      await gift.locator(".purchase-progress").allTextContents(),
      ["1 von 2 schon gekauft", "0 von 1 schon gekauft"],
    );
    await audit(gift, "reservation-grouped");
    for (const [group, index, category] of [
      [antoniaGroup, 0, "Weihnachten"],
      [antoniaGroup, 1, "Geburtstag"],
      [beaGroup, 0, "Ostern"],
    ]) {
      const card = group.locator(".wish-card").nth(index);
      assert.equal(
        await card.getByLabel("Kategorie", { exact: true }).inputValue(),
        "Sonstiges",
      );
      await card
        .getByLabel("Kategorie", { exact: true })
        .selectOption(category);
      await card
        .getByRole("button", { name: "Kategorie speichern", exact: true })
        .click();
      await gift.getByText("Kategorie gespeichert.", { exact: true }).waitFor();
      assert.equal(
        await card.getByLabel("Kategorie", { exact: true }).inputValue(),
        category,
      );
    }
    assert.deepEqual(
      await gift.locator(".budget-category h4").allTextContents(),
      ["Geburtstag", "Weihnachten", "Ostern"],
    );
    assert.deepEqual(await gift.locator(".budget-total").allTextContents(), [
      "30,00 € verplant",
      "20,00 € verplant",
      "15,00 € verplant",
    ]);
    assert.deepEqual(
      await gift.locator(".purchase-progress").allTextContents(),
      [
        "0 von 1 schon gekauft",
        "1 von 1 schon gekauft",
        "0 von 1 schon gekauft",
      ],
    );
    await audit(gift, "reservation-categories");
    await page.goto(base);
    const activity = page.locator(".recent-activity");
    assert.match(await activity.innerText(), /Bea/);
    assert.match(await activity.innerText(), /Hat sich registriert/);
    assert.match(await activity.innerText(), /1 Wunsch hinzugefügt/);
    assert.doesNotMatch(
      await activity.innerText(),
      /Kaufpreis|gekauft|reserviert/,
    );
    await audit(page, "recent-activity");
    await page.reload();
    assert.match(
      await page.locator(".recent-activity").innerText(),
      /keine neuen Änderungen/,
    );
    await recipient.close();
    console.log(
      `OK: no-JS setup/CRUD/8 MB upload, public sharing/native/copy/login return, reservation flow, ${audits} axe/overflow audits, keyboard skip link, local resources`,
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
