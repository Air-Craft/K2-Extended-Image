<?php
/**
* @version		$Id: $
* @package		K2
* @subpackage	Extended Image Plugin
* @copyright	Copyright (C) 2011 Amritvela. All rights reserved.
* @license		GPLv2 (http://www.opensource.org/licenses/gpl-2.0)
* @todo			Optional "scale/zoom" attribute for zoomed crops?
* @todo			Better error reporting
* @todo			Only resize if required.  No "zooming in"
* @todo			New image doesnt overwrite overrides?
* @todo			Use better image naming convention. Intercept k2item before output and update with item plugin param values. 
*/

// no direct access
defined('_JEXEC') or die ('Restricted access');

jimport('joomla.application.component.helper');
jimport('joomla.html.parameter');
jimport('joomla.environment.uri');

JLoader::register('K2Plugin', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'lib'.DS.'k2plugin.php');
JLoader::register('TableK2Category', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'tables'.DS.'k2category.php');
JLoader::register('TableK2Item', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'tables'.DS.'k2item.php');
JLoader::register('Upload', JPATH_ADMINISTRATOR.DS.'components'.DS.'com_k2'.DS.'lib'.DS.'class.upload.php');
JLoader::register('JElementK2Image', JPATH_PLUGINS.DS.'k2'.DS.'extendedimage'.DS.'elements'.DS.'k2image.php');

/**
 * Plugin to handle advanced K2 image handling including crop and box fit and individual size preview and overrides 
 * 
 * <pre>
 * FEATURES:
 * - Quality settings, per size, by % or desired filesize estimate
 * - Auto preview
 * - Smart handling of landscape and portrait images
 * - Only converts uploaded images if not already JPEG
 * </pre>
 */
class plgK2ExtendedImage extends K2Plugin 
{
	public $pluginName = 'extendedimage';
	public $pluginNameHumanReadable = 'K2 Extended Image';
	
	const METHOD_CROP = 'crop';
	const METHOD_FIT = 'fit';
	const METHOD_FIT_W = 'fit_w';
	const METHOD_FIT_H = 'fit_h';
	
	// Pseudo constants
	private static $DEFAULT_SIZES = array('XS'=>100, 'S'=>200, 'M'=>400, 'L'=>600, 'XL'=>800, 'Generic'=>300);
	private static $PATH_SRC; // = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src';
	private static $PATH_CACHE; // = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache';
	
	/**
	 * @var string
	 */
	private $_hidden_image_pathname = '';

	private $_is_upload = false;
	
	/**
	 * Constuctor
	 */
	public function __construct(& $subject, $config = array())
	{
		// PHP doesn't like constants in class prop definitions
		$this->PATH_SRC = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'src';
		$this->PATH_CACHE = JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache';
	
		parent::__construct($subject, $config);	// loads the plugin params		
	}

	/**#@+
	 * Override K2 width settings with our updated ones
	 *
	 * K2 uses the parameter specified width as the render width for
	 * the HTML in the template. As these props are set very late in some templates via
	 * {@see K2HelperUtilities::setDefaultImage()}, we try several events to ensure things
	 * are covered in most bases.
	 */
	public function onK2CommentsBlock(&$item, &$params, $limitstart)
	{
		$this->_setItemImageSizes($item);
	}
	public function onK2CommentsCounter(&$item, &$params, $limitstart)
	{
		$this->_setItemImageSizes($item);
	}
	public function onK2BeforeDisplay($item, &$params, $limitstart)
	{
		$this->_setItemImageSizes($item);
	}
	/**#@-*/

	/**
	 * Set the imageWidth prop to the correct value.
	 *
	 * Also added properties k2ExtImageWidth, k2ExtImageHeight, and k2ExtImageAttr
	 * for use in templates when K2's event flow overwrites imageWidth too late
	 * for our hooks
	 *
	 * @param object $item K2 Item
	 * @todo Calculate from params rather than read the file, for efficiency
	 */
	private function _setItemImageSizes($item)
	{
		// Don't process if already done...
		if (@isset($item->k2ExtImageWidth) && ($item->k2ExtImageWidth == $item->imageWidth))
			return;

		// Get the image property name in the item object based on the item's group
		switch (@$item->itemGroup)
		{
			case 'leading':
			case 'primary':
			case 'secondary':
			case 'links':
			case 'feed':
				$prop = 'image' . $item->params->get($item->itemGroup. 'ImgSize');
				break;
			default:	// item view
				if (JRequest::getCmd('view') == 'item')
					$prop = 'image' . $item->params->get('itemImgSize');
				else
					return;	// can't handle generic now as there's no itemGroup for it!
		}

		$imageUrl = $item->$prop;
		if (empty($imageUrl))
			return;

		$info = getimagesize(JPATH_SITE.DS.'media'.DS.'k2'.DS.'items'.DS.'cache'.DS.pathinfo($imageUrl, PATHINFO_BASENAME));

		$item->imageWidth = $info[0];
		$item->k2ExtImageWidth = $info[0];
		$item->k2ExtImageHeight = $info[1];
		$item->k2ExtImageAttr = $info[3];
		
	}

	/**
	 * Preliminary setup required before the item processes
	 */
	public function onBeforeK2Save($item, $is_new)
	{
		// We need the item id for new items to in the meantime, Move uploaded item image to a 
	 	// temporary location and hide it from K2
		$this->_hideUploadedImageFromK2();
	}
	
	/**
	 * Handle the advanced image processing
	 */
	public function onAfterK2Save($item, $is_new)
	{
		$k2params = $this->_getItemParams($item);
		
		$reset_sizes = $this->_handleResetOverrides($item);
		$overriden_sizes = $this->_handleImageOverrides($item, $k2params);
		
		// If new image (which we hid onBeforeK2Save()), then handle it properly and remove our 
		// temporary file
		if (!empty($this->_hidden_image_pathname)) {
			$this->_saveOriginalImage($item, $this->_hidden_image_pathname);
		}
		
		// Loop through the sizes and process
		foreach (self::$DEFAULT_SIZES as $size => $dum) {
			
			// If a new image was specified only generate if not overriden on same post
			if ($this->_hidden_image_pathname) {
				if (!in_array($size, $overriden_sizes)) {
					$this->_saveResizedImageFromSrc($size, $item, $k2params);
				}
				
			// Otherwise regenerate from src, any "reset" overrides
			} else {
				if (in_array($size, $reset_sizes)) {
					$this->_saveResizedImageFromSrc($size, $item, $k2params);
				}
			}
		}
		
		// Clear our hidden image flag to prevent future conflicts
		$this->_hidden_image_pathname = '';
		
		// Update the item plugin params so the Image tab shows all the sizes
		$this->_setItemToShowAllAvailImages($item); 
	}
	
	/**
	 * Save the uploaded item image to a temp location and clear request to 
	 * prevent default image processing
	 */
	private function _hideUploadedImageFromK2()
	{
		// Get the handler for the item image if specified
		$h = $this->_getItemNewImageHandler();
		if ($h === NULL) return;
		
		// Move it in the src folder
		$h->file_new_name_body = '__plgk2extimage';
		$h->process($this->PATH_SRC);
		if (!$h->processed)
			$this->_raiseError($h->error);
		
		// Cleanup and hide image upload from K2 (even on error) and set our internal flag
		$this->_clearItemNewImageRequest(); 
		$this->_hidden_image_pathname = $h->file_dst_pathname;
		
		// Dont erase images on the server!
		if ($this->_is_upload)
			$h->clean();
	} 
	
	/**
	 * Remove image overrides marked "reset"
	 */
	private function _handleResetOverrides($item)
	{
		$reset_sizes = array();
		$plg_params = $this->_getItemPluginParams($item);
		$has_changed = false;
		
		foreach (self::$DEFAULT_SIZES as $size => $dum) {
			$lcsize = strtolower($size);
			
			if (JRequest::getBool("extendedimage_{$lcsize}_override_delete")) {
				$filename = md5('Image'.$item->id) . '_' . $size . '.jpg';
				@unlink($this->PATH_CACHE.DS.$filename);
				$reset_sizes[] = $size;

				// Clear the plugin param
				$plg_params->set("extendedimage_{$lcsize}_override", '');	// need '' rather than NULL here I think
				$has_changed = true;
			}
		}
		
		// Update the item if we removed anything
		//if ($has_changed)
		//	$this->_updateItemPluginParams($item, $plg_params);
			
		return $reset_sizes;
	}
	
	/**
	 * Handle the image overrides for each size
	 * @param TableK2Item $item
	 * @param JParameter $k2params Includes image sizes and default quality
	 */
	private function _handleImageOverrides(TableK2Item $item, JParameter $k2params)
	{
		$filename = md5("Image".$item->id);		// base filename
		$processed_sizes = array();				// array of size codes which were override 				
		$plg_params = $this->_getItemPluginParams($item);	// to update the override params for the item
		$item_has_changed = false;
		
		// Loop through sizes and upload if given
		foreach (self::$DEFAULT_SIZES as $size => $dum) {
			$lcsize = strtolower($size);
			
			// Skip if override not specified
			$h = $this->_getImageOverrideHandler($size);
			if (is_null($h)) continue;
			
			// Setup the handler
			$h->image_resize = false;
			$h->file_auto_rename = false;
			$h->file_overwrite = true;
			$h->file_new_name_body = $filename.'_'.$size;
			$h->image_convert = '';
			
			// Convert image to JPEG if required
			if ($h->file_src_mime != 'image/jpeg') {
				$h->image_convert =  'jpg';
			
				// Image quality settings
				$global_quality = $k2params->get('imagesQuality');
				$quality = $this->params->get("{$lcsize}_quality", NULL);
				$filesize = $this->params->get("{$lcsize}_filesize", NULL);	// desired filesize for the JPEG
				if ($quality > 0)
					$h->jpeg_quality = $quality;
				elseif ($filesize > 0)
					$h->jpeg_size = intval($filesize) * 1024;	// convert to bytes
				else
				$h->jpeg_quality = $global_quality;
			}
			
			$h->process($this->PATH_CACHE);
			if (!$h->processed)
				$this->_raiseError($h->error);
			
			// Dont erase images on the server!
			if ($this->_is_upload)
				$h->clean();
			
			// Set the item plugin params for this size
			$tmp = JURI::root(true).'/media/k2/items/cache/'.$h->file_dst_name;
			$plg_params->set("extendedimage_{$lcsize}_override", $tmp);
			$item_has_changed = true;
				
			// Add the size code to the list of override sizes
			$processed_sizes[] = $size;	// skip this image later even if error
		}	

		// if ($item_has_changed)
		//	$this->_updateItemPluginParams($item, $plg_params);
		
		return $processed_sizes;
	}
	
	/**
	 * Get the JParameter for item's plugin specific params 
	 * @param TableK2Item $item
	 * @return JParameter
	 */
	private function _getItemPluginParams(TableK2Item $item)
	{
		$params = new JParameter($item->plugins);		
		return $params;
	}
	
	/**
	 * Store the item with the given params merged into the existin ones
	 * @param TableK2Item $item
	 * @param JParameter $new_params
	 * @todo Error reporting
	 */
	private function _updateItemPluginParams(TableK2Item $item, JParameter $new_params)
	{
		$item_params = $this->_getItemPluginParams($item);
		
		// Manually merge because it wont clear blanks
		foreach (self::$DEFAULT_SIZES as $size => $dum) {
			$lcsize = strtolower($size);
			$key = "extendedimage_{$lcsize}_override";
			$item_params->set($key, $new_params->get($key));
		}
		//$item_params->merge($new_params);
		
		$item->plugins = $item_params->toString();
		$item->store();
	}
	
	/**
	 * Save the resized image from the original src (media/k2/items/src/) for the specified size
	 * @param string $size Size code: XS,S,M,L,XL,Generic
	 * @param TableK2Item $item
	 * @param JParameter $k2params	Merged global, category and item params
	 */
	private function _saveResizedImageFromSrc($size, TableK2Item $item, JParameter $k2params)
	{
		// New handler from our item's source image
		$filenamebase = md5('Image'.$item->id);
		$h = new Upload($this->PATH_SRC.DS.$filenamebase.'.jpg');
		
		$lcsize = strtolower($size);
		
		// Setup handler
		$h->image_resize = true;
		$h->image_convert = 'jpg';
		$h->file_auto_rename = false;
		$h->file_overwrite = true;
	
		$h->image_ratio
			= $h->image_ratio_crop
			= $h->image_ratio_x
			= $h->image_ratio_y = false;
		
		// Image quality settings
		$global_quality = $k2params->get('imagesQuality');
		$quality = $this->params->get("{$lcsize}_quality", NULL);
		$filesize = $this->params->get("{$lcsize}_filesize", NULL);	// desired filesize for the JPEG
		if ($quality > 0)
			$h->jpeg_quality = $quality;
		elseif ($filesize > 0) {
			$h->jpeg_size = intval($filesize) * 1024;	// convert to bytes
			$h->jpeg_size = NULL;
		} else
			$h->jpeg_quality = $global_quality;
		
		// Append _[SIZE] to the filename
		$h->file_new_name_body = $filenamebase.'_'.$size;
		
		// Get the K2 "width" and plugin settings for this size
		$method = $this->params->get("{$lcsize}_method", self::METHOD_FIT);
		$aspect_ratio = $this->params->get("{$lcsize}_aspect_ratio", '1:1');
		
		$param_name = 'itemImage'.$size;
		$k2size = JRequest::getInt($param_name)
			? JRequest::getInt($param_name)
			: $k2params->get($param_name, self::$DEFAULT_SIZES[$size]);
		
		// NEXT: 	Calculate the constraint sizes given the source's orientation and the plugin 
		// 			specified aspect ratio 
		$isPortrait = ($h->image_src_x < $h->image_src_y);

		$tmp = explode(':', $aspect_ratio);
		$aspect_ratio_fl = $tmp[0] / $tmp[1];		// always >= 1
		
		$h->image_x = $isPortrait
			? round($k2size / $aspect_ratio_fl) 
			: $k2size;
		$h->image_y = $isPortrait
			? $k2size
			: round($k2size / $aspect_ratio_fl);
			
		// Set the handler resize type
		switch ($method){
			case self::METHOD_FIT_W:
				$h->image_ratio_y = true;
				break;
			case self::METHOD_FIT_H:
				$h->image_ratio_y = true;
				break;
			case self::METHOD_CROP:
				$h->image_ratio_crop = true;
				break;
			case self::METHOD_FIT:
			default:
				$h->image_ratio = true;
				break;
		}  
		
		$h->process($this->PATH_CACHE);
		if (!$h->processed)
			$this->_raiseError($h->error);
			
		// DO NOT clean() as we need to keep the src!	
	}
	
	/**
	 * Move the image $src_path to the items src folder with the proper name for this
	 * item.  Convert to jpeg only if required
	 * @param TableK2Item $item
	 * @param string $src_path
	 */
	private function _saveOriginalImage(TableK2Item $item, $src_path)
	{
		$h = new Upload($src_path);
		$h->allowed = array('image/*');
		
		// Convert only if required
		if ($h->file_src_name_mime != 'image/jpeg')
			$h->image_convert = 'jpg';
			
		$h->jpeg_quality = 100;
		$h->file_auto_rename = false;
		$h->file_overwrite = true;
		$h->file_new_name_body = md5("Image".$item->id);
		
		$h->process($this->PATH_SRC);
		
		// Error checking and cleanup
		if (!$h->processed)
				$this->_raiseError($h->error);
					
		$h->clean();	// remove our tmp source
	}
	
	/**
	 * Hack(?) the override values for ALL sizes where a file exists so they show up in the 
	 * images tab even without overrides  
	 * 
	 * @param TableK2Item $item
	 */
	private function _setItemToShowAllAvailImages(TableK2Item $item)
	{
		$item_plg_params = $this->_getItemPluginParams($item);
		foreach (self::$DEFAULT_SIZES as $size => $dum) {
			$lcsize = strtolower($size);
			
			// If the image exists, hack the value into the override plugin param
			// so it shows in the preview
			$filepath = $this->PATH_CACHE.DS.md5('Image'.$item->id).'_'.$size.'.jpg';
			$fileurl = JURI::root(true).'/media/k2/items/cache/'.md5('Image'.$item->id).'_'.$size.'.jpg';
			
			if (file_exists($filepath)) {
				$item_plg_params->set("extendedimage_{$lcsize}_override", $fileurl);
			} else {
				$item_plg_params->set("extendedimage_{$lcsize}_override", '');
			}
		}
		$this->_updateItemPluginParams($item, $item_plg_params);
	}
	
	/**
	 * Return the Upload handler for the override for the size code specified or NULL if
	 * none submitted  
	 * @param $size Size code: XS,S...
	 * @return Upload or NULL if no override submitted
	 * @todo better error reporting
	 */
	private function _getImageOverrideHandler($size)
	{
		$size = strtolower($size);
		$files = JRequest::get('files');
		$key 				= "extendedimage_{$size}_override_upload";
		$del_key 			= "extendedimage_{$size}_override_delete";
		$from_server_key 	= "extendedimage_{$size}_override_server";
		
		$existing_image = JRequest::getVar($from_server_key);	// browse server
		$image = NULL;
		
		if (($files[$key]['error'] === 0 || $existing_image) && !JRequest::getBool($del_key)) {
			if($files[$key]['error'] === 0){
				$image = $files[$key];
				$this->_is_upload = true;
			} else {
				$image = JPATH_SITE.DS.JPath::clean($existing_image);
				$this->_is_upload = false;
			}
		}
		
		// Return handler or NULL if no image is specified in the item form 
		if ($image) {
			$handler = new Upload($image);
			$handle->allowed = array('image/*');
			$h->file_auto_rename = false;
			$h->file_overwrite = true;
			return $handler;		
		}
			
		return NULL; 
	} 
	
	/**
	 * Return the handler for processing the main item image if one was specified by the admin form
	 * @return Upload or NULL if no image saved in the item form
	 * @todo better error reporting
	 */
	private function _getItemNewImageHandler()
	{
		$files = JRequest::get('files');
		$existing_image = JRequest::getVar('existingImage');	// browse server
		$image = NULL;
		
		if ( ($files['image']['error'] === 0 || $existing_image) && !JRequest::getBool('del_image')) {
			if($files['image']['error'] === 0){
				$image = $files['image'];
				$this->_is_upload = true;
			} else {
				$image = JPATH_SITE.DS.JPath::clean($existing_image);
				$this->_is_upload = false;
			}
		}
		
		// Return hanlder or NULL if no image is specified in the item form 
		if ($image) {
			$handler = new Upload($image);
			$handle->allowed = array('image/*');
			$h->file_auto_rename = false;
			$h->file_overwrite = true;
			return $handler;		
		}
			
		return NULL; 
	}
	
	/**
	 * Remove the uploaded image from the request to bypass normal K2 processing
	 */
	private function _clearItemNewImageRequest()
	{
		JRequest::setVar('image', NULL, 'FILES');
	}
	
	/**
	 * Get the K2 image size (amongst other) parameters taking global, categ & item into account
	 * @param TableK2Item
	 * @return JParameter
	 */
	private function _getItemParams(TableK2Item $item)
	{
		// Start with the global params
		$params = &JComponentHelper::getParams('com_k2');
		
		// Merge the parameters for this item's category
		$db = JFactory::getDBO();
		$category = new TableK2Category($db);//&JTable::getInstance('K2Category', 'Table');
		$category->load($item->catid);
		$cparams = new JParameter($category->params);

		// Inherited?
		if ($cparams->get('inheritFrom')) {
			$masterCategoryID = $cparams->get('inheritFrom');
			$query = "SELECT * FROM #__k2_categories WHERE id=".(int)$masterCategoryID;
			$db->setQuery($query, 0, 1);
			$masterCategory = $db->loadObject();
			$cparams = new JParameter($masterCategory->params);
		}

		$params->merge($cparams);
		
		return $params;
	}
	
	/**
	 * Add an error to the Joomla queue.  Does NOT stop processing
	 * @param string $msg
	 */
	private function _raiseError($msg)
	{
		JFactory::getApplication()->enqueueMessage(
 			JText::_('Error uploaded image: ') . JText::_($msg), 
 			'error'
 		);
	}
} 

