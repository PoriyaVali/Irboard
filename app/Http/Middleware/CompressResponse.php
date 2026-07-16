<?php
namespace App\Http\Middleware;

use Closure;

class CompressResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        
        $content = $response->getContent();
        if (strlen($content) > 1024) {
            $compressed = gzencode($content, 6);
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', 'gzip');
        }
        
        return $response;
    }
}
