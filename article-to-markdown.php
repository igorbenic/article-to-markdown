<?php

/**
 * Plugin Name: Article to Markdown.
 * Author: Igor Benić
 * Author URI: https://ibenic.com
 * Version: 1.1.0
 */

namespace Article2Markdown;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, function () {
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
	flush_rewrite_rules();
});

add_action('parse_request', '\Article2Markdown\parse_request' );;

function parse_request($wp) {
	if (!isset($wp->request)) {
		return;
	}

	$is_md_request = str_ends_with($wp->request, '.md');
	$is_llm = wpmd_is_llm_user_agent();
	$is_force_md = isset($_GET['format']) && $_GET['format'] === 'md';

	if (!$is_md_request && !$is_llm && !$is_force_md) {
		return;
	}

	$slug = $is_md_request
		? substr($wp->request, 0, -3)
		: $wp->request;

	$post = get_page_by_path($slug, OBJECT, ['post', 'page']);

	if (!$post) {
		status_header(404);
		exit;
	}

	render_markdown_post($post);
	exit;
}

function render_markdown_post(\WP_Post $post)
{
	$converter = new \League\HTMLToMarkdown\HtmlConverter([
		'strip_tags' => true,
		'hard_break' => true,
	]);

	$content = apply_filters('the_content', $post->post_content);
	$markdown = $converter->convert($content);

	header('Content-Type: text/markdown; charset=utf-8');
	header('X-Robots-Tag: noindex, nofollow');
	header('Vary: User-Agent');
	header('X-Content-Intent: llm-ingestion');

	$markdown = preg_replace('/<!--(.|\s)*?-->/', '', $markdown);

	echo wpmd_generate_front_matter($post);
	echo "# " . $post->post_title . "\n\n";
	echo $markdown . "\n";
}

function wpmd_generate_front_matter(\WP_Post $post): string
{
	$author = get_the_author_meta('display_name', $post->post_author);

	$categories = get_the_category($post->ID);
	$category_names = array_map(fn($c) => $c->name, $categories);

	$tags = get_the_tags($post->ID);
	$tag_names = $tags ? array_map(fn($t) => $t->name, $tags) : [];

	$front_matter = [
		'title'     => $post->post_title,
		'slug'      => $post->post_name,
		'date'      => get_post_time(DATE_ATOM, true, $post),
		'modified'  => get_post_modified_time(DATE_ATOM, true, $post),
		'author'    => $author,
		'excerpt'   => wp_strip_all_tags($post->post_excerpt),
		'categories'=> $category_names,
		'tags'      => $tag_names,
		'canonical' => get_permalink($post),
	];

	return "---\n" . wpmd_yaml_encode($front_matter) . "---\n\n";
}

function wpmd_yaml_encode(array $data, int $indent = 0): string
{
	$yaml = '';
	$space = str_repeat('  ', $indent);

	foreach ($data as $key => $value) {
		if (is_array($value)) {
			if (empty($value)) {
				continue;
			}

			$yaml .= "{$space}{$key}:\n";
			foreach ($value as $item) {
				$yaml .= "{$space}  - \"" . esc_yaml($item) . "\"\n";
			}
		} else {
			if ($value === '' || $value === null) {
				continue;
			}

			$yaml .= "{$space}{$key}: \"" . esc_yaml($value) . "\"\n";
		}
	}

	return $yaml;
}

function esc_yaml(string $value): string
{
	return str_replace(
		['"', "\n", "\r"],
		['\"', ' ', ' '],
		$value
	);
}

function wpmd_is_llm_user_agent(): bool
{
	$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
	$ua = strtolower($ua);

	$llm_agents = [
		'gptbot',           // OpenAI
		'chatgpt',
		'openai',
		'claudebot',        // Anthropic
		'anthropic',
		'perplexity',
		'youbot',
		'cohere',
		'gemini',
		'google-extended',
		'llm',
		'ai-agent',
	];

	$llm_agents = apply_filters('article_to_markdown_llm_agents', $llm_agents);

	foreach ($llm_agents as $agent) {
		if (str_contains($ua, $agent)) {
			return true;
		}
	}

	return false;
}

