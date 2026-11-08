"use strict";
// Progressive Verbesserung: alle Formulare funktionieren auch ohne JavaScript.
document.addEventListener(
  "error",
  (event) => {
    if (event.target instanceof HTMLImageElement) {
      event.target.hidden = true;
      event.target.parentElement.classList.add("image-failed");
    }
  },
  true,
);
function feedback(message, error = false) {
  let box = document.getElementById("dynamic-feedback");
  if (!box) {
    box = document.createElement("div");
    box.id = "dynamic-feedback";
    document.getElementById("main").prepend(box);
  }
  box.className = `notice ${error ? "error" : "success"}`;
  box.setAttribute("role", error ? "alert" : "status");
  box.textContent = message;
}
function reservationStatus(form, state) {
  const card = form.closest(".wish-card");
  const quantity = Number(card.dataset.quantity);
  const status = card.querySelector(".availability");
  status.className = `availability ${state.reservedByMe ? "mine" : state.available > 0 ? "free" : "taken"}`;
  status.textContent = state.reservedByMe
    ? "Eine Einheit für dich reserviert"
    : state.available > 0
      ? quantity > 1
        ? `${state.available} von ${quantity} verfügbar`
        : "Noch zu haben"
      : "Bereits reserviert";
  const button = form.querySelector("button");
  form.elements.action.value = state.reservedByMe ? "unreserve" : "reserve";
  button.textContent = state.reservedByMe
    ? "Reservierung freigeben"
    : quantity > 1
      ? "Eine Einheit reservieren"
      : "Für mich reservieren";
  button.className = `button ${state.reservedByMe ? "secondary" : "primary"} full`;
  button.disabled = !state.reservedByMe && state.available === 0;
  if (button.disabled) button.textContent = "Schon verplant";
}
document.querySelectorAll("form").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    if (
      form.getAttribute("id") === "wish-form" &&
      (event.submitter?.value === "searchImage" ||
        form.getAttribute("aria-busy") === "true")
    )
      return;
    if (!form.matches(".reservation-form") || !window.fetch) {
      if (event.submitter) {
        event.submitter.setAttribute("aria-busy", "true");
        form.classList.add("submitting");
      }
      return;
    }
    event.preventDefault();
    const button = form.querySelector("button");
    if (button.getAttribute("aria-busy") === "true") return;
    button.disabled = true;
    button.setAttribute("aria-busy", "true");
    try {
      const response = await fetch(form.dataset.api, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const result = await response.json();
      if (result.csrf)
        document.querySelectorAll("input[name=csrf]").forEach((input) => {
          input.value = result.csrf;
        });
      if (!result.ok) {
        button.disabled = false;
        if (response.status === 409) {
          const snapshot = await fetch(`${form.dataset.api}?action=state`, {
            credentials: "same-origin",
          }).then((r) => r.json());
          if (snapshot.ok) {
            const wish = snapshot.data.wishes.find(
              (w) => w.id === form.elements.id.value,
            );
            if (wish && Object.hasOwn(wish, "available"))
              reservationStatus(form, wish);
          }
        }
        feedback(result.error.message, true);
        return;
      }
      feedback(result.data.message);
      if (
        form.elements.returnPage.value === "reservations" &&
        !result.data.reservedByMe
      ) {
        form.closest(".wish-card").remove();
        if (!document.querySelector(".wish-card"))
          feedback(
            "Alle Reservierungen freigegeben. In „Alle Listen“ findest du neue Geschenkideen.",
          );
      } else {
        reservationStatus(form, result.data);
      }
    } catch {
      button.disabled = false;
      feedback(
        "Verbindung unterbrochen. Bitte die Seite aktualisieren und den Reservierungsstand prüfen, bevor du es erneut versuchst.",
        true,
      );
    } finally {
      button.removeAttribute("aria-busy");
    }
  });
});

// Die optionale Bildsuche läuft als zweite Anfrage, damit die Produktvorschau
// schon während der Suche nutzbar ist. Ohne JavaScript bleibt der POST-Button.
(() => {
  const form = document.querySelector("#wish-form");
  const button = document.querySelector("#openai-image-search");
  const status = document.querySelector("#openai-image-status");
  const box = document.querySelector("#imported-image-box");
  if (!form || !button || !status || !box) return;
  let busy = false;
  let controller;
  const fields = ["title", "productUrl"];
  const signature = () =>
    JSON.stringify(fields.map((name) => form.elements[name].value));
  fields.forEach((name) =>
    form.elements[name].addEventListener("input", () => controller?.abort()),
  );
  form.elements.image?.addEventListener("change", () => controller?.abort());
  form.addEventListener("submit", (event) => {
    if (event.submitter !== button) {
      if (busy) {
        event.preventDefault();
        status.textContent =
          "Die Bildsuche läuft noch. Bitte kurz warten oder Titel bzw. Link ändern, um sie abzubrechen.";
      }
      return;
    }
    event.preventDefault();
    search();
  });
  async function search() {
    if (busy) return;
    if (!form.elements.title.value.trim()) {
      status.className = "notice soft";
      status.textContent = "Bitte zuerst einen Produktnamen eingeben.";
      form.elements.title.focus();
      return;
    }
    if (form.elements.image?.files?.length) {
      status.className = "notice soft";
      status.textContent =
        "Ein eigenes Bild ist ausgewählt. Entferne die Dateiauswahl, wenn OpenAI suchen soll.";
      return;
    }
    busy = true;
    controller = new AbortController();
    button.disabled = true;
    form.setAttribute("aria-busy", "true");
    status.className = "notice soft";
    status.textContent =
      "OpenAI sucht ein Bild, bei Bedarf auch ein allgemeines Beispielbild …";
    const initial = signature();
    const payload = { action: "searchImage" };
    ["csrf", "listId", "id", ...fields].forEach(
      (name) => (payload[name] = form.elements[name].value),
    );
    try {
      const response = await fetch(
        form.dataset.imageApi + "?action=searchImage",
        {
          method: "POST",
          credentials: "same-origin",
          signal: controller.signal,
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        },
      );
      const result = await response.json();
      if (!result.ok)
        throw new Error(
          result.error?.message || "Die Bildsuche ist gerade nicht verfügbar.",
        );
      if (signature() !== initial) return;
      form.querySelector('[name="useImportedImage"]')?.remove();
      box.replaceChildren();
      status.textContent = result.data.warning;
      status.className = result.data.hasImage
        ? "notice success"
        : "notice soft";
      if (!result.data.hasImage) return;
      const selected = document.createElement("input");
      Object.assign(selected, {
        type: "checkbox",
        name: "useImportedImage",
        value: "yes",
        checked: true,
      });
      const selection = document.createElement("label");
      selection.className = "check";
      selection.append(selected, "Dieses Bild übernehmen");
      const preview = document.createElement("img");
      Object.assign(preview, {
        className: "image-preview",
        alt: "Importierte Bildvorschau",
        src: box.dataset.previewUrl + "&v=" + Date.now(),
      });
      const credit = document.createElement("a");
      Object.assign(credit, {
        textContent: "Bildvorschlag · Bildquelle (öffnet neuen Tab)",
        href: result.data.imageSourceUrl,
        target: "_blank",
        rel: "noopener noreferrer",
      });
      const help = document.createElement("p");
      help.className = "field-help";
      help.textContent = "Importiertes Bild wird beim Speichern übernommen.";
      box.append(selection, preview, credit, help);
      document.querySelector(".image-search-help")?.remove();
    } catch (error) {
      status.className = "notice error";
      status.textContent =
        error.name === "AbortError"
          ? "Bildsuche abgebrochen. Bitte mit den geänderten Angaben erneut suchen."
          : error.message;
      // Eine fehlgeschlagene Suche darf kein altes Importbild auswählen.
      form.querySelector('[name="useImportedImage"]')?.remove();
      box.replaceChildren();
    } finally {
      busy = false;
      button.disabled = false;
      button.removeAttribute("aria-busy");
      form.classList.remove("submitting");
      form.removeAttribute("aria-busy");
    }
  }
  if (form.dataset.imageAuto === "yes") search();
})();

// Native Teilen-Funktion auf Handys, sonst Zwischenablage oder manuelles Kopieren.
document.querySelectorAll(".share-control").forEach((control) => {
  const input = control.querySelector(".share-url");
  const button = control.querySelector("[data-share-button]");
  const status = control.querySelector(".share-feedback");
  input.value = new URL(input.value, window.location.href).href;
  button.hidden = false;
  button.textContent = navigator.share ? "Link teilen" : "Link kopieren";
  input.addEventListener("click", () => input.select());
  button.addEventListener("click", async () => {
    const data = { title: button.dataset.shareTitle, url: input.value };
    if (navigator.share) {
      try {
        await navigator.share(data);
        return;
      } catch (error) {
        if (error.name === "AbortError") return;
      }
    }
    try {
      await navigator.clipboard.writeText(input.value);
      status.textContent = "Link kopiert. Du kannst ihn jetzt verschicken.";
    } catch {
      input.focus();
      input.select();
      status.textContent = "Bitte den markierten Link kopieren.";
    }
    status.hidden = false;
  });
});
