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
$categoriesList = taxonomy_get_tree(2);
$tagsList = taxonomy_get_tree(4);

printf("Loaded %d categories and %d tags\n", count(categoriesList), count(tagsList));
foreach ($categoriesList as $c) {
	$categories[$c->tid] = $c;
}
foreach ($tagsList as $c) {
	$tags[$c->tid] = $c;
}

$taxonomyExtractor = function($what) { return $what->name; };

$result = db_query("SELECT nid FROM {node}");
foreach ($result as $tmp) {
	$node = node_load($tmp->nid);
	$url = url(drupal_get_path_alias('node/' . $node->nid));
	$hugoTopics = array(); 
	$hugoTags = array();
	foreach ($node->field_category as $language => $languageCategories) {
		foreach ($languageCategories as $category) {
			array_push($hugoTopics, $categories[$category['tid']]);
		}
	}
	foreach ($node->field_tags as $language => $languageTags) {
		foreach ($languageTags as $tag) {
			array_push($hugoTags, $tags[$tag['tid']]);
		}
	}
	$finalTags = array_unique(array_map($taxonomyExtractor, $hugoTags));
	$finalTopics = array_unique(array_map($taxonomyExtractor, $hugoTopics));
	printf("%5d. %s %s\n       T: %s\n       C: %s\n", $tmp->nid, $node->title, $url, implode(', ', $finalTags), implode(', ', $finalTopics));
}
