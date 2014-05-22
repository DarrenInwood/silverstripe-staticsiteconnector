<?php


/**
 * Allows adding PHP processor functionality to an import
 */
class StaticSiteContentSource_ImportProcessor extends DataObject {

	private static $db = array(
		'Name' => 'Text',
		'Order' => 'Int',
	);

	private static $has_one = array(
		"Schema" => "StaticSiteContentSource_ImportSchema",
	);

	private static $default_sort = 'Order';

	public function getCMSFields() {
		$fields = new FieldList();

		$map = array();
		foreach( ClassInfo::implementorsOf('StaticSiteContentSource_ImportProcess') as $class ) {
			$map[$class] = $class;
		}

		$fields->push(
			new DropdownField('Name', 'Select a processor to apply:', $map)
		);		

		$fields->removeByName('Order');

		return $fields;
	}

}

/**
 * The interface used to process an item.
 * Processing is run after the import rules have completed.
 * The $item has handy function getContentExtractor() which in turn has handy function getContent()
 * which will return the content of the HTML page; 
 */
interface StaticSiteContentSource_ImportProcess {

	// Inside your function, you can use eg:
	// $elements = $item->getContentExtractor()->getPhpQuery()->find('.css-selector');
	// // I find it easier to use for and eq() than foreach, as the elements remain phpQuery objects
	// for ( $i = 0; $i < $elements->size(); $i++ ) {
	//     $element = $elements->eq($i);
	//     $content = $object->Content;
	//     $content = phpQuery::newDocument('<div id="munged-content">'.$content.'</div>');
	//     // Note that we already replaced all preceding ones, so it's always eq(0)
	//     $content->find('#'.$element->attr('id'))->eq(0)->replaceWith("\n[shortcode id=".$element->attr('id')."]\n");
	//     $object->Content = $content->find('#munged-content')->html();
	// }
	// $object->write();
	// $object->publish('Stage', 'Live');
	public static function process(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy);

}
