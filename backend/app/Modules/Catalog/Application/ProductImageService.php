<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImage;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Shared\Domain\Enums\FilePurpose;
use App\Modules\Shared\Domain\Exceptions\BusinessRuleViolation;
use App\Modules\Shared\Infrastructure\Files\PrivateFileStorage;
use GdImage;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;

/**
 * Photos produits (L2-05) : l'image est décodée puis réencodée en WebP, ce qui supprime les métadonnées
 * (EXIF, position GPS) et neutralise un fichier polyglotte ; une miniature de 320 px sert aux listes.
 */
final class ProductImageService
{
    private const MAX_SIDE = 1600;

    private const THUMB_SIDE = 320;

    private const MAX_IMAGES = 10;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PrivateFileStorage $files,
    ) {}

    public function upload(string $productId, UploadedFile $upload, ?string $variantId, ?string $actorId): ProductImage
    {
        $product = Product::query()->findOrFail($productId);

        if ($variantId !== null && ProductVariant::query()->where('product_id', $product->id)->whereKey($variantId)->doesntExist()) {
            throw BusinessRuleViolation::invalidField('variant_id', 'Cette variante n\'appartient pas au produit.');
        }

        // Contrôle du type réel et de la taille avant décodage.
        $original = $this->files->storeUpload($upload, FilePurpose::ProductImage);
        $raw = $this->files->contents($original);
        $this->files->delete($original);

        $image = @imagecreatefromstring($raw);

        if (! $image instanceof GdImage) {
            throw BusinessRuleViolation::invalidField('image', 'Cette image ne peut pas être lue.', 'file_rejected');
        }

        $name = pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME).'.webp';
        $main = $this->files->storeContents($this->encode($image, self::MAX_SIDE), $name, FilePurpose::ProductImage);
        $thumb = $this->files->storeContents($this->encode($image, self::THUMB_SIDE), 'miniature-'.$name, FilePurpose::ProductImage);
        imagedestroy($image);

        return $this->db->transaction(function () use ($product, $variantId, $main, $thumb, $actorId) {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $count = ProductImage::query()->where('product_id', $product->id)->count();

            if ($count >= self::MAX_IMAGES) {
                throw BusinessRuleViolation::invalidField('image', 'Un produit ne peut pas avoir plus de '.self::MAX_IMAGES.' photos.');
            }

            $row = new ProductImage;
            $row->fill([
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'file_id' => $main->id,
                'thumb_file_id' => $thumb->id,
                'position' => $count,
            ])->forceFill(['created_by' => $actorId])->save();

            return $row;
        });
    }

    public function delete(string $imageId): void
    {
        $image = ProductImage::query()->findOrFail($imageId);
        $fileIds = array_filter([$image->file_id, $image->thumb_file_id]);
        $image->delete();

        foreach ($fileIds as $fileId) {
            $this->files->delete($this->files->findForCurrentCompany($fileId));
        }
    }

    private function encode(GdImage $source, int $maxSide): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagewebp($target, null, 82);
        imagedestroy($target);

        return (string) ob_get_clean();
    }
}
