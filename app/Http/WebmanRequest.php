<?php

namespace App\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/** Adapterman supplies globals, but php://input is not its HTTP request body. */
class WebmanRequest
{
    public static function capture(): Request
    {
        Request::enableHttpMethodParameterOverride();
        $content = $GLOBALS['HTTP_RAW_REQUEST_DATA'] ?? '';
        $parameters = $_POST;
        // Mirror Symfony's capture behavior for forms sent with PUT/PATCH/DELETE.
        // Adapterman puts those in $_REQUEST, while Symfony needs the body bag.
        if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded')
            && in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            parse_str($content, $parameters);
        }
        return Request::createFromBase(new SymfonyRequest(
            $_GET, $parameters, [], $_COOKIE, $_FILES, $_SERVER, $content
        ));
    }
}
