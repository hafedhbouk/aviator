<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Support\Text\SearchNormalizer;
use RuntimeException;

/**
 * Empreinte HMAC d'un identifiant stocké chiffré (D8) : permet la recherche
 * exacte et la détection des doublons sans conserver le numéro en clair.
 */
final class BlindIndex
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 32) {
            throw new RuntimeException(
                'Clé AVIATOR_BLIND_INDEX_KEY absente ou trop courte (32 caractères minimum) : renseignez-la dans .env.'
            );
        }
    }

    /**
     * @param  string  $purpose  Usage de l'empreinte (exemple : « id_document », « license ») :
     *                           un même numéro donne des empreintes différentes selon l'usage.
     */
    public function hash(string $value, string $purpose): string
    {
        return hash_hmac('sha256', $purpose.'|'.SearchNormalizer::identifier($value), $this->key);
    }
}
