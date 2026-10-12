<?php
declare(strict_types=1);
namespace Kienzlefon;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

final class HttpApi
{
    private string $token;
    public function __construct(private Runtime $runtime) { $this->token = bin2hex(random_bytes(32)); }
    public function __invoke(ServerRequestInterface $request): Response|PromiseInterface
    {
        try {
            $http = $this->runtime->config['http'];
            $host = trim(strtolower($request->getUri()->getHost()), '[]');
            if (!in_array($host, array_map(static fn($v) => trim(strtolower($v), '[]'), $http['allowed_hosts']), true)) return self::json(['error'=>'Unbekannter Hostname.'], 403);
            $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';
            if (!self::allowedAddress($address, $http['allowed_networks'])) return self::json(['error'=>'Zugriff nur aus dem konfigurierten Netzwerk.'], 403);
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '') {
                $expected = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
                if ($origin !== $expected && $origin !== rtrim($http['public_url'], '/')) return self::json(['error'=>'Fremder Web-Ursprung.'], 403);
            }
            if (in_array($request->getHeaderLine('Sec-Fetch-Site'), ['cross-site'], true) && $request->getMethod() !== 'GET') return self::json(['error'=>'Fremder Web-Ursprung.'], 403);
            $method = $request->getMethod(); $path = $request->getUri()->getPath();
            if ($method === 'GET' && $path === '/api/v1/bootstrap') {
                $clients = [];
                foreach ($this->runtime->config['clients'] as $id => $client) $clients[] = ['id'=>$id,'label'=>$client['label'],'extension'=>$client['extension']];
                return self::json(['token'=>$this->token,'clients'=>$clients,'demo'=>$this->runtime->demo,'version'=>Version::CURRENT]);
            }
            if ($method === 'GET' && $path === '/api/v1/telephony') return self::json($this->runtime->view());
            if ($method === 'GET' && $path === '/healthz') return self::json(['status'=>'ok','demo'=>$this->runtime->demo,'version'=>Version::CURRENT]);
            if ($method === 'POST') {
                if (!hash_equals($this->token, $request->getHeaderLine('X-Kienzlefon-Token'))) return self::json(['error'=>'Sitzung abgelaufen. Seite neu laden.'], 403);
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') return self::json(['error'=>'JSON erforderlich.'], 415);
                $body = (string)$request->getBody();
                if (strlen($body) > 8192) return self::json(['error'=>'Anfrage zu groß.'], 413);
                $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($input) || array_is_list($input)) throw new \InvalidArgumentException('JSON-Objekt erforderlich.');
                if ($path === '/api/v1/lookup') {
                    if (!is_string($input['number'] ?? null)) throw new \InvalidArgumentException('Rufnummer fehlt.');
                    return $this->runtime->lookup($input['number'])->then(static function ($result) { $result['patients'] = Aps::publicPatients($result['patients']); unset($result['name']); return self::json($result); }, self::failure(...));
                }
                if ($path === '/api/v1/patient-open') return self::json($this->runtime->openPatient($input));
                if ($path === '/api/v1/calls') return $this->runtime->originate($input)->then(static fn($call) => self::json($call, 202), self::failure(...));
                return self::json(['error'=>'Endpunkt nicht gefunden.'], 404);
            }
            if ($method !== 'GET' && $method !== 'HEAD') return self::json(['error'=>'Methode nicht erlaubt.'], 405);
            $assets = ['/' => ['index.html','text/html; charset=utf-8'], '/patient-oeffnen'=>['index.html','text/html; charset=utf-8'], '/waehlen'=>['index.html','text/html; charset=utf-8'], '/app.js'=>['app.js','application/javascript; charset=utf-8'], '/style.css'=>['style.css','text/css; charset=utf-8'], '/favicon.svg'=>['favicon.svg','image/svg+xml']];
            if (!isset($assets[$path])) return self::json(['error'=>'Seite nicht gefunden.'], 404);
            [$file,$type] = $assets[$path];
            return new Response(200, self::headers() + ['Content-Type'=>$type], $method === 'HEAD' ? '' : file_get_contents(dirname(__DIR__) . '/public/' . $file));
        } catch (\Throwable $e) { return self::failure($e); }
    }
    public static function allowedAddress(string $address, array $networks): bool
    {
        $ip = @inet_pton($address);
        if ($ip === false) return false;
        if (strlen($ip) === 16 && substr($ip, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $ip = substr($ip, 12);
        foreach ($networks as $network) {
            [$base,$bits] = explode('/', $network); $raw = @inet_pton($base); $bits = (int)$bits;
            if ($raw === false || strlen($raw) !== strlen($ip)) continue;
            $bytes = intdiv($bits, 8); $remaining = $bits % 8;
            if (substr($raw, 0, $bytes) !== substr($ip, 0, $bytes)) continue;
            if (!$remaining || ((ord($raw[$bytes]) ^ ord($ip[$bytes])) & (255 << (8 - $remaining))) === 0) return true;
        }
        return false;
    }
    private static function failure(\Throwable $e): Response
    {
        $validation = $e instanceof \InvalidArgumentException || $e instanceof \JsonException || $e instanceof \TypeError;
        $message = $e instanceof \TypeError ? 'Ungültige Eingabedaten.' : ($e instanceof \JsonException ? 'Ungültiges JSON.' : $e->getMessage());
        return self::json(['error'=>$message], $validation ? 400 : 503);
    }
    private static function headers(): array
    {
        return ['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer','X-Frame-Options'=>'DENY','Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'"];
    }
    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, self::headers() + ['Content-Type'=>'application/json; charset=utf-8'], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
