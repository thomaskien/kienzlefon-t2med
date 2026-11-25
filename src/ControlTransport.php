<?php
declare(strict_types=1);
namespace Kienzlefon;
use React\Http\Browser;
use React\Socket\Connector;
use React\Promise\PromiseInterface;

final class ControlTransport
{
    private Browser $http;
    public function __construct(private array $config)
    {
        $this->http=(new Browser(new Connector(['timeout'=>2,'tls'=>['verify_peer'=>true,'verify_peer_name'=>true]])))
            ->withTimeout(5)->withFollowRedirects(false)->withRejectErrorResponse(false)->withResponseBuffer(2*1024*1024);
    }
    public function request(string $method,string $path,?array $body=null): PromiseInterface
    {
        try { $secret=TelephonyContract::secret($this->config['token_file']); }
        catch (\Throwable) { return \React\Promise\reject(new ControlError('state_unavailable')); }
        return $this->http->request($method,rtrim($this->config['base_url'],'/').$path,
            ['Authorization'=>'Bearer '.$secret,'Accept'=>'application/json','X-Kienzlefon-Display'=>'1','Content-Type'=>'application/json'],
            $body===null ? '' : json_encode($body,JSON_THROW_ON_ERROR))->then(static function($response) use ($path) {
                $status=$response->getStatusCode();
                if ($status>=300 && $status<400) throw new ControlError('state_unavailable');
                try {
                    // Preserve the array/object distinction in the authorization list.
                    $scope = $path === '/control/v1/routing/workplaces';
                    $value = json_decode((string)$response->getBody(), !$scope, 32, JSON_THROW_ON_ERROR);
                    if ($scope) $value = $value instanceof \stdClass ? get_object_vars($value) : null;
                } catch(\Throwable) { $value=null; }
                return ['status'=>$status,'body'=>$value];
            },static function(\Throwable $e) {
                throw new ControlError(str_contains(strtolower($e->getMessage()),'timed out') ? 'upstream_timeout' : 'upstream_unreachable');
            });
    }
}
