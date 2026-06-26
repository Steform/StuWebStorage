<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Folder;
use App\Entity\FolderShareGrant;
use App\Repository\FolderRepository;
use App\Repository\FolderShareGrantRepository;
use App\Repository\ShareGrantRepository;
use App\Service\Share\FolderAncestorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @brief Backfill folder ancestor closure and folder share grants; optionally purge redundant file grants.
 * @date 2026-06-26
 * @author Stephane H.
 */
#[AsCommand(
    name: 'app:share:migrate-folder-grants',
    description: 'Backfill folder_ancestor and folder_share_grant; optionally purge redundant share_grant rows',
)]
final class MigrateFolderShareGrantsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderRepository $folderRepository,
        private readonly FolderAncestorService $folderAncestorService,
        private readonly FolderShareGrantRepository $folderShareGrantRepository,
        private readonly ShareGrantRepository $shareGrantRepository,
    ) {
        parent::__construct();
    }

    /**
     * @brief Configure command options.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report actions without writing changes')
            ->addOption('purge-redundant', null, InputOption::VALUE_NONE, 'Delete file grants covered by active folder grants');
    }

    /**
     * @brief Execute migration backfill and optional purge.
     * @param InputInterface $input Console input.
     * @param OutputInterface $output Console output.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $purgeRedundant = (bool) $input->getOption('purge-redundant');

        $ownerIds = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT owner_user_id FROM folder ORDER BY owner_user_id ASC'
        );
        $ancestorRows = 0;
        foreach ($ownerIds as $ownerId) {
            $ownerId = (int) $ownerId;
            if ($ownerId < 1) {
                continue;
            }
            if ($dryRun) {
                $ancestorRows += \count($this->folderRepository->findBy(['ownerUserId' => $ownerId]));
                continue;
            }
            $ancestorRows += $this->folderAncestorService->rebuildForOwner($ownerId);
        }

        $folders = $this->folderRepository->findAll();
        $grantUpserts = 0;
        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }
            $granteeIds = $folder->getFriendsShareUserIds();
            if ($granteeIds === []) {
                continue;
            }
            $folderId = (int) ($folder->getId() ?? 0);
            if ($folderId < 1) {
                continue;
            }
            foreach ($granteeIds as $granteeUserId) {
                if ($granteeUserId <= 0 || $granteeUserId === $folder->getOwnerUserId()) {
                    continue;
                }
                ++$grantUpserts;
                if (!$dryRun) {
                    $existing = $this->folderShareGrantRepository->findOneByFolderAndGrantee($folderId, $granteeUserId);
                    if (!$existing instanceof FolderShareGrant) {
                        $this->entityManager->persist(new FolderShareGrant($folderId, $granteeUserId, null));
                    }
                }
            }
        }
        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $purged = 0;
        if ($purgeRedundant) {
            $sql = 'SELECT sg.id, sg.shared_file_id, sg.grantee_user_id
                FROM share_grant sg
                INNER JOIN shared_file sf ON sf.id = sg.shared_file_id
                INNER JOIN folder_ancestor fa ON fa.folder_id = sf.folder_id
                INNER JOIN folder_share_grant fsg ON fsg.folder_id = fa.ancestor_folder_id
                    AND fsg.grantee_user_id = sg.grantee_user_id
                WHERE (fsg.expires_at IS NULL OR fsg.expires_at > NOW())
                  AND (sg.expires_at IS NULL OR sg.expires_at > NOW())';
            $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql);
            foreach ($rows as $row) {
                ++$purged;
                if (!$dryRun) {
                    $this->shareGrantRepository->deletePair((int) $row['shared_file_id'], (int) $row['grantee_user_id']);
                }
            }
        }

        $io->success(sprintf(
            'Folder ancestors rebuilt for %d folder(s); %d folder grant(s) ensured; %d redundant file grant(s) %s.',
            $ancestorRows,
            $grantUpserts,
            $purged,
            $dryRun ? 'would be purged' : 'purged'
        ));

        return Command::SUCCESS;
    }
}
