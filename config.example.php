<?php
// Als config.php kopieren. Bestehende config.php bei Updates erhalten.
// Copy-Deployment: storage/ liegt neben index.php und wird durch .htaccess gesperrt.
return [
    'storage' => __DIR__.'/storage',
    // null: storage/data-v4 mit JSON-Dateien und separaten Bildern.
    // storage/.htaccess mitkopieren; Apache 2.4 muss Require all denied beachten.
    // Vor der ersten Nutzung prüft MyWish den Schutz per lokaler HTTP-Anfrage.
    // Optional: absoluter privater Datenpfad außerhalb des Webroots (z.B. für Nginx).
    'data' => null,

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
