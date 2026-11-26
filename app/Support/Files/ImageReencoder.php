<?php

declare(strict_types=1);

namespace App\Support\Files;

use GdImage;
use InvalidArgumentException;

/**
 * Ré-encodage des images envoyées par le navigateur (D44, D45), avec l'extension GD
 * (présente chez l'hébergeur, aucun paquet) :
 *
 * - le contenu est contrôlé (JPEG, PNG ou WebP réels, taille en pixels bornée) : un
 *   fichier qui n'est pas une image, même renommé en `.jpg`, est refusé ;
 * - l'image est redessinée, puis réécrite : les métadonnées (EXIF, position GPS) et
 *   tout contenu caché disparaissent ;
 * - photo : plus grand côté ramené à 1 600 px, JPEG qualité 75 ; signature : PNG, fond
 *   transparent conservé.
 */
final class ImageReencoder
{
    public const MAX_SIDE = 1600;

    public const JPEG_QUALITY = 75;

    /** Au-delà, l'image n'est pas décodée (mémoire du mutualisé). */
    public const MAX_PIXELS = 40_000_000;

    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    public function photo(string $bytes): array
    {
        $image = $this->decode($bytes);
        [$width, $height] = $this->fit(imagesx($image), imagesy($image));

        $canvas = $this->canvas($width, $height, transparent: false);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);

        return ['bytes' => (string) ob_get_clean(), 'width' => $width, 'height' => $height];
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    public function signature(string $bytes): array
    {
        $image = $this->decode($bytes, [IMAGETYPE_PNG]);
        [$width, $height] = $this->fit(imagesx($image), imagesy($image));

        $canvas = $this->canvas($width, $height, transparent: true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        ob_start();
        imagepng($canvas, null, 9);

        return ['bytes' => (string) ob_get_clean(), 'width' => $width, 'height' => $height];
    }

    /**
     * @param  list<int>  $accepted
     */
    private function decode(string $bytes, array $accepted = self::ACCEPTED): GdImage
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], $accepted, true)) {
            throw new InvalidArgumentException('Fichier refusé : ce n\'est pas une image acceptée.');
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image refusée : dimensions hors limites.');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Fichier refusé : image illisible.');
        }

        return $image;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height): array
    {
        $largest = max($width, $height);

        if ($largest <= self::MAX_SIDE) {
            return [$width, $height];
        }

        return [
            max(1, intdiv($width * self::MAX_SIDE, $largest)),
            max(1, intdiv($height * self::MAX_SIDE, $largest)),
        ];
    }

    private function canvas(int $width, int $height, bool $transparent): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if ($transparent) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        }

        return $canvas;
    }
}
