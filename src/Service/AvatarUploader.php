<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gère l'upload d'un avatar utilisateur :
 *   - valide le format (jpg/png/webp uniquement, pas de svg pour éviter XSS)
 *   - valide la taille max (5 Mo)
 *   - redimensionne à 400x400 max via GD tout en gardant le ratio
 *   - encode en base64 et pose photoData + photoMimeType sur User
 *
 * Le stockage en DB (TEXT + base64) suit exactement le pattern déjà en place
 * pour Coach.photoData — cohérence + zéro dépendance filesystem, backup facile.
 */
final class AvatarUploader
{
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_BYTES = 5 * 1024 * 1024; // 5 Mo
    private const TARGET_SIZE = 400;

    /**
     * @throws \RuntimeException si l'upload est invalide (format/taille) ou si
     *                          GD n'est pas installé côté serveur.
     */
    public function apply(User $user, UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Le fichier envoyé est invalide.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('L\'image dépasse 5 Mo, choisis un fichier plus léger.');
        }
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \RuntimeException('Format non supporté — utilise du JPG, PNG ou WebP.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('Extension GD requise côté serveur (pas installée).');
        }

        try {
            $raw = file_get_contents($file->getPathname());
            if ($raw === false) {
                throw new FileException('Impossible de lire le fichier uploadé.');
            }

            $source = imagecreatefromstring($raw);
            if ($source === false) {
                throw new \RuntimeException('Impossible de décoder cette image.');
            }

            [$resized, $outputMime] = $this->resizeSquare($source, $mime);
            imagedestroy($source);

            $encoded = base64_encode($resized);

            $user->setPhotoData($encoded);
            $user->setPhotoMimeType($outputMime);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de traiter l\'image : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retire l'avatar (bouton "Retirer ma photo").
     */
    public function remove(User $user): void
    {
        $user->setPhotoData(null);
        $user->setPhotoMimeType(null);
    }

    /**
     * Redimensionne l'image à 400x400 max en conservant le ratio via GD.
     * Retourne le binaire encodé (JPG ou PNG selon transparence) + le mime cible.
     *
     * @return array{0: string, 1: string}
     */
    private function resizeSquare(\GdImage $source, string $sourceMime): array
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $ratio = min(self::TARGET_SIZE / $srcW, self::TARGET_SIZE / $srcH, 1.0);
        $dstW = max(1, (int) round($srcW * $ratio));
        $dstH = max(1, (int) round($srcH * $ratio));

        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            throw new \RuntimeException('Impossible d\'allouer une nouvelle image.');
        }

        // Préserve la transparence pour PNG / WebP
        $keepsAlpha = in_array($sourceMime, ['image/png', 'image/webp'], true);
        if ($keepsAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        }

        imagecopyresampled($dst, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        if ($keepsAlpha) {
            imagepng($dst, null, 6);
            $outputMime = 'image/png';
        } else {
            imagejpeg($dst, null, 85);
            $outputMime = 'image/jpeg';
        }
        $binary = (string) ob_get_clean();
        imagedestroy($dst);

        return [$binary, $outputMime];
    }
}
