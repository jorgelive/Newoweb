<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'oweb' => [
        'path' => 'oweb/app.js',
        'entrypoint' => true,
    ],
    'panel' => [
        'path' => 'panel/app.js',
        'entrypoint' => true,
    ],
    'front' => [
        'path' => 'front/app.js',
        'entrypoint' => true,
    ],
    '@symfony/stimulus-bundle' => [
        'path' => '@symfony/stimulus-bundle/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    'tippy.js' => [
        'version' => '6.3.7',
    ],
    '@popperjs/core' => [
        'version' => '2.10.2',
    ],
    'sweetalert2' => [
        'version' => '11.26.18',
    ],
];
