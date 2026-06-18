<?php

namespace Ptpn\IonClient\Exceptions;

use RuntimeException;

class IonClientException extends RuntimeException
{
    public const TYPE_NETWORK  = 'network';
    public const TYPE_AUTH     = 'auth';
    public const TYPE_RESPONSE = 'response';
    public const TYPE_CONFIG   = 'config';

    /**
     * Error type identifier.
     *
     * @var string
     */
    private string $type;

    /**
     * @param string          $message
     * @param string          $type
     * @param int             $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        string $type = self::TYPE_NETWORK,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->type = $type;
    }

    /**
     * Get the error type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Create an exception for network/transport failures.
     * NOTE: Do NOT pass the original Guzzle exception as $previous here to
     * avoid leaking request headers (including X-Client-Secret) in logs.
     *
     * @param string $message
     * @return static
     */
    public static function networkError(string $message): static
    {
        return new static($message, self::TYPE_NETWORK);
    }

    /**
     * Create an exception for authentication/authorization failures.
     *
     * @param string $message
     * @return static
     */
    public static function authFailed(string $message): static
    {
        return new static($message, self::TYPE_AUTH);
    }

    /**
     * Create an exception for unexpected or unparseable API responses.
     *
     * @param string $message
     * @return static
     */
    public static function invalidResponse(string $message): static
    {
        return new static($message, self::TYPE_RESPONSE);
    }

    /**
     * Create an exception for missing or invalid package configuration.
     *
     * @param string $message
     * @return static
     */
    public static function configError(string $message): static
    {
        return new static($message, self::TYPE_CONFIG);
    }
}
