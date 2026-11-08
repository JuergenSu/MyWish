"use strict";

(() => {
  const banner = document.getElementById("install-banner");
  if (!banner || !window.isSecureContext) return;

  const button = document.getElementById("install-button");
  const dismissButton = document.getElementById("install-dismiss");
  const help = document.getElementById("install-help");
  const standalone = window.matchMedia("(display-mode: standalone)");
  const base = new URL(banner.dataset.base, window.location.origin);
  const storageKey = `mywish:install-dismissed:${base.pathname}`;
  const ios =
    /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  const mobile =
    ios ||
    /Android/.test(navigator.userAgent) ||
    navigator.userAgentData?.mobile;
  let promptEvent = null;
  let dismissed = false;

  try {
    dismissed = Number(localStorage.getItem(storageKey)) > Date.now();
  } catch {
    /* Auch ohne verfügbaren lokalen Speicher bedienbar. */
  }

  function installed() {
    return standalone.matches || navigator.standalone === true;
  }

  function update() {
    banner.hidden = !mobile || dismissed || installed();
    button.textContent = promptEvent ? "Hinzufügen" : "So geht’s";
  }

  function dismiss() {
    dismissed = true;
    try {
      localStorage.setItem(storageKey, String(Date.now() + 7 * 86400000));
    } catch {
      /* Schließen bleibt für die aktuelle Seite wirksam. */
    }
    if (banner.contains(document.activeElement)) {
      document.querySelector(".header .brand")?.focus({ preventScroll: true });
    }
    update();
  }

  function showInstructions() {
    help.textContent = ios
      ? "Tippe auf „Teilen“ und dann auf „Zum Home-Bildschirm“. Bestätige mit „Hinzufügen“. Falls der Eintrag fehlt, öffne die Seite in Safari."
      : "Öffne das Browsermenü (⋮) und wähle „App installieren“ oder „Zum Startbildschirm hinzufügen“. Bestätige mit „Hinzufügen“.";
    help.hidden = false;
    button.setAttribute("aria-expanded", "true");
  }

  window.addEventListener("beforeinstallprompt", (event) => {
    if (!mobile) return;
    event.preventDefault();
    promptEvent = event;
    update();
  });
  window.addEventListener("appinstalled", () => {
    promptEvent = null;
    dismiss();
  });
  standalone.addEventListener?.("change", update);
  dismissButton.addEventListener("click", dismiss);
  button.addEventListener("click", async () => {
    if (!promptEvent) {
      showInstructions();
      return;
    }
    const event = promptEvent;
    promptEvent = null;
    button.disabled = true;
    try {
      // Unmittelbar auf den Nutzerklick reagieren: die Bestätigung übernimmt das OS.
      await event.prompt();
      const choice = await event.userChoice;
      if (choice.outcome === "accepted") dismiss();
      else showInstructions();
    } catch {
      showInstructions();
    } finally {
      button.disabled = false;
      update();
    }
  });

  if ("serviceWorker" in navigator) {
    navigator.serviceWorker
      .register(new URL("sw.js", base), { scope: base.pathname })
      .catch(() => {
        /* Menü-Anleitung bleibt auch ohne Service Worker verfügbar. */
      });
  }
  update();
})();
