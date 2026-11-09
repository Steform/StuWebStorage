<?php

declare(strict_types=1);

namespace App\Service\Download;

/**
 * @brief Stream wrapper that forwards fwrite() chunks to a callback (bounded memory).
 * @date 2026-11-02
 * @author Stephane H.
 */
final class CallbackWriteStream
{
    /** @var array<string, callable(string): void> */
    private static array $callbacks = [];

    private static bool $registered = false;

    private string $id = '';

    /** @var resource|null */
    public $context;

    /**
     * @brief Open a writable stream that invokes $sink for every fwrite chunk.
     * @param callable(string): void $sink Chunk consumer.
     * @return resource Writable stream handle.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public static function open(callable $sink)
    {
        self::register();
        $id = bin2hex(random_bytes(8));
        self::$callbacks[$id] = $sink;
        $handle = fopen('callbackwrite://'.$id, 'wb');
        if ($handle === false) {
            unset(self::$callbacks[$id]);
            throw new \RuntimeException('download.sink_stream_failed');
        }

        return $handle;
    }

    /**
     * @brief Register the stream wrapper once per process.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        if (!in_array('callbackwrite', stream_get_wrappers(), true)) {
            stream_wrapper_register('callbackwrite', self::class);
        }
        self::$registered = true;
    }

    /**
     * @brief Resolve callback for a stream path id.
     * @param string $id Callback id.
     * @return callable(string): void|null
     * @date 2026-11-02
     * @author Stephane H.
     */
    public static function resolve(string $id): ?callable
    {
        return self::$callbacks[$id] ?? null;
    }

    /**
     * @brief Release callback after stream close.
     * @param string $id Callback id.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public static function release(string $id): void
    {
        unset(self::$callbacks[$id]);
    }

    /**
     * @brief Stream open hook.
     * @param string $path Wrapper path.
     * @param string $mode Open mode.
     * @param int $options Unused.
     * @param string|null $openedPath Unused.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $id = (string) parse_url($path, PHP_URL_HOST);
        if ($id === '' || !isset(self::$callbacks[$id])) {
            return false;
        }
        $this->id = $id;

        return str_contains($mode, 'w') || str_contains($mode, 'a');
    }

    /**
     * @brief Stream write hook.
     * @param string $data Chunk.
     * @return int Bytes accepted.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_write(string $data): int
    {
        $cb = self::$callbacks[$this->id] ?? null;
        if ($cb === null) {
            return 0;
        }
        $cb($data);

        return strlen($data);
    }

    /**
     * @brief Stream close hook.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_close(): void
    {
        self::release($this->id);
    }

    /**
     * @brief Stream eof hook.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @brief Stream stat hook.
     * @return array<int|string, mixed>
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_stat(): array
    {
        return [];
    }

    /**
     * @brief Stream flush hook.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function stream_flush(): bool
    {
        return true;
    }
}
