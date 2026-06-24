<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\UX\Icons\Iconify;
use Symfony\UX\Icons\Registry\LocalSvgIconRegistry;

/**
 * @brief Import vscode-icons used by the file extension resolver.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
#[AsCommand(
    name: 'app:file-icons:import',
    description: 'Import vscode-icons listed in config/icons/vscode-icons-used.txt',
)]
final class FileIconsImportCommand extends Command
{
    private const LIST_PATH = 'config/icons/vscode-icons-used.txt';

    private const ICONIFY_SET = 'vscode-icons';

    private const LOCAL_SET = 'vscode';

    /**
     * @param Iconify $iconify Iconify API client.
     * @param LocalSvgIconRegistry $registry Local SVG icon storage.
     * @param string $projectDir Application root directory.
     */
    public function __construct(
        #[Autowire(service: '.ux_icons.iconify')]
        private readonly Iconify $iconify,
        #[Autowire(service: '.ux_icons.local_svg_icon_registry')]
        private readonly LocalSvgIconRegistry $registry,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * @brief Import configured icon suffixes from Iconify into assets/icons/vscode/.
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
        $listFile = $this->projectDir.'/'.self::LIST_PATH;
        if (!is_readable($listFile)) {
            $io->error(sprintf('Missing icon list file: %s', self::LIST_PATH));

            return Command::FAILURE;
        }

        if (!$this->iconify->hasIconSet(self::ICONIFY_SET)) {
            $io->error(sprintf('Icon set "%s" is not available from Iconify.', self::ICONIFY_SET));

            return Command::FAILURE;
        }

        $suffixes = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            file($listFile, FILE_IGNORE_NEW_LINES) ?: [],
        )));

        if ($suffixes === []) {
            $io->warning('No icon suffixes found to import.');

            return Command::SUCCESS;
        }

        $metadata = $this->iconify->metadataFor(self::ICONIFY_SET);
        $io->writeln(sprintf(
            ' Icon set: %s (License: %s)',
            $metadata['name'],
            $metadata['license']['title'],
        ));

        $imported = 0;
        $failed = [];

        foreach ($this->iconify->chunk(self::ICONIFY_SET, $suffixes) as $iconNames) {
            try {
                $batchResults = $this->iconify->fetchIcons(self::ICONIFY_SET, $iconNames);
            } catch (\InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::FAILURE;
            }

            foreach ($iconNames as $suffix) {
                $icon = $batchResults[$suffix] ?? null;
                if ($icon === null) {
                    $failed[] = $suffix;
                    $io->writeln(sprintf(
                        ' <fg=red;options=bold>✗</> Not found <fg=bright-white;bg=black>%s:%s</>',
                        self::ICONIFY_SET,
                        $suffix,
                    ));

                    continue;
                }

                $this->registry->add(sprintf('%s/%s', self::LOCAL_SET, $suffix), (string) $icon);
                ++$imported;
                $io->writeln(sprintf(
                    ' <fg=bright-green;options=bold>✓</> Imported <fg=bright-white;bg=black>%s:%s</>',
                    self::LOCAL_SET,
                    $suffix,
                ));
            }
        }

        if ($imported === \count($suffixes)) {
            $io->success(sprintf('Imported %d vscode-icons into assets/icons/%s/.', $imported, self::LOCAL_SET));

            return Command::SUCCESS;
        }

        if ($imported > 0) {
            $io->warning(sprintf(
                'Imported %d/%d icons. Missing: %s',
                $imported,
                \count($suffixes),
                implode(', ', $failed),
            ));

            return Command::FAILURE;
        }

        $io->error(sprintf('Imported 0/%d icons.', \count($suffixes)));

        return Command::FAILURE;
    }
}
