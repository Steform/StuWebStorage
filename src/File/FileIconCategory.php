<?php

declare(strict_types=1);

namespace App\File;

/**
 * @brief High-level families used for extension icon fallbacks.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
enum FileIconCategory: string
{
    case OfficeWord = 'office_word';
    case OfficeExcel = 'office_excel';
    case OfficePowerpoint = 'office_powerpoint';
    case Pdf = 'pdf';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';
    case Code = 'code';
    case Text = 'text';
    case Database = 'database';
    case Email = 'email';
    case Font = 'font';
    case Executable = 'executable';
    case Certificate = 'certificate';
    case Cad = 'cad';
    case Binary = 'binary';
    case Ebook = 'ebook';
    case Subtitle = 'subtitle';
    case Gis = 'gis';
    case Default = 'default';
}
