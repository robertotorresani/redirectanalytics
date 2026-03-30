<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Redirect Analytics',
    'description' => 'Tracks TYPO3 redirects via Google Analytics 4 Measurement Protocol (server-side)',
    'category' => 'plugin',
    'author' => 'Roberto Torresani',
    'author_email' => 'roberto@torresani.eu',
    'state' => 'beta',
    'version' => '0.5.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-13.99.99',
            'redirects' => '13.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
