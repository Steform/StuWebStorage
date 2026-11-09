#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * @brief Prune CV/Employment translation sections while keeping storage-related keys.
 *
 * @return int
 * @date 2026-06-22
 * @author Stephane H.
 */
function main(): int
{
    $projectDir = dirname(__DIR__);
    $translationDir = $projectDir.'/translations';
    $locales = ['en', 'fr', 'de', 'lt', 'no'];

    $dropTopLevel = [
        'home',
        'maintenance',
        'dashboard',
        'bug_report',
        'cv',
        'employment',
        'rollback_command',
    ];

    foreach ($locales as $locale) {
        $path = sprintf('%s/messages.%s.yaml', $translationDir, $locale);
        if (!is_file($path)) {
            fwrite(STDERR, "Missing translation file: {$path}\n");
            return 1;
        }

        /** @var array<string, mixed> $messages */
        $messages = Yaml::parseFile($path);
        $messages = pruneMessages($messages, $dropTopLevel);

        $yaml = Yaml::dump($messages, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($path, rtrim($yaml)."\n");
        $lineCount = substr_count(file_get_contents($path) ?: '', "\n") + 1;
        echo sprintf("Pruned messages.%s.yaml (%d lines)\n", $locale, $lineCount);
    }

    return 0;
}

/**
 * @brief Apply storage-focused pruning rules to one locale tree.
 *
 * @param array<string, mixed> $messages Translation tree.
 * @param list<string> $dropTopLevel Top-level keys to remove.
 * @return array<string, mixed>
 * @date 2026-06-22
 * @author Stephane H.
 */
function pruneMessages(array $messages, array $dropTopLevel): array
{
    foreach ($dropTopLevel as $key) {
        unset($messages[$key]);
    }

    if (isset($messages['app']) && is_array($messages['app'])) {
        $nav = is_array($messages['app']['nav'] ?? null) ? $messages['app']['nav'] : [];
        $keepNav = [
            'home',
            'files_user',
            'files_admin',
            'security_users',
            'security_invite',
            'my_profile',
            'logout',
            'language',
        ];
        $messages['app']['nav'] = array_intersect_key($nav, array_flip($keepNav));
    }

    if (isset($messages['mail']) && is_array($messages['mail'])) {
        $messages['mail'] = array_intersect_key(
            $messages['mail'],
            array_flip(['totp', 'invite'])
        );
        if (isset($messages['mail']['totp']['brand']) && is_string($messages['mail']['totp']['brand'])) {
            $messages['mail']['totp']['brand'] = 'StuWebStorage';
        }
    }

    if (isset($messages['admin']) && is_array($messages['admin'])) {
        $messages['admin'] = array_intersect_key(
            $messages['admin'],
            array_flip(['users', 'invite'])
        );
    }

    if (isset($messages['profile']['bubble']) && is_array($messages['profile']['bubble'])) {
        unset($messages['profile']['bubble']);
    }

    if (isset($messages['floating']['actions']['bug'])) {
        unset($messages['floating']['actions']['bug']);
    }

    return $messages;
}

exit(main());
