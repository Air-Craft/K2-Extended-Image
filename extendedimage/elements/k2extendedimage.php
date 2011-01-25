<?php
/**
* @version		$Id: $
* @package		K2
* @subpackage	Extended Image Plugin
* @copyright	Copyright (C) 2011 Amritvela. All rights reserved.
* @license		GPLv2 http://www.opensource.org/licenses/gpl-2.0 
*/

// no direct access
defined('_JEXEC') or die('Restricted access');
jimport('joomla.filter.filteroutput');

/**
 * Image selector K2-style with server browser and preview
 */
class JElementK2ExtendedImage extends JElement
{
	var $_name = 'k2extendedimage';
	
	/**
	 * Prevent JS from beign written more than once
	 * @var unknown_type
	 */
	private static $_js = false;  
	
	public function fetchElement($name, $value, & $node, $control_name)
	{
		$value_iname = "{$control_name}[{$name}]";
		$upload_iname = "{$name}_upload";	// control name's are only for values we want to store with the item
		$server_iname = "{$name}_server";
		$del_iname = "{$name}_delete";	
		
		$html = '<table><tr><td>
			<input type="hidden" name="'.$value_iname.'" value="'.JFilterOutput::ampReplace($value).'" />
			<input type="file" name="'.$upload_iname.'" class="fileUpload" />
	    	<br />
	    	<input style="margin-top:3px;" type="text" name="'.$server_iname.'" id="'.$name.'_server" class="text_area" readonly="readonly"> <input type="button" id="btn_'.$name.'_server" value="' . JText::_('Browse server...') . '" class="k2browse"  /></td>
		';
	
		if (!empty($value)) {
			// Make an image cache buster
			$cb = md5(microtime(true));
			$html .= '<td>
				      <a style="display:block; max-height:150px; max-width:150px;" class="modal" href="' . "$value" . '" title="' . JText::_('Click on image to preview in original size') . '">
				      	<img alt="' . $value . '" src="' . "$value?$cb" . '" style="max-height:150px; max-width:150px;margin:0 4px!important" class="k2AdminImage"/>
				      </a>
				      <input type="checkbox" name="'.$del_iname.'" id="'.$name.'_delete" value="1" />
				      <label for="'.$name.'_delete">' . JText::_('Reset to Original') . '</label>';
		}		
		
		$html .= '</td></tr></table>';
		
		// Add JS if not done already
		if (!JElementK2ExtendedImage::$_js) {
			JFactory::getDocument()->addScriptDeclaration("
				
				window.addEvent('domready', function () { 
					$$('.k2browse').addEvent('click', function(e){
						var e = new Event(e);
						e.stop(); 
						var btn = e.target;
						var inp = btn.getPrevious();
						
						// Temporarily swap input names to fool the popup
						$('existingImageValue').setProperty('name', 'tmp_existingImage');
						var origName = inp.getProperty('name');
						inp.setProperty('name', 'existingImage');
						
						SqueezeBox.initialize();
						SqueezeBox.fromElement(this, {
							handler: 'iframe',
							url: 'http://www.rg.localhost/administrator/index.php?option=com_k2&view=item&task=filebrowser&type=image&tmpl=component',
							size: {x: 590, y: 400},
							onClose: function ()
							{ 
								// Revert input name hack 
								inp.setProperty('name', origName);
								$('existingImageValue').setProperty('name', 'existingImage');
							}
						});
					});
				});
			");
			$this->_js = true;
		}
		//$html .= '</table>';		
		
		
	
		return $html;
	}

}

