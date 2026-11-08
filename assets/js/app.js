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
