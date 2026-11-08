"use strict";

// Installierbare App ohne Offline-Kopien privater Listen, APIs oder Bilder.
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (event) =>
  event.waitUntil(self.clients.claim()),
);
self.addEventListener("fetch", (event) => {
  event.respondWith(fetch(event.request));
});
