<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Integration;

use Illuminate\Http\Client\Response;
use LogicException;

/**
 * 一次出站 HTTP 调用的结果。
 *
 * 成功携带 Response；失败携带 IntegrationException（及可选的原始 Response）。
 * 默认不断开调用栈；需要硬失败时显式 valueOrThrow()。
 */
final class IntegrationResult
{
    /**
     * @param Response|null $response 响应；连接失败时可能为 null
     * @param IntegrationException|null $error 失败原因
     */
    private function __construct(
        private readonly ?Response $response,
        private readonly ?IntegrationException $error,
    ) {
    }

    /**
     * 构造成功结果。
     */
    public static function success(Response $response): self
    {
        return new self($response, null);
    }

    /**
     * 构造失败结果。
     *
     * @param IntegrationException $error 失败原因
     * @param Response|null $response 若已收到 HTTP 响应则可附带
     */
    public static function failure(IntegrationException $error, ?Response $response = null): self
    {
        return new self($response, $error);
    }

    /**
     * 是否成功。
     */
    public function successful(): bool
    {
        return $this->error === null;
    }

    /**
     * 是否失败。
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * 成功时的响应；失败时可能仍有响应（例如 HTTP/业务失败），连接失败则为 null。
     */
    public function response(): ?Response
    {
        return $this->response;
    }

    /**
     * 失败原因；成功时为 null。
     */
    public function error(): ?IntegrationException
    {
        return $this->error;
    }

    /**
     * 硬失败：成功返回 Response，失败抛出 IntegrationException。
     *
     * @throws IntegrationException
     */
    public function valueOrThrow(): Response
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->response === null) {
            throw new LogicException('IntegrationResult is marked successful but has no response.');
        }

        return $this->response;
    }

    /**
     * 软失败：成功返回 Response，失败返回给定默认值。
     *
     * @template TDefault
     * @param TDefault $default 失败时的默认值
     * @return Response|TDefault
     */
    public function valueOr(mixed $default): mixed
    {
        if ($this->failed()) {
            return $default;
        }

        return $this->response;
    }
}
