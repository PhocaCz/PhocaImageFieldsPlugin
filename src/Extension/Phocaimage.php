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
use Joomla\Registry\Registry;
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

   /* public function __construct($subject, array $config = [])
    {
        parent::__construct($subject, $config);

        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_fields_phocaimage', JPATH_ADMINISTRATOR, null, true);
    }*/

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
     * Write hardening files (.htaccess for Apache, web.config for IIS) into an
     * upload folder so that no file placed in it - regardless of extension - can
     * ever be executed as a script by the webserver. This is defense-in-depth on
     * top of the strict MIME-based extension allow-list enforced at upload time;
     * it also protects any legacy files that may already exist on disk from
     * earlier, less strict versions of this plugin.
     *
     * Writing .htaccess/web.config is controlled by the "harden_upload_folder"
     * plugin parameter (enabled by default), because some hosts/server
     * configurations reject or misinterpret directives in these files (e.g.
     * missing AllowOverride, IIS handler mapping conflicts, other software
     * already managing its own .htaccess in the same tree). The index.html
     * placeholder (directory listing protection) is unconditional and always
     * written, since it cannot break a server configuration.
     *
     * @param   string  $fullPath  Absolute filesystem path to the upload folder.
     *
     * @return  void
     *
     * @since   6.0.5
     */
    private function hardenUploadFolder(string $fullPath): void
    {
        $writeServerConfigFiles = (bool) $this->params->get('harden_upload_folder', 1);

        if ($writeServerConfigFiles) {
            $htaccessFile = $fullPath . '/.htaccess';
            if (!is_file($htaccessFile)) {
                $htaccess = <<<HTACCESS
# Prevent execution of any script in this folder, regardless of extension.
# This folder only ever contains user-uploaded image files.

# <IfModule mod_php.c>
#    php_flag engine off
# </IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(?:php[0-9]?|phtml|pht|phar|shtml|shtm|sht|cgi|pl|py|asp|aspx|jsp|jspx|htaccess|htpasswd)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

# Belt-and-braces: also strip any handler mapping for these extensions.
<IfModule mod_mime.c>
    RemoveHandler .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht
    RemoveType .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht
</IfModule>

# Options -ExecCGI -Indexes
AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .pht .phar .cgi .pl .py .asp .aspx .jsp .jspx .shtml .shtm .sht

HTACCESS;
                @file_put_contents($htaccessFile, $htaccess);
            }

            $webConfigFile = $fullPath . '/web.config';
            if (!is_file($webConfigFile)) {
                $webConfig = <<<WEBCONFIG
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <handlers>
            <clear />
            <add name="StaticFile" path="*" verb="*" modules="StaticFileModule" resourceType="Either" requireAccess="Read" />
        </handlers>
        <security>
            <requestFiltering>
                <fileExtensions allowUnlisted="true">
                    <add fileExtension=".php" allowed="false" />
                    <add fileExtension=".phtml" allowed="false" />
                    <add fileExtension=".asp" allowed="false" />
                    <add fileExtension=".aspx" allowed="false" />
                    <add fileExtension=".cgi" allowed="false" />
                    <add fileExtension=".shtml" allowed="false" />
                    <add fileExtension=".shtm" allowed="false" />
                    <add fileExtension=".sht" allowed="false" />
                </fileExtensions>
            </requestFiltering>
        </security>
    </system.webServer>
</configuration>

WEBCONFIG;
                @file_put_contents($webConfigFile, $webConfig);
            }
        }

        // Directory-listing protection is unconditional: it cannot break a
        // server configuration the way .htaccess/web.config directives can,
        // so it is written regardless of the "harden_upload_folder" setting.
        if (!is_file($fullPath . '/index.html')) {
            @file_put_contents($fullPath . '/index.html', '<!DOCTYPE html><title></title>');
        }
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
        Text::script('PLG_FIELDS_PHOCAIMAGE_ERROR_MAX_IMAGES_EXCEEDED');


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
            return '';
        }

        $display = $this->getFieldDisplay($field);

        // Automatic positions are rendered by this plugin's content events below.
        // Returning an empty string here keeps Joomla from wrapping the gallery in
        // the generic fields list, while display=0 remains available for {field ID}.
        if (in_array((string) $display, ['1', '2', '3'])) {
            return '';
        }

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
        // We prepend it to text so it is displayed after intro or full image
        $result  = $this->renderPositionedGallery($context, $item, '2');
        if ($result !== '') {
            if (isset($item->text)) {
                $item->text = $result . $item->text;
            }
            if (isset($item->introtext)) {
                $item->introtext = $result . $item->introtext;
            }
        }

        // Position 3: After Content, but before pagination (Append to text)
        $result3 = $this->renderPositionedGallery($context, $item, '3');
        if ($result3 !== '') {
            if (isset($item->text)) {
                $item->text .= $result3;
            }
            if (isset($item->introtext)) {
                $item->introtext .= $result3;
            }
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

            $display = $this->getFieldDisplay($field);

            if ((string) $display === (string) $position) {
                $output .= $this->renderGallery($field, $item);
            }
        }

        return $output;
    }

    /**
     * Gets the Automatic Display setting for the custom field.
     */
    private function getFieldDisplay($field): string
    {
        if ($field->params instanceof Registry) {
            return (string) $field->params->get('display', '2');
        }

        return '2';
    }

    /**
     * Core gallery rendering logic.
     */
   /* private function renderGallery($field, $item)
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
    }*/

    private function renderGallery($field, $item)
    {
        // Joomla's helper automatically checks:
        // 1. templates/your_template/html/plg_fields_phocaimage/phocaimage.php
        // 2. plugins/fields/phocaimage/tmpl/phocaimage.php
        // override example: templates/phoca_premiere/html/plg_fields_phocaimage/phocaimage.php
        $path = PluginHelper::getLayoutPath('fields', 'phocaimage', 'phocaimage');
        if (!$path || !file_exists($path)) {
            return '';
        }

        // Prepare data for layout
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
            $user   = Factory::getUser();

            // 1. Check if user is logged in
            if ($user->guest) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            $input  = $app->getInput();
            $action = $input->getCmd('action', '');
            $articleId = $input->getInt('article_id', 0);

            // 2. Check Permissions
            $canDo = false;

            if ($articleId == 0) {
                // New Article - Check Create Permission
                $canDo = $user->authorise('core.create', 'com_content');
            } else {
                // Existing Article - Check Edit Permission
                $canDo = $user->authorise('core.edit', 'com_content.article.' . $articleId);

                // Check Edit Own Permission if Edit failed
                if (!$canDo && $user->authorise('core.edit.own', 'com_content.article.' . $articleId)) {
                    // We need to verify that the user is indeed the owner of the article
                    $db = $this->getDatabase();
                    $query = $db->getQuery(true)
                        ->select($db->quoteName('created_by'))
                        ->from($db->quoteName('#__content'))
                        ->where($db->quoteName('id') . ' = ' . $articleId);
                    $ownerId = (int) $db->setQuery($query)->loadResult();

                    if ($ownerId === $user->id) {
                        $canDo = true;
                    }
                }
            }

            if (!$canDo) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

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
        $existingCount = $input->getInt('existing_count', 50);

        if (empty($files)) {
            return ['success' => false, 'message' => Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_NO_FILE')];
        }

        // Enforce max images limit
        $maxImages = (int) $this->params->get('max_images', 50);
        if ($maxImages > 0 && ($existingCount + count($files)) > $maxImages) {
            return ['success' => false, 'message' => Text::sprintf('PLG_FIELDS_PHOCAIMAGE_ERROR_MAX_IMAGES_EXCEEDED', $maxImages)];
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

        // Defense-in-depth: ensure this (and any parent) upload folder cannot
        // execute scripts even if a file with an unexpected extension ever ends
        // up here, regardless of how it got there or what the webserver/OS
        // considers executable.
        $this->hardenUploadFolder($fullPath);

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

        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => $file['name']. ": " . Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_INVALID_TYPE')];
        }

        // Strictly and fully decode the uploaded file before anything else
        // happens to it. This is a real decode (GD actually parses the whole
        // image), not just a header check, so it cannot be fooled by a file
        // that merely *starts* with a valid-looking image header followed by
        // arbitrary attacker data (e.g. a GIF header followed by an embedded PHP payload).
        //
        // Nothing is written anywhere - not to the upload folder, not under
        // any name - unless this decode fully succeeds. If it fails, we stop
        // immediately: the file is never moved, never renamed, never touched
        // again. PHP removes the original tmp upload automatically at the end
        // of the request, so no trace of a rejected file is left on disk.
        $decoded = ImageHelper::decodeAndValidate($file['tmp_name']);

        if ($decoded === null) {
            // This is worth recording: a file that claims (via its name and/or
            // a superficial Content-Type) to be an image but does not decode as
            // one is a strong signal of a deliberate upload-bypass attempt
            // (extension smuggling, polyglot payloads, truncated/corrupted
            // crafted files, etc.), not an ordinary user error.
            try {
                Log::add(
                    sprintf(
                        'Rejected upload "%s" from user #%d (IP %s): file is not a valid, fully-decodable image.',
                        $file['name'],
                        Factory::getUser()->id,
                        $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '')
                    ),
                    Log::WARNING,
                    'plg_fields_phocaimage'
                );
            } catch (\Throwable $e) {
                // Logging must never block/break the rejection itself.
            }

            return ['success' => false, 'message' => $file['name']. ": " . Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_INVALID_TYPE')];
        }

        $mimeType      = $decoded['mime'];
        $safeExtension = $decoded['extension'];
        $image         = $decoded['image'];

        // The stored extension is derived exclusively from the detected MIME type
        // of the successfully decoded image, never from the uploaded filename.
        // This makes it impossible for an attacker to control the extension the
        // file is saved with (e.g. .shtml, .phtml, .php, .pht, double
        // extensions, etc.), which is what determines whether the webserver
        // will execute it - independent of the file's actual byte content.
        $filename = $this->sanitizeFilename($file['name'], $safeExtension);
        $destFile = $destPath . '/' . $filename;

        // Handle duplicate filenames
        $counter = 1;
        $pathInfo = pathinfo($filename);
        while (file_exists($destFile)) {
            $filename = $pathInfo['filename'] . '_' . $counter . '.' . $pathInfo['extension'];
            $destFile = $destPath . '/' . $filename;
            $counter++;
        }

        // Write the file by RE-ENCODING the decoded pixel data, rather than
        // copying the uploaded bytes verbatim. This guarantees the file that
        // lands in the web-accessible folder contains only genuine image data
        // GD itself produced - any trailing/appended bytes an attacker put
        // after a valid image header are discarded, not just hidden behind a
        // safe extension.
        $quality = $this->getQualityForMimeType($mimeType);
        $saved   = ImageHelper::encodeAndSave($image, $destFile, $mimeType, $quality);
        imagedestroy($image);

        if (!$saved) {
            if (file_exists($destFile)) {
                @unlink($destFile);
            }
            return ['success' => false, 'message' => $file['name']. ": " . Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_MOVE_FILE')];
        }

        // Transform to WebP if enable - possible performance problem, so te parameter is disabled
        $webpTransform = 0;//(bool) $this->params->get('webp_transform', 0);
        if ($webpTransform) {
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($destFile);

            if ($mimeType !== 'image/webp') {
                $pathInfo = pathinfo($filename);
                // Check uniqueness for new filename
                $newFilename = $pathInfo['filename'] . '.webp';
                $newDestFile = $destPath . '/' . $newFilename;

                $counter = 1;
                while (file_exists($newDestFile)) {
                    $newFilename = $pathInfo['filename'] . '_' . $counter . '.webp';
                    $newDestFile = $destPath . '/' . $newFilename;
                    $counter++;
                }

                $quality = (int) $this->params->get('webp_quality', 80);
                if (ImageHelper::convert($destFile, $newDestFile, 'image/webp', $quality)) {
                    // Delete original
                    if (file_exists($destFile)) {
                        unlink($destFile);
                    }
                    // Update variables to point to the new WebP file
                    $filename = $newFilename;
                    $destFile = $newDestFile;
                }
            }
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
        if ($articleId === 0) {
            // New article - use temp folder with session hash
            $session  = Factory::getApplication()->getSession();
            $tempHash = substr(md5($session->getId() . $fieldId), 0, 12);
            return $this->getBasePath() . '/temp_' . $tempHash;
        }

        // Use central folder generation
        $date = $this->getArticleDate($articleId);
        return self::getFolder($this->params, $articleId, $date);
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
        $date = $this->getArticleDate($articleId);
        return self::getFolder($this->params, $articleId, $date);
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

        if ($folderStructure === 'year' || $folderStructure === 'year_month' || $folderStructure === 'year_month_day') {
            // Define regex patterns for different structures
            $pattern = match($folderStructure) {
                'year'           => '/^\d{4}$/',
                'year_month'     => '/^\d{4}_\d{2}$/',
                'year_month_day' => '/^\d{4}_\d{2}_\d{2}$/',
            };

            // Search for the article folder in all matching directories
            $dirs = Folder::folders($basePath);
            foreach ($dirs as $dir) {
                if (preg_match($pattern, $dir)) {
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
     * Get the folder path based on configuration.
     *
     * @param   Registry     $params     The plugin parameters.
     * @param   int          $articleId  The article ID.
     * @param   string|null  $date       The article creation date.
     *
     * @return  string
     *
     * @since   6.0.0
     */
    public static function getFolder(Registry $params, int $articleId, ?string $date = null): string
    {
        $folder = trim($params->get('folder', 'phocaimage'), '/ ');
        $folder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $folder);
        $folder = Folder::makeSafe($folder);

        $subfolder = trim($params->get('subfolder', ''), '/ ');
        $subfolder = preg_replace('/[^a-zA-Z0-9_\-]/', '', $subfolder);
        $subfolder = Folder::makeSafe($subfolder);

        $basePath = 'images/' . $folder;

        if ($subfolder !== '') {
            $basePath .= '/' . $subfolder;
        }

        $folderStructure = $params->get('folder_structure', 'article_id');
        $dateStr         = $date ?: 'now';
        $timestamp       = strtotime($dateStr);

        if ($timestamp === false) {
            $timestamp = time();
        }

        return match ($folderStructure) {
            'year'           => $basePath . '/' . date('Y', $timestamp) . '/' . $articleId,
            'year_month'     => $basePath . '/' . date('Y_m', $timestamp) . '/' . $articleId,
            'year_month_day' => $basePath . '/' . date('Y_m_d', $timestamp) . '/' . $articleId,
            default          => $basePath . '/' . $articleId,
        };
    }

    /**
     * Sanitize filename for safe storage.
     *
     * IMPORTANT: The extension used for the stored file is always the
     * $safeExtension argument, derived by the caller from server-side content
     * inspection (finfo/getimagesize) of the uploaded file. The extension
     * present in the original, client-supplied $filename is intentionally
     * ignored and discarded here - it must never be trusted, since it is
     * entirely attacker-controlled and is what determines whether a
     * webserver will execute the file (e.g. .shtml, .phtml, .php5, .pht,
     * double extensions like .jpg.php, etc.).
     *
     * @param   string  $filename       The original (untrusted) filename, used only for its base name.
     * @param   string  $safeExtension  The validated extension to force onto the stored file.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function sanitizeFilename(string $filename, string $safeExtension): string
    {
        // Only allow known-safe extensions to be applied, regardless of caller input.
        $allowedExtensions = ['jpg', 'png', 'gif', 'webp', 'avif'];
        $safeExtension = strtolower($safeExtension);
        if (!in_array($safeExtension, $allowedExtensions, true)) {
            // Should never happen given current callers, but fail safe.
            $safeExtension = 'bin';
        }

        // Get just the name portion of the original filename; its extension is discarded.
        $pathInfo = pathinfo($filename);
        $name     = $pathInfo['filename'] ?? '';

        // Remove any non-alphanumeric characters except dash and underscore
        // (this also strips any embedded dots, preventing double-extension tricks
        // such as "shell.php" being used as the "name" part of "shell.php.jpg").
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);

        // Remove multiple underscores
        $name = preg_replace('/_+/', '_', $name);

        // Trim underscores
        $name = trim($name, '_');

        // Ensure we have a filename
        if (empty($name)) {
            $name = 'image_' . time();
        }

        return $name . '.' . $safeExtension;
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
        $introImageFormat = (int) $this->params->get('intro_image_format', 1);
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
        $thumbPathM  = $uploadPath . '/phoca_thumb_m_' . $filename;

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

            if ($introImageFormat == 2) {
                $articleImages['image_intro'] = $thumbPathM;
            } else {
                $articleImages['image_intro'] = $thumbPath;
            }
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
