<?php
// Optional: als config.php kopieren. Ohne Google sind keine Zugangsdaten erforderlich.
// Empfohlen: beschreibbarer Ordner außerhalb des öffentlich erreichbaren Webroots.
return [
    'storage' => __DIR__.'/storage',
    // null: Setup-Einstellung. Betreiber kann hier auf open oder closed umschalten.
    'registration' => null,
    // Optional: Google OAuth-Webclient. Leere Werte deaktivieren Google-Login.
    // Secret ausschließlich hier auf dem Server oder in einer Umgebungsvariable speichern.
    'google' => [
        'client_id' => '',
        'client_secret' => getenv('MYWISH_GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => '', // https://deine-domain/mywish/auth/google/callback.php
    ],
];
