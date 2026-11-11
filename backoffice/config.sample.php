<?php
// Copia questo file in config.php e compila i valori. config.php non va mai nel repository.
return [
    'site_name' => 'ARNOplast',
    'site_url' => 'https://www.arnoplast.it',          // indirizzo del sito pubblico (logo e link nelle e-mail)
    'assets_url' => 'https://www.arnoplast.it',         // dove stanno le immagini del sito (per le anteprime della galleria); finché il sito è in prova: https://nuovo.arnoplast.it
    'admin_url' => 'https://gestione.arnoplast.it',     // indirizzo di questo backoffice
    'allowed_origins' => ['https://www.arnoplast.it', 'https://nuovo.arnoplast.it'], // siti che possono inviare i moduli
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=NOME_DATABASE;charset=utf8mb4',
        'user' => 'UTENTE_DATABASE',
        'password' => 'PASSWORD_DATABASE',
    ],
    'mail' => [
        'host' => 'mail.arnoplast.it',
        'port' => 465,
        'encryption' => 'ssl',                           // ssl (465) oppure tls (587)
        'user' => 'richieste@arnoplast.it',
        'password' => 'PASSWORD_DELLA_CASELLA',
        'from' => 'richieste@arnoplast.it',
        'from_name' => 'ARNOplast',
        'notify_to' => ['info@arnoplast.it'],            // chi riceve la notifica di una nuova richiesta
    ],
    // Lettura delle risposte dei clienti (facoltativa: se manca si usano host, utente e password di 'mail').
    // 'imap' => ['host' => 'mail.arnoplast.it', 'port' => 993, 'encryption' => 'ssl', 'folder' => 'INBOX'],
    'secret' => 'STRINGA_CASUALE_LUNGA_ALMENO_32_CARATTERI', // per rendere anonimi gli indirizzi IP nel registro
    'setup_token' => 'STRINGA_CASUALE_PER_CREARE_IL_PRIMO_UTENTE', // serve una volta sola, poi svuotala
    'contact_types' => ['Privato/Altro', 'Azienda Agricola', 'Garden/Vivaio', 'Riv. Macc. Agr./Officina', 'Agraria/Consorzio Agrario',
        'Ferramenta/Fai da te', 'Manutentori/Giardinieri', 'Grossa Distrib. Spec.', 'Ingrosso/Consulente', 'Installatore/Importatore', 'Edile', 'Ente'],
];
