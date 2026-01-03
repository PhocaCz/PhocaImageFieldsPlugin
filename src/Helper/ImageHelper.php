<?php

/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocaimage
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Phoca\Plugin\Fields\Phocaimage\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Image processing helper using native PHP GD library.
 *
 * @since  1.0.0
 */
final class ImageHelper
{
    /**
     * Supported MIME types and their GD creation functions.
     *
     * @var    array<string, string>
     * @since  1.0.0
     */
    private const MIME_HANDLERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
        'image/avif' => 'imagecreatefromavif',
    ];

    /**
     * Create GD image resource from file.
     *
     * @param   string  $path  The path to the image file.
     *
     * @return  \GdImage|false
     *
     * @since   1.0.0
     */
    public static function createFromFile(string $path): \GdImage|false
    {
        if (!file_exists($path)) {
            return false;
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (!isset(self::MIME_HANDLERS[$mimeType])) {
            return false;
        }

        $handler = self::MIME_HANDLERS[$mimeType];

        if (!function_exists($handler)) {
            return false;
        }

        return $handler($path);
    }

    /**
     * Generate medium and large thumbnails from source image.
     *
     * @param   string              $sourcePath   Path to the source image.
     * @param   string              $destDir      Destination directory for thumbnails.
     * @param   array<string, int>  $mediumSize   Medium thumbnail dimensions ['width', 'height'].
     * @param   array<string, int>  $largeSize    Large thumbnail dimensions ['width', 'height'].
     * @param   bool                $cropToFit    Whether to crop to exact dimensions.
     * @param   int                 $quality      Output quality (0-100).
     *
     * @return  array<string, string>  Paths to generated thumbnails.
     *
     * @since   1.0.0
     */
    public static function generateThumbnails(
        string $sourcePath,
        string $destDir,
        array $mediumSize,
        array $largeSize,
        bool $cropToFit,
        int $quality
    ): array {
        $source = self::createFromFile($sourcePath);

        if ($source === false) {
            return [];
        }

        $filename  = basename($sourcePath);
        $finfo     = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType  = $finfo->file($sourcePath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $thumbnails = [];

        // Generate medium thumbnail
        $mediumImage = self::resizeAndCrop(
            $source,
            $mediumSize['width'],
            $mediumSize['height'],
            $cropToFit
        );

        $mediumPath = $destDir . '/phoca_thumb_m_' . $filename;
        if (self::saveImage($mediumImage, $mediumPath, $mimeType, $quality)) {
            $thumbnails['medium'] = 'phoca_thumb_m_' . $filename;
        }
        imagedestroy($mediumImage);

        // Generate large thumbnail
        $largeImage = self::resizeAndCrop(
            $source,
            $largeSize['width'],
            $largeSize['height'],
            $cropToFit
        );

        $largePath = $destDir . '/phoca_thumb_l_' . $filename;
        if (self::saveImage($largeImage, $largePath, $mimeType, $quality)) {
            $thumbnails['large'] = 'phoca_thumb_l_' . $filename;
        }
        imagedestroy($largeImage);

        imagedestroy($source);

        return $thumbnails;
    }

    /**
     * Delete an image and its thumbnails.
     *
     * @param   string  $imagePath  Path to the original image.
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    public static function deleteImageWithThumbnails(string $imagePath): bool
    {
        $dir      = dirname($imagePath);
        $filename = basename($imagePath);
        $deleted  = true;

        // Delete original
        if (file_exists($imagePath)) {
            $deleted = unlink($imagePath) && $deleted;
        }

        // Delete medium thumbnail
        $mediumPath = $dir . '/phoca_thumb_m_' . $filename;
        if (file_exists($mediumPath)) {
            $deleted = unlink($mediumPath) && $deleted;
        }

        // Delete large thumbnail
        $largePath = $dir . '/phoca_thumb_l_' . $filename;
        if (file_exists($largePath)) {
            $deleted = unlink($largePath) && $deleted;
        }

        // Even if some unlink calls failed, or if the file was already missing,
        // we want the caller to consider this a "success" so the database state can be updated.
        return true;
    }

    /**
     * Get image dimensions.
     *
     * @param   string  $imagePath  Path to the image.
     *
     * @return  array<string, int>|null
     *
     * @since   1.0.0
     */
    public static function getImageDimensions(string $imagePath): ?array
    {
        if (!file_exists($imagePath)) {
            return null;
        }

        $size = getimagesize($imagePath);

        if ($size === false) {
            return null;
        }

        return [
            'width'  => $size[0],
            'height' => $size[1],
        ];
    }

    /**
     * Resize and optionally crop an image.
     *
     * @param   \GdImage  $image         The source GD image.
     * @param   int       $targetWidth   Target width in pixels.
     * @param   int       $targetHeight  Target height in pixels.
     * @param   bool      $cropToFit     Whether to crop to exact dimensions.
     *
     * @return  \GdImage
     *
     * @since   1.0.0
     */
    private static function resizeAndCrop(
        \GdImage $image,
        int $targetWidth,
        int $targetHeight,
        bool $cropToFit
    ): \GdImage {
        $srcWidth  = imagesx($image);
        $srcHeight = imagesy($image);

        if ($cropToFit) {
            return self::cropToFit($image, $srcWidth, $srcHeight, $targetWidth, $targetHeight);
        }

        return self::resizeToFit($image, $srcWidth, $srcHeight, $targetWidth, $targetHeight);
    }

    /**
     * Resize image to fit within dimensions while maintaining aspect ratio.
     *
     * @param   \GdImage  $image         The source GD image.
     * @param   int       $srcWidth      Source width.
     * @param   int       $srcHeight     Source height.
     * @param   int       $targetWidth   Target width.
     * @param   int       $targetHeight  Target height.
     *
     * @return  \GdImage
     *
     * @since   1.0.0
     */
    private static function resizeToFit(
        \GdImage $image,
        int $srcWidth,
        int $srcHeight,
        int $targetWidth,
        int $targetHeight
    ): \GdImage {
        $srcRatio    = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($srcRatio > $targetRatio) {
            // Source is wider - fit to width
            $newWidth  = $targetWidth;
            $newHeight = (int) round($targetWidth / $srcRatio);
        } else {
            // Source is taller - fit to height
            $newHeight = $targetHeight;
            $newWidth  = (int) round($targetHeight * $srcRatio);
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        self::preserveTransparency($resized, $image);

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $srcWidth,
            $srcHeight
        );

        return $resized;
    }

    /**
     * Crop image to exact dimensions using center cropping.
     *
     * @param   \GdImage  $image         The source GD image.
     * @param   int       $srcWidth      Source width.
     * @param   int       $srcHeight     Source height.
     * @param   int       $targetWidth   Target width.
     * @param   int       $targetHeight  Target height.
     *
     * @return  \GdImage
     *
     * @since   1.0.0
     */
    private static function cropToFit(
        \GdImage $image,
        int $srcWidth,
        int $srcHeight,
        int $targetWidth,
        int $targetHeight
    ): \GdImage {
        $srcRatio    = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($srcRatio > $targetRatio) {
            // Source is wider - crop width
            $cropHeight = $srcHeight;
            $cropWidth  = (int) round($srcHeight * $targetRatio);
            $cropX      = (int) round(($srcWidth - $cropWidth) / 2);
            $cropY      = 0;
        } else {
            // Source is taller - crop height
            $cropWidth  = $srcWidth;
            $cropHeight = (int) round($srcWidth / $targetRatio);
            $cropX      = 0;
            $cropY      = (int) round(($srcHeight - $cropHeight) / 2);
        }

        $cropped = imagecreatetruecolor($targetWidth, $targetHeight);
        self::preserveTransparency($cropped, $image);

        imagecopyresampled(
            $cropped,
            $image,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        return $cropped;
    }

    /**
     * Preserve transparency for PNG and GIF images.
     *
     * @param   \GdImage  $destination  The destination image.
     * @param   \GdImage  $source       The source image.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private static function preserveTransparency(\GdImage $destination, \GdImage $source): void
    {
        // Enable alpha blending
        imagealphablending($destination, false);
        imagesavealpha($destination, true);

        // Allocate a transparent color
        $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
        imagefilledrectangle($destination, 0, 0, imagesx($destination), imagesy($destination), $transparent);

        imagealphablending($destination, true);
    }

    /**
     * Save GD image to file based on MIME type.
     *
     * @param   \GdImage  $image     The GD image to save.
     * @param   string    $path      The output path.
     * @param   string    $mimeType  The MIME type.
     * @param   int       $quality   Quality setting (0-100).
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    private static function saveImage(\GdImage $image, string $path, string $mimeType, int $quality): bool
    {
        return match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $path, $quality),
            'image/png'  => imagepng($image, $path, (int) round((100 - $quality) / 11.11)),
            'image/gif'  => imagegif($image, $path),
            'image/webp' => imagewebp($image, $path, $quality),
            'image/avif' => function_exists('imageavif') ? imageavif($image, $path, $quality) : false,
            default => false,
        };
    }
}
