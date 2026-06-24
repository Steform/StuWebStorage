<?php

declare(strict_types=1);

namespace App\File;

/**
 * @brief Resolve file extensions to Symfony UX vscode-icons names.
 *
 * @date 2026-06-24
 * @author Stephane H.
 */
final class FileExtensionIconResolver
{
  private const ICON_PREFIX = 'vscode:';

  /**
   * @var array<string, string> extension => icon suffix (without prefix)
   */
  private const EXTENSION_ICON_MAP = [
    // Office Word
    'doc' => 'file-type-word',
    'docx' => 'file-type-word',
    'docm' => 'file-type-word',
    'dot' => 'file-type-word',
    'dotx' => 'file-type-word',
    'rtf' => 'file-type-rtf',
    'odt' => 'file-type-word',
    'pages' => 'file-type-pages',
    // Office Excel
    'xls' => 'file-type-excel',
    'xlsx' => 'file-type-excel',
    'xlsm' => 'file-type-excel',
    'xlsb' => 'file-type-excel',
    'ods' => 'file-type-excel',
    'csv' => 'file-type-excel',
    'tsv' => 'file-type-excel',
    'numbers' => 'file-type-numbers',
    // Office PowerPoint
    'ppt' => 'file-type-powerpoint',
    'pptx' => 'file-type-powerpoint',
    'pptm' => 'file-type-powerpoint',
    'odp' => 'file-type-powerpoint',
    'key' => 'file-type-keynote',
    'one' => 'file-type-onenote',
    'onetoc2' => 'file-type-onenote',
    // Documents
    'pdf' => 'file-type-pdf',
    'txt' => 'file-type-text',
    'log' => 'file-type-text',
    'md' => 'file-type-markdown',
    'markdown' => 'file-type-markdown',
    'tex' => 'file-type-tex',
    'latex' => 'file-type-tex',
    'epub' => 'file-type-ebook',
    'mobi' => 'file-type-ebook',
    'azw' => 'file-type-ebook',
    'azw3' => 'file-type-ebook',
    // Images
    'png' => 'file-type-image',
    'jpg' => 'file-type-image',
    'jpeg' => 'file-type-image',
    'jpe' => 'file-type-image',
    'jfif' => 'file-type-image',
    'gif' => 'file-type-gif',
    'webp' => 'file-type-image',
    'svg' => 'file-type-svg',
    'bmp' => 'file-type-image',
    'ico' => 'file-type-favicon',
    'cur' => 'file-type-favicon',
    'tif' => 'file-type-image',
    'tiff' => 'file-type-image',
    'heic' => 'file-type-image',
    'heif' => 'file-type-image',
    'psd' => 'file-type-photoshop',
    'ai' => 'file-type-ai',
    'eps' => 'file-type-eps',
    'raw' => 'file-type-image',
    'cr2' => 'file-type-image',
    'nef' => 'file-type-image',
    'arw' => 'file-type-image',
    'dng' => 'file-type-image',
    'xcf' => 'file-type-gimp',
    'sketch' => 'file-type-sketch',
    'fig' => 'file-type-figma',
    // Video
    'mp4' => 'file-type-video',
    'm4v' => 'file-type-video',
    'webm' => 'file-type-video',
    'avi' => 'file-type-video',
    'mkv' => 'file-type-video',
    'mov' => 'file-type-video',
    'wmv' => 'file-type-video',
    'flv' => 'file-type-video',
    'ogv' => 'file-type-video',
    'mpeg' => 'file-type-video',
    'mpg' => 'file-type-video',
    '3gp' => 'file-type-video',
    'ts' => 'file-type-video',
    'm2ts' => 'file-type-video',
    // Audio
    'mp3' => 'file-type-audio',
    'wav' => 'file-type-audio',
    'flac' => 'file-type-audio',
    'ogg' => 'file-type-audio',
    'oga' => 'file-type-audio',
    'aac' => 'file-type-audio',
    'm4a' => 'file-type-audio',
    'wma' => 'file-type-audio',
    'opus' => 'file-type-audio',
    'mid' => 'file-type-audio',
    'midi' => 'file-type-audio',
    // Archives
    'zip' => 'file-type-zip',
    'rar' => 'file-type-zip',
    '7z' => 'file-type-zip',
    'tar' => 'file-type-zip',
    'gz' => 'file-type-zip',
    'gzip' => 'file-type-zip',
    'bz2' => 'file-type-zip',
    'xz' => 'file-type-zip',
    'tgz' => 'file-type-zip',
    'tbz2' => 'file-type-zip',
    'zst' => 'file-type-zip',
    'iso' => 'file-type-zip',
    'img' => 'file-type-zip',
    'dmg' => 'file-type-zip',
    'pkg' => 'file-type-zip',
    'deb' => 'file-type-zip',
    'rpm' => 'file-type-zip',
    'apk' => 'file-type-android',
    'msi' => 'file-type-zip',
    // Code & markup
    'php' => 'file-type-php',
    'js' => 'file-type-js',
    'mjs' => 'file-type-js',
    'cjs' => 'file-type-js',
    'ts' => 'file-type-typescript',
    'jsx' => 'file-type-reactjs',
    'tsx' => 'file-type-reactts',
    'vue' => 'file-type-vue',
    'svelte' => 'file-type-svelte',
    'html' => 'file-type-html',
    'htm' => 'file-type-html',
    'css' => 'file-type-css',
    'scss' => 'file-type-scss',
    'sass' => 'file-type-scss',
    'less' => 'file-type-less',
    'json' => 'file-type-json',
    'xml' => 'file-type-xml',
    'xsl' => 'file-type-xml',
    'xslt' => 'file-type-xml',
    'yml' => 'file-type-yaml',
    'yaml' => 'file-type-yaml',
    'toml' => 'file-type-toml',
    'ini' => 'file-type-config',
    'conf' => 'file-type-config',
    'cfg' => 'file-type-config',
    'env' => 'file-type-config',
    'sql' => 'file-type-sql',
    'py' => 'file-type-python',
    'rb' => 'file-type-ruby',
    'go' => 'file-type-go',
    'rs' => 'file-type-rust',
    'java' => 'file-type-java',
    'class' => 'file-type-java',
    'jar' => 'file-type-java',
    'kt' => 'file-type-kotlin',
    'kts' => 'file-type-kotlin',
    'swift' => 'file-type-swift',
    'c' => 'file-type-c',
    'h' => 'file-type-c',
    'cpp' => 'file-type-cpp',
    'cc' => 'file-type-cpp',
    'cxx' => 'file-type-cpp',
    'hpp' => 'file-type-cpp',
    'cs' => 'file-type-csharp',
    'fs' => 'file-type-fsharp',
    'fsx' => 'file-type-fsharp',
    'dart' => 'file-type-dart',
    'lua' => 'file-type-lua',
    'pl' => 'file-type-perl',
    'pm' => 'file-type-perl',
    'r' => 'file-type-r',
    'scala' => 'file-type-scala',
    'sh' => 'file-type-shell',
    'bash' => 'file-type-shell',
    'zsh' => 'file-type-shell',
    'fish' => 'file-type-shell',
    'ps1' => 'file-type-powershell',
    'psm1' => 'file-type-powershell',
    'bat' => 'file-type-batch',
    'cmd' => 'file-type-batch',
    'dockerfile' => 'file-type-docker',
    'gradle' => 'file-type-gradle',
    'cmake' => 'file-type-cmake',
    'mk' => 'file-type-makefile',
    'make' => 'file-type-makefile',
    'wasm' => 'file-type-wasm',
    'ipynb' => 'file-type-jupyter',
    // Data & science
    'db' => 'file-type-sqlite',
    'sqlite' => 'file-type-sqlite',
    'sqlite3' => 'file-type-sqlite',
    'parquet' => 'file-type-json',
    'avro' => 'file-type-json',
    'h5' => 'file-type-json',
    'hdf5' => 'file-type-json',
    'mat' => 'file-type-matlab',
    'rdata' => 'file-type-r',
    'rds' => 'file-type-r',
    'sav' => 'file-type-json',
    'dta' => 'file-type-json',
    'geojson' => 'file-type-json',
    'kml' => 'file-type-xml',
    'kmz' => 'file-type-xml',
    // CAD / 3D
    'dwg' => 'file-type-autocad',
    'dxf' => 'file-type-autocad',
    'stl' => 'file-type-3d',
    'obj' => 'file-type-3d',
    'fbx' => 'file-type-3d',
    'blend' => 'file-type-3d',
    'step' => 'file-type-3d',
    'stp' => 'file-type-3d',
    'iges' => 'file-type-3d',
    // Security
    'pem' => 'file-type-cert',
    'crt' => 'file-type-cert',
    'cer' => 'file-type-cert',
    'der' => 'file-type-cert',
    'p12' => 'file-type-key',
    'pfx' => 'file-type-key',
    'gpg' => 'file-type-key',
    'asc' => 'file-type-key',
    // Email & contacts
    'eml' => 'file-type-email',
    'msg' => 'file-type-email',
    'ics' => 'file-type-email',
    'vcf' => 'file-type-email',
    // Fonts
    'ttf' => 'file-type-font',
    'otf' => 'file-type-font',
    'woff' => 'file-type-font',
    'woff2' => 'file-type-font',
    'eot' => 'file-type-font',
    // Executables & system
    'exe' => 'file-type-exe',
    'com' => 'file-type-exe',
    'dll' => 'file-type-dll',
    'sys' => 'file-type-binary',
    'lnk' => 'file-type-link',
    'reg' => 'file-type-reg',
    // Virtualization / backup
    'vmdk' => 'file-type-binary',
    'vdi' => 'file-type-binary',
    'vhd' => 'file-type-binary',
    'vhdx' => 'file-type-binary',
    'bak' => 'file-type-binary',
    'old' => 'file-type-binary',
    'tmp' => 'file-type-binary',
    // Access DB
    'mdb' => 'file-type-access',
    'accdb' => 'file-type-access',
  ];

  /**
   * @var array<string, string> category value => icon suffix
   */
  private const CATEGORY_ICON_MAP = [
    'office_word' => 'file-type-word',
    'office_excel' => 'file-type-excel',
    'office_powerpoint' => 'file-type-powerpoint',
    'pdf' => 'file-type-pdf',
    'image' => 'file-type-image',
    'video' => 'file-type-video',
    'audio' => 'file-type-audio',
    'archive' => 'file-type-zip',
    'code' => 'file-type-code',
    'text' => 'file-type-text',
    'database' => 'file-type-sqlite',
    'email' => 'file-type-email',
    'font' => 'file-type-font',
    'executable' => 'file-type-exe',
    'certificate' => 'file-type-cert',
    'cad' => 'file-type-3d',
    'binary' => 'file-type-binary',
    'default' => 'default-file',
  ];

  /**
   * @var array<string, list<string>> category value => extensions
   */
  private const CATEGORY_EXTENSIONS = [
    'office_word' => ['doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'pages'],
    'office_excel' => ['xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv', 'tsv', 'numbers'],
    'office_powerpoint' => ['ppt', 'pptx', 'pptm', 'odp', 'key', 'one', 'onetoc2'],
    'pdf' => ['pdf'],
    'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tif', 'tiff', 'heic', 'heif', 'psd', 'raw'],
    'video' => ['mp4', 'webm', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'ogv', 'mpeg', 'mpg', 'm4v', '3gp'],
    'audio' => ['mp3', 'wav', 'flac', 'ogg', 'aac', 'm4a', 'wma', 'opus', 'midi', 'mid'],
    'archive' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz', 'iso', 'dmg', 'deb', 'rpm'],
    'code' => ['php', 'js', 'ts', 'html', 'css', 'json', 'xml', 'yml', 'yaml', 'py', 'java', 'go', 'rs', 'c', 'cpp', 'cs', 'sh', 'rb'],
    'text' => ['txt', 'log', 'md', 'markdown', 'rtf', 'tex', 'ini', 'conf', 'cfg'],
    'database' => ['db', 'sqlite', 'sqlite3', 'sql'],
    'email' => ['eml', 'msg', 'ics', 'vcf'],
    'font' => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
    'executable' => ['exe', 'com', 'dll', 'msi', 'apk', 'bat', 'cmd'],
    'certificate' => ['pem', 'crt', 'cer', 'key', 'p12', 'pfx'],
    'cad' => ['dwg', 'dxf', 'stl', 'obj', 'blend'],
    'binary' => ['bin', 'dat', 'bak', 'tmp', 'vmdk', 'vdi'],
  ];

  /**
   * @brief Resolve extension to UX icon descriptor.
   *
   * @param string $extension Raw file extension (with or without leading dot).
   * @return FileIconDescriptor
   * @date 2026-06-24
   * @author Stephane H.
   */
  public function resolve(string $extension): FileIconDescriptor
  {
    $normalized = $this->normalizeExtension($extension);
    if ($normalized === '') {
      return $this->buildDescriptor('default-file', 'FILE', FileIconCategory::Default);
    }

  $normalized = $this->resolveCompoundExtension($normalized);

    if (isset(self::EXTENSION_ICON_MAP[$normalized])) {
      return $this->buildDescriptor(
        self::EXTENSION_ICON_MAP[$normalized],
        strtoupper($normalized),
        $this->categoryForExtension($normalized),
      );
    }

    foreach (self::CATEGORY_EXTENSIONS as $categoryValue => $extensions) {
      if (\in_array($normalized, $extensions, true)) {
        return $this->buildDescriptor(
          self::CATEGORY_ICON_MAP[$categoryValue],
          strtoupper($normalized),
          FileIconCategory::from($categoryValue),
        );
      }
    }

    return $this->buildDescriptor('default-file', strtoupper($normalized), FileIconCategory::Default);
  }

  /**
   * @brief List unique vscode icon suffixes referenced by the resolver.
   *
   * @param void No input parameter.
   * @return list<string>
   * @date 2026-06-24
   * @author Stephane H.
   */
  public function listUsedIconSuffixes(): array
  {
    $suffixes = array_values(self::EXTENSION_ICON_MAP);
    foreach (self::CATEGORY_ICON_MAP as $iconSuffix) {
      $suffixes[] = $iconSuffix;
    }

    $suffixes = array_unique($suffixes);
    sort($suffixes);

    return array_values($suffixes);
  }

  /**
   * @brief Normalize extension token.
   *
   * @param string $extension Raw extension.
   * @return string
   * @date 2026-06-24
   * @author Stephane H.
   */
  private function normalizeExtension(string $extension): string
  {
    $ext = strtolower(trim($extension));
    if (str_starts_with($ext, '.')) {
      $ext = substr($ext, 1);
    }

    return $ext;
  }

  /**
   * @brief Prefer trailing segment for compound extensions (e.g. tar.gz).
   *
   * @param string $extension Normalized extension.
   * @return string
   * @date 2026-06-24
   * @author Stephane H.
   */
  private function resolveCompoundExtension(string $extension): string
  {
    if (!str_contains($extension, '.')) {
      return $extension;
    }

    $segments = explode('.', $extension);
    $last = (string) end($segments);

    return $last !== '' ? $last : $extension;
  }

  /**
   * @brief Infer category for a known extension key.
   *
   * @param string $extension Normalized extension present in the exact map.
   * @return FileIconCategory
   * @date 2026-06-24
   * @author Stephane H.
   */
  private function categoryForExtension(string $extension): FileIconCategory
  {
    foreach (self::CATEGORY_EXTENSIONS as $categoryValue => $extensions) {
      if (\in_array($extension, $extensions, true)) {
        return FileIconCategory::from($categoryValue);
      }
    }

    return FileIconCategory::Default;
  }

  /**
   * @brief Build descriptor with icon prefix applied.
   *
   * @param string $iconSuffix Icon suffix without prefix.
   * @param string $ariaLabel Accessible label.
   * @param FileIconCategory $category Icon family.
   * @return FileIconDescriptor
   * @date 2026-06-24
   * @author Stephane H.
   */
  private function buildDescriptor(string $iconSuffix, string $ariaLabel, FileIconCategory $category): FileIconDescriptor
  {
    return new FileIconDescriptor(
      self::ICON_PREFIX.$iconSuffix,
      $ariaLabel,
      $category,
    );
  }
}
