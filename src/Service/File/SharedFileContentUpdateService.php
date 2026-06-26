<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\SharedFile;
use RuntimeException;

/**
 * @brief Persist plaintext edits for owned shared text files with encryption and quota checks.
 */
final class SharedFileContentUpdateService
{
    public const EXCEPTION_EXTENSION_NOT_ALLOWED = 'content_edit.extension_not_allowed';

    public const EXCEPTION_TOO_LARGE = 'content_edit.too_large';

    public const EXCEPTION_INVALID_UTF8 = 'content_edit.invalid_utf8';

    public const EXCEPTION_STORAGE_UNREADABLE = 'content_edit.storage_unreadable';

    public const EXCEPTION_STORAGE_FAILED = 'content_edit.storage_failed';

    /** @var list<string> Lowercase extensions aligned with FilesController text preview allowlist. */
    public const EDITABLE_TEXT_EXTENSIONS = [
        'txt', 'log', 'md', 'markdown', 'json', 'csv', 'tsv', 'xml', 'yml', 'yaml', 'ini', 'conf',
    ];

    public function __construct(
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly int $maxTextEditBytes = 20971520,
    ) {
    }

    /**
     * @brief Validate extension, UTF-8, size, quota; re-encrypt storage and update entity metadata.
     * @param SharedFile $sharedFile Target shared file entity (mutated in place).
     * @param string $utf8Content New plaintext body.
     * @param int $ownerUserId Owner user identifier for quota enforcement.
     * @return int New plaintext byte size.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function saveContent(SharedFile $sharedFile, string $utf8Content, int $ownerUserId): int
    {
        $extension = strtolower($sharedFile->getFileExtension());
        if (!\in_array($extension, self::EDITABLE_TEXT_EXTENSIONS, true)) {
            throw new RuntimeException(self::EXCEPTION_EXTENSION_NOT_ALLOWED);
        }

        if (!mb_check_encoding($utf8Content, 'UTF-8')) {
            throw new RuntimeException(self::EXCEPTION_INVALID_UTF8);
        }

        $newByteSize = strlen($utf8Content);
        if ($newByteSize > $this->maxTextEditBytes) {
            throw new RuntimeException(self::EXCEPTION_TOO_LARGE);
        }

        $oldByteSize = $sharedFile->getByteSize();
        $deltaBytes = max(0, $newByteSize - $oldByteSize);
        if ($deltaBytes > 0) {
            try {
                $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, $deltaBytes);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                    throw new RuntimeException(UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED, 0, $e);
                }

                throw $e;
            }
        }

        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            throw new RuntimeException(self::EXCEPTION_STORAGE_UNREADABLE);
        }

        $tmpPlain = tempnam(sys_get_temp_dir(), 'sws_txt_edit_');
        if ($tmpPlain === false) {
            throw new RuntimeException(self::EXCEPTION_STORAGE_FAILED);
        }

        $tmpEncrypted = $storagePath.'.tmp';

        try {
            if (file_put_contents($tmpPlain, $utf8Content) === false) {
                throw new RuntimeException(self::EXCEPTION_STORAGE_FAILED);
            }

            $plainLen = $this->fileEncryptionService->encryptPlainFileToV2Storage($tmpPlain, $tmpEncrypted);
            if ($plainLen !== $newByteSize) {
                throw new RuntimeException(self::EXCEPTION_STORAGE_FAILED);
            }

            if (!@rename($tmpEncrypted, $storagePath)) {
                throw new RuntimeException(self::EXCEPTION_STORAGE_FAILED);
            }

            $sharedFile->setByteSize($newByteSize);
            $sharedFile->touchUpdatedAt();
        } finally {
            if (is_file($tmpPlain)) {
                @unlink($tmpPlain);
            }
            if (is_file($tmpEncrypted)) {
                @unlink($tmpEncrypted);
            }
        }

        return $newByteSize;
    }

    /**
     * @brief Whether a lowercase file extension may be edited as plain text.
     * @param string $extension Lowercase extension without dot.
     * @return bool
     * @date 2026-06-26
     * @author Stephane H.
     */
    public static function isEditableTextExtension(string $extension): bool
    {
        return \in_array(strtolower($extension), self::EDITABLE_TEXT_EXTENSIONS, true);
    }
}
