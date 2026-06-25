<?php

declare(strict_types=1);

namespace App\Dto\Share;

/**
 * @brief Shared-for-me folder node used for grantee-side tree navigation.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class SharedForMeFolderNode
{
    /**
     * @brief Build a shared folder node.
     * @param int $id Folder identifier.
     * @param string $name Display folder name.
     * @param int|null $parentId Parent folder identifier or null at owner root.
     * @param int $ownerUserId Owner user identifier.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        public readonly int $ownerUserId,
    ) {
    }

    /**
     * @brief Convert node to listing row shape consumed by Twig templates.
     * @return array{id: int, name: string}
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function toListingRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
