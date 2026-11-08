<?php
// Als config.php kopieren. Bestehende config.php bei Updates erhalten.
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
    // Optional: echte Produktbilder über die OpenAI Responses API suchen.
    // Leerer API-Key deaktiviert die Suche. Schlüssel nur auf dem Server eintragen.
    'openai' => [
        'api_key' => '',
        'model' => 'gpt-6-astra',
        'daily_limit' => 100, // Maximale neue Suchanfragen pro UTC-Tag für diese Installation.
    ],
];
