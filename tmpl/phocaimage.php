<?php
/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocaimage
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Phoca\Plugin\Fields\Phocaimage\Helper\ImageHelper;

// Check if value exists
if (empty($field->value)) {
    return;
}

$images = json_decode($field->value, true);
if (!is_array($images) || empty($images)) {
    return;
}

// Order images by 'order' key
usort($images, function ($a, $b) {
    return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
});

// Load assets
/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('plg_fields_phocaimage');
$wa->usePreset('plg_fields_phocaimage.frontend');

// Determine path
// Logic needs to match Extension/Phocaimage.php getUploadPath
// Since we are in the plugin context, we can use $this helper
$articleId = $item->id;
$articleTitle = $item->title;
$basePath  = $this->getUploadPath((int) $articleId, (int) $field->id) . '/';

$galleryId = 'phocaimage-gallery-' . $articleId;

$altValueType = $this->params->get('alt_value', 3);
?>

<div id="<?php echo $galleryId; ?>" class="phocaimage-gallery">
    <?php foreach ($images as $image):
        $filename = $image['filename'];
        $original = $basePath . $filename;
        $thumbM   = $basePath . 'phoca_thumb_m_' . $filename;
        $thumbL   = $basePath . 'phoca_thumb_l_' . $filename; // Used for lightbox if we want

        // For PhotoSwipe we serve large thumbnail
        $lightboxImage = $thumbL;

        // Get dimensions for PhotoSwipe (required)
        // Possible feature: store width/height in JSON.
        // "width": imageSize[0], "height": ... passed in response.
        // backend-uploader.js `updateDataInput` only saves {filename, order}.

        $dimensions = ['width' => 1200, 'height' => 800]; // Default fallback
        $absPath = JPATH_ROOT . '/' . $lightboxImage;
        if (file_exists($absPath)) {
            $dims = getimagesize($absPath);
            if ($dims) {
                $dimensions = ['width' => $dims[0], 'height' => $dims[1]];
            }
        }

        if ($altValueType == 1) {
            $altValue = '';
        } else if ($altValueType == 2) {
            $altValue = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');
        } else {
            $altValue = htmlspecialchars($articleTitle, ENT_QUOTES, 'UTF-8');
        }

    ?>
        <a href="<?php echo Uri::root() . $lightboxImage; ?>"
           data-pswp-width="<?php echo $dimensions['width']; ?>"
           data-pswp-height="<?php echo $dimensions['height']; ?>"
           target="_blank">
            <img src="<?php echo Uri::root() . $thumbM; ?>" alt="<?php echo $altValue ?>">
        </a>
    <?php endforeach; ?>
</div>
