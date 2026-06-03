<?php

declare(strict_types=1);

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'tx_scheduler_task',
    [
        'tx_microsoftgraphmailer_batch_size' => [
            'label' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.batch_size',
            'description' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.batch_size.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 50,
                'range' => ['lower' => 1, 'upper' => 1000],
                'required' => true,
            ],
        ],
        'tx_microsoftgraphmailer_max_age_days' => [
            'label' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.max_age_days',
            'description' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.max_age_days.description',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'default' => 0,
                'range' => ['lower' => 0, 'upper' => 365],
                'required' => true,
            ],
        ],
    ]
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.title',
        'description' => 'LLL:EXT:microsoft_graph_mailer/Resources/Private/Language/locallang_be.xlf:task.resend.description',
        'value' => \WapplerSystems\MicrosoftGraphMailer\Task\ResendUndeliveredTask::class,
        'icon' => 'mimetypes-x-tx_scheduler_task_group',
        'group' => 'mailer',
    ],
    '
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:tx_scheduler_task.tabs.options,
            tx_microsoftgraphmailer_batch_size,
            tx_microsoftgraphmailer_max_age_days,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:tx_scheduler_task.tabs.timing,
            execution_details,
            nextexecution,
        --div--;LLL:EXT:scheduler/Resources/Private/Language/locallang_tca.xlf:tx_scheduler_task.tabs.access,
            disable,
            description,
    ',
    [],
    '',
    'tx_scheduler_task'
);
