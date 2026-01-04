<?php
/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocaimage
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;


/** @var array $displayData */
$data = $displayData;


$wrapperId = 'phocaimage-wrapper-' . $data['id'];
?>

<div id="<?php echo $wrapperId; ?>" class="phocaimage-wrapper"
     data-upload-url="<?php echo $data['uploadUrl']; ?>"
     data-delete-url="<?php echo $data['deleteUrl']; ?>"
     data-article-id="<?php echo $data['articleId']; ?>"
     data-field-id="<?php echo $data['fieldId']; ?>"
     data-upload-path="<?php echo $data['uploadPath']; ?>"
     data-csrf-token="<?php echo $data['csrfToken']; ?>"
     data-enable-caption="<?php echo (int)$data['enableCaption']; ?>"
     data-enable-delete-all="<?php echo (int)$data['enableDeleteAll']; ?>">

    <!-- Hidden Input for Data Storage -->
    <input type="hidden" name="<?php echo $data['name']; ?>" id="<?php echo $data['id']; ?>" class="phocaimage-data"
           value="<?php echo $data['value']; ?>">

    <!-- Drop Zone -->
    <div class="phocaimage-dropzone">
        <div class="phocaimage-dropzone-content">
            <span class="icon-upload" aria-hidden="true"></span>
            <p><?php echo Text::_('PLG_FIELDS_PHOCAIMAGE_DRAG_DROP_DESC'); ?></p>
            <button type="button" class="btn btn-primary phocaimage-select-btn">
                <?php echo Text::_('PLG_FIELDS_PHOCAIMAGE_SELECT_FILES'); ?>
            </button>
            <?php if ($data['enableDeleteAll']): ?>
                <button type="button" class="btn btn-danger phocaimage-delete-all-btn">
                    <span class="icon-trash" aria-hidden="true"></span>
                    <?php echo Text::_('PLG_FIELDS_PHOCAIMAGE_DELETE_ALL'); ?>
                </button>
            <?php endif; ?>
            <input type="file" multiple accept="image/*" class="phocaimage-file-input" style="display:none;">
        </div>
        <div class="phocaimage-progress-bar" style="width: 0%; display: none;"></div>
    </div>

    <!-- Gallery Grid -->
    <div class="phocaimage-gallery" id="phocaimage-gallery-<?php echo $data['id']; ?>">
        <?php foreach ($data['images'] as $image):
            $filename    = isset($image['filename']) ? $image['filename'] : $image; // Handle legacy string array if any
            $thumbUrl    = $data['uploadPath'] . '/phoca_thumb_m_' . $filename;
        ?>
            <div class="phocaimage-item" data-filename="<?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="phocaimage-thumb">
                    <img src="<?php echo $thumbUrl; ?>" alt="<?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="phocaimage-info <?php if ($data['enableCaption']) { echo 'phocaimage-caption-box';} ?>">
                    <?php if ($data['enableCaption']): ?>
                        <div class="phocaimage-caption-container">
                            <input type="text"
                                   class="form-control form-control-sm phocaimage-caption-input"
                                   placeholder="<?php echo Text::_('PLG_FIELDS_PHOCAIMAGE_CAPTION'); ?>"
                                   title="<?php echo Text::_('PLG_FIELDS_PHOCAIMAGE_CAPTION_DESC'); ?>"
                                   value="<?php echo htmlspecialchars($image['caption'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="phocaimage-filename"><?php echo htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="phocaimage-actions">
                    <button type="button" class="btn btn-danger btn-sm phocaimage-delete-btn"
                            title="<?php echo Text::_('JACTION_DELETE'); ?>">
                        <span class="icon-trash" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Messages -->
    <div class="alert alert-danger phocaimage-error" style="display: none;"></div>
</div>
