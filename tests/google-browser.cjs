// Lokaler Provider-Doppelgänger: echte Redirects/Callbacks, keine Google-Zugangsdaten.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const net = require("node:net");
const { spawn, execFileSync } = require("node:child_process");
const root = path.resolve(__dirname, "..");
const artifacts = path.join(__dirname, ".artifacts");
const axePath = process.env.AXE_PATH || require.resolve("axe-core/axe.min.js");
const php = process.env.PHP_BIN || "php";
(async () => {
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), "mywish-google-browser-"));
  const doc = path.join(temp, "public");
  fs.cpSync(root, path.join(doc, "mywish"), {
    recursive: true,
    filter: (source) =>
      !/\/(\.git|node_modules|\.artifacts|__pycache__)(\/|$)/.test(source) &&
      !["state.php", "layout.php", "lock.php", "config.php"].includes(
        path.basename(source),
      ) &&
      !/\/(backup-schema-.*|google-jwks.*)\.php$/.test(source),
  });
  const keyPath = path.join(temp, "keys.json");
  const keyScript =
    "$k=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);openssl_pkey_export($k,$p);$d=openssl_pkey_get_details($k);$e=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');echo json_encode(['private'=>$p,'jwks'=>['keys'=>[['kty'=>'RSA','kid'=>'test-key','alg'=>'RS256','use'=>'sig','n'=>$e($d['rsa']['n']),'e'=>$e($d['rsa']['e'])]]]]);";
  fs.writeFileSync(keyPath, execFileSync(php, ["-r", keyScript]));
  const port = await new Promise((resolve) => {
    const s = net.createServer();
    s.listen(0, "127.0.0.1", () => {
      const p = s.address().port;
      s.close(() => resolve(p));
    });
  });
  const base = `http://127.0.0.1:${port}/mywish/`;
  fs.writeFileSync(
    path.join(doc, "mywish/config.php"),
    `<?php return ['data'=>${JSON.stringify(path.join(temp, "data"))},'google'=>['client_id'=>'fixture.apps.googleusercontent.com','client_secret'=>'test-secret-only','redirect_uri'=>'${base}auth/google/callback.php']];`,
  );
  fs.mkdirSync(artifacts, { recursive: true });
  const log = fs.openSync(path.join(artifacts, "google-server.log"), "w");
  const server = spawn(
    php,
    [
      "-d",
      `auto_prepend_file=${path.join(root, "tests/fixtures/google-transport.php")}`,
      "-S",
      `127.0.0.1:${port}`,
      "-t",
      doc,
    ],
    {
      stdio: ["ignore", log, log],
      detached: true,
      env: { ...process.env, MYWISH_GOOGLE_TEST_KEYS: keyPath },
    },
  );
  let browser;
  let audits = 0;
  let provider = {
    sub: "google-browser",
    email: "google@example.com",
    name: "Google Person",
  };
  async function connect(context) {
    await context.route(
      "https://accounts.google.com/o/oauth2/v2/auth?*",
      async (route) => {
        const q = new URL(route.request().url()).searchParams;
        const claims = {
          iss: "https://accounts.google.com",
          aud: "fixture.apps.googleusercontent.com",
          email_verified: true,
          nonce: q.get("nonce"),
          iat: Math.floor(Date.now() / 1000),
          exp: Math.floor(Date.now() / 1000) + 600,
          auth_time: Math.floor(Date.now() / 1000),
          _challenge: q.get("code_challenge"),
          ...provider,
        };
        const code =
          "fixture:" + Buffer.from(JSON.stringify(claims)).toString("base64");
        const callback = new URL(q.get("redirect_uri"));
        callback.searchParams.set("state", q.get("state"));
        callback.searchParams.set("code", code);
        await route.fulfill({
          status: 303,
          headers: { location: callback.href },
          body: "",
        });
      },
    );
  }
  async function audit(page, name) {
    for (const width of [360, 768, 1440]) {
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
      assert.deepEqual(violations, [], `axe ${name} ${width}`);
      audits++;
    }
  }
  try {
    for (let i = 0; i < 100; i++) {
      try {
        await fetch(base + "index.php");
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
    const owner = await browser.newContext();
    const session = await (
      await owner.request.get(base + "api/index.php?action=session")
    ).json();
    const setup = await owner.request.post(
      base + "api/index.php?action=setup",
      {
        data: {
          csrf: session.csrf,
          siteName: "MyWish",
          name: "Existing",
          username: "existing@example.com",
          password: "Localpass!12",
          registration: "open",
        },
      },
    );
    assert.equal(setup.status(), 201);
    const context = await browser.newContext();
    await connect(context);
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    await page.goto(base + "index.php?page=login");
    assert.equal(
      await page.locator('.auth-tabs [aria-current="page"]').textContent(),
      "Mit Google",
    );
    assert.equal(await page.getByLabel("Passwort", { exact: true }).count(), 0);
    assert.equal(
      await page
        .getByRole("link", { name: "Jetzt registrieren", exact: true })
        .count(),
      0,
    );
    await audit(page, "google-login");
    await page.getByRole("link", { name: "Mit Passwort", exact: true }).click();
    assert.equal(
      await page.locator('.auth-tabs [aria-current="page"]').textContent(),
      "Mit Passwort",
    );
    assert.equal(
      await page
        .getByRole("button", { name: "Mit Google anmelden", exact: true })
        .count(),
      0,
    );
    await audit(page, "google-password-login");
    await page
      .getByLabel("Benutzername oder E-Mail", { exact: true })
      .fill("existing@example.com");
    await page.getByLabel("Passwort", { exact: true }).fill("incorrect");
    await page.getByRole("button", { name: "Anmelden", exact: true }).click();
    await page.getByRole("alert").waitFor();
    assert.equal(
      await page.locator('.auth-tabs [aria-current="page"]').textContent(),
      "Mit Passwort",
      "Failed password login stays on the second tab",
    );
    assert.equal(
      await page
        .getByLabel("Benutzername oder E-Mail", { exact: true })
        .inputValue(),
      "existing@example.com",
    );

    await page
      .getByRole("link", { name: "Jetzt registrieren", exact: true })
      .click();
    await audit(page, "google-registration");
    await page
      .getByRole("link", { name: "Jetzt anmelden", exact: true })
      .click();
    assert.equal(
      await page.locator('.auth-tabs [aria-current="page"]').textContent(),
      "Mit Passwort",
      "Return from registration stays on password tab",
    );

    await page.getByRole("link", { name: "Mit Google", exact: true }).click();
    await page
      .getByRole("button", { name: "Mit Google anmelden", exact: true })
      .click();
    await page.getByRole("heading", { name: /Hallo Google Person/ }).waitFor();
    await page.getByRole("link", { name: /Mein Konto:/ }).click();
    await audit(page, "google-only-account");
    assert.equal(
      await page.getByLabel("Aktuelles Passwort", { exact: true }).count(),
      0,
    );
    await page
      .getByRole("button", {
        name: "Mit Google erneut bestätigen",
        exact: true,
      })
      .click();
    await page
      .getByText(
        "Google bestätigt. Du kannst dein Konto jetzt für fünf Minuten bearbeiten.",
        { exact: true },
      )
      .waitFor();
    await page
      .getByLabel("Anzeigename", { exact: true })
      .fill("Changed Google Name");
    await page
      .getByLabel("Lokales Passwort festlegen (optional)", { exact: true })
      .fill("Newpassword!12");
    await page
      .getByRole("button", { name: "Änderungen speichern", exact: true })
      .click();
    await page
      .locator('form:has(input[name="action"][value="account"])')
      .getByLabel("Aktuelles Passwort", { exact: true })
      .waitFor();
    await audit(page, "google-linked-account");
    assert.deepEqual(errors, []);
    const legacy = await owner.newPage();
    await connect(owner);
    provider = {
      sub: "google-owner",
      email: "existing@example.com",
      name: "Existing",
    };
    await legacy.goto(base + "index.php?page=account");
    await legacy
      .getByLabel("Aktuelles Passwort zum Verknüpfen", { exact: true })
      .fill("Localpass!12");
    await legacy
      .getByRole("button", { name: "Google-Konto verknüpfen", exact: true })
      .click();
    await legacy
      .getByText("Dein Google-Konto ist jetzt verknüpft.", { exact: true })
      .waitFor();
    // Appseitiger OAuth-Start ist ein natives POST-Formular. Googles eigene UI wird nicht getestet.
    const nojs = await browser.newContext({ javaScriptEnabled: false });
    await nojs.route("**/auth/google/start.php", async (route) => {
      const response = await route.fetch({ maxRedirects: 0 });
      assert.equal(response.status(), 303);
      assert.equal(
        new URL(response.headers().location).origin,
        "https://accounts.google.com",
      );
      await route.fulfill({
        status: 200,
        contentType: "text/html; charset=utf-8",
        body: '<!doctype html><html lang="de"><h1>Google-Weiterleitung gestartet</h1></html>',
      });
    });
    const native = await nojs.newPage();
    await native.goto(base + "index.php?page=login");
    await native
      .getByRole("link", { name: "Mit Passwort", exact: true })
      .click();
    await native
      .getByLabel("Benutzername oder E-Mail", { exact: true })
      .fill("existing@example.com");
    await native.getByLabel("Passwort", { exact: true }).fill("Localpass!12");
    await native.getByRole("button", { name: "Anmelden", exact: true }).click();
    await native.getByRole("heading", { name: /Hallo Existing/ }).waitFor();
    await native.getByRole("link", { name: /Mein Konto:/ }).click();
    await native.getByRole("button", { name: "Abmelden", exact: true }).click();
    await native.goto(base + "index.php?page=login");

    await native
      .getByRole("button", { name: "Mit Google anmelden", exact: true })
      .click();
    await native
      .getByRole("heading", { name: "Google-Weiterleitung gestartet" })
      .waitFor();
    console.log(
      `OK: Google-first login/password tab, linking/reauthentication, no-JS tabs and form start, local password creation, ${audits} axe/overflow audits`,
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
