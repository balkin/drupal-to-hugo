<?php
if (file_exists( $_SERVER['HOME'] . '/gocode')) {
	@define('HUGO_PATH',  $_SERVER['HOME'] . '/gocode/bin/hugo');
}
elseif (file_exists( $_SERVER['HOME'] . '/go')) {
	@define('HUGO_PATH',  $_SERVER['HOME'] . '/go/bin/hugo');
}
else {
	@define('HUGO_PATH', 'hugo');
}

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

function myUrlEncode($string) {
	$entities = array('%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D', ';');
	$replacements = array('!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "+", "$", ",", "/", "?", "%", "#", "[", "]", "\\;");
	return str_replace($entities, $replacements, urlencode($string));
}

require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
module_load_include('module', 'field');
$taxonomyExtractor = function($what) { return $what->name; };
$GLOBALS['base_path'] = '';

error_reporting(E_ALL);
chdir(DRUPAL_ROOT);
setlocale(LC_ALL, 'ru_RU.UTF8', 'ru_RU.UTF-8', 'ru_RU.utf8');

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
	private $path;
	private $node;
	private $tags;
	private $topics;
	private $aliases;
	private $rewrite = array();
	function __construct($node, $tags, $topics) {
		$this->node = $node;
		$source = 'node/' . $node->nid;
		$this->aliases = array($source);
		$this->url = urldecode(url(drupal_get_path_alias($source)));
		$sanitized = $this->sanitize($this->url);
		if ($this->url != $sanitized) {
			$this->aliases[] = $this->url;
			$this->rewrite['/'.myUrlEncode($this->url)] = '/' . $sanitized;
			if (substr($this->url, -1) != '/' && substr($this->url, -4) != 'html') {
				$this->rewrite['/'.myUrlEncode($this->url) . '/'] = '/' . $sanitized;
			}
			$this->url = $sanitized;
		}
		$urls2 = db_query("SELECT * FROM {url_alias} WHERE source = '$source'");
		foreach ($urls2 as $row) {
			if ($row->alias != $sanitized) {
				$this->rewrite['/' . myUrlEncode($row->alias)] = '/' . $sanitized;
			}
		}
		$this->tags = array_unique($tags);
		$this->topics = array_unique($topics);
		if (preg_match('#\.html$#', $this->url)) {
			$this->path = str_replace('.html', '', $this->url);
			$this->aliases[] = $this->path;
		} else {
			$this->path = $this->url;
		}
	}
	function sanitize($s) {
		$out = '';
		for ($i = 0; $i < mb_strlen($s); $i++) {
			$ch = mb_substr($s, $i, 1);
			if (ctype_alnum($ch) || ctype_alpha($ch) || preg_match('#\p{Lu}#', $ch) || preg_match('#\p{Lt}#', $ch) ||
				$ch == '/' || $ch == '.' || $ch == ',' || $ch == '-' || $ch == '_') {
				$out .= $ch;
			}
		}
		return $out;
	}
	function endsWith($haystack, $needle) {
	    $length = strlen($needle);
	    if ($length == 0) {
	        return true;
	    }
	    return (substr($haystack, -$length) === $needle);
	}

	function getURL() { 
		if ($this->endsWith($this->url, '.html')) return $this->url;
		return $this->url . '/';
	}
	function getPath() { return $this->path; }
	function getTitle() { return $this->node->title; }
	function getTopics() { return $this->topics; }
	function getTags() { return $this->tags; }
	function getNode() { return $this->node; }
	function getAliases() { return $this->aliases; }
	function getRewrite() { return $this->rewrite; }
	function getSummary() {
		$options = array('label'=>'hidden', 'type' => 'text_summary_or_trimmed', 'settings'=>array('trim_length' => 220));
		$f = field_view_field('node', $this->node, 'body', $options);
		return render($f);
	}
	function getBody() {
		$f = field_view_field('node', $this->node, 'body', array('label'=>'hidden'));
		return render($f);
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

	public function prepareMetadata(HugoPage $page) {
		$title = addcslashes($page->getTitle(), '"');
		$quoteLambda = function($x) { return '"' . $x . '"'; };
		$catString = implode(',', array_map($quoteLambda, $page->getTopics()));
		$tagsString = implode(',', array_map($quoteLambda, $page->getTags()));
		$aliasesString = implode(',', array_map($quoteLambda, $page->getAliases()));
		$node = $page->getNode();
		$typeString = $node->type == 'blog' ? 'post' : 'page';
		$dateString = date('c', $node->created);
		$changedString = date('c', $node->changed);
$meta = <<<EOF
+++
categories = [$catString]
date = "$dateString"
changed = "$changedString"
description = ""
tags = [$tagsString]
title = "$title"
type = "$typeString"
url = "{$page->getURL()}"
aliases = [$aliasesString]

+++

EOF;
		return $meta;
	}

	private $rewrites = array();

	public function exportPage(HugoPage $page) {
		$dp = strrpos($page->getPath(), '/');
		if ($dp !== FALSE) {
			$sd = substr($page->getPath(), 0, $dp);
			$d = $this->getPath('content', $sd);
			$fd = substr($page->getPath(), $dp+1);
			mkdir($d, 0755, TRUE);
			$localPath = 'content' . DIRECTORY_SEPARATOR . $sd;
			$f = $this->getPath($localPath, $fd . '.html');
			$body = $this->beautifyParagraphs($page->getBody());
			$meta = $this->prepareMetadata($page);
			file_put_contents($f, $meta . $body);
		}
		foreach ($page->getRewrite() as $from => $to) {
			$this->rewrites[] = "$from $to;\n";
		}
	}

	public function exportRewrites() {
		if (count($this->rewrites)) {
			$this->rewrites[] = "/rss.xml /index.xml;";
			file_put_contents( $this->path . DIRECTORY_SEPARATOR . 'rewrite.map', implode('', $this->rewrites) );
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
$exporter->exportRewrites();

chdir(DRUPAL_ROOT . DIRECTORY_SEPARATOR . 'hugo');
system(HUGO_PATH);
