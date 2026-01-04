<?php

/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocaimage
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Phoca\Plugin\Fields\Phocaimage\Extension;

use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\Filesystem\Folder;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Plugin\FieldsPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Phoca\Plugin\Fields\Phocaimage\Helper\ImageHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * PhocaImage Fields Plugin
 *
 * Provides a custom field type for managing image galleries with drag-and-drop upload,
 * sorting capabilities, and PhotoSwipe lightbox integration.
 *
 * @since  1.0.0
 */
final class Phocaimage extends FieldsPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Get the base path for image storage.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getBasePath(): string
    {
        $folder = trim($this->params->get('folder', 'phocaimage'), '/ ');
        $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $folder);
        $folder = Folder::makeSafe($folder);

        $subfolder = trim($this->params->get('subfolder', ''), '/ ');
        $subfolder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subfolder);
        $subfolder = Folder::makeSafe($subfolder);

        if ($subfolder !== '') {
            return 'images/'.$folder.'/' . $subfolder;
        }

        return 'images/'.$folder;
    }

    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    bool
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * The type of the field our plugin handles.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'phocaimage';

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array<string, string>
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onContentAfterSave'      => 'onContentAfterSave',
            'onContentAfterDelete'    => 'onContentAfterDelete',
            'onAjaxPhocaimage'        => 'onAjaxPhocaimage',
            'onContentAfterTitle'     => 'onContentAfterTitle',
            'onContentBeforeDisplay'  => 'onContentBeforeDisplay',
            'onContentAfterDisplay'   => 'onContentAfterDisplay',
        ]);
    }

    /**
     * Transforms the field into a DOM XML element and appends it as a child on the given parent.
     *
     * @param   \stdClass    $field   The field.
     * @param   \DOMElement  $parent  The field node parent.
     * @param   Form         $form    The form.
     *
     * @return  \DOMElement|null
     *
     * @since   1.0.0
     */
    public function onCustomFieldsPrepareDom($field, \DOMElement $parent, Form $form): ?\DOMElement
    {
        $fieldNode = parent::onCustomFieldsPrepareDom($field, $parent, $form);

        if (!$fieldNode) {
            return null;
        }

        // Override the type to use our custom field
        $fieldNode->setAttribute('type', 'phocaimage');

        // Add the field namespace so Joomla can find our custom field type
        $fieldNode->setAttribute('addfieldprefix', 'Phoca\\Plugin\\Fields\\Phocaimage\\Field');

        // Load assets
        /** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_fields_phocaimage');
        $wa->usePreset('plg_fields_phocaimage.backend');

        Text::script('PLG_FIELDS_PHOCAIMAGE_DELETE');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_UPLOAD_FAILED');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_UPLOAD_FAILED_WITH_STATUS');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_CHECK_CONSOLE_FOR_DETAILS');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_INVALID_SERVER_RESPONSE');
        Text::script('PLG_FIELDS_PHOCAIMAGE_NETWORK_ERROR_OCCURED');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_WHILE_DELETING');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_FAILED_DELETE_IMAGE');
        Text::script('PLG_FIELDS_PHOCAIMAGE_ARE_YOU_SURE_DELETE_IMAGE');
        Text::script('PLG_FIELDS_PHOCAIMAGE_CAPTION');
        Text::script('PLG_FIELDS_PHOCAIMAGE_CAPTION_DESC');
        Text::script('PLG_FIELDS_PHOCAIMAGE_CONFIRM_DELETE_ALL');


        return $fieldNode;
    }

    /**
     * Override prepare field to support independent rendering.
     *
     * @param   string     $context  The context.
     * @param   \stdclass  $item     The item.
     * @param   \stdclass  $field    The field.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public function onCustomFieldsPrepareField($context, $item, $field)
    {
        // Check if the field should be processed by us
        if ($field->type !== $this->type) {
            return;
        }

        $display = $this->params->get('display', '2');

        // If display is set to any of the automatic positions (1, 2, 3), we return empty string here
        // so that Joomla's FieldsHelper doesn't wrap it in its own UL/LI list.
        if (in_array((string) $display, ['1', '2', '3'])) {
            return '';
        }

        // If display is manual or hidden (0), we let it through but render it our way
        return $this->renderGallery($field, $item);
    }

    /**
     * Handle AfterTitle display.
     */
    public function onContentAfterTitle(EventInterface $event)
    {
        $context = $event->getArgument('context');
        $item    = $event->getArgument('item');
        $result  = $this->renderPositionedGallery($context, $item, '1');

        if ($result !== '') {
            $event->addResult($result);
        }
    }

    /**
     * Handle BeforeDisplay display.
     */
    public function onContentBeforeDisplay(EventInterface $event)
    {
        $context = $event->getArgument('context');
        $item    = $event->getArgument('item');

        // Position 2: Traditional Before Content (Joomla Result)
        $result  = $this->renderPositionedGallery($context, $item, '2');
        if ($result !== '') {
            $event->addResult($result);
        }

        // Position 3: After Content, but before pagination (Append to text)
        $result3 = $this->renderPositionedGallery($context, $item, '3');
        if ($result3 !== '') {
            $item->text .= $result3;
        }
    }

    /**
     * Handle AfterDisplay display.
     */
    public function onContentAfterDisplay(EventInterface $event)
    {
        // Currently handled in beforeDisplay to control exact position relative to text body
    }

    /**
     * Helper to render gallery based on position
     */
    private function renderPositionedGallery($context, $item, $position)
    {
        // Don't process in admin
        if ($this->getApplication()->isClient('administrator')) {
            return '';
        }

        $fields = FieldsHelper::getFields($context, $item, false);
        $output = '';

        foreach ($fields as $field) {
            if ($field->type !== 'phocaimage') {
                continue;
            }

            $display = $this->params->get('display', '2');

            if ((string) $display === (string) $position) {
                $output .= $this->renderGallery($field, $item);
            }
        }

        return $output;
    }

    /**
     * Core gallery rendering logic.
     */
    private function renderGallery($field, $item)
    {
        // Frontend vs Backend layout
        if ($this->getApplication()->isClient('administrator')) {
            $path = JPATH_PLUGINS . '/fields/phocaimage/layouts/phocaimage.php';
        } else {
            $path = JPATH_PLUGINS . '/fields/phocaimage/tmpl/phocaimage.php';
        }

        if (!file_exists($path)) {
            return '';
        }

        // Prepare data for layout
        // The layout expects $field and $item
        ob_start();
        include $path;
        return ob_get_clean();
    }

    /**
     * Handle article save event - migrate temp folders for new articles.
     *
     * @param   Model\AfterSaveEvent  $event  The event object.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentAfterSave(Model\AfterSaveEvent $event): void
    {
        $context = $event->getContext();
        $item    = $event->getItem();
        $isNew   = $event->getIsNew();

        // Only process articles
        if ($context !== 'com_content.article' && $context !== 'com_content.form') {
            return;
        }

        if (empty($item->id)) {
            return;
        }

        if ($isNew) {
            $this->migrateTempFolder((int) $item->id);
        }

        $title = $item->title ?? '';

        // If title is not in item, we might need to get it from data or DB
        if (empty($title)) {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('title'))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = ' . (int) $item->id);
            $title = (string) $db->setQuery($query)->loadResult();
        }

        $this->syncArticleImage((int) $item->id, $title);
    }

    /**
     * Handle article delete event - cleanup image folders.
     *
     * @param   Model\AfterDeleteEvent  $event  The event object.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onContentAfterDelete(Model\AfterDeleteEvent $event): void
    {
        $context = $event->getContext();
        $item    = $event->getItem();

        // Only process articles
        if ($context !== 'com_content.article') {
            return;
        }

        if (empty($item->id)) {
            return;
        }

        $this->cleanupImageFolder((int) $item->id);
    }

    /**
     * AJAX handler for upload, delete, and other operations.
     *
     * @return  mixed
     *
     * @since   1.0.0
     */
    public function onAjaxPhocaimage(EventInterface $event): void
    {
        try {
            // Check CSRF token
            if (!Session::checkToken('get') && !Session::checkToken('post')) {
                throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }

            $app    = $this->getApplication();
            $input  = $app->getInput();
            $action = $input->getCmd('action', '');

            $result = match ($action) {
                'upload' => $this->handleUpload(),
                'delete' => $this->handleDelete(),
                'getpath' => $this->handleGetPath(),
                default => ['success' => false, 'message' => 'Invalid action: ' . $action],
            };

            // In Joomla 6, Subscriber events return results via arguments
            $event->setArgument('result', $result);
        } catch (\Throwable $e) {
            $event->setArgument('result', [
                'success' => false,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine()
            ]);
        }
    }

    /**
     * Handle file upload via AJAX.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function handleUpload(): array
    {
        $input     = $this->getApplication()->getInput();
        $articleId = $input->getInt('article_id', 0);
        $fieldId   = $input->getInt('field_id', 0);
        $files     = $input->files->get('phocaimage_files', [], 'array');

        if (empty($files)) {
            return ['success' => false, 'message' => Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_NO_FILE')];
        }

        // Determine upload path
        $uploadPath = $this->getUploadPath($articleId, $fieldId);
        $fullPath   = JPATH_ROOT . '/' . $uploadPath;

        // Create directory if it doesn't exist
        if (!is_dir($fullPath)) {
            if (!Folder::create($fullPath)) {
                return ['success' => false, 'message' => Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_CREATE_FOLDER')];
            }
        }

        $uploaded = [];
        $message = [];

        foreach ($files as $file) {
            $result = $this->processUploadedFile($file, $fullPath);
            if ($result['success']) {
                $uploaded[] = $result;
            } else {
                if (isset($result['message']) && $result['message'] != '') {
                    $message[] = $result['message'];
                }
            }
        }

        $messageOutput = '';
        if (!empty($message)) {
            $messageOutput = implode(", ", $message);
        }

        return [
            'success'  => !empty($uploaded),
            'files'    => $uploaded,
            'path'     => $uploadPath,
            'message'  => $messageOutput
        ];
    }

    /**
     * Process a single uploaded file.
     *
     * @param   array<string, mixed>  $file      The uploaded file data.
     * @param   string                $destPath  The destination path.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function processUploadedFile(array $file, string $destPath): array
    {
        // Validate file size
        $maxSizeBytes = (int) $this->params->get('max_upload_size', 5242880);

        if (!isset($file['name'])) {
            $file['name'] = '';
        }

        if ($file['size'] > $maxSizeBytes) {
            return [
                'success' => false,
                'message' => $file['name']. ": " . Text::sprintf('PLG_FIELDS_PHOCAIMAGE_ERROR_FILE_TOO_LARGE', $maxSizeBytes)
            ];
        }

        // Validate file type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType     = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, $allowedMimes, true)) {
            return ['success' => false, 'message' => $file['name']. ": " .  Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_INVALID_TYPE')];
        }

        // Sanitize filename
        $filename = $this->sanitizeFilename($file['name']);
        $destFile = $destPath . '/' . $filename;

        // Handle duplicate filenames
        $counter = 1;
        $pathInfo = pathinfo($filename);
        while (file_exists($destFile)) {
            $filename = $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $destFile = $destPath . '/' . $filename;
            $counter++;
        }

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destFile)) {
            return ['success' => false, 'message' => $file['name']. ": " . Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_MOVE_FILE')];
        }

        // Generate thumbnails
        $mediumSize = [
            'width'  => (int) $this->params->get('medium_width', 300),
            'height' => (int) $this->params->get('medium_height', 200),
        ];
        $largeSize = [
            'width'  => (int) $this->params->get('large_width', 1200),
            'height' => (int) $this->params->get('large_height', 800),
        ];
        $cropToFit = (bool) $this->params->get('crop_to_fit', false);

        // Get mime type for quality
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $currMime = $finfo->file($destFile);
        $quality  = $this->getQualityForMimeType($currMime);

        $thumbnails = ImageHelper::generateThumbnails(
            $destFile,
            $destPath,
            $mediumSize,
            $largeSize,
            $cropToFit,
            $quality
        );

        // Get image dimensions for PhotoSwipe safely
        $imageSize = @getimagesize($destFile);
        $width     = 0;
        $height    = 0;

        if ($imageSize) {
            $width  = (int) $imageSize[0];
            $height = (int) $imageSize[1];
        }

        return [
            'success'    => true,
            'filename'   => $filename,
            'width'      => $width,
            'height'     => $height,
            'thumbnails' => $thumbnails,
        ];
    }

    /**
     * Handle file deletion via AJAX.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function handleDelete(): array
    {
        $input     = $this->getApplication()->getInput();
        $filename  = $input->getString('filename', '');
        $articleId = $input->getInt('article_id', 0);
        $fieldId   = $input->getInt('field_id', 0);

        if (empty($filename)) {
            return ['success' => false, 'message' => Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_NO_FILENAME')];
        }

        // Sanitize filename to prevent path traversal
        $filename   = basename($filename);
        $uploadPath = $this->getUploadPath($articleId, $fieldId);
        $fullPath   = JPATH_ROOT . '/' . $uploadPath . '/' . $filename;

        // Delete original and thumbnails
        $deleted = ImageHelper::deleteImageWithThumbnails($fullPath);

        return [
            'success' => $deleted,
            'message' => $deleted
                ? Text::_('PLG_FIELDS_PHOCAIMAGE_FILE_DELETED')
                : Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_DELETE_FILE'),
        ];
    }

    /**
     * Handle get path request via AJAX.
     *
     * @return  array<string, mixed>
     *
     * @since   1.0.0
     */
    private function handleGetPath(): array
    {
        $input     = $this->getApplication()->getInput();
        $articleId = $input->getInt('article_id', 0);
        $fieldId   = $input->getInt('field_id', 0);

        return [
            'success' => true,
            'path'    => $this->getUploadPath($articleId, $fieldId),
        ];
    }

    /**
     * Get the upload path for an article.
     *
     * @param   int  $articleId  The article ID.
     * @param   int  $fieldId    The field ID.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public function getUploadPath(int $articleId, int $fieldId): string
    {
        $folderStructure = $this->params->get('folder_structure', 'article_id');

        if ($articleId === 0) {
            // New article - use temp folder with session hash
            $session  = Factory::getApplication()->getSession();
            $tempHash = substr(md5($session->getId() . $fieldId), 0, 12);
            return $this->getBasePath() . '/temp_' . $tempHash;
        }

        if ($folderStructure === 'year_month') {
            $created   = $this->getArticleDate($articleId);
            $yearMonth = date('Y_m', strtotime($created));
            return $this->getBasePath() . '/' . $yearMonth . '/' . $articleId;
        }

        return $this->getBasePath() . '/' . $articleId;
    }

    /**
     * Get the permanent path for an article.
     *
     * @param   int  $articleId  The article ID.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getPermanentPath(int $articleId): string
    {
        $folderStructure = $this->params->get('folder_structure', 'article_id');

        if ($folderStructure === 'year_month') {
            $created   = $this->getArticleDate($articleId);
            $yearMonth = date('Y_m', strtotime($created));
            return $this->getBasePath() . '/' . $yearMonth . '/' . $articleId;
        }

        return $this->getBasePath() . '/' . $articleId;
    }

    /**
     * Migrate temporary folder to permanent location for newly saved article.
     *
     * @param   int  $articleId  The new article ID.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function migrateTempFolder(int $articleId): void
    {
        $session    = Factory::getApplication()->getSession();
        $sessionId  = $session->getId();
        $db         = $this->getDatabase();
        $basePath   = JPATH_ROOT . '/' . $this->getBasePath();

        // Find all phocaimage fields
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id']))
            ->from($db->quoteName('#__fields'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('phocaimage'))
            ->where($db->quoteName('state') . ' = 1');

        $fields = $db->setQuery($query)->loadColumn();

        foreach ($fields as $fieldId) {
            $tempHash    = substr(md5($sessionId . $fieldId), 0, 12);
            $tempPath    = $basePath . '/temp_' . $tempHash;
            $permPathRelative = $this->getPermanentPath($articleId);
            $permanentPath = JPATH_ROOT . '/' . $permPathRelative;

            // Check if temp folder exists
            if (!is_dir($tempPath)) {
                continue;
            }

            // Create permanent directory structure
            $parentDir = dirname($permanentPath);
            if (!is_dir($parentDir)) {
                Folder::create($parentDir);
            }

            // Move temp folder to permanent location
            if (!rename($tempPath, $permanentPath)) {
                // Fallback: copy and delete
                if (Folder::copy($tempPath, $permanentPath)) {
                    Folder::delete($tempPath);
                }
            }

            // Update field value in database
            $this->updateFieldPaths($fieldId, $articleId, $tempHash);
        }
    }

    /**
     * Update field value paths after folder migration.
     *
     * @param   int     $fieldId    The field ID.
     * @param   int     $articleId  The article ID.
     * @param   string  $tempHash   The temporary folder hash.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function updateFieldPaths(int $fieldId, int $articleId, string $tempHash): void
    {
        $db = $this->getDatabase();

        // Get current field value
        $query = $db->getQuery(true)
            ->select($db->quoteName('value'))
            ->from($db->quoteName('#__fields_values'))
            ->where($db->quoteName('field_id') . ' = ' . $fieldId)
            ->where($db->quoteName('item_id') . ' = ' . $db->quote($articleId));

        $value = $db->setQuery($query)->loadResult();

        if (empty($value)) {
            return;
        }

        // Replace temp path with permanent path
        $tempPath      = 'temp_' . $tempHash;
        $permanentPath = $this->getPermanentPath($articleId);
        $permanentPath = str_replace($this->getBasePath() . '/', '', $permanentPath);

        $newValue = str_replace($tempPath, $permanentPath, $value);

        // Update the value
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__fields_values'))
            ->set($db->quoteName('value') . ' = ' . $db->quote($newValue))
            ->where($db->quoteName('field_id') . ' = ' . $fieldId)
            ->where($db->quoteName('item_id') . ' = ' . $db->quote($articleId));

        $db->setQuery($query)->execute();
    }

    /**
     * Cleanup image folder when article is deleted.
     *
     * @param   int  $articleId  The article ID.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function cleanupImageFolder(int $articleId): void
    {
        $folderStructure = $this->params->get('folder_structure', 'article_id');
        $basePath        = JPATH_ROOT . '/' . $this->getBasePath();

        if ($folderStructure === 'year_month') {
            // Search for the article folder in all year_month directories
            $yearMonthDirs = Folder::folders($basePath);
            foreach ($yearMonthDirs as $dir) {
                if (preg_match('/^\d{4}_\d{2}$/', $dir)) {
                    $articlePath = $basePath . '/' . $dir . '/' . $articleId;
                    if (is_dir($articlePath)) {
                        Folder::delete($articlePath);
                    }
                }
            }
        } else {
            $articlePath = $basePath . '/' . $articleId;
            if (is_dir($articlePath)) {
                Folder::delete($articlePath);
            }
        }
    }

    /**
     * Sanitize filename for safe storage.
     *
     * @param   string  $filename  The original filename.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function sanitizeFilename(string $filename): string
    {
        // Get extension
        $pathInfo  = pathinfo($filename);
        $extension = strtolower($pathInfo['extension'] ?? '');
        $name      = $pathInfo['filename'];

        // Remove any non-alphanumeric characters except dash and underscore
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);

        // Trim underscores
        $name = trim($name, '_');

        // Ensure we have a filename
        if (empty($name)) {
            $name = 'image_' . time();
        }

        return $name . '.' . $extension;
    }

    /**
     * Get quality setting based on MIME type.
     *
     * @param   string  $mimeType  The MIME type.
     *
     * @return  int
     *
     * @since   1.0.0
     */
    private function getQualityForMimeType(string $mimeType): int
    {
        return match ($mimeType) {
            'image/jpeg' => (int) $this->params->get('jpeg_quality', 85),
            'image/webp' => (int) $this->params->get('webp_quality', 80),
            'image/avif' => (int) $this->params->get('avif_quality', 60),
            default => 85,
        };
    }

    /**
     * Get the creation date for an article.
     *
     * @param   int  $articleId  The article ID.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getArticleDate(int $articleId): string
    {
        static $dates = [];

        if (isset($dates[$articleId])) {
            return $dates[$articleId];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('created'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = ' . (int) $articleId);

        $date = $db->setQuery($query)->loadResult();

        // Fallback to current date if not found (should not happen for existing)
        $dates[$articleId] = $date ?: date('Y-m-d H:i:s');

        return $dates[$articleId];
    }

    /**
     * Synchronize the first image from phocaimage field to article intro/full image.
     *
     * @param   int  $articleId  The article ID.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function syncArticleImage(int $articleId, string $title = ''): void
    {
        $syncType = (int) $this->params->get('sync_article_image', 0);
        if ($syncType === 0) {
            return;
        }

        $db = $this->getDatabase();

        // Get all phocaimage fields for this article
        $query = $db->getQuery(true)
            ->select($db->quoteName(['v.value']))
            ->from($db->quoteName('#__fields_values', 'v'))
            ->join('INNER', $db->quoteName('#__fields', 'f'), $db->quoteName('f.id') . ' = ' . $db->quoteName('v.field_id'))
            ->where($db->quoteName('f.type') . ' = ' . $db->quote('phocaimage'))
            ->where($db->quoteName('v.item_id') . ' = ' . $db->quote($articleId))
            ->order($db->quoteName('f.ordering') . ' ASC');

        $rows = $db->setQuery($query)->loadObjectList();

        if (empty($rows)) {
            return;
        }

        // We take the first phocaimage field that has images
        $foundImages = [];
        foreach ($rows as $row) {
            if (empty($row->value)) {
                continue;
            }
            $images = json_decode($row->value, true);
            if (is_array($images) && !empty($images)) {
                $foundImages = $images;
                break;
            }
        }

        if (empty($foundImages)) {
            return;
        }

        $firstImage = $foundImages[0];
        $filename   = $firstImage['filename'] ?? '';
        if (empty($filename)) {
            return;
        }

        // Construct path to large thumbnail
        $uploadPath = $this->getPermanentPath($articleId);
        $thumbPath  = $uploadPath . '/phoca_thumb_l_' . $filename;

        // Update article record
        $query = $db->getQuery(true)
            ->select($db->quoteName('images'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = ' . $db->quote($articleId));
        $imagesJson = $db->setQuery($query)->loadResult();

        $articleImages = json_decode($imagesJson ?: '{}', true);
        if (!is_array($articleImages)) {
            $articleImages = [];
        }

        $changed = false;

        // Intro Image sync - only if empty
        if (($syncType === 1 || $syncType === 3) && empty($articleImages['image_intro'])) {
            $articleImages['image_intro'] = $thumbPath;
            if (!empty($title)) {
                $articleImages['image_intro_alt'] = $title;
            }
            $changed = true;
        }

        // Full Image sync - only if empty
        if (($syncType === 2 || $syncType === 3) && empty($articleImages['image_fulltext'])) {
            $articleImages['image_fulltext'] = $thumbPath;
            if (!empty($title)) {
                $articleImages['image_fulltext_alt'] = $title;
            }
            $changed = true;
        }

        if ($changed) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__content'))
                ->set($db->quoteName('images') . ' = ' . $db->quote(json_encode($articleImages)))
                ->where($db->quoteName('id') . ' = ' . $db->quote($articleId));
            $db->setQuery($query)->execute();
        }
    }
}
