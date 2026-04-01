<?php
// generar_llaves.php

require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

try {
    $vapid = VAPID::createVapidKeys();

    echo "====================================\n";
    echo "🔑 CLAVES VAPID GENERADAS CON ÉXITO\n";
    echo "====================================\n\n";

    echo "Añade esto a tu .env de Vue (Vite):\n";
    echo "VITE_VAPID_PUBLIC_KEY=" . $vapid['publicKey'] . "\n\n";

    echo "Añade esto a tu .env de Symfony:\n";
    echo "VAPID_PUBLIC_KEY=" . $vapid['publicKey'] . "\n";
    echo "VAPID_PRIVATE_KEY=" . $vapid['privateKey'] . "\n";
    echo "VAPID_SUBJECT=mailto:admin@openperu.pe\n\n";

} catch (Exception $e) {
    echo "Error al generar las llaves: " . $e->getMessage() . "\n";
}