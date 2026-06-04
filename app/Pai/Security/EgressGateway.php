<?php

namespace App\Pai\Security;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * 可信區與外部世界之間的安全出口。所有對外/MCP 請求都應透過
 * {@see client()} 發出——它在請求離開前於網路層把 {{vault:NAME}}
 * 佔位符換成真正憑證。智能體因此從未持有、也無法外洩它沒有的密碼。
 */
class EgressGateway
{
    public function __construct(private readonly SecretVault $vault) {}

    /** 取得已掛上機密注入中介層的 HTTP client。 */
    public function client(): PendingRequest
    {
        return Http::withRequestMiddleware(fn (RequestInterface $request) => $this->inject($request));
    }

    private function inject(RequestInterface $request): RequestInterface
    {
        // headers（最常見：Authorization: Bearer {{vault:...}}）
        foreach ($request->getHeaders() as $name => $values) {
            $resolved = array_map(fn (string $v) => $this->resolve($v), $values);
            if ($resolved !== $values) {
                $request = $request->withHeader($name, $resolved);
            }
        }

        // body（如 JSON payload 內嵌憑證佔位）
        $body = (string) $request->getBody();
        if ($body !== '' && SecretRef::containsRef($body)) {
            $request = $request->withBody(Utils::streamFor($this->resolve($body)));
        }

        return $request;
    }

    private function resolve(string $value): string
    {
        return preg_replace_callback(
            SecretRef::PATTERN,
            fn (array $m) => $this->vault->get($m[1]) ?? $m[0], // 找不到則保留佔位，不靜默變空字串
            $value,
        );
    }
}
