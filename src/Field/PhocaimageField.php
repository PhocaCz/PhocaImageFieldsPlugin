<?php

/**
 * @package     Phoca.Plugin
 * @subpackage  Fields.phocaimage
 *
 * @copyright   (C) 2026 Jan Pavelka
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Phoca\Plugin\Fields\Phocaimage\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * PhocaImage Custom Field
 *
 * @since  1.0.0
 */
class PhocaimageField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Phocaimage';

    /**
     * Method to get the field input markup.
     *
     * @return  string  The field input markup.
     *
     * @since   1.0.0
     */
    protected function getInput(): string
    {



        // Get existing images
        $images    = [];
        $rawImages = (string) $this->value;


        if (!empty($rawImages) && $rawImages !== 'null') {
            $decoded = json_decode($rawImages, true);
            if (is_array($decoded)) {
                $images = $decoded;
            }
        }

        // Get article ID from input or form data
        $articleId = (int) ($this->form->getValue('id') ?? 0);

        // Joomla doesn't always provide the field ID in the element attributes.
        // But the name usually follows the pattern jform[com_fields][ID] or just [ID]
        $fieldId = (int) $this->element['fieldid'];
        if (!$fieldId) {
            $fieldId = (int) $this->element['id'];
        }

        // Robust fallback 1: Extract from name jform[com_fields][ID]
        if (!$fieldId && preg_match('/\[(\d+)\]$/', $this->name, $matches)) {
            $fieldId = (int) $matches[1];
        }

        // Robust fallback 2: Query database by field name if we still have 0
        if (!$fieldId) {
            $db = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__fields'))
                ->where($db->quoteName('name') . ' = ' . $db->quote($this->fieldname))
                ->where($db->quoteName('type') . ' = ' . $db->quote('phocaimage'));
            $fieldId = (int) $db->setQuery($query)->loadResult();
        }

        // Determine upload path for display
        $uploadPath = $this->getUploadPath($articleId, $fieldId);

        // Prepare data for layout
        $data = [
            'id'             => $this->id,
            'name'           => $this->name,
            'value'          => htmlspecialchars($rawImages, ENT_QUOTES, 'UTF-8'),
            'images'         => $images,
            'uploadUrl'      => Uri::base() . 'index.php?option=com_ajax&plugin=phocaimage&group=fields&format=json&action=upload',
            'deleteUrl'      => Uri::base() . 'index.php?option=com_ajax&plugin=phocaimage&group=fields&format=json&action=delete',
            'uploadPath'     => Uri::root(true) . '/' . $uploadPath,
            'articleId'      => $articleId,
            'fieldId'        => $fieldId,
            'csrfToken'      => Session::getFormToken(),
            'messages'       => [
                'deleteConfirm' => Text::_('PLG_FIELDS_PHOCAIMAGE_CONFIRM_DELETE'),
                'uploadError'   => Text::_('PLG_FIELDS_PHOCAIMAGE_ERROR_UPLOAD'),
            ],
        ];

        // Render the layout
        $layout = new FileLayout('phocaimage', JPATH_PLUGINS . '/fields/phocaimage/layouts');
        return $layout->render($data);
    }

    /**
     * Get the upload path for the current item.
     *
     * @param   int  $articleId  The article ID.
     * @param   int  $fieldId    The field ID.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    private function getUploadPath(int $articleId, int $fieldId): string
    {
        // Get plugin params robustly
        $plugin    = PluginHelper::getPlugin('fields', 'phocaimage');
        $params    = new Registry($plugin->params ?? '');
        $subfolder = trim($params->get('subfolder', ''), '/ ');
        $basePath  = 'images/phocaimage';

        if ($subfolder !== '') {
            $basePath .= '/' . $subfolder;
        }

        if ($articleId === 0) {
            $session  = Factory::getApplication()->getSession();
            $tempHash = substr(md5($session->getId() . $fieldId), 0, 12);
            return $basePath . '/temp_' . $tempHash;
        }

        $folderStructure = $params->get('folder_structure', 'article_id');

        if ($folderStructure === 'year_month') {
            $db    = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('created'))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = ' . (int) $articleId);
            $date = $db->setQuery($query)->loadResult();

            $yearMonth = date('Y_m', strtotime($date ?: 'now'));
            return $basePath . '/' . $yearMonth . '/' . $articleId;
        }

        return $basePath . '/' . $articleId;
    }
}
