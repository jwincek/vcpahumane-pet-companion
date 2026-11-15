<?php
/**
 * Hand-maintained dependency manifest for edit.js. See the matching file
 * in featured-pets/ for the rationale — both blocks use the same wp-*
 * packages, so the manifest is identical.
 */
return [
	'dependencies' => [
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-server-side-render',
		'wp-i18n',
	],
	'version'      => '0.3.0',
];
