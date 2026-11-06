<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Analytics for Matomo',
    'description' => 'Shows Matomo statistics of the current page directly in the TYPO3 Page module.',
    'category' => 'be',
    'author' => 'Maik Schneider',
    'author_email' => 'schneider.maik@me.com',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
            'fluid' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
