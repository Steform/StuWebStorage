<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Transitive closure row linking a folder to one of its ancestors (including itself).
 */
#[ORM\Entity(repositoryClass: 'App\Repository\FolderAncestorRepository')]
#[ORM\Table(name: 'folder_ancestor')]
#[ORM\Index(name: 'idx_folder_ancestor_ancestor_folder', columns: ['ancestor_folder_id', 'folder_id'])]
class FolderAncestor
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $folderId;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $ancestorFolderId;

    #[ORM\Column(type: 'integer')]
    private int $depth;

    /**
     * @brief Build one folder-ancestor closure row.
     * @param int $folderId Descendant folder identifier.
     * @param int $ancestorFolderId Ancestor folder identifier.
     * @param int $depth Distance from descendant to ancestor (0 = self).
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function __construct(int $folderId, int $ancestorFolderId, int $depth)
    {
        $this->folderId = $folderId;
        $this->ancestorFolderId = $ancestorFolderId;
        $this->depth = $depth;
    }

    /**
     * @brief Get descendant folder identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getFolderId(): int
    {
        return $this->folderId;
    }

    /**
     * @brief Get ancestor folder identifier.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getAncestorFolderId(): int
    {
        return $this->ancestorFolderId;
    }

    /**
     * @brief Get depth between descendant and ancestor.
     * @param void No input parameter.
     * @return int
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function getDepth(): int
    {
        return $this->depth;
    }
}
