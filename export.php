<?php
$_SERVER['REMOTE_ADDR'] = '';
if (file_exists('includes/bootstrap.inc')) {
define('DRUPAL_ROOT', getcwd());
}
elseif (file_exists('../includes/bootstrap.inc')) {
define('DRUPAL_ROOT', dirname('..'));
}

require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
module_load_include('module', 'field');
$GLOBALS['base_path'] = '';

error_reporting(E_ALL);

class DrupalTaxonomy {
	private $categories = array();
	private $tags = array();

	function __construct($category_tid, $tags_tid) {
		$categoriesList = taxonomy_get_tree($category_tid);
		$tagsList = taxonomy_get_tree($tags_tid);
		foreach ($categoriesList as $c) {
			$this->categories[$c->tid] = $c;
		}
		foreach ($tagsList as $c) {
			$this->tags[$c->tid] = $c;
		}
	}

	public function categoriesCount() { return count($this->categories); }
	public function tagsCount() { return count($this->tags); }

	public function getCategoryMapper() {
		return function($x) { return $this->categories[$x['tid']]; };
	}
	public function getTagMapper() {
		return function($x) { return $this->tags[$x['tid']]; };
	}
}

$taxonomyExtractor = function($what) { return $what->name; };

class HugoPage {
	private $url;
	private $node;
	private $tags;
	private $topics;
	function __construct($node, $tags, $topics) {
		$this->node = $node;
		$this->url = urldecode(url(drupal_get_path_alias('node/' . $node->nid)));
		$this->tags = array_unique($tags);
		$this->topics = array_unique($topics);
	}
	function __toString() {
		return sprintf("%5d. %s %s\n       T: %s\n       C: %s\n", $this->node->nid, $this->node->title, $this->url, 
					implode(', ', $this->tags), implode(', ', $this->topics));
	}
}

$drupalTaxonomy = new DrupalTaxonomy(2, 4);
printf("Loaded %d categories and %d tags\n", $drupalTaxonomy->categoriesCount(), $drupalTaxonomy->tagsCount());
$result = db_query("SELECT nid FROM {node}");
foreach ($result as $tmp) {
	$node = node_load($tmp->nid);
	$hugoTopics = array(); 
	$hugoTags = array();
	foreach ($node->field_category as $language => $languageCategories) {
		$hugoTopics += array_map($drupalTaxonomy->getCategoryMapper(), $languageCategories);
	}
	foreach ($node->field_tags as $language => $languageTags) {
		$hugoTags += array_map($drupalTaxonomy->getTagMapper(), $languageTags);
	}
	$page = new HugoPage($node, array_map($taxonomyExtractor, $hugoTags), array_map($taxonomyExtractor, $hugoTopics));
	echo $page;
}
