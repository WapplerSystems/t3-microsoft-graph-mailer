<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Microsoft Graph Mailer',
    'description' => 'Microsoft Graph mailer transport for TYPO3 — sends emails via Microsoft 365 Graph API with OAuth2',
    'category' => 'misc',
    'author' => 'Sven Wappler',
    'author_email' => 'typo3@wappler.systems',
    'author_company' => 'WapplerSystems',
    'state' => 'beta',
    'version' => '14.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'oauth_service' => '14.2.0',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
