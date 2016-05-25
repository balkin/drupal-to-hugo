<?php
$_SERVER['REMOTE_ADDR'] = '';
if (file_exists('includes/bootstrap.inc')) {
	define('DRUPAL_ROOT', getcwd());
}
elseif (file_exists('../includes/bootstrap.inc')) {
	define('DRUPAL_ROOT', dirname('..'));
}
else {
	define('DRUPAL_ROOT', dirname(__FILE__));
}

require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
module_load_include('module', 'field');
$taxonomyExtractor = function($what) { return $what->name; };
$GLOBALS['base_path'] = '';

error_reporting(E_ALL);
chdir(DRUPAL_ROOT);

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

class HugoPage {
	private $url;
	private $node;
	private $tags;
	private $topics;
	private $aliases;
	function __construct($node, $tags, $topics) {
		$this->node = $node;
		$this->url = urldecode(url(drupal_get_path_alias('node/' . $node->nid)));
		$this->tags = array_unique($tags);
		$this->topics = array_unique($topics);
		$this->aliases = array('node/'.$node->nid, $this->url);
		if (preg_match('#\.html$#', $this->url)) {
			$this->url = str_replace('.html', '', $this->url);
			$this->aliases[] = $this->url;
		}
	}
	function getURL() { return $this->url; }
	function getBody() {
		foreach ($this->node->body as $language => $body) {
			return $body[0]['value'];
		}
	}
	function __toString() {
		return sprintf("%5d. %s %s\n       T: %s\n       C: %s\n       A: %s\n", $this->node->nid, $this->node->title, $this->url, 
					implode(', ', $this->tags), implode(', ', $this->topics), implode(', ', $this->aliases));
	}
}

class HugoExporter {
	private $path = 'hugo';

	function __construct($p) {
		$this->path = $p;
		if (!is_dir($p)) {
			mkdir($p, 0755);
		}
	}

	public function getPath($subdirectory, $filename) {
			return $this->path . DIRECTORY_SEPARATOR . $subdirectory . DIRECTORY_SEPARATOR . $filename;
	}

	public function beautifyParagraphs($body) {
		return str_replace("\n\n", "\n", str_replace('</p>', "</p>\n", $body));
	}

	public function exportPage(HugoPage $page) {
		$dp = strrpos($page->getURL(), '/');
		if ($dp !== FALSE) {
			$sd = substr($page->getURL(), 0, $dp);
			$d = $this->getPath('content', $sd);
			$fd = substr($page->getURL(), $dp+1);
			mkdir($d, 0755, TRUE);
			$localPath = 'content' . DIRECTORY_SEPARATOR . $sd;
			$f = $this->getPath($localPath, $fd . '.html');
			$body = $this->beautifyParagraphs($page->getBody());
			file_put_contents($f, $body);
		}
	}
}

$drupalTaxonomy = new DrupalTaxonomy(2, 4);
$exporter = new HugoExporter("hugo");
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
	$exporter->exportPage($page);
}
