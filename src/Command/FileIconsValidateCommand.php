<?php

declare(strict_types=1);

namespace App\Command;

use App\File\FileIconMappingProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @brief Validate file icon YAML mappings, Iconify names, and local SVG assets.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
#[AsCommand(
    name: 'app:file-icons:validate',
    description: 'Validate file icon mappings, Iconify availability, and local SVG assets',
)]
final class FileIconsValidateCommand extends Command
{
    private const ICONIFY_INDEX_URL = 'https://raw.githubusercontent.com/iconify/icon-sets/master/json/vscode-icons.json';

    private const LOCAL_ICON_SET = 'vscode';

    /**
     * @param FileIconMappingProvider $mappingProvider Mapping loader.
     * @param string $projectDir Application root directory.
     */
    public function __construct(
        private readonly FileIconMappingProvider $mappingProvider,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * @brief Run mapping, Iconify, and local asset validation.
     *
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-06-24
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = [];

        $iconifySet = $this->loadIconifyIconSet($errors);
        $referencedIcons = $this->mappingProvider->listAllIconSuffixes();

        foreach ($referencedIcons as $iconSuffix) {
            if ($iconifySet !== null && !isset($iconifySet[$iconSuffix])) {
                $errors[] = sprintf('Icon "%s" is not available in vscode-icons on Iconify.', $iconSuffix);
            }

            $localPath = sprintf(
                '%s/assets/icons/%s/%s.svg',
                $this->projectDir,
                self::LOCAL_ICON_SET,
                $iconSuffix,
            );
            if (!is_readable($localPath)) {
                $errors[] = sprintf('Missing local SVG for "%s" (%s).', $iconSuffix, $localPath);
            }
        }

        $manifestPath = $this->projectDir.'/config/icons/vscode-icons-used.txt';
        if (is_readable($manifestPath)) {
            $manifest = array_values(array_filter(array_map(
                static fn (string $line): string => trim($line),
                file($manifestPath, FILE_IGNORE_NEW_LINES) ?: [],
            )));
            sort($manifest);
            $expected = $referencedIcons;
            sort($expected);
            if ($manifest !== $expected) {
                $errors[] = 'config/icons/vscode-icons-used.txt is out of date. Run php bin/console app:file-icons:export.';
            }
        } else {
            $errors[] = 'Missing config/icons/vscode-icons-used.txt manifest.';
        }

        $extensionCount = \count($this->mappingProvider->listAllExtensionEntries());
        if ($errors === []) {
            $io->success(sprintf(
                'Validated %d extensions, %d icons, and local SVG assets.',
                $extensionCount,
                \count($referencedIcons),
            ));

            return Command::SUCCESS;
        }

        $io->error(sprintf('Validation failed with %d issue(s):', \count($errors)));
        foreach ($errors as $error) {
            $io->writeln(' - '.$error);
        }

        return Command::FAILURE;
    }

    /**
     * @brief Load vscode-icons index from Iconify GitHub mirror.
     *
     * @param list<string> $errors Collected validation errors.
     * @return array<string, true>|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function loadIconifyIconSet(array &$errors): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'header' => "User-Agent: StuWebStorage-file-icons-validate\r\n",
            ],
        ]);

        $json = @file_get_contents(self::ICONIFY_INDEX_URL, false, $context);
        if ($json === false) {
            $errors[] = 'Could not download vscode-icons index from Iconify (network).';

            return null;
        }

        $data = json_decode($json, true);
        if (!\is_array($data) || !isset($data['icons']) || !\is_array($data['icons'])) {
            $errors[] = 'Invalid vscode-icons index payload from Iconify.';

            return null;
        }

        $set = [];
        foreach (array_keys($data['icons']) as $iconName) {
            if (\is_string($iconName)) {
                $set[$iconName] = true;
            }
        }

        return $set;
    }
}
